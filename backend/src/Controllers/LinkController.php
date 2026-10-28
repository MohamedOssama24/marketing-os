<?php
declare(strict_types=1);

/**
 * Link tracciati: un indirizzo breve (/r/{codice}) da mettere nei post, che conta i clic e porta al sito
 * del cliente con i parametri UTM, così le visite si vedono anche in Google Analytics.
 */
final class LinkController
{
    private const SELECT = 'SELECT l.id, l.brand_profile_id, b.name AS brand_name, b.primary_color AS brand_color, l.content_item_id, c.title AS content_title, c.channel AS content_channel,
        l.code, l.label, l.target_url, l.utm_source, l.utm_medium, l.utm_campaign, l.utm_content, l.clicks, l.unique_clicks, l.last_click_at, l.created_at
        FROM tracked_links l LEFT JOIN brand_profiles b ON b.id=l.brand_profile_id LEFT JOIN content_items c ON c.id=l.content_item_id';
    /** Chi legge l'anteprima del link (social, chat, motori di ricerca) non è una persona che clicca. */
    private const BOTS = '/(bot|crawler|spider|slurp)([\/\s;)_-]|$)|facebookexternalhit|facebookcatalog|meta-externalagent|embedly|whatsapp|skypeuripreview|preview|headless|curl|wget|python-|httpclient|okhttp|go-http|java\//i';
    private const MEDIUM = ['instagram' => 'social', 'facebook' => 'social', 'linkedin' => 'social', 'email' => 'email', 'blog' => 'blog'];

    public static function index(): never
    {
        $user = Auth::user();
        [$where, $params] = self::filters($user);
        $stmt = Database::connection()->prepare(self::SELECT . " WHERE {$where} ORDER BY l.created_at DESC, l.id DESC LIMIT 500");
        $stmt->execute($params);
        Http::json(['data' => array_map([self::class, 'present'], $stmt->fetchAll())]);
    }

    /** Clic degli ultimi giorni (?days=30, massimo 365) per giorno, provenienza e dispositivo; filtrabili per cliente o per link. */
    public static function summary(): never
    {
        $user = Auth::user();
        $days = max(7, min(365, Http::optionalInt($_GET, 'days') ?? 30));
        [$where, $params] = self::filters($user);
        $since = (new DateTimeImmutable("-" . ($days - 1) . ' days'))->format('Y-m-d');
        $base = "FROM link_clicks k JOIN tracked_links l ON l.id=k.link_id WHERE {$where} AND k.clicked_at >= ?";
        $params[] = "{$since} 00:00:00";
        $pdo = Database::connection();

        $stmt = $pdo->prepare("SELECT DATE(k.clicked_at) AS day, COUNT(*) AS clicks, SUM(k.is_unique) AS unique_clicks {$base} GROUP BY DATE(k.clicked_at)");
        $stmt->execute($params);
        $byDay = array_column($stmt->fetchAll(), null, 'day');
        $daily = [];
        for ($i = 0; $i < $days; $i++) {
            $day = (new DateTimeImmutable($since))->modify("+{$i} days")->format('Y-m-d');
            $daily[] = ['day' => $day, 'clicks' => (int)($byDay[$day]['clicks'] ?? 0), 'unique_clicks' => (int)($byDay[$day]['unique_clicks'] ?? 0)];
        }
        $group = function (string $column) use ($pdo, $base, $params): array {
            $stmt = $pdo->prepare("SELECT k.{$column} AS name, COUNT(*) AS clicks {$base} GROUP BY k.{$column} ORDER BY clicks DESC");
            $stmt->execute($params);
            return array_map(fn($r) => ['name' => $r['name'], 'clicks' => (int)$r['clicks']], $stmt->fetchAll());
        };
        Http::json(['data' => [
            'days' => $days,
            'clicks' => array_sum(array_column($daily, 'clicks')),
            'unique_clicks' => array_sum(array_column($daily, 'unique_clicks')),
            'daily' => $daily,
            'sources' => $group('source'),
            'devices' => $group('device'),
        ]]);
    }

