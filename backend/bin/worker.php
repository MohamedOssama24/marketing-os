<?php
declare(strict_types=1);

// Esegue i lavori in coda (generazione contenuti). Avviato dal servizio "worker" di Docker Compose.
require_once __DIR__ . '/../src/bootstrap.php';

$handlers = [
    'generate_content' => function (array $job): array {
        $payload = $job['payload'];
        $orgId = (int)$job['organization_id'];
        $project = Tenant::find('projects', (int)$payload['project_id'], $orgId, 'id, name, objective, target_audience, brand_profile_id');
        if (!$project) throw new GenerationException('Il progetto non esiste più.');
        $brand = $project['brand_profile_id'] ? (Tenant::find('brand_profiles', (int)$project['brand_profile_id'], $orgId) ?? []) : [];

        $variants = ContentGenerators::make()->generate($brand, $project, $payload);

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
