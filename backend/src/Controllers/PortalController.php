<?php
declare(strict_types=1);

/**
 * Portale del cliente: un solo link, senza account, dove il cliente approva o chiede modifiche ai post,
 * vede cosa uscirà, apre i report condivisi e manda richieste all'agenzia.
 */
final class PortalController
{
    private const CONTENT = 'c.id, c.channel, c.content_type, c.title, c.body, c.visual, c.status, c.review_note, c.reviewed_via, c.scheduled_at, c.published_at, c.published_url';

    // Lato agenzia

    public static function show(int $brandId): never
    {
        $user = Auth::user();
        Http::json(['data' => self::agencyView($brandId, $user['organization_id'])]);
    }

    public static function enable(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        Database::connection()->prepare('UPDATE brand_profiles SET portal_token=COALESCE(portal_token, ?) WHERE id=? AND organization_id=?')
            ->execute([bin2hex(random_bytes(16)), $brandId, $user['organization_id']]);
        Audit::log($user, 'portal.enabled', 'brand_profile', $brandId);
        Http::json(['data' => self::agencyView($brandId, $user['organization_id'])]);
    }

    /** Disattiva il link: chi lo ha non vede più niente. Riattivandolo il link cambia. */
    public static function disable(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        Database::connection()->prepare('UPDATE brand_profiles SET portal_token=NULL WHERE id=? AND organization_id=?')->execute([$brandId, $user['organization_id']]);
        Audit::log($user, 'portal.disabled', 'brand_profile', $brandId);
        Http::json(['data' => self::agencyView($brandId, $user['organization_id'])]);
    }

    public static function updateRequest(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('client_requests', $id, $user['organization_id'], 'id');
        $status = Http::oneOf(Http::body(), 'status', ['open', 'done']);
        Database::connection()->prepare('UPDATE client_requests SET status=? WHERE id=? AND organization_id=?')->execute([$status, $id, $user['organization_id']]);
        Http::json(['data' => ['id' => $id, 'status' => $status]]);
    }

    private static function agencyView(int $brandId, int $orgId): array
    {
        $brand = Tenant::findOrFail('brand_profiles', $brandId, $orgId, 'id, portal_token');
        $stmt = Database::connection()->prepare('SELECT id, name, message, status, created_at FROM client_requests WHERE organization_id=? AND brand_profile_id=? ORDER BY status=\'done\', id DESC LIMIT 50');
        $stmt->execute([$orgId, $brandId]);
        return ['token' => $brand['portal_token'], 'requests' => $stmt->fetchAll()];
    }

    // Lato cliente (pubblico)

