<?php
declare(strict_types=1);

/** Registro delle sponsorizzate: quanto si spende per ogni cliente, inserito a mano o importato dal CSV di Meta. */
final class AdSpendController
{
    private const PLATFORMS = ['meta', 'google', 'tiktok', 'linkedin', 'other'];
    private const SELECT = 'SELECT s.id, s.brand_profile_id, b.name AS brand_name, b.primary_color AS brand_color, s.project_id, p.name AS project_name,
        s.platform, s.campaign_name, s.period_start, s.period_end, s.budget, s.spent, s.impressions, s.reach, s.clicks, s.results, s.source, s.notes,
        s.invoice_id, i.status AS invoice_status, i.period AS invoice_period, s.created_at, s.updated_at
        FROM ad_spend s JOIN brand_profiles b ON b.id=s.brand_profile_id LEFT JOIN projects p ON p.id=s.project_id LEFT JOIN invoices i ON i.id=s.invoice_id';

    public static function index(): never
    {
        $user = Auth::user();
        [$where, $params] = self::filters($user);
        $stmt = Database::connection()->prepare(self::SELECT . " WHERE {$where} ORDER BY s.period_end DESC, s.id DESC LIMIT 1000");
        $stmt->execute($params);
        Http::json(['data' => array_map([self::class, 'present'], $stmt->fetchAll())]);
    }

