<?php
declare(strict_types=1);

// Esegue i lavori in coda (analisi siti, campagne, generazione contenuti). Avviato dal servizio "worker" di Docker Compose.
require_once __DIR__ . '/../src/bootstrap.php';

/** Orario di pubblicazione consigliato per canale. */
const CHANNEL_TIMES = ['instagram' => '18:30', 'facebook' => '12:30', 'linkedin' => '09:00', 'blog' => '10:00', 'email' => '08:30'];

function loadProject(array $job): array
{
    $orgId = (int)$job['organization_id'];
    $project = Tenant::find('projects', (int)$job['payload']['project_id'], $orgId, 'id, name, objective, target_audience, brand_profile_id');
    if (!$project) throw new GenerationException('Il progetto non esiste più.');
    return [$project, loadBrand($orgId, $project['brand_profile_id'] ? (int)$project['brand_profile_id'] : null)];
}

/** Brand kit del cliente con gli esempi di stile appresi dalle correzioni passate. */
function loadBrand(int $orgId, ?int $brandId): array
{
    $brand = $brandId ? (Tenant::find('brand_profiles', $brandId, $orgId) ?? []) : [];
    if ($brand) $brand['style_examples'] = StyleMemory::forBrand($orgId, (int)$brand['id']);
    return $brand;
}

/** Legge un sito per audit e concorrenti, con gli errori di lettura mostrati all'utente. */
function readSite(string $url): array
{
    try {
        return SiteReader::read($url);
    } catch (SiteException $e) {
        throw new GenerationException($e->getMessage());
    }
}

/** Testi di una lista di stringhe dall'AI, puliti e accorciati. */
function cleanList(mixed $list, int $max, int $length = 300): array
{
    if (!is_array($list)) return [];
    $items = array_values(array_filter(array_map(fn($s) => is_scalar($s) ? mb_substr(trim((string)$s), 0, $length) : '', $list)));
    return array_slice($items, 0, $max);
}

