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
    $brand = $project['brand_profile_id'] ? (Tenant::find('brand_profiles', (int)$project['brand_profile_id'], $orgId) ?? []) : [];
    return [$project, $brand];
}

$handlers = [
    'analyze_site' => function (array $job): array {
        $orgId = (int)$job['organization_id'];
        try {
            $site = SiteReader::read($job['payload']['url']);
        } catch (SiteException $e) {
            throw new GenerationException($e->getMessage());
        }
        $kit = MarketingAis::make()->brandKitFromSite($site);
        $logoId = null;
        if ($logo = SiteReader::downloadLogo($site['logo_candidates'])) {
            $logoId = AssetController::saveBytes($orgId, (int)$job['created_by'], $logo['bytes'], $logo['name']);
        }

        $fields = ['name' => 160, 'summary' => 500, 'industry' => 160, 'audience' => 2000, 'tone_of_voice' => 2000, 'products' => 5000, 'keywords' => 1000, 'words_to_avoid' => 1000, 'brand_guidelines' => 5000];
        $values = [];
        foreach ($fields as $key => $max) {
            $value = trim((string)($kit[$key] ?? ''));
            $values[$key] = $value === '' ? null : mb_substr($value, 0, $max);
        }
        $values['name'] ??= $site['host'];
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO brand_profiles (organization_id, ' . implode(', ', array_keys($values)) . ', website_url, primary_color, secondary_color, logo_asset_id) VALUES (?' . str_repeat(', ?', count($values) + 4) . ')');
        $stmt->execute([$orgId, ...array_values($values), $site['url'], $kit['primary_color'], $kit['secondary_color'], $logoId]);
        return ['brand_id' => (int)$pdo->lastInsertId(), 'site_colors' => $site['colors'], 'logo_found' => $logoId !== null];
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
        $stmt = $pdo->prepare('INSERT INTO content_items (organization_id, project_id, created_by, channel, content_type, title, body, visual, scheduled_at, ai_generated, job_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $ids = [];
        foreach ($plan['posts'] as $post) {
            $channel = in_array($post['channel'] ?? '', $payload['channels'], true) ? $post['channel'] : $payload['channels'][0];
            $day = max(1, min($lastDay, (int)($post['day'] ?? 1)));
            $when = $start->modify('+' . ($day - 1) . ' days')->format('Y-m-d') . ' ' . (CHANNEL_TIMES[$channel] ?? '10:00') . ':00';
            $visual = ContentController::visual($post['visual'] ?? null, $orgId, false);
            $stmt->execute([
                $orgId, $project['id'], $job['created_by'], $channel,
                in_array($post['content_type'] ?? '', MarketingAi::CONTENT_TYPES, true) ? $post['content_type'] : 'post',
                mb_substr(trim((string)($post['title'] ?? '')) ?: 'Senza titolo', 0, 220),
                formatBody($post),
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
        $stmt = $pdo->prepare('INSERT INTO content_items (organization_id, project_id, created_by, channel, content_type, title, body, ai_generated, job_id) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $ids = [];
        foreach ($variants as $variant) {
            $stmt->execute([
                $orgId, $project['id'], $job['created_by'], $payload['channel'], $payload['content_type'],
                mb_substr(trim((string)$variant['title']) ?: 'Senza titolo', 0, 220),
                formatBody($variant),
                $job['id'],
            ]);
            $ids[] = (int)$pdo->lastInsertId();
        }
        return ['content_ids' => $ids];
    },
];

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
while (true) {
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
