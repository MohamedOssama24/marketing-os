<?php
declare(strict_types=1);

/**
 * Sponsorizzate preparate con la procedura guidata: obiettivo, pubblico, budget e testi, controllati con le regole di Meta
 * (subito, in automatico) e, a richiesta, dall'AI. Con il cliente collegato a Meta si pubblicano da qui (in pausa) e si attivano
 * con una conferma; altrimenti il risultato è una scheda da copiare in Gestione inserzioni.
 * Regole Meta: le pagine ufficiali controllate ogni giorno e i cambiamenti trovati.
 */
final class AdPlanController
{
    private const STATUSES = ['draft', 'ready', 'live', 'ended'];
    /** Musica delle sponsorizzate salvate prima che si potesse scegliere: la libreria di Meta, senza atmosfera. */
    private const NO_CHOICE_MUSIC = ['source' => 'meta', 'mood' => null, 'meta_picks' => true, 'asset_id' => null, 'track_name' => null, 'rights_confirmed' => false];
    private const SELECT = 'SELECT a.*, b.name AS brand_name, b.primary_color AS brand_color, b.meta_ad_account_id, j.status AS review_status, j.error AS review_error,
        mj.status AS meta_job_status, mj.error AS meta_job_error, s.spent AS meta_spent, s.impressions AS meta_impressions, s.reach AS meta_reach, s.clicks AS meta_clicks, s.results AS meta_results
        FROM ad_plans a JOIN brand_profiles b ON b.id=a.brand_profile_id LEFT JOIN jobs j ON j.id=a.review_job_id LEFT JOIN jobs mj ON mj.id=a.meta_job_id
        LEFT JOIN (SELECT ad_plan_id, SUM(spent) AS spent, SUM(impressions) AS impressions, SUM(reach) AS reach, SUM(clicks) AS clicks, SUM(results) AS results
          FROM ad_spend WHERE ad_plan_id IS NOT NULL GROUP BY ad_plan_id) s ON s.ad_plan_id=a.id';

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
        $fields = self::validated($data, $user['organization_id']);
        $fields['checks'] = json_encode(MetaRules::check($fields), JSON_UNESCAPED_UNICODE);
        $fields['audience'] = json_encode($fields['audience'], JSON_UNESCAPED_UNICODE);
        $fields['music'] = json_encode($fields['music'], JSON_UNESCAPED_UNICODE);
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
        if ($current['meta']['published']) self::updatePublished($current, $data, $user);
        // Solo lo stato: le schede pubblicate restano come sono state caricate su Meta.
        if (array_keys($data) === ['status']) {
            $fields['status'] = Http::oneOf($data, 'status', self::STATUSES);
        } else {
            $fields = self::validated($data + $current, $user['organization_id']);
            if (array_key_exists('status', $data)) $fields['status'] = Http::oneOf($data, 'status', self::STATUSES);
            $fields['checks'] = json_encode(MetaRules::check($fields), JSON_UNESCAPED_UNICODE);
            $fields['audience'] = json_encode($fields['audience'], JSON_UNESCAPED_UNICODE);
            $fields['music'] = json_encode($fields['music'], JSON_UNESCAPED_UNICODE);
        }
        $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
        Database::connection()->prepare("UPDATE ad_plans SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($fields), $id, $user['organization_id']]);
        Audit::log($user, 'ad_plan.updated', 'ad_plan', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $plan = self::find($id, $user['organization_id']);
        if ($plan['meta']['published'] && !$plan['meta']['sample'] && !in_array($plan['meta']['status'], ['paused', 'campaign_paused', 'adset_paused', 'completed', 'disapproved', 'archived', 'deleted'], true)) {
            Http::validationError('La sponsorizzata è attiva su Meta: mettila in pausa prima di eliminarla da qui.');
        }
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

    /** Pubblica su Meta in pausa (dal worker): campagna, gruppo di inserzioni, immagine, creatività e inserzione. */
    public static function publish(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $plan = self::find($id, $user['organization_id']);
        if (in_array($plan['meta']['job_status'], ['queued', 'running'], true)) Http::json(['data' => $plan], 202);
        if ($plan['meta']['ad_id']) Http::validationError('La sponsorizzata è già su Meta.');
        $connection = MetaMarketing::connection($user['organization_id'], $plan['brand_profile_id']);
        $problems = MetaMarketing::problems($plan, $connection, self::imagePath($plan, $user['organization_id']));
        if ($problems) Http::json(['error' => 'not_ready', 'message' => $problems[0], 'problems' => $problems], 422);
        $job = Jobs::enqueue($user, 'meta_publish', ['ad_plan_id' => $id]);
        Database::connection()->prepare('UPDATE ad_plans SET meta_job_id=?, meta_status=\'publishing\', meta_feedback=NULL WHERE id=? AND organization_id=?')
            ->execute([$job['id'], $id, $user['organization_id']]);
        Audit::log($user, 'ad_plan.meta_publish', 'ad_plan', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])], 202);
    }

    /**
     * Attiva la sponsorizzata su Meta: da qui si spendono soldi veri, quindi solo titolare o amministratore,
     * e con la conferma del budget giornaliero mostrato (se nel frattempo è cambiato, si richiede di nuovo).
     */
    public static function activate(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $plan = self::find($id, $user['organization_id']);
        if (!$plan['meta']['ad_id']) Http::validationError('Pubblica prima la sponsorizzata su Meta.');
        $confirmed = filter_var(Http::body()['confirm_daily_budget'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($confirmed === false || abs($confirmed - $plan['daily_budget']) > 0.001) Http::validationError('Conferma il budget giornaliero per attivare la sponsorizzata.');
        if ($plan['daily_budget'] > MetaMarketing::maxDailyBudget()) Http::validationError('Il budget giornaliero supera il tetto impostato per l\'app.');
        $plan = self::onMeta($plan, $user, fn($connection) => MetaMarketing::setStatus($plan, $connection, true), ['meta_status' => 'in_process', 'status' => 'live', 'activated_at' => date('Y-m-d H:i:s')]);
        Audit::log($user, 'ad_plan.meta_activated', 'ad_plan', $id);
        Http::json(['data' => $plan]);
    }

    public static function pause(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $plan = self::find($id, $user['organization_id']);
        if (!$plan['meta']['ad_id']) Http::validationError('La sponsorizzata non è su Meta.');
        $plan = self::onMeta($plan, $user, fn($connection) => MetaMarketing::setStatus($plan, $connection, false), ['meta_status' => 'paused']);
        Audit::log($user, 'ad_plan.meta_paused', 'ad_plan', $id);
        Http::json(['data' => $plan]);
    }

    /** Rilegge subito da Meta stato della revisione e risultati. */
    public static function syncMeta(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $plan = self::find($id, $user['organization_id']);
        if (!$plan['meta']['ad_id']) Http::validationError('La sponsorizzata non è su Meta.');
        $connection = MetaMarketing::connection($user['organization_id'], $plan['brand_profile_id']) ?? Http::validationError('Ricollega Facebook nella scheda del cliente.');
        try {
            self::saveSync($plan, $connection, $user['organization_id']);
        } catch (MetaApiException $e) {
            MetaConnectionController::markError($connection, $e);
            Http::validationError($e->getMessage());
        }
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    /** Salva su ad_plans e ad_spend stato e risultati letti da Meta (anche dal worker). */
    public static function saveSync(array $plan, array $connection, int $organizationId): void
    {
        $result = MetaMarketing::sync($plan, $connection);
        $pdo = Database::connection();
        $status = $plan['status'];
        if ($plan['end_date'] !== null && $plan['end_date'] < date('Y-m-d') && $status === 'live') $status = 'ended';
        $pdo->prepare('UPDATE ad_plans SET meta_status=?, meta_feedback=?, meta_synced_at=?, status=? WHERE id=? AND organization_id=?')
            ->execute([$result['status'], $result['feedback'] ? json_encode($result['feedback'], JSON_UNESCAPED_UNICODE) : null, date('Y-m-d H:i:s'), $status, $plan['id'], $organizationId]);

        // Una riga di spesa per mese: la fatturazione e i report la usano come quelle inserite a mano. Le righe già fatturate non si toccano.
        $find = $pdo->prepare('SELECT id, invoice_id FROM ad_spend WHERE ad_plan_id=? AND period_start=?');
        foreach ($result['months'] as $m) {
            $values = [$m['end'], $plan['daily_budget'], $m['spent'], $m['impressions'], $m['reach'], $m['clicks'], $m['results']];
            $find->execute([$plan['id'], $m['start']]);
            $row = $find->fetch();
            if ($row) {
                if ($row['invoice_id'] === null) {
                    $pdo->prepare('UPDATE ad_spend SET period_end=?, budget=?, spent=?, impressions=?, reach=?, clicks=?, results=? WHERE id=?')->execute([...$values, $row['id']]);
                }
                continue;
            }
            if ($m['spent'] <= 0 && $m['impressions'] === 0) continue;
            $insert = 'INSERT INTO ad_spend (organization_id, brand_profile_id, ad_plan_id, platform, source, campaign_name, period_start, period_end, budget, spent, impressions, reach, clicks, results)
                VALUES (?, ?, ?, \'meta\', \'meta\', ?, ?, ?, ?, ?, ?, ?, ?, ?)';
            $name = mb_substr($plan['name'], 0, 175);
            try {
                $pdo->prepare($insert)->execute([$organizationId, $plan['brand_profile_id'], $plan['id'], $name, $m['start'], ...$values]);
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000') throw $e;
                // Un'altra spesa con lo stesso nome e periodo: si distingue con il numero della sponsorizzata.
                $pdo->prepare($insert)->execute([$organizationId, $plan['brand_profile_id'], $plan['id'], "{$name} #{$plan['id']}", $m['start'], ...$values]);
            }
        }
    }

    /** Immagine dell'inserzione: quella scelta, altrimenti la foto del post sponsorizzato. */
    public static function imagePath(array $plan, int $organizationId): ?string
    {
        $assetId = $plan['image_asset_id'];
        if ($assetId === null && $plan['content_item_id'] !== null) {
            $content = Tenant::find('content_items', $plan['content_item_id'], $organizationId, 'visual');
            $visual = $content && $content['visual'] ? json_decode($content['visual'], true) : null;
            $assetId = isset($visual['asset_id']) ? (int)$visual['asset_id'] : null;
        }
        return $assetId ? AssetController::localPath($assetId, $organizationId, 'image') : null;
    }

    /** Chi paga l'inserzione per il regolamento europeo: il cliente o l'agenzia. */
    public static function payer(int $organizationId, int $brandId): string
    {
        $stmt = Database::connection()->prepare('SELECT b.name, b.billing_name, b.ads_payer, o.name AS agency FROM brand_profiles b JOIN organizations o ON o.id=b.organization_id WHERE b.id=? AND b.organization_id=?');
        $stmt->execute([$brandId, $organizationId]);
        $row = $stmt->fetch();
        return $row['ads_payer'] === 'agency' ? (string)$row['agency'] : (string)($row['billing_name'] ?: $row['name']);
    }

    /** Pubblicata su Meta: si possono cambiare solo budget, data di fine e stato; i testi sono quelli già caricati. */
    private static function updatePublished(array $current, array $data, array $user): never
    {
        $budget = array_key_exists('daily_budget', $data) ? filter_var($data['daily_budget'], FILTER_VALIDATE_FLOAT) : $current['daily_budget'];
        if ($budget === false || $budget < MetaRules::MIN_DAILY_BUDGET) Http::validationError('Il budget giornaliero minimo su Meta è 1 €.');
        if ($budget > MetaMarketing::maxDailyBudget()) Http::validationError('Il budget giornaliero supera il tetto impostato per l\'app.');
        $end = array_key_exists('end_date', $data) ? ($data['end_date'] ? (self::date($data['end_date']) ?? Http::validationError('Data di fine non valida.')) : null) : $current['end_date'];
        if ($end !== null && $end < date('Y-m-d')) Http::validationError('La data di fine è già passata.');
        foreach (['objective', 'special_category', 'primary_text', 'headline', 'description', 'cta', 'url', 'start_date', 'audience', 'image_asset_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] != $current[$key]) {
                Http::validationError('La sponsorizzata è già su Meta: qui puoi cambiare solo budget e data di fine. Per testi, pubblico o immagine creane una nuova.');
            }
        }
        $fields = ['daily_budget' => round((float)$budget, 2), 'end_date' => $end];
        if (array_key_exists('status', $data)) $fields['status'] = Http::oneOf($data, 'status', self::STATUSES);
        if ($fields['daily_budget'] != $current['daily_budget'] || $end !== $current['end_date']) {
            $plan = self::onMeta($current, $user, fn($connection) => MetaMarketing::updateSchedule($current, $connection, $fields['daily_budget'], $end), $fields);
        } else {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            Database::connection()->prepare("UPDATE ad_plans SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($fields), $current['id'], $user['organization_id']]);
            $plan = self::find($current['id'], $user['organization_id']);
        }
        Audit::log($user, 'ad_plan.updated', 'ad_plan', $current['id']);
        Http::json(['data' => $plan]);
    }

    /** Esegue un'operazione su Meta e, se riesce, salva i campi; gli errori di Meta arrivano all'utente. */
    private static function onMeta(array $plan, array $user, callable $operation, array $fields): array
    {
        $connection = MetaMarketing::connection($user['organization_id'], $plan['brand_profile_id']) ?? Http::validationError('Ricollega Facebook nella scheda del cliente.');
        if ((bool)$connection['sample'] !== $plan['meta']['sample']) Http::validationError('Il cliente è stato ricollegato a un altro account: gestisci questa sponsorizzata da Gestione inserzioni.');
        try {
            $operation($connection);
        } catch (MetaApiException $e) {
            MetaConnectionController::markError($connection, $e);
            Http::validationError($e->getMessage());
        }
        $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
        Database::connection()->prepare("UPDATE ad_plans SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($fields), $plan['id'], $user['organization_id']]);
        return self::find($plan['id'], $user['organization_id']);
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
            'music' => $row['music'] === null ? self::NO_CHOICE_MUSIC : json_decode($row['music'], true),
            'checks' => $row['checks'] === null ? null : json_decode($row['checks'], true),
            'review' => $row['review'] === null ? null : json_decode($row['review'], true),
            'reviewed_at' => $row['reviewed_at'],
            'review_status' => $row['review_status'],
            'review_error' => $row['review_status'] === 'failed' ? $row['review_error'] : null,
            'status' => $row['status'],
            'image_asset_id' => $row['image_asset_id'] === null ? null : (int)$row['image_asset_id'],
            'meta' => [
                'published' => $row['meta_campaign_id'] !== null,
                'sample' => (bool)$row['meta_sample'],
                'campaign_id' => $row['meta_campaign_id'],
                'adset_id' => $row['meta_adset_id'],
                'image_hash' => $row['meta_image_hash'],
                'creative_id' => $row['meta_creative_id'],
                'ad_id' => $row['meta_ad_id'],
                'status' => $row['meta_status'],
                'feedback' => $row['meta_feedback'] === null ? [] : json_decode($row['meta_feedback'], true),
                'job_status' => $row['meta_job_status'],
                'error' => $row['meta_job_status'] === 'failed' ? $row['meta_job_error'] : null,
                'published_at' => $row['published_at'],
                'activated_at' => $row['activated_at'],
                'synced_at' => $row['meta_synced_at'],
                'results' => $row['meta_spent'] === null ? null : [
                    'spent' => round((float)$row['meta_spent'], 2), 'impressions' => (int)$row['meta_impressions'], 'reach' => (int)$row['meta_reach'],
                    'clicks' => (int)$row['meta_clicks'], 'results' => (int)$row['meta_results'],
                ],
            ],
            'ads_manager_url' => 'https://adsmanager.facebook.com/adsmanager/manage/' . ($row['meta_campaign_id'] && !$row['meta_sample'] && $account
                ? "ads?act={$account}&selected_campaign_ids={$row['meta_campaign_id']}"
                : 'campaigns' . ($account ? "?act={$account}" : '')),
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
            'music' => is_array($data['music'] ?? null) ? [
                'source' => (string)($data['music']['source'] ?? 'meta'),
                'asset_id' => is_numeric($data['music']['asset_id'] ?? null) ? (int)$data['music']['asset_id'] : null,
                'track_name' => is_string($data['music']['track_name'] ?? null) ? mb_substr($data['music']['track_name'], 0, 255) : '',
                'rights_confirmed' => !empty($data['music']['rights_confirmed']),
            ] : [],
            ...array_map(fn($key) => is_scalar($data[$key] ?? null) ? mb_substr((string)$data[$key], 0, 5000) : '', ['primary_text' => 'primary_text', 'headline' => 'headline', 'description' => 'description', 'cta' => 'cta', 'url' => 'url']),
        ];
    }

    private static function validated(array $data, int $organizationId): array
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
            'music' => self::music($data['music'] ?? null, $organizationId),
            'image_asset_id' => self::image($data['image_asset_id'] ?? null, $organizationId),
        ];
    }

    /** Musica scelta: la traccia propria deve essere un audio della libreria dell'organizzazione. */
    private static function music(mixed $value, int $organizationId): array
    {
        $music = is_array($value) ? $value : [];
        $source = Http::oneOf($music, 'source', array_keys(MetaRules::MUSIC_SOURCES), 'meta');
        $mood = is_string($music['mood'] ?? null) && isset(MetaRules::MUSIC_MOODS[$music['mood']]) ? $music['mood'] : null;
        $assetId = $source === 'own' ? Http::optionalInt($music, 'asset_id') : null;
        $trackName = null;
        if ($assetId !== null) {
            $stmt = Database::connection()->prepare("SELECT original_name FROM assets WHERE id=? AND organization_id=? AND kind='audio'");
            $stmt->execute([$assetId, $organizationId]);
            $trackName = $stmt->fetchColumn();
            if ($trackName === false) Http::validationError('La musica deve essere una traccia audio della tua libreria.');
        }
        return [
            'source' => $source,
            'mood' => $source === 'meta' ? $mood : null,
            'meta_picks' => $source === 'meta' && (bool)($music['meta_picks'] ?? true),
            'asset_id' => $assetId,
            'track_name' => $trackName,
            'rights_confirmed' => $source === 'own' && !empty($music['rights_confirmed']),
        ];
    }

    /** Immagine scelta per l'inserzione: deve essere una foto della libreria dell'organizzazione. */
    private static function image(mixed $value, int $organizationId): ?int
    {
        if ($value === null || $value === '') return null;
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || !Tenant::find('assets', $id, $organizationId, 'id')) Http::validationError('L\'immagine deve essere una foto della tua libreria.');
        if (AssetController::localPath($id, $organizationId, 'image') === null) Http::validationError('L\'immagine scelta non è una foto.');
        return $id;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) return null;
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $d && $d->format('Y-m-d') === $value ? $value : null;
    }
}
