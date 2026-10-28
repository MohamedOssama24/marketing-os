<?php
declare(strict_types=1);

/** Coda di lavori su MySQL, eseguiti da bin/worker.php. */
final class Jobs
{
    private const COLUMNS = 'id, type, status, result, error, created_at, started_at, finished_at';

    public static function enqueue(array $user, string $type, array $payload): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO jobs (organization_id, created_by, type, payload) VALUES (?, ?, ?, ?)');
        $stmt->execute([$user['organization_id'], $user['id'], $type, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        return self::present(Tenant::findOrFail('jobs', (int)$pdo->lastInsertId(), $user['organization_id'], self::COLUMNS));
    }

    public static function show(int $id): never
    {
        $user = Auth::user();
        Http::json(['data' => self::present(Tenant::findOrFail('jobs', $id, $user['organization_id'], self::COLUMNS))]);
    }

    /** Prende in carico il prossimo lavoro in coda in modo atomico, oppure null se non ce ne sono. */
    public static function claim(): ?array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("UPDATE jobs SET status='running', started_at=UTC_TIMESTAMP(), attempts=attempts+1, id=LAST_INSERT_ID(id) WHERE status='queued' ORDER BY id LIMIT 1");
        $stmt->execute();
        if ($stmt->rowCount() === 0) return null;
        $job = $pdo->query('SELECT * FROM jobs WHERE id=' . (int)$pdo->lastInsertId())->fetch();
        $job['payload'] = json_decode($job['payload'], true);
        return $job;
    }

    public static function finish(int $id, array $result): void
    {
        $stmt = Database::connection()->prepare("UPDATE jobs SET status='done', result=?, error=NULL, finished_at=UTC_TIMESTAMP() WHERE id=?");
        $stmt->execute([json_encode($result, JSON_UNESCAPED_UNICODE), $id]);
    }

    public static function fail(int $id, string $message): void
    {
        $stmt = Database::connection()->prepare("UPDATE jobs SET status='failed', error=?, finished_at=UTC_TIMESTAMP() WHERE id=?");
        $stmt->execute([mb_substr($message, 0, 500), $id]);
    }

    private static function present(array $job): array
    {
        $job['result'] = $job['result'] === null ? null : json_decode($job['result'], true);
        return $job;
    }
}
