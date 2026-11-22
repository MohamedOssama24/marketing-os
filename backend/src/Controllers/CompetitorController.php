<?php
declare(strict_types=1);

/** Concorrenti di un cliente: l'AI legge il loro sito (e lo rilegge ogni settimana) e suggerisce come distinguersi. */
final class CompetitorController
{
    private const MAX_PER_CLIENT = 5;

    public static function index(int $brandId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $stmt = Database::connection()->prepare('SELECT c.id, c.name, c.url, c.analysis, c.last_checked_at, c.created_at, c.job_id, j.status AS job_status, j.error AS job_error
            FROM competitors c LEFT JOIN jobs j ON j.id=c.job_id WHERE c.organization_id=? AND c.brand_profile_id=? ORDER BY c.id');
        $stmt->execute([$user['organization_id'], $brandId]);
        Http::json(['data' => array_map(fn($c) => self::present($c, $user['organization_id']), $stmt->fetchAll())]);
    }

    public static function store(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM competitors WHERE organization_id=? AND brand_profile_id=?');
        $stmt->execute([$user['organization_id'], $brandId]);
        if ((int)$stmt->fetchColumn() >= self::MAX_PER_CLIENT) Http::validationError('Puoi seguire al massimo ' . self::MAX_PER_CLIENT . ' concorrenti per cliente.');
        $data = Http::body();
        try {
            $url = SafeHttp::normalizeUrl(Http::requiredString($data, 'url', 2048));
        } catch (SiteException $e) {
            Http::validationError($e->getMessage());
        }
        $name = Http::optionalString($data, 'name', 160) ?? preg_replace('/^www\./', '', (string)parse_url($url, PHP_URL_HOST));
        $pdo->prepare('INSERT INTO competitors (organization_id, brand_profile_id, created_by, name, url) VALUES (?, ?, ?, ?, ?)')
            ->execute([$user['organization_id'], $brandId, $user['id'], $name, $url]);
        $id = (int)$pdo->lastInsertId();
        self::analyze($id, $user);
        if (MetaAdLibrary::source() !== null) CompetitorAdsController::enqueue($id, $user);
        Audit::log($user, 'competitor.created', 'competitor', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])], 202);
    }

    public static function refresh(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        self::find($id, $user['organization_id']);
        self::analyze($id, $user);
        Http::json(['data' => self::find($id, $user['organization_id'])], 202);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('competitors', $id, $user['organization_id'], 'id');
        Database::connection()->prepare('DELETE FROM competitors WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Audit::log($user, 'competitor.deleted', 'competitor', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    private static function analyze(int $id, array $user): void
    {
        $job = Jobs::enqueue($user, 'analyze_competitor', ['competitor_id' => $id]);
        Database::connection()->prepare('UPDATE competitors SET job_id=? WHERE id=? AND organization_id=?')->execute([$job['id'], $id, $user['organization_id']]);
    }

    private static function find(int $id, int $orgId): array
    {
        $stmt = Database::connection()->prepare('SELECT c.id, c.name, c.url, c.analysis, c.last_checked_at, c.created_at, c.job_id, j.status AS job_status, j.error AS job_error
            FROM competitors c LEFT JOIN jobs j ON j.id=c.job_id WHERE c.id=? AND c.organization_id=? LIMIT 1');
        $stmt->execute([$id, $orgId]);
        return self::present($stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404), $orgId);
    }

    private static function present(array $c, int $orgId): array
    {
        $c['id'] = (int)$c['id'];
        $c['analysis'] = $c['analysis'] === null ? null : json_decode($c['analysis'], true);
        $c['analyzing'] = in_array($c['job_status'], ['queued', 'running'], true);
        // Libreria inserzioni di Meta: in UE mostra tutte le inserzioni attive, senza bisogno di API.
        $c['ad_library_url'] = 'https://www.facebook.com/ads/library/?active_status=active&ad_type=all&country=IT&q=' . rawurlencode($c['name']);
        $c['ads'] = self::adsPreview($c['id'], $orgId);
        return $c;
    }

    /**
     * Le 3 inserzioni attive più recenti del concorrente (già lette dall'archivio di Meta) e quante sono in tutto.
     * @return array{connected: bool, active: int, preview: list<array>}
     */
    private static function adsPreview(int $competitorId, int $orgId): array
    {
        $pdo = Database::connection();
        $active = '(stopped_on IS NULL OR stopped_on >= CURDATE())';
        $count = $pdo->prepare("SELECT COUNT(*) FROM competitor_ads WHERE competitor_id=? AND organization_id=? AND {$active}");
        $count->execute([$competitorId, $orgId]);
        $stmt = $pdo->prepare("SELECT archive_id, titles, bodies, platforms, started_on FROM competitor_ads
            WHERE competitor_id=? AND organization_id=? AND {$active} ORDER BY started_on DESC, id DESC LIMIT 3");
        $stmt->execute([$competitorId, $orgId]);
        $today = strtotime(date('Y-m-d'));
        return [
            'connected' => MetaAdLibrary::source() !== null,
            'active' => (int)$count->fetchColumn(),
            'preview' => array_map(fn(array $ad) => [
                'archive_id' => $ad['archive_id'],
                'title' => mb_substr((string)(json_decode((string)$ad['titles'], true)[0] ?? ''), 0, 120),
                'text' => mb_substr((string)(json_decode((string)$ad['bodies'], true)[0] ?? ''), 0, 220),
                'platforms' => json_decode((string)$ad['platforms'], true) ?: [],
                'days' => max(1, (int)(($today - strtotime($ad['started_on'])) / 86400) + 1),
                // Link pubblico all'inserzione (l'anteprima dell'API contiene il codice di accesso e non va mostrata).
                'url' => 'https://www.facebook.com/ads/library/?id=' . rawurlencode($ad['archive_id']),
            ], $stmt->fetchAll()),
        ];
    }
}