    /**
     * Crea un link. Con content_item_id cliente, campagna e UTM si ricavano dal post, e se il post ha già
     * un link si restituisce quello (un post pubblicato deve continuare a usare lo stesso indirizzo).
     */
    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        $pdo = Database::connection();
        $contentId = Http::optionalInt($data, 'content_item_id');
        $brandId = Http::optionalInt($data, 'brand_profile_id');
        $content = null;
        if ($contentId !== null) {
            $stmt = $pdo->prepare('SELECT c.id, c.title, c.channel, p.name AS project_name, p.brand_profile_id, b.website_url
                FROM content_items c JOIN projects p ON p.id=c.project_id LEFT JOIN brand_profiles b ON b.id=p.brand_profile_id WHERE c.id=? AND c.organization_id=?');
            $stmt->execute([$contentId, $user['organization_id']]);
            $content = $stmt->fetch() ?: Http::validationError('Contenuto non trovato.');
            $existing = $pdo->prepare('SELECT id FROM tracked_links WHERE organization_id=? AND content_item_id=? ORDER BY id LIMIT 1');
            $existing->execute([$user['organization_id'], $contentId]);
            if ($id = $existing->fetchColumn()) Http::json(['data' => self::find((int)$id, $user['organization_id'])]);
            $brandId = $content['brand_profile_id'] === null ? null : (int)$content['brand_profile_id'];
        } elseif ($brandId !== null) {
            Tenant::find('brand_profiles', $brandId, $user['organization_id'], 'id') ?? Http::validationError('Cliente non trovato.');
        }

        $target = Http::optionalString($data, 'target_url', 2048) ?? $content['website_url'] ?? null;
        if ($target === null) Http::validationError('Indica l\'indirizzo di destinazione (ad esempio la pagina del prodotto).');
        if (!preg_match('#^https?://#i', $target)) $target = 'https://' . $target;
        if (!filter_var($target, FILTER_VALIDATE_URL) || !preg_match('#^https?://[^/]+\.[a-z]{2,}#i', $target)) Http::validationError('Indirizzo di destinazione non valido.');

        $channel = $content['channel'] ?? null;
        $label = Http::optionalString($data, 'label', 160) ?? ($content ? mb_substr($content['title'], 0, 160) : parse_url($target, PHP_URL_HOST));
        $utm = [
            'utm_source' => Http::optionalString($data, 'utm_source', 60) ?? ($channel === 'email' ? 'newsletter' : $channel),
            'utm_medium' => Http::optionalString($data, 'utm_medium', 60) ?? ($channel ? self::MEDIUM[$channel] ?? 'social' : null),
            'utm_campaign' => Http::optionalString($data, 'utm_campaign', 120) ?? ($content ? self::slug($content['project_name']) : null),
            'utm_content' => Http::optionalString($data, 'utm_content', 120) ?? ($content ? "post-{$content['id']}" : null),
        ];

        $stmt = $pdo->prepare('INSERT INTO tracked_links (organization_id, brand_profile_id, content_item_id, code, label, target_url, utm_source, utm_medium, utm_campaign, utm_content, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        for ($attempt = 0; ; $attempt++) {
            try {
                $stmt->execute([$user['organization_id'], $brandId, $contentId, self::code(), $label, $target, ...array_values($utm), $user['id']]);
                break;
            } catch (PDOException $e) {
                if ($e->getCode() !== '23000' || $attempt >= 4) throw $e;
            }
        }
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'link.created', 'tracked_link', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])], 201);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $stmt = Database::connection()->prepare('DELETE FROM tracked_links WHERE id=? AND organization_id=?');
        $stmt->execute([$id, $user['organization_id']]);
        if ($stmt->rowCount() === 0) Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404);
        Audit::log($user, 'link.deleted', 'tracked_link', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** Indirizzo pubblico /r/{codice}: conta il clic (se non è un'anteprima automatica) e reindirizza. */
    public static function redirect(string $code): never
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, target_url, utm_source, utm_medium, utm_campaign, utm_content FROM tracked_links WHERE code=?');
        $stmt->execute([$code]);
        $link = $stmt->fetch();
        if (!$link) self::notFound();

        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && $agent !== '' && !preg_match(self::BOTS, $agent)) {
            // Ora italiana (date.timezone del server), così i grafici per giorno seguono i giorni del cliente.
            $now = date('Y-m-d H:i:s');
            $today = date('Y-m-d');
            // Impronta che cambia ogni giorno e non permette di risalire all'IP.
            $visitor = substr(hash('sha256', (getenv('APP_KEY') ?: 'marketing-os') . "|{$today}|" . Http::clientIp() . "|{$agent}"), 0, 32);
            $seen = $pdo->prepare('SELECT 1 FROM link_clicks WHERE link_id=? AND visitor_hash=? AND clicked_at >= ? LIMIT 1');
            $seen->execute([$link['id'], $visitor, "{$today} 00:00:00"]);
            $unique = $seen->fetchColumn() ? 0 : 1;
            $pdo->prepare('INSERT INTO link_clicks (link_id, clicked_at, visitor_hash, is_unique, source, device) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$link['id'], $now, $visitor, $unique, self::source($agent, (string)($_SERVER['HTTP_REFERER'] ?? '')), self::device($agent)]);
            $pdo->prepare('UPDATE tracked_links SET clicks=clicks+1, unique_clicks=unique_clicks+?, last_click_at=? WHERE id=?')->execute([$unique, $now, $link['id']]);
        }

        http_response_code(302);
        header('Location: ' . self::destination($link));
        header('Cache-Control: private, no-store');
        header('X-Robots-Tag: noindex');
        exit;
    }

    /** Aggiunge ai contenuti di una lista il link tracciato e i suoi clic. */
    public static function withClicks(array $items, int $organizationId): array
    {
        if (!$items) return $items;
        $ids = array_map(fn($i) => (int)$i['id'], $items);
        $stmt = Database::connection()->prepare('SELECT content_item_id, code, clicks, unique_clicks FROM tracked_links WHERE organization_id=? AND content_item_id IN (' . implode(',', $ids) . ')');
        $stmt->execute([$organizationId]);
        $links = [];
        foreach ($stmt->fetchAll() as $row) {
            $links[(int)$row['content_item_id']] ??= ['short_url' => self::shortUrl($row['code']), 'clicks' => (int)$row['clicks'], 'unique_clicks' => (int)$row['unique_clicks']];
        }
        return array_map(fn($i) => $i + ['tracked_link' => $links[(int)$i['id']] ?? null], $items);
    }

    public static function destination(array $link): string
    {
        $target = $link['target_url'];
        $utm = array_filter([
            'utm_source' => $link['utm_source'], 'utm_medium' => $link['utm_medium'], 'utm_campaign' => $link['utm_campaign'], 'utm_content' => $link['utm_content'],
        ], fn($v) => $v !== null && $v !== '');
        // Se l'indirizzo ha già i suoi UTM si rispettano quelli.
        if (!$utm || preg_match('/[?&]utm_[a-z]+=/i', $target)) return $target;
        $fragment = '';
        if (($hash = strpos($target, '#')) !== false) [$target, $fragment] = [substr($target, 0, $hash), substr($target, $hash)];
        return $target . (str_contains($target, '?') ? '&' : '?') . http_build_query($utm) . $fragment;
    }

    private static function find(int $id, int $organizationId): array
    {
        $stmt = Database::connection()->prepare(self::SELECT . ' WHERE l.id=? AND l.organization_id=?');
        $stmt->execute([$id, $organizationId]);
        return self::present($stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404));
    }

    /** @return array{0: string, 1: list<mixed>} */
    private static function filters(array $user): array
    {
        $where = 'l.organization_id=?';
        $params = [$user['organization_id']];
        foreach (['brand_id' => 'l.brand_profile_id', 'content_id' => 'l.content_item_id', 'link_id' => 'l.id'] as $key => $column) {
            $value = Http::optionalInt($_GET, $key);
            if ($value === null) continue;
            $where .= " AND {$column}=?";
            $params[] = $value;
        }
        return [$where, $params];
    }

    private static function present(array $row): array
    {
        foreach (['id', 'brand_profile_id', 'content_item_id'] as $key) $row[$key] = $row[$key] === null ? null : (int)$row[$key];
        $row['clicks'] = (int)$row['clicks'];
        $row['unique_clicks'] = (int)$row['unique_clicks'];
        $row['short_url'] = self::shortUrl($row['code']);
        $row['destination_url'] = self::destination($row);
        return $row;
    }

    private static function shortUrl(string $code): string
    {
        return rtrim(getenv('APP_URL') ?: 'http://localhost:8088', '/') . '/r/' . $code;
    }

    /** 7 caratteri senza quelli che si confondono (0/O, 1/l/I). */
    private static function code(): string
    {
        $alphabet = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 7; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        return $code;
    }

    private static function slug(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
        return substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '', '-'), 0, 120) ?: 'campagna';
    }

    /** Da dove arriva il clic: i browser interni delle app si riconoscono dallo user agent, gli altri dal referrer. */
    private static function source(string $agent, string $referrer): string
    {
        if (preg_match('/Instagram/i', $agent)) return 'instagram';
        if (preg_match('/FBAN|FBAV|FB_IAB/i', $agent)) return 'facebook';
        if (preg_match('/LinkedInApp/i', $agent)) return 'linkedin';
        $host = strtolower((string)parse_url($referrer, PHP_URL_HOST));
        if ($host === '') return 'direct';
        foreach (['instagram' => 'instagram.', 'facebook' => 'facebook.', 'linkedin' => 'linkedin.', 'google' => 'google.', 'x' => 't.co'] as $name => $needle) {
            if (str_contains($host, $needle)) return $name;
        }
        if (preg_match('/mail\.|outlook\.|brevo|sendinblue|mailchimp|mailchi\.mp/', $host)) return 'email';
        return 'other';
    }

    private static function device(string $agent): string
    {
        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $agent)) return 'tablet';
        return preg_match('/Mobile|iPhone|Android/i', $agent) ? 'mobile' : 'desktop';
    }

    private static function notFound(): never
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Robots-Tag: noindex');
        echo '<!doctype html><html lang="it"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Link non valido</title>'
            . '<body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:90vh;color:#17182a"><div style="text-align:center">'
            . '<h1 style="font-size:1.3rem">Link non valido</h1><p style="color:#6b6e85">Questo link non esiste più o è stato scritto male.</p></div></body></html>';
        exit;
    }
}
