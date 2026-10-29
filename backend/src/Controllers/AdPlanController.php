<?php
declare(strict_types=1);

/**
 * Sponsorizzate preparate con la procedura guidata: obiettivo, pubblico, budget e testi, controllati con le regole di Meta
 * (subito, in automatico) e, a richiesta, dall'AI. Il risultato è una scheda da copiare in Gestione inserzioni.
 * Regole Meta: le pagine ufficiali controllate ogni giorno e i cambiamenti trovati.
 */
final class AdPlanController
{
    private const STATUSES = ['draft', 'ready', 'live', 'ended'];
    private const SELECT = 'SELECT a.*, b.name AS brand_name, b.primary_color AS brand_color, b.meta_ad_account_id, j.status AS review_status, j.error AS review_error
        FROM ad_plans a JOIN brand_profiles b ON b.id=a.brand_profile_id LEFT JOIN jobs j ON j.id=a.review_job_id';

    public static function rules(): never
    {
        $user = Auth::user();
        Http::json(['data' => MetaRules::rulebook() + ['monitor' => MetaPolicyMonitor::overview($user['organization_id'])]]);
    }

    /** L'organizzazione ha letto i cambiamenti delle regole: spariscono dagli avvisi. */
    public static function markRulesSeen(): never
    {
        $user = Auth::user();
        Database::connection()->prepare('UPDATE organizations SET meta_rules_seen_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $user['organization_id']]);
        Http::json(['data' => MetaPolicyMonitor::overview($user['organization_id'])]);
    }

    /** Controllo immediato delle pagine di Meta, senza aspettare quello giornaliero. */
    public static function refreshRules(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $pdo = Database::connection();
        $running = $pdo->query("SELECT id FROM jobs WHERE type='check_meta_policies' AND status IN ('queued','running') LIMIT 1")->fetchColumn();
        if ($running) Http::json(['data' => ['job_id' => (int)$running]], 202);
        $last = $pdo->query('SELECT MAX(checked_at) FROM meta_policy_sources')->fetchColumn();
        if ($last && strtotime($last) > strtotime('-10 minutes')) {
            Http::json(['error' => 'too_soon', 'message' => 'Le pagine di Meta sono state controllate pochi minuti fa.'], 429);
        }
        $job = Jobs::enqueue($user, 'check_meta_policies', []);
        Audit::log($user, 'meta_rules.refresh', 'job', (int)$job['id']);
        Http::json(['data' => ['job_id' => (int)$job['id']]], 202);
    }

    /** Controllo con le regole di Meta di una sponsorizzata non ancora salvata, mentre la si scrive. */
    public static function check(): never
    {
        Auth::user();
        Http::json(['data' => MetaRules::check(self::normalized(Http::body()))]);
    }

    public static function index(): never
    {
        $user = Auth::user();
        $params = [$user['organization_id']];
        $where = 'a.organization_id=?';
        if (isset($_GET['brand_id']) && $_GET['brand_id'] !== '') {
            $where .= ' AND a.brand_profile_id=?';
            $params[] = (int)$_GET['brand_id'];
        }
        $stmt = Database::connection()->prepare(self::SELECT . " WHERE {$where} ORDER BY FIELD(a.status, 'draft', 'ready', 'live', 'ended'), a.updated_at DESC LIMIT 200");
        $stmt->execute($params);
        Http::json(['data' => array_map([self::class, 'present'], $stmt->fetchAll())]);
    }

    public static function show(int $id): never
    {
        $user = Auth::user();
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        $brandId = (int)($data['brand_id'] ?? 0);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $contentId = Http::optionalInt($data, 'content_id');
        if ($contentId !== null) Tenant::findOrFail('content_items', $contentId, $user['organization_id'], 'id');
        $fields = self::validated($data);
        $fields['checks'] = json_encode(MetaRules::check($fields), JSON_UNESCAPED_UNICODE);
        $fields['audience'] = json_encode($fields['audience'], JSON_UNESCAPED_UNICODE);
        $pdo = Database::connection();
        $columns = array_keys($fields);
        $pdo->prepare('INSERT INTO ad_plans (organization_id, brand_profile_id, content_item_id, created_by, ' . implode(', ', $columns) . ') VALUES (?, ?, ?, ?' . str_repeat(', ?', count($columns)) . ')')
            ->execute([$user['organization_id'], $brandId, $contentId, $user['id'], ...array_values($fields)]);
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'ad_plan.created', 'ad_plan', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])], 201);
    }

    public static function update(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $current = self::find($id, $user['organization_id']);
        $data = Http::body();
        $fields = [];
        // Solo lo stato: le schede pubblicate restano come sono state caricate su Meta.
        if (array_keys($data) === ['status']) {
            $fields['status'] = Http::oneOf($data, 'status', self::STATUSES);
        } else {
            $fields = self::validated($data + $current);
            if (array_key_exists('status', $data)) $fields['status'] = Http::oneOf($data, 'status', self::STATUSES);
            $fields['checks'] = json_encode(MetaRules::check($fields), JSON_UNESCAPED_UNICODE);
            $fields['audience'] = json_encode($fields['audience'], JSON_UNESCAPED_UNICODE);
        }
        $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
        Database::connection()->prepare("UPDATE ad_plans SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($fields), $id, $user['organization_id']]);
        Audit::log($user, 'ad_plan.updated', 'ad_plan', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        self::find($id, $user['organization_id']);
        Database::connection()->prepare('DELETE FROM ad_plans WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Audit::log($user, 'ad_plan.deleted', 'ad_plan', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** Revisione approfondita dell'AI: rilegge la pagina di destinazione e controlla l'inserzione come farebbe Meta. */
    public static function review(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $plan = self::find($id, $user['organization_id']);
        if (in_array($plan['review_status'], ['queued', 'running'], true)) Http::json(['data' => $plan], 202);
        $job = Jobs::enqueue($user, 'ad_review', ['ad_plan_id' => $id]);
        Database::connection()->prepare('UPDATE ad_plans SET review_job_id=? WHERE id=? AND organization_id=?')->execute([$job['id'], $id, $user['organization_id']]);
        Http::json(['data' => self::find($id, $user['organization_id'])], 202);
    }

    public static function find(int $id, int $organizationId): array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE a.id=? AND a.organization_id=?');
        $stmt->execute([$id, $organizationId]);
        $row = $stmt->fetch();
        if (!$row) Http::json(['error' => 'not_found', 'message' => 'Sponsorizzata non trovata.'], 404);
        return self::present($row);
    }

    private static function present(array $row): array
    {
        $account = $row['meta_ad_account_id'];
        return [
            'id' => (int)$row['id'],
            'brand_profile_id' => (int)$row['brand_profile_id'],
            'brand_name' => $row['brand_name'],
            'brand_color' => $row['brand_color'],
            'content_item_id' => $row['content_item_id'] === null ? null : (int)$row['content_item_id'],
            'name' => $row['name'],
            'objective' => $row['objective'],
            'special_category' => $row['special_category'],
            'audience' => json_decode($row['audience'], true),
            'daily_budget' => (float)$row['daily_budget'],
            'start_date' => $row['start_date'],
            'end_date' => $row['end_date'],
            'primary_text' => $row['primary_text'],
            'headline' => $row['headline'],
            'description' => $row['description'],
            'cta' => $row['cta'],
            'url' => $row['url'],
            'checks' => $row['checks'] === null ? null : json_decode($row['checks'], true),
            'review' => $row['review'] === null ? null : json_decode($row['review'], true),
            'reviewed_at' => $row['reviewed_at'],
            'review_status' => $row['review_status'],
            'review_error' => $row['review_status'] === 'failed' ? $row['review_error'] : null,
            'status' => $row['status'],
            'ads_manager_url' => 'https://adsmanager.facebook.com/adsmanager/manage/campaigns' . ($account ? "?act={$account}" : ''),
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    /** Valori della richiesta nella forma usata da MetaRules::check, senza bloccare su campi mancanti (serve mentre si scrive). */
    private static function normalized(array $data): array
    {
        $audience = is_array($data['audience'] ?? null) ? $data['audience'] : [];
        return [
            'objective' => is_string($data['objective'] ?? null) && isset(MetaRules::OBJECTIVES[$data['objective']]) ? $data['objective'] : 'traffic',
            'special_category' => is_string($data['special_category'] ?? null) && isset(MetaRules::SPECIAL_CATEGORIES[$data['special_category']]) ? $data['special_category'] : 'none',
            'audience' => [
                'age_min' => (int)($audience['age_min'] ?? 18), 'age_max' => (int)($audience['age_max'] ?? 65),
                'genders' => is_string($audience['genders'] ?? null) ? $audience['genders'] : 'all',
                'interests' => array_values(array_filter((array)($audience['interests'] ?? []), 'is_string')),
            ],
            'daily_budget' => is_numeric($data['daily_budget'] ?? null) ? (float)$data['daily_budget'] : 0,
            'start_date' => is_string($data['start_date'] ?? null) ? $data['start_date'] : null,
            'end_date' => is_string($data['end_date'] ?? null) ? $data['end_date'] : null,
            ...array_map(fn($key) => is_scalar($data[$key] ?? null) ? mb_substr((string)$data[$key], 0, 5000) : '', ['primary_text' => 'primary_text', 'headline' => 'headline', 'description' => 'description', 'cta' => 'cta', 'url' => 'url']),
        ];
    }

    private static function validated(array $data): array
    {
        $objective = Http::oneOf($data, 'objective', array_keys(MetaRules::OBJECTIVES));
        $audience = is_array($data['audience'] ?? null) ? $data['audience'] : [];
        $ageMin = filter_var($audience['age_min'] ?? 18, FILTER_VALIDATE_INT, ['options' => ['min_range' => 13, 'max_range' => 65]]);
        $ageMax = filter_var($audience['age_max'] ?? 65, FILTER_VALIDATE_INT, ['options' => ['min_range' => 13, 'max_range' => 65]]);
        if ($ageMin === false || $ageMax === false || $ageMax < $ageMin) Http::validationError('Età non valida: Meta accetta da 13 a 65+ anni.');
        $radius = filter_var($audience['radius_km'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 80]]);
        if ($radius === false) Http::validationError('Il raggio va da 0 a 80 km.');
        $interests = array_values(array_unique(array_filter(array_map(fn($i) => is_scalar($i) ? mb_substr(trim((string)$i), 0, 60) : '', (array)($audience['interests'] ?? [])))));
        $location = is_scalar($audience['location'] ?? null) ? mb_substr(trim((string)$audience['location']), 0, 200) : '';

        $budget = filter_var($data['daily_budget'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($budget === false || $budget < 0 || $budget > 100000) Http::validationError('Budget giornaliero non valido.');
        $start = self::date($data['start_date'] ?? null) ?? Http::validationError('Data di inizio non valida.');
        $end = ($data['end_date'] ?? null) ? (self::date($data['end_date']) ?? Http::validationError('Data di fine non valida.')) : null;
        if ($end !== null && $end < $start) Http::validationError('La data di fine viene prima di quella di inizio.');

        $url = Http::optionalString($data, 'url', 2048);
        if ($url !== null && !preg_match('#^https?://[^\s/$.?\#].[^\s]*$#i', $url)) Http::validationError('Il link deve iniziare con https://');
        $cta = Http::oneOf($data, 'cta', array_keys(MetaRules::CTAS), MetaRules::OBJECTIVES[$objective]['ctas'][0]);
        $name = Http::optionalString($data, 'name', 160) ?? (MetaRules::OBJECTIVES[$objective]['label'] . ' · ' . date('d/m/Y', strtotime($start)));

        return [
            'name' => $name,
            'objective' => $objective,
            'special_category' => Http::oneOf($data, 'special_category', array_keys(MetaRules::SPECIAL_CATEGORIES), 'none'),
            'audience' => [
                'location' => $location !== '' ? $location : 'Italia', 'radius_km' => $radius,
                'age_min' => $ageMin, 'age_max' => $ageMax,
                'genders' => Http::oneOf($audience, 'genders', ['all', 'female', 'male'], 'all'),
                'interests' => array_slice($interests, 0, 15),
                'advantage' => (bool)($audience['advantage'] ?? true),
            ],
            'daily_budget' => round((float)$budget, 2),
            'start_date' => $start,
            'end_date' => $end,
            'primary_text' => mb_substr(trim((string)($data['primary_text'] ?? '')), 0, 5000),
            'headline' => mb_substr(trim((string)($data['headline'] ?? '')), 0, 255),
            'description' => mb_substr(trim((string)($data['description'] ?? '')), 0, 255),
            'cta' => $cta,
            'url' => $url,
        ];
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }
}