    /** Smista le richieste pubbliche al portale: /api/v1/public/portal/{token}[/...]. */
    public static function publicRoute(string $token, string $rest, string $method): never
    {
        $brand = self::brandByToken($token);
        if ($rest === '' && $method === 'GET') self::publicShow($brand);
        if ($rest === '/requests' && $method === 'POST') self::publicRequest($brand);
        if (preg_match('#^/content/(\d+)/(approve|reject)$#', $rest, $m) && $method === 'POST') self::publicReview($brand, (int)$m[1], $m[2]);
        if (preg_match('#^/assets/(\d+)$#', $rest, $m) && $method === 'GET') self::publicAsset($brand, (int)$m[1]);
        Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404);
    }

    private static function publicShow(array $brand): never
    {
        $pdo = Database::connection();
        $orgId = (int)$brand['organization_id'];
        $brandId = (int)$brand['id'];
        $content = function (string $where, string $order, int $limit, array $params = []) use ($pdo, $orgId, $brandId): array {
            $stmt = $pdo->prepare('SELECT ' . self::CONTENT . " FROM content_items c JOIN projects p ON p.id=c.project_id AND p.organization_id=c.organization_id
                WHERE c.organization_id=? AND p.brand_profile_id=? AND {$where} ORDER BY {$order} LIMIT {$limit}");
            $stmt->execute([$orgId, $brandId, ...$params]);
            return array_map([self::class, 'presentContent'], $stmt->fetchAll());
        };
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('SELECT period, share_token, views FROM client_reports WHERE organization_id=? AND brand_profile_id=? AND share_token IS NOT NULL ORDER BY period DESC LIMIT 12');
        $stmt->execute([$orgId, $brandId]);
        $reports = $stmt->fetchAll();
        $stmt = $pdo->prepare('SELECT id, name, message, status, created_at FROM client_requests WHERE organization_id=? AND brand_profile_id=? ORDER BY id DESC LIMIT 10');
        $stmt->execute([$orgId, $brandId]);
        $requests = $stmt->fetchAll();

        Http::json(['data' => [
            'client' => [
                'name' => $brand['name'], 'primary_color' => $brand['primary_color'], 'secondary_color' => $brand['secondary_color'],
                'font_family' => $brand['font_family'], 'logo_asset_id' => $brand['logo_asset_id'] === null ? null : (int)$brand['logo_asset_id'],
            ],
            'agency' => ['name' => $brand['agency_name']],
            'pending' => $content("c.status='in_review'", 'c.scheduled_at IS NULL, c.scheduled_at, c.id', 50),
            'upcoming' => $content("c.status IN ('approved','scheduled') AND (c.scheduled_at IS NULL OR c.scheduled_at >= ?)", 'c.scheduled_at IS NULL, c.scheduled_at', 30, [$now]),
            'published' => $content("c.status='published'", 'c.published_at DESC', 12),
            'reports' => array_map(fn($r) => ['period' => $r['period'], 'token' => $r['share_token']], $reports),
            'requests' => $requests,
        ]]);
    }

    /** Il cliente approva o chiede modifiche: vale come la revisione nell'app, con la nota firmata dal cliente. */
    private static function publicReview(array $brand, int $contentId, string $action): never
    {
        self::limit();
        $orgId = (int)$brand['organization_id'];
        $data = self::optionalBody();
        $name = Http::optionalString($data, 'name', 120);
        $note = $action === 'reject' ? Http::requiredString($data, 'note', 1000) : Http::optionalString($data, 'note', 1000);
        $item = self::contentOf($brand, $contentId);
        if ($item['status'] !== 'in_review') Http::json(['error' => 'invalid_state', 'message' => 'Questo post è già stato rivisto.'], 409);

        $style = ContentController::forStyle($contentId, $orgId);
        if ($style && $action === 'approve') StyleMemory::captureEdit($orgId, $style);
        if ($style && $action === 'reject') StyleMemory::captureFeedback($orgId, $style, $note);

        $signature = $name ? " ({$name})" : '';
        $reviewNote = $action === 'approve' ? ($note ? "Approvato dal cliente{$signature}: {$note}" : "Approvato dal cliente{$signature}.") : "Il cliente{$signature} chiede: {$note}";
        Database::connection()->prepare("UPDATE content_items SET status=?, review_note=?, reviewed_by=NULL, reviewed_via='portal', reviewed_at=UTC_TIMESTAMP() WHERE id=? AND organization_id=?")
            ->execute([$action === 'approve' ? 'approved' : 'rejected', mb_substr($reviewNote, 0, 1000), $contentId, $orgId]);
        Database::connection()->prepare('INSERT INTO audit_logs (organization_id, user_id, action, entity_type, entity_id) VALUES (?, NULL, ?, ?, ?)')
            ->execute([$orgId, $action === 'approve' ? 'content.approved_by_client' : 'content.rejected_by_client', 'content_item', $contentId]);
        Http::json(['data' => self::contentOf($brand, $contentId)]);
    }

    private static function publicRequest(array $brand): never
    {
        self::limit();
        $data = Http::body();
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO client_requests (organization_id, brand_profile_id, name, message) VALUES (?, ?, ?, ?)')
            ->execute([$brand['organization_id'], $brand['id'], Http::optionalString($data, 'name', 120), Http::requiredString($data, 'message', 3000)]);
        Http::json(['data' => ['id' => (int)$pdo->lastInsertId()]], 201);
    }

    /** Immagini del portale: solo logo e foto di questo cliente, o foto usate nei suoi post. */
    private static function publicAsset(array $brand, int $assetId): never
    {
        $orgId = (int)$brand['organization_id'];
        $stmt = Database::connection()->prepare("SELECT a.mime_type FROM assets a WHERE a.id=? AND a.organization_id=? AND a.kind='image' AND (a.brand_profile_id=? OR a.id=?
            OR EXISTS (SELECT 1 FROM content_items c JOIN projects p ON p.id=c.project_id WHERE c.organization_id=a.organization_id AND p.brand_profile_id=?
                AND CAST(JSON_UNQUOTE(JSON_EXTRACT(c.visual, '$.asset_id')) AS UNSIGNED)=a.id)) LIMIT 1");
        $stmt->execute([$assetId, $orgId, $brand['id'], (int)$brand['logo_asset_id'], $brand['id']]);
        $mime = $stmt->fetchColumn();
        $path = $mime ? AssetController::localPath($assetId, $orgId, 'image') : null;
        if (!$path) Http::json(['error' => 'not_found', 'message' => 'Immagine non trovata.'], 404);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        header("Content-Security-Policy: default-src 'none'");
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private static function brandByToken(string $token): array
    {
        $stmt = Database::connection()->prepare("SELECT b.id, b.organization_id, b.name, b.primary_color, b.secondary_color, b.font_family, b.logo_asset_id, o.name AS agency_name
            FROM brand_profiles b JOIN organizations o ON o.id=b.organization_id WHERE b.portal_token=? AND o.status='active' LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Portale non disponibile.'], 404);
    }

    private static function contentOf(array $brand, int $contentId): array
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::CONTENT . ' FROM content_items c JOIN projects p ON p.id=c.project_id AND p.organization_id=c.organization_id
            WHERE c.id=? AND c.organization_id=? AND p.brand_profile_id=? LIMIT 1');
        $stmt->execute([$contentId, $brand['organization_id'], $brand['id']]);
        $row = $stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Post non trovato.'], 404);
        return self::presentContent($row);
    }

    /** Le note interne per il revisore non arrivano mai al cliente. */
    private static function presentContent(array $c): array
    {
        $c['id'] = (int)$c['id'];
        $c['body'] = trim(preg_replace('/\n*---\nNote per il revisore:.*$/su', '', $c['body']));
        $c['visual'] = $c['visual'] === null ? null : json_decode($c['visual'], true);
        return $c;
    }

    /** Limite alle azioni pubbliche per indirizzo IP, contro abusi del link. */
    private static function limit(): void
    {
        $key = 'portal:' . Http::clientIp();
        RateLimiter::check($key, 60, 600);
        RateLimiter::record($key);
    }

    private static function optionalBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        return trim($raw) === '' ? [] : Http::body();
    }
}
