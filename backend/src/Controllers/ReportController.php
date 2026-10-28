<?php
declare(strict_types=1);

/**
 * Report mensile per il cliente: post pubblicati, clic sui link, sponsorizzate e il commento dell'agenzia.
 * I numeri si ricalcolano sempre dai dati del mese; il cliente lo apre da un link pubblico, senza account.
 */
final class ReportController
{
    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    /** Stato dei report di un mese (?period=AAAA-MM) per tutti i clienti. */
    public static function index(): never
    {
        $user = Auth::user();
        $period = self::period($_GET['period'] ?? null);
        $stmt = Database::connection()->prepare('SELECT b.id, b.name, b.primary_color, b.logo_asset_id, r.summary IS NOT NULL AS has_summary,
                r.shared_at, r.views, r.last_viewed_at
            FROM brand_profiles b LEFT JOIN client_reports r ON r.brand_profile_id=b.id AND r.period=? AND r.organization_id=b.organization_id
            WHERE b.organization_id=? ORDER BY b.name');
        $stmt->execute([$period, $user['organization_id']]);
        $clients = array_map(fn(array $r) => [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'primary_color' => $r['primary_color'],
            'logo_asset_id' => $r['logo_asset_id'] === null ? null : (int)$r['logo_asset_id'],
            'has_summary' => (bool)$r['has_summary'],
            'shared' => $r['shared_at'] !== null,
            'views' => (int)($r['views'] ?? 0),
            'last_viewed_at' => $r['last_viewed_at'],
        ], $stmt->fetchAll());
        Http::json(['data' => ['period' => $period, 'clients' => $clients]]);
    }

    public static function show(int $brandId): never
    {
        $user = Auth::user();
        $brand = self::brand($brandId, $user['organization_id']);
        $period = self::period($_GET['period'] ?? null);
        Http::json(['data' => self::build($brand, $period, self::saved($user['organization_id'], $brandId, $period), false)]);
    }

    /** Salva il commento dell'agenzia: com'è andato il mese e cosa si farà il prossimo. */
    public static function update(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $brand = self::brand($brandId, $user['organization_id']);
        $data = Http::body();
        $period = self::period($data['period'] ?? null);
        $summary = Http::optionalString($data, 'summary', 5000);
        $nextSteps = Http::optionalString($data, 'next_steps', 5000);
        Database::connection()->prepare('INSERT INTO client_reports (organization_id, brand_profile_id, period, summary, next_steps, updated_by) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE summary=VALUES(summary), next_steps=VALUES(next_steps), updated_by=VALUES(updated_by)')
            ->execute([$user['organization_id'], $brandId, $period, $summary, $nextSteps, $user['id']]);
        Audit::log($user, 'report.updated', 'brand_profile', $brandId);
        Http::json(['data' => self::build($brand, $period, self::saved($user['organization_id'], $brandId, $period), false)]);
    }

    /** Crea il link pubblico del report (se esiste già resta lo stesso, così quello inviato al cliente continua a funzionare). */
    public static function share(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $brand = self::brand($brandId, $user['organization_id']);
        $period = self::period(Http::body()['period'] ?? null);
        $token = bin2hex(random_bytes(16));
        Database::connection()->prepare('INSERT INTO client_reports (organization_id, brand_profile_id, period, share_token, shared_at, updated_by) VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE share_token=COALESCE(share_token, VALUES(share_token)), shared_at=COALESCE(shared_at, VALUES(shared_at))')
            ->execute([$user['organization_id'], $brandId, $period, $token, date('Y-m-d H:i:s'), $user['id']]);
        Audit::log($user, 'report.shared', 'brand_profile', $brandId);
        Http::json(['data' => self::build($brand, $period, self::saved($user['organization_id'], $brandId, $period), false)]);
    }

    /** Disattiva il link pubblico: chi lo ha ricevuto non vede più il report. */
    public static function unshare(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $brand = self::brand($brandId, $user['organization_id']);
        $period = self::period(Http::body()['period'] ?? null);
        Database::connection()->prepare('UPDATE client_reports SET share_token=NULL, shared_at=NULL, views=0, last_viewed_at=NULL WHERE organization_id=? AND brand_profile_id=? AND period=?')
            ->execute([$user['organization_id'], $brandId, $period]);
        Audit::log($user, 'report.unshared', 'brand_profile', $brandId);
        Http::json(['data' => self::build($brand, $period, self::saved($user['organization_id'], $brandId, $period), false)]);
    }

    /** Report aperto dal cliente con il link pubblico. Conta le aperture. */
    public static function publicShow(string $token): never
    {
        $report = self::byToken($token);
        $brand = self::brand((int)$report['brand_profile_id'], (int)$report['organization_id']);
        Database::connection()->prepare('UPDATE client_reports SET views=views+1, last_viewed_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $report['id']]);
        Http::json(['data' => self::build($brand, $report['period'], $report, true)]);
    }

    /** Logo del cliente per il report pubblico (i file della libreria altrimenti richiedono l'accesso). */
    public static function publicLogo(string $token): never
    {
        $report = self::byToken($token);
        $orgId = (int)$report['organization_id'];
        $logoId = Tenant::find('brand_profiles', (int)$report['brand_profile_id'], $orgId, 'logo_asset_id')['logo_asset_id'] ?? null;
        $asset = $logoId ? Tenant::find('assets', (int)$logoId, $orgId, 'kind, mime_type') : null;
        $path = $asset && $asset['kind'] === 'image' ? AssetController::localPath((int)$logoId, $orgId, 'image') : null;
        if (!$path) Http::json(['error' => 'not_found', 'message' => 'Logo non trovato.'], 404);
        header('Content-Type: ' . $asset['mime_type']);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: public, max-age=3600');
        header("Content-Security-Policy: default-src 'none'");
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private static function build(array $brand, string $period, ?array $saved, bool $public): array
    {
        $orgId = (int)$brand['organization_id'];
        $brandId = (int)$brand['id'];
        $start = "{$period}-01";
        $end = date('Y-m-t', strtotime($start));
        $previous = date('Y-m', strtotime("{$start} -1 month"));
        $prevStart = "{$previous}-01";
        $prevEnd = date('Y-m-t', strtotime($prevStart));
        $nextStart = date('Y-m-01', strtotime("{$start} +1 month"));
        $nextEnd = date('Y-m-t', strtotime($nextStart));
        $pdo = Database::connection();

        // Post pubblicati nel mese, con i clic ricevuti nel mese dai loro link
        $stmt = $pdo->prepare("SELECT c.id, c.title, c.channel, c.content_type, COALESCE(c.published_at, c.scheduled_at) AS published_at, c.published_url,
                (SELECT COUNT(*) FROM link_clicks k JOIN tracked_links l ON l.id=k.link_id WHERE l.content_item_id=c.id AND k.clicked_at BETWEEN ? AND ?) AS clicks
            FROM content_items c JOIN projects p ON p.id=c.project_id
            WHERE c.organization_id=? AND p.brand_profile_id=? AND c.status='published' AND COALESCE(c.published_at, c.scheduled_at) BETWEEN ? AND ?
            ORDER BY published_at");
        $stmt->execute(["{$start} 00:00:00", "{$end} 23:59:59", $orgId, $brandId, "{$start} 00:00:00", "{$end} 23:59:59"]);
        $posts = array_map(fn(array $r) => ['id' => (int)$r['id'], 'title' => $r['title'], 'channel' => $r['channel'], 'content_type' => $r['content_type'],
            'published_at' => $r['published_at'], 'published_url' => $r['published_url'], 'clicks' => (int)$r['clicks']], $stmt->fetchAll());
        $byChannel = [];
        foreach ($posts as $post) $byChannel[$post['channel']] = ($byChannel[$post['channel']] ?? 0) + 1;
        arsort($byChannel);

        $count = $pdo->prepare("SELECT COUNT(*) FROM content_items c JOIN projects p ON p.id=c.project_id
            WHERE c.organization_id=? AND p.brand_profile_id=? AND c.status=? AND COALESCE(c.published_at, c.scheduled_at) BETWEEN ? AND ?");
        $count->execute([$orgId, $brandId, 'published', "{$prevStart} 00:00:00", "{$prevEnd} 23:59:59"]);
        $prevPosts = (int)$count->fetchColumn();
        $planned = $pdo->prepare("SELECT COUNT(*) FROM content_items c JOIN projects p ON p.id=c.project_id
            WHERE c.organization_id=? AND p.brand_profile_id=? AND c.status IN ('approved','scheduled') AND c.scheduled_at BETWEEN ? AND ?");
        $planned->execute([$orgId, $brandId, "{$nextStart} 00:00:00", "{$nextEnd} 23:59:59"]);

        // Clic su tutti i link del cliente
        $clicksIn = function (string $from, string $to) use ($pdo, $orgId, $brandId): array {
            $stmt = $pdo->prepare('SELECT COUNT(*) AS clicks, COALESCE(SUM(k.is_unique), 0) AS unique_clicks FROM link_clicks k JOIN tracked_links l ON l.id=k.link_id
                WHERE l.organization_id=? AND l.brand_profile_id=? AND k.clicked_at BETWEEN ? AND ?');
            $stmt->execute([$orgId, $brandId, "{$from} 00:00:00", "{$to} 23:59:59"]);
            $row = $stmt->fetch();
            return ['clicks' => (int)$row['clicks'], 'unique_clicks' => (int)$row['unique_clicks']];
        };
        $clicks = $clicksIn($start, $end);
        $prevClicks = $clicksIn($prevStart, $prevEnd);
        $base = 'FROM link_clicks k JOIN tracked_links l ON l.id=k.link_id WHERE l.organization_id=? AND l.brand_profile_id=? AND k.clicked_at BETWEEN ? AND ?';
        $params = [$orgId, $brandId, "{$start} 00:00:00", "{$end} 23:59:59"];
        $stmt = $pdo->prepare("SELECT DAY(k.clicked_at) AS day, COUNT(*) AS clicks {$base} GROUP BY DAY(k.clicked_at)");
        $stmt->execute($params);
        $byDay = array_column($stmt->fetchAll(), 'clicks', 'day');
        $daily = [];
        for ($d = 1; $d <= (int)date('t', strtotime($start)); $d++) $daily[] = ['day' => sprintf('%s-%02d', $period, $d), 'clicks' => (int)($byDay[$d] ?? 0)];
        $group = function (string $column) use ($pdo, $base, $params): array {
            $stmt = $pdo->prepare("SELECT k.{$column} AS name, COUNT(*) AS clicks {$base} GROUP BY k.{$column} ORDER BY clicks DESC");
            $stmt->execute($params);
            return array_map(fn($r) => ['name' => $r['name'], 'clicks' => (int)$r['clicks']], $stmt->fetchAll());
        };
        $stmt = $pdo->prepare("SELECT l.label, COUNT(*) AS clicks {$base} GROUP BY l.id, l.label ORDER BY clicks DESC LIMIT 5");
        $stmt->execute($params);
        $topLinks = array_map(fn($r) => ['label' => $r['label'], 'clicks' => (int)$r['clicks']], $stmt->fetchAll());

        // Sponsorizzate: come nel resto dell'app una riga conta nel mese in cui finisce il suo periodo
        $stmt = $pdo->prepare('SELECT platform, campaign_name, period_start, period_end, spent, budget, impressions, reach, clicks, results
            FROM ad_spend WHERE organization_id=? AND brand_profile_id=? AND period_end BETWEEN ? AND ? ORDER BY spent DESC');
        $stmt->execute([$orgId, $brandId, $start, $end]);
        $campaigns = array_map(fn(array $r) => AdSpendController::metrics($r) + ['platform' => $r['platform'], 'campaign_name' => $r['campaign_name'],
            'period_start' => $r['period_start'], 'period_end' => $r['period_end']], $stmt->fetchAll());
        $totals = ['spent' => 0.0, 'budget' => 0.0, 'impressions' => 0, 'reach' => 0, 'clicks' => 0, 'results' => 0];
        foreach ($campaigns as $c) foreach ($totals as $key => $_) $totals[$key] += $c[$key];
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(spent), 0) AS spent, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(results), 0) AS results
            FROM ad_spend WHERE organization_id=? AND brand_profile_id=? AND period_end BETWEEN ? AND ?');
        $stmt->execute([$orgId, $brandId, $prevStart, $prevEnd]);
        $prevAds = $stmt->fetch();

        $agency = $pdo->prepare('SELECT name FROM organizations WHERE id=?');
        $agency->execute([$orgId]);
        $hasLogo = $brand['logo_asset_id'] !== null;

        $report = [
            'period' => $period,
            'period_label' => self::MONTHS[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4),
            'previous_label' => self::MONTHS[(int)substr($previous, 5, 2) - 1],
            'generated_at' => date('Y-m-d H:i:s'),
            'agency' => ['name' => $agency->fetchColumn()],
            'client' => [
                'id' => $brandId,
                'name' => $brand['name'],
                'website_url' => $brand['website_url'],
                'primary_color' => $brand['primary_color'],
                'secondary_color' => $brand['secondary_color'],
                'logo_asset_id' => $public || !$hasLogo ? null : (int)$brand['logo_asset_id'],
                'has_logo' => $hasLogo,
            ],
            'summary' => $saved['summary'] ?? null,
            'next_steps' => $saved['next_steps'] ?? null,
            'content' => [
                'published' => count($posts),
                'previous' => $prevPosts,
                'planned_next_month' => (int)$planned->fetchColumn(),
                'by_channel' => array_map(fn($name, $n) => ['name' => $name, 'posts' => $n], array_keys($byChannel), array_values($byChannel)),
                'posts' => $posts,
            ],
            'clicks' => $clicks + [
                'previous' => $prevClicks,
                'daily' => $daily,
                'sources' => $group('source'),
                'devices' => $group('device'),
                'top_links' => $topLinks,
            ],
            'ads' => [
                'totals' => AdSpendController::metrics($totals),
                'previous' => ['spent' => round((float)$prevAds['spent'], 2), 'clicks' => (int)$prevAds['clicks'], 'results' => (int)$prevAds['results']],
                'campaigns' => $campaigns,
            ],
        ];
        if (!$public) {
            $report['share'] = [
                'token' => $saved['share_token'] ?? null,
                'shared_at' => $saved['shared_at'] ?? null,
                'views' => (int)($saved['views'] ?? 0),
                'last_viewed_at' => $saved['last_viewed_at'] ?? null,
            ];
        }
        return $report;
    }

    private static function brand(int $brandId, int $organizationId): array
    {
        return Tenant::findOrFail('brand_profiles', $brandId, $organizationId, 'id, organization_id, name, website_url, primary_color, secondary_color, logo_asset_id');
    }

    private static function saved(int $organizationId, int $brandId, string $period): ?array
    {
        $stmt = Database::connection()->prepare('SELECT summary, next_steps, share_token, shared_at, views, last_viewed_at FROM client_reports WHERE organization_id=? AND brand_profile_id=? AND period=?');
        $stmt->execute([$organizationId, $brandId, $period]);
        return $stmt->fetch() ?: null;
    }

    /** Report condiviso; un link disattivato o di un'organizzazione sospesa risponde come uno inesistente. */
    private static function byToken(string $token): array
    {
        $stmt = Database::connection()->prepare("SELECT r.id, r.organization_id, r.brand_profile_id, r.period, r.summary, r.next_steps
            FROM client_reports r JOIN organizations o ON o.id=r.organization_id WHERE r.share_token=? AND o.status='active'");
        $stmt->execute([$token]);
        return $stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Report non trovato o non più condiviso.'], 404);
    }

    private static function period(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $value)) Http::validationError("Campo 'period' deve essere un mese AAAA-MM.");
        return $value;
    }
}