$handlers = [
    /** Crea il cliente dal sito, oppure (con brand_id) aggiorna il brand kit di un cliente esistente. */
    'analyze_site' => function (array $job): array {
        $orgId = (int)$job['organization_id'];
        $userId = (int)$job['created_by'];
        $existing = isset($job['payload']['brand_id'])
            ? (Tenant::find('brand_profiles', (int)$job['payload']['brand_id'], $orgId, 'id, name, logo_asset_id') ?? throw new GenerationException('Il cliente non esiste più.'))
            : null;
        try {
            $site = SiteReader::read($job['payload']['url']);
        } catch (SiteException $e) {
            throw new GenerationException($e->getMessage());
        }
        $kit = MarketingAis::make()->brandKitFromSite($site);

        $fields = ['name' => 160, 'summary' => 500, 'industry' => 160, 'audience' => 2000, 'tone_of_voice' => 2000, 'products' => 5000, 'keywords' => 1000, 'words_to_avoid' => 1000, 'brand_guidelines' => 5000];
        $values = [];
        foreach ($fields as $key => $max) {
            $value = trim((string)($kit[$key] ?? ''));
            $values[$key] = $value === '' ? null : mb_substr($value, 0, $max);
        }
        $values['name'] ??= $site['host'];
        $values['website_url'] = $site['url'];
        // Profili Instagram, Facebook e LinkedIn collegati dal sito, se presenti.
        foreach ($site['socials'] as $key => $url) $values[$key] = mb_substr($url, 0, 2048);
        $pdo = Database::connection();

        if ($existing) {
            // Il nome scelto dall'utente resta; i campi vuoti nell'analisi non cancellano quelli esistenti.
            unset($values['name']);
            $values = array_filter($values, fn($v) => $v !== null);
            if ($kit['primary_color']) $values['primary_color'] = $kit['primary_color'];
            if ($kit['secondary_color']) $values['secondary_color'] = $kit['secondary_color'];
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($values)));
            $pdo->prepare("UPDATE brand_profiles SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($values), $existing['id'], $orgId]);
            $brandId = (int)$existing['id'];
        } else {
            $values['primary_color'] = $kit['primary_color'];
            $values['secondary_color'] = $kit['secondary_color'];
            $stmt = $pdo->prepare('INSERT INTO brand_profiles (organization_id, ' . implode(', ', array_keys($values)) . ') VALUES (?' . str_repeat(', ?', count($values)) . ')');
            $stmt->execute([$orgId, ...array_values($values)]);
            $brandId = (int)$pdo->lastInsertId();
            // Cliente nato da un audit: il potenziale cliente resta collegato.
            if (isset($job['payload']['prospect_id'])) {
                $pdo->prepare('UPDATE prospects SET brand_profile_id=? WHERE id=? AND organization_id=?')->execute([$brandId, (int)$job['payload']['prospect_id'], $orgId]);
            }
        }

        $logoId = $existing['logo_asset_id'] ?? null;
        if (!$logoId && ($logo = SiteReader::downloadLogo($site['logo_candidates']))) {
            $logoId = AssetController::saveBytes($orgId, $userId, $logo['bytes'], $logo['name'], $brandId);
            $pdo->prepare('UPDATE brand_profiles SET logo_asset_id=? WHERE id=? AND organization_id=?')->execute([$logoId, $brandId, $orgId]);
        }

        // Foto dei contenuti del sito, saltando quelle già importate per questo cliente.
        $stmt = $pdo->prepare('SELECT original_name FROM assets WHERE organization_id=? AND brand_profile_id=?');
        $stmt->execute([$orgId, $brandId]);
        $known = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        $photos = 0;
        foreach (SiteReader::downloadImages($site['image_candidates']) as $image) {
            if (isset($known[$image['name']])) continue;
            if (AssetController::saveBytes($orgId, $userId, $image['bytes'], $image['name'], $brandId)) $photos++;
        }
        return ['brand_id' => $brandId, 'site_colors' => $site['colors'], 'logo_found' => $logoId !== null, 'photos_imported' => $photos];
    },

    /** Video del post: storyboard dal testo, foto del cliente, montaggio con FFmpeg. Nessun servizio a pagamento. */
    'render_video' => function (array $job): array {
        $orgId = (int)$job['organization_id'];
        $payload = $job['payload'];
        $item = Tenant::find('content_items', (int)$payload['content_id'], $orgId, ContentController::COLUMNS)
            ?? throw new GenerationException('Il contenuto non esiste più.');
        $item = ContentController::present($item);
        $project = Tenant::find('projects', (int)$item['project_id'], $orgId, 'brand_profile_id');
        $brand = $project && $project['brand_profile_id'] ? (Tenant::find('brand_profiles', (int)$project['brand_profile_id'], $orgId) ?? []) : [];
        $brandId = $brand ? (int)$brand['id'] : null;

        // Prima la foto del post, poi le altre foto del cliente.
        $photoIds = array_values(array_unique(array_filter([
            $item['visual']['asset_id'] ?? null,
            ...($brandId ? AssetController::brandPhotoIds($orgId, $brandId) : []),
        ])));
        $photos = array_values(array_filter(array_map(fn($id) => AssetController::localPath((int)$id, $orgId, 'image'), $photoIds)));
        $logo = !empty($brand['logo_asset_id']) ? AssetController::localPath((int)$brand['logo_asset_id'], $orgId, 'image') : null;
        $music = $payload['music_asset_id'] ? AssetController::localPath((int)$payload['music_asset_id'], $orgId, 'audio') : null;

        $scenes = VideoStoryboard::fromPost($item, $brand, $photos);
        $output = sys_get_temp_dir() . '/video-' . bin2hex(random_bytes(6)) . '.mp4';
        $started = microtime(true);
        try {
            VideoRenderer::render($scenes, $brand, $payload['format'], $logo, $music, $output);
            $name = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower(($brand['name'] ?? 'video') . '-' . $item['id'])) . '-' . $payload['format'] . '.mp4';
            $assetId = AssetController::saveLocalFile($orgId, (int)$job['created_by'], $output, $name, $brandId);
        } finally {
            @unlink($output);
        }
        Database::connection()->prepare('UPDATE content_items SET video_asset_id=? WHERE id=? AND organization_id=?')->execute([$assetId, $item['id'], $orgId]);
        return ['content_id' => (int)$item['id'], 'asset_id' => $assetId, 'scenes' => count($scenes), 'seconds' => round(microtime(true) - $started, 1)];
    },

    'suggest_goals' => function (array $job): array {
        $brand = Tenant::find('brand_profiles', (int)$job['payload']['brand_id'], (int)$job['organization_id'])
            ?? throw new GenerationException('Il cliente non esiste più.');
        $goals = [];
        foreach (MarketingAis::make()->suggestGoals($brand) as $goal) {
            $title = trim((string)($goal['title'] ?? ''));
            $text = trim((string)($goal['goal'] ?? ''));
            if ($title !== '' && $text !== '') $goals[] = ['title' => mb_substr($title, 0, 60), 'goal' => mb_substr($text, 0, 500)];
        }
        return ['goals' => $goals];
    },

    'rewrite_content' => function (array $job): array {
        $orgId = (int)$job['organization_id'];
        $item = Tenant::find('content_items', (int)$job['payload']['content_id'], $orgId, ContentController::COLUMNS)
            ?? throw new GenerationException('Il contenuto non esiste più.');
        $item = ContentController::present($item);
        if (!in_array($item['status'], ['draft', 'rejected'], true)) throw new GenerationException('Il contenuto non è più modificabile.');
        $project = Tenant::find('projects', (int)$item['project_id'], $orgId, 'brand_profile_id');
        $brand = loadBrand($orgId, $project && $project['brand_profile_id'] ? (int)$project['brand_profile_id'] : null);

        $result = MarketingAis::make()->rewriteContent($brand, $item, $job['payload']['instruction']);
        // La foto scelta resta, anche se l'AI cambia testi e stile.
        $visual = ContentController::visual(($result['visual'] ?? []) + ['asset_id' => $item['visual']['asset_id'] ?? null], $orgId, false);
        Database::connection()->prepare("UPDATE content_items SET title=?, body=?, ai_body=body, visual=?, status='draft' WHERE id=? AND organization_id=?")->execute([
            mb_substr(trim((string)($result['title'] ?? '')) ?: $item['title'], 0, 220),
            formatBody($result),
            $visual === null ? null : json_encode($visual, JSON_UNESCAPED_UNICODE),
            $item['id'], $orgId,
        ]);
        return ['content_id' => (int)$item['id']];
    },

    'generate_campaign' => function (array $job): array {
        $payload = $job['payload'];
        $orgId = (int)$job['organization_id'];
        [$project, $brand] = loadProject($job);
        $plan = MarketingAis::make()->campaignPlan($brand, $project, $payload);

        $pdo = Database::connection();
        $pdo->prepare('UPDATE projects SET strategy=? WHERE id=? AND organization_id=?')
            ->execute([json_encode($plan['strategy'], JSON_UNESCAPED_UNICODE), $project['id'], $orgId]);

        $start = new DateTimeImmutable($payload['start_date']);
        $lastDay = $payload['weeks'] * 7;
        // Le foto del cliente vanno a rotazione sui post con layout fotografico.
        $photos = $project['brand_profile_id'] ? AssetController::brandPhotoIds($orgId, (int)$project['brand_profile_id']) : [];
        $photoIndex = 0;
        $stmt = $pdo->prepare('INSERT INTO content_items (organization_id, project_id, created_by, channel, content_type, title, body, ai_body, visual, scheduled_at, ai_generated, job_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $ids = [];
        foreach ($plan['posts'] as $post) {
            $channel = in_array($post['channel'] ?? '', $payload['channels'], true) ? $post['channel'] : $payload['channels'][0];
            $day = max(1, min($lastDay, (int)($post['day'] ?? 1)));
            $when = $start->modify('+' . ($day - 1) . ' days')->format('Y-m-d') . ' ' . (CHANNEL_TIMES[$channel] ?? '10:00') . ':00';
            $visual = ContentController::visual($post['visual'] ?? null, $orgId, false);
            if ($visual && $photos && in_array($visual['layout'], ['photo', 'split'], true)) {
                $visual['asset_id'] = $photos[$photoIndex++ % count($photos)];
            }
            $stmt->execute([
                $orgId, $project['id'], $job['created_by'], $channel,
                in_array($post['content_type'] ?? '', MarketingAi::CONTENT_TYPES, true) ? $post['content_type'] : 'post',
                mb_substr(trim((string)($post['title'] ?? '')) ?: 'Senza titolo', 0, 220),
                ...array_fill(0, 2, formatBody($post)), // testo e copia originale dell'AI
                $visual === null ? null : json_encode($visual, JSON_UNESCAPED_UNICODE),
                $when,
                $job['id'],
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        return ['project_id' => (int)$project['id'], 'content_ids' => $ids];
    },

    'generate_content' => function (array $job): array {
        $payload = $job['payload'];
        $orgId = (int)$job['organization_id'];
        [$project, $brand] = loadProject($job);

        $variants = MarketingAis::make()->contentVariants($brand, $project, $payload);

        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO content_items (organization_id, project_id, created_by, channel, content_type, title, body, ai_body, visual, ai_generated, job_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $ids = [];
        // Con una foto le varianti alternano "Foto" e "Foto e colore"; senza, colore pieno e chiaro.
        $assetId = $payload['asset_id'] ?? null;
        $layouts = $assetId ? ['photo', 'split'] : ['bold', 'light'];
        foreach ($variants as $i => $variant) {
            $title = mb_substr(trim((string)$variant['title']) ?: 'Senza titolo', 0, 220);
            $visual = ContentController::visual([
                'headline' => mb_substr($title, 0, 60), 'subheadline' => '', 'badge' => '',
                'layout' => $layouts[$i % 2], 'asset_id' => $assetId,
            ], $orgId, false);
            $stmt->execute([
                $orgId, $project['id'], $job['created_by'], $payload['channel'], $payload['content_type'],
                $title,
                ...array_fill(0, 2, formatBody($variant)), // testo e copia originale dell'AI
                json_encode($visual, JSON_UNESCAPED_UNICODE),
                $job['id'],
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        return ['content_ids' => $ids];
    },

    /** Audit gratuito del sito di un potenziale cliente: controlli automatici, analisi AI e proposta di servizi. */
    'audit_prospect' => function (array $job): array {
        $orgId = (int)$job['organization_id'];
        $prospect = Tenant::find('prospects', (int)$job['payload']['prospect_id'], $orgId, 'id, url')
            ?? throw new GenerationException('Il potenziale cliente non esiste più.');
        $site = readSite($prospect['url']);
        $checks = SiteChecks::run($site);
        $ai = MarketingAis::make()->prospectAudit($site, $checks);
        $name = mb_substr(trim((string)($ai['name'] ?? '')) ?: $site['host'], 0, 160);
        $audit = [
            'name' => $name,
            'summary' => mb_substr(trim((string)($ai['summary'] ?? '')), 0, 600),
            'score' => SiteChecks::score($checks),
            'checks' => $checks,
            'strengths' => cleanList($ai['strengths'] ?? [], 5),
            'issues' => cleanObjects($ai['issues'] ?? [], ['title', 'detail', 'fix'], 6),
            'post_ideas' => cleanObjects($ai['post_ideas'] ?? [], ['channel', 'title', 'body'], 3, 1000),
            'services' => cleanObjects($ai['services'] ?? [], ['name', 'description'], 5),
            'pitch' => mb_substr(trim((string)($ai['pitch'] ?? '')), 0, 1500),
            'colors' => array_slice($site['colors'], 0, 2),
            'site_url' => $site['url'],
            'analyzed_at' => date('Y-m-d H:i:s'),
        ];
        Database::connection()->prepare('UPDATE prospects SET name=COALESCE(name, ?), audit=? WHERE id=? AND organization_id=?')
            ->execute([$name, json_encode($audit, JSON_UNESCAPED_UNICODE), $prospect['id'], $orgId]);
        return ['prospect_id' => (int)$prospect['id']];
    },

    /** Un contenuto in altri formati: le nuove bozze vanno nella stessa campagna. */
    'repurpose_content' => function (array $job): array {
        $orgId = (int)$job['organization_id'];
        $item = Tenant::find('content_items', (int)$job['payload']['content_id'], $orgId, ContentController::COLUMNS)
            ?? throw new GenerationException('Il contenuto non esiste più.');
        $item = ContentController::present($item);
        $project = Tenant::find('projects', (int)$item['project_id'], $orgId, 'brand_profile_id');
        $brand = loadBrand($orgId, $project && $project['brand_profile_id'] ? (int)$project['brand_profile_id'] : null);
        $formats = $job['payload']['formats'];
        $results = MarketingAis::make()->repurposeContent($brand, $item, $formats);

        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO content_items (organization_id, project_id, created_by, channel, content_type, title, body, ai_body, visual, ai_generated, job_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $ids = [];
        $done = [];
        foreach ($results as $result) {
            $format = $result['format'] ?? '';
            if (!in_array($format, $formats, true) || isset($done[$format])) continue;
            $done[$format] = true;
            [$channel, $type] = Repurpose::FORMATS[$format];
            $visual = ContentController::visual(($result['visual'] ?? []) + ['asset_id' => $item['visual']['asset_id'] ?? null], $orgId, false);
            $stmt->execute([
                $orgId, $item['project_id'], $job['created_by'], $channel, $type,
                mb_substr(trim((string)($result['title'] ?? '')) ?: $item['title'], 0, 220),
                ...array_fill(0, 2, formatBody($result)), // testo e copia originale dell'AI
                $visual === null ? null : json_encode($visual, JSON_UNESCAPED_UNICODE),
                $job['id'],
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        if (!$ids) throw new GenerationException('L\'AI non ha prodotto nessun formato. Riprova.');
        return ['project_id' => (int)$item['project_id'], 'content_ids' => $ids];
    },

    /** Risposte proposte a una recensione: l'utente sceglie e salva quella che preferisce. */
    'review_replies' => function (array $job): array {
        $payload = $job['payload'];
        $brand = loadBrand((int)$job['organization_id'], (int)$payload['brand_id']) ?: throw new GenerationException('Il cliente non esiste più.');
        $replies = MarketingAis::make()->reviewReplies($brand, $payload);
        return ['replies' => cleanList($replies, 3, 2000)];
    },

    /** Legge il sito di un concorrente e lo confronta con il brand kit del cliente. */
    'analyze_competitor' => function (array $job): array {
        $orgId = (int)$job['organization_id'];
        $competitor = Tenant::find('competitors', (int)$job['payload']['competitor_id'], $orgId, 'id, brand_profile_id, url')
            ?? throw new GenerationException('Il concorrente non esiste più.');
        $site = readSite($competitor['url']);
        $brief = MarketingAis::make()->competitorBrief(loadBrand($orgId, (int)$competitor['brand_profile_id']), $site);
        $analysis = [
            'summary' => mb_substr(trim((string)($brief['summary'] ?? '')), 0, 800),
            'offers' => cleanList($brief['offers'] ?? [], 6),
            'messages' => cleanList($brief['messages'] ?? [], 5),
            'tone' => mb_substr(trim((string)($brief['tone'] ?? '')), 0, 400),
            'opportunities' => cleanList($brief['opportunities'] ?? [], 4, 500),
            'socials' => $site['socials'],
        ];
        Database::connection()->prepare('UPDATE competitors SET analysis=?, last_checked_at=? WHERE id=? AND organization_id=?')
            ->execute([json_encode($analysis, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $competitor['id'], $orgId]);
        return ['competitor_id' => (int)$competitor['id']];
    },
];

/** Oggetti di una lista dall'AI con solo i campi attesi, scartando quelli senza il primo campo. */
function cleanObjects(mixed $list, array $keys, int $max, int $length = 600): array
{
    if (!is_array($list)) return [];
    $items = [];
    foreach ($list as $entry) {
        if (!is_array($entry)) continue;
        $clean = [];
        foreach ($keys as $key) $clean[$key] = is_scalar($entry[$key] ?? null) ? mb_substr(trim((string)$entry[$key]), 0, $length) : '';
        if ($clean[$keys[0]] !== '') $items[] = $clean;
    }
    return array_slice($items, 0, $max);
}

/** Ogni settimana rianalizza i concorrenti già analizzati, così l'agenzia vede se cambiano offerte e messaggi. */
function refreshCompetitors(): void
{
    $pdo = Database::connection();
    $stale = $pdo->prepare("SELECT c.id, c.organization_id, c.created_by FROM competitors c LEFT JOIN jobs j ON j.id=c.job_id
        WHERE c.last_checked_at < ? AND c.created_by IS NOT NULL AND (j.id IS NULL OR j.status IN ('done','failed')) LIMIT 20");
    $stale->execute([date('Y-m-d H:i:s', strtotime('-7 days'))]);
    foreach ($stale->fetchAll() as $c) {
        $job = Jobs::enqueue(['organization_id' => (int)$c['organization_id'], 'id' => (int)$c['created_by']], 'analyze_competitor', ['competitor_id' => (int)$c['id']]);
        $pdo->prepare('UPDATE competitors SET job_id=? WHERE id=?')->execute([$job['id'], $c['id']]);
    }
}

/** Unisce testo, invito all'azione, hashtag e note per il revisore in un unico corpo modificabile. */
function formatBody(array $variant): string
{
    $parts = [trim((string)$variant['body'])];
    if (trim((string)($variant['call_to_action'] ?? '')) !== '') $parts[] = '👉 ' . trim($variant['call_to_action']);
    $tags = array_filter(array_map(fn($t) => '#' . ltrim(preg_replace('/\s+/', '', (string)$t), '#'), $variant['hashtags'] ?? []), fn($t) => $t !== '#');
    if ($tags) $parts[] = implode(' ', $tags);
    if (trim((string)($variant['reviewer_notes'] ?? '')) !== '') $parts[] = "---\nNote per il revisore: " . trim($variant['reviewer_notes']);
    return implode("\n\n", $parts);
}

echo "Worker avviato.\n";
$nextRefresh = 0;
while (true) {
    if (time() >= $nextRefresh) {
        try {
            refreshCompetitors();
        } catch (Throwable $e) {
            error_log((string)$e);
        }
        $nextRefresh = time() + 3600;
    }
    $job = Jobs::claim();
    if (!$job) { sleep(2); continue; }
    echo "Lavoro #{$job['id']} ({$job['type']})...\n";
    try {
        $handler = $handlers[$job['type']] ?? throw new GenerationException("Tipo di lavoro sconosciuto: {$job['type']}");
        Jobs::finish((int)$job['id'], $handler($job));
        echo "Lavoro #{$job['id']} completato.\n";
    } catch (GenerationException $e) {
        Jobs::fail((int)$job['id'], $e->getMessage());
        echo "Lavoro #{$job['id']} fallito: {$e->getMessage()}\n";
    } catch (Throwable $e) {
        error_log((string)$e);
        Jobs::fail((int)$job['id'], 'Errore interno durante l\'elaborazione.');
        echo "Lavoro #{$job['id']} fallito: errore interno.\n";
    }
}