    /** Totali dell'anno per mese e per cliente, per i grafici. La spesa conta nel mese in cui finisce il periodo. */
    public static function summary(): never
    {
        $user = Auth::user();
        $year = self::year();
        [$where, $params] = self::filters($user, false);
        $where .= ' AND s.period_end BETWEEN ? AND ?';
        $params = [...$params, "{$year}-01-01", "{$year}-12-31"];
        $pdo = Database::connection();

        $stmt = $pdo->prepare("SELECT MONTH(s.period_end) AS month, SUM(s.spent) AS spent, SUM(s.clicks) AS clicks, SUM(s.impressions) AS impressions
            FROM ad_spend s WHERE {$where} GROUP BY MONTH(s.period_end)");
        $stmt->execute($params);
        $byMonth = [];
        foreach ($stmt->fetchAll() as $row) $byMonth[(int)$row['month']] = $row;
        $months = [];
        for ($m = 1; $m <= 12; $m++) {
            $months[] = ['month' => $m, 'spent' => round((float)($byMonth[$m]['spent'] ?? 0), 2), 'clicks' => (int)($byMonth[$m]['clicks'] ?? 0), 'impressions' => (int)($byMonth[$m]['impressions'] ?? 0)];
        }

        $stmt = $pdo->prepare("SELECT b.id, b.name, b.primary_color, SUM(s.spent) AS spent, SUM(s.budget) AS budget, SUM(s.impressions) AS impressions,
                SUM(s.reach) AS reach, SUM(s.clicks) AS clicks, SUM(s.results) AS results, COUNT(*) AS entries
            FROM ad_spend s JOIN brand_profiles b ON b.id=s.brand_profile_id WHERE {$where} GROUP BY b.id, b.name, b.primary_color ORDER BY spent DESC");
        $stmt->execute($params);
        $clients = array_map(fn(array $r) => self::metrics($r) + ['id' => (int)$r['id'], 'name' => $r['name'], 'primary_color' => $r['primary_color'], 'entries' => (int)$r['entries']], $stmt->fetchAll());

        $totals = ['spent' => 0.0, 'budget' => 0.0, 'impressions' => 0, 'reach' => 0, 'clicks' => 0, 'results' => 0];
        foreach ($clients as $c) foreach ($totals as $key => $_) $totals[$key] += $c[$key] ?? 0;
        Http::json(['data' => ['year' => $year, 'totals' => self::metrics($totals), 'months' => $months, 'clients' => $clients]]);
    }

    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $fields = self::validated(Http::body(), $user);
        $pdo = Database::connection();
        $columns = array_keys($fields);
        $stmt = $pdo->prepare('INSERT INTO ad_spend (organization_id, created_by, source, ' . implode(', ', $columns) . ") VALUES (?, ?, 'manual'" . str_repeat(', ?', count($columns)) . ')');
        try {
            $stmt->execute([$user['organization_id'], $user['id'], ...array_values($fields)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') Http::json(['error' => 'duplicate', 'message' => 'Esiste già una riga per questa campagna nello stesso periodo.'], 409);
            throw $e;
        }
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'ad_spend.created', 'ad_spend', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])], 201);
    }

    public static function update(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $row = self::editable($id, $user);
        $fields = self::validated(Http::body() + self::find($id, $user['organization_id']), $user);
        $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
        $stmt = Database::connection()->prepare("UPDATE ad_spend SET {$set}, invoice_id=NULL WHERE id=? AND organization_id=?");
        try {
            $stmt->execute([...array_values($fields), $id, $user['organization_id']]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') Http::json(['error' => 'duplicate', 'message' => 'Esiste già una riga per questa campagna nello stesso periodo.'], 409);
            throw $e;
        }
        // La riga torna nella sua bozza di fattura (o in un'altra, se il periodo è cambiato) con l'importo nuovo.
        if ($row['invoice_id']) BillingController::rebuild((int)$row['invoice_id'], $user['organization_id']);
        Audit::log($user, 'ad_spend.updated', 'ad_spend', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $row = self::editable($id, $user);
        Database::connection()->prepare('DELETE FROM ad_spend WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        if ($row['invoice_id']) BillingController::rebuild((int)$row['invoice_id'], $user['organization_id']);
        Audit::log($user, 'ad_spend.deleted', 'ad_spend', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /**
     * Importa il CSV di Gestione inserzioni per un cliente. Con preview=1 restituisce solo cosa succederebbe.
     * Le righe già presenti (stessa campagna e stesso periodo) vengono aggiornate, tranne quelle già fatturate.
     */
    public static function import(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $platform = Http::oneOf($_POST, 'platform', self::PLATFORMS, 'meta');
        $projectId = Http::optionalInt($_POST, 'project_id');
        if ($projectId !== null) self::assertProject($projectId, $brandId, $user);
        $file = $_FILES['file'] ?? null;
        if (!is_array($file) || is_array($file['error'] ?? null) || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            Http::validationError("Carica il file CSV nel campo 'file'.");
        }
        if ($file['size'] > 2 * 1024 * 1024) Http::validationError('Il file CSV supera 2 MB: esporta un periodo più breve.');
        $parsed = AdsCsvParser::parse((string)file_get_contents($file['tmp_name']));
        if (!$parsed['rows']) Http::validationError('Nel file non ci sono righe con spesa da importare.');

        $pdo = Database::connection();
        $existing = $pdo->prepare('SELECT s.id, s.invoice_id, i.status AS invoice_status FROM ad_spend s LEFT JOIN invoices i ON i.id=s.invoice_id
            WHERE s.organization_id=? AND s.brand_profile_id=? AND s.platform=? AND s.campaign_name=? AND s.period_start=? AND s.period_end=?');
        $preview = ($_POST['preview'] ?? '') === '1';
        $counts = ['new' => 0, 'update' => 0, 'locked' => 0];
        $rebuild = [];
        $rows = [];
        if (!$preview) $pdo->beginTransaction();
        try {
            foreach ($parsed['rows'] as $row) {
                $existing->execute([$user['organization_id'], $brandId, $platform, $row['campaign_name'], $row['period_start'], $row['period_end']]);
                $found = $existing->fetch();
                $action = !$found ? 'new' : (in_array($found['invoice_status'], ['invoiced', 'paid'], true) ? 'locked' : 'update');
                $counts[$action]++;
                $rows[] = $row + ['action' => $action];
                if ($preview || $action === 'locked') continue;
                if ($action === 'new') {
                    $pdo->prepare("INSERT INTO ad_spend (organization_id, brand_profile_id, project_id, created_by, platform, source, campaign_name, period_start, period_end, budget, spent, impressions, reach, clicks, results)
                        VALUES (?, ?, ?, ?, ?, 'csv', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$user['organization_id'], $brandId, $projectId, $user['id'], $platform, $row['campaign_name'], $row['period_start'], $row['period_end'], $row['budget'], $row['spent'], $row['impressions'], $row['reach'], $row['clicks'], $row['results']]);
                } else {
                    $pdo->prepare('UPDATE ad_spend SET budget=?, spent=?, impressions=?, reach=?, clicks=?, results=?, project_id=COALESCE(?, project_id), invoice_id=NULL WHERE id=?')
                        ->execute([$row['budget'], $row['spent'], $row['impressions'], $row['reach'], $row['clicks'], $row['results'], $projectId, $found['id']]);
                    if ($found['invoice_id']) $rebuild[(int)$found['invoice_id']] = true;
                }
            }
            if (!$preview) {
                foreach (array_keys($rebuild) as $invoiceId) BillingController::rebuild($invoiceId, $user['organization_id']);
                $pdo->commit();
                Audit::log($user, 'ad_spend.imported', 'brand_profile', $brandId);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        Http::json(['data' => [
            'preview' => $preview,
            'counts' => $counts,
            'skipped' => $parsed['skipped'],
            'errors' => $parsed['errors'],
            'total_spent' => round(array_sum(array_column($parsed['rows'], 'spent')), 2),
            'rows' => array_slice($rows, 0, 200),
        ]]);
    }

    private static function find(int $id, int $organizationId): array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE s.id=? AND s.organization_id=?');
        $stmt->execute([$id, $organizationId]);
        $row = $stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404);
        return self::present($row);
    }

    /** Una riga già fatturata non si tocca più: prima va riaperta la fattura. */
    private static function editable(int $id, array $user): array
    {
        $row = self::find($id, $user['organization_id']);
        if (in_array($row['invoice_status'], ['invoiced', 'paid'], true)) {
            Http::json(['error' => 'invoiced', 'message' => 'Questa spesa è in una fattura già emessa: riapri la fattura per modificarla.'], 409);
        }
        return $row;
    }

    private static function validated(array $data, array $user): array
    {
        $brandId = Http::optionalInt($data, 'brand_profile_id') ?? Http::validationError("Campo 'brand_profile_id' obbligatorio.");
        Tenant::find('brand_profiles', $brandId, $user['organization_id'], 'id') ?? Http::validationError('Cliente non trovato.');
        $projectId = Http::optionalInt($data, 'project_id');
        if ($projectId !== null) self::assertProject($projectId, $brandId, $user);
        $start = self::date($data, 'period_start');
        $end = self::date($data, 'period_end');
        if ($end < $start) Http::validationError('La data di fine non può precedere quella di inizio.');
        $fields = [
            'brand_profile_id' => $brandId,
            'project_id' => $projectId,
            'platform' => Http::oneOf($data, 'platform', self::PLATFORMS, 'meta'),
            'campaign_name' => Http::requiredString($data, 'campaign_name', 190),
            'period_start' => $start,
            'period_end' => $end,
            'spent' => self::amount($data, 'spent', true),
            'budget' => self::amount($data, 'budget', false),
            'notes' => Http::optionalString($data, 'notes', 500),
        ];
        foreach (['impressions', 'reach', 'clicks', 'results'] as $key) {
            $value = Http::optionalInt($data, $key);
            if ($value !== null && ($value < 0 || $value > 4294967295)) Http::validationError("Campo '{$key}' non valido.");
            $fields[$key] = $value;
        }
        return $fields;
    }

    private static function assertProject(int $projectId, int $brandId, array $user): void
    {
        $project = Tenant::find('projects', $projectId, $user['organization_id'], 'brand_profile_id');
        if (!$project || (int)$project['brand_profile_id'] !== $brandId) Http::validationError('La campagna indicata non è di questo cliente.');
    }

    private static function date(array $data, string $key): string
    {
        $value = is_string($data[$key] ?? null) ? $data[$key] : '';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) Http::validationError("Campo '{$key}' deve essere una data AAAA-MM-GG.");
        return $value;
    }

    public static function amount(array $data, string $key, bool $required): ?float
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) Http::validationError("Campo '{$key}' obbligatorio.");
            return null;
        }
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 9999999999) Http::validationError("Campo '{$key}' deve essere un importo positivo.");
        return round((float)$value, 2);
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function filters(array $user, bool $withDates = true): array
    {
        $where = 's.organization_id=?';
        $params = [$user['organization_id']];
        $brandId = Http::optionalInt($_GET, 'brand_id');
        if ($brandId !== null) { $where .= ' AND s.brand_profile_id=?'; $params[] = $brandId; }
        if ($withDates) {
            foreach (['from' => 's.period_end >= ?', 'to' => 's.period_start <= ?'] as $key => $condition) {
                if (($_GET[$key] ?? '') === '') continue;
                $params[] = self::date($_GET, $key);
                $where .= " AND {$condition}";
            }
        }
        return [$where, $params];
    }

    private static function year(): int
    {
        $year = Http::optionalInt($_GET, 'year') ?? (int)date('Y');
        if ($year < 2000 || $year > 2100) Http::validationError("Campo 'year' non valido.");
        return $year;
    }

    /** Totali con CPC, CTR e costo per risultato calcolati. */
    public static function metrics(array $r): array
    {
        $spent = round((float)($r['spent'] ?? 0), 2);
        $clicks = (int)($r['clicks'] ?? 0);
        $impressions = (int)($r['impressions'] ?? 0);
        $results = (int)($r['results'] ?? 0);
        return [
            'spent' => $spent,
            'budget' => round((float)($r['budget'] ?? 0), 2),
            'impressions' => $impressions,
            'reach' => (int)($r['reach'] ?? 0),
            'clicks' => $clicks,
            'results' => $results,
            'cpc' => $clicks > 0 ? round($spent / $clicks, 2) : null,
            'ctr' => $impressions > 0 ? round($clicks / $impressions * 100, 2) : null,
            'cost_per_result' => $results > 0 ? round($spent / $results, 2) : null,
        ];
    }

    private static function present(array $row): array
    {
        foreach (['id', 'brand_profile_id', 'project_id', 'invoice_id', 'impressions', 'reach', 'clicks', 'results'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        }
        $row['spent'] = (float)$row['spent'];
        $row['budget'] = $row['budget'] === null ? null : (float)$row['budget'];
        return $row;
    }
}
