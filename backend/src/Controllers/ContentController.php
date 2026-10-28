<?php
declare(strict_types=1);

final class ContentController
{
    public const CHANNELS = ['instagram', 'facebook', 'linkedin', 'blog', 'email', 'website', 'other'];
    private const COLUMNS = 'id, project_id, channel, content_type, title, body, status, ai_generated, job_id, review_note, reviewed_by, reviewed_at, scheduled_at, created_at, updated_at';

    public static function index(int $projectId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('projects', $projectId, $user['organization_id'], 'id');
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM content_items WHERE project_id=? AND organization_id=? ORDER BY created_at DESC, id DESC LIMIT 200');
        $stmt->execute([$projectId, $user['organization_id']]);
        Http::json(['data' => $stmt->fetchAll()]);
    }

    public static function store(int $projectId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('projects', $projectId, $user['organization_id'], 'id');
        $data = Http::body();
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO content_items (organization_id, project_id, created_by, channel, content_type, title, body) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $user['organization_id'], $projectId, $user['id'],
            Http::oneOf($data, 'channel', self::CHANNELS, 'other'),
            Http::optionalString($data, 'content_type', 80) ?? 'post',
            Http::requiredString($data, 'title', 220),
            Http::requiredString($data, 'body', 20000),
        ]);
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'content.created', 'content_item', $id);
        Http::json(['data' => self::find($id, $user)], 201);
    }

    /** Modifica testo e canale. Un contenuto rifiutato torna in bozza; quelli in revisione o approvati sono bloccati. */
    public static function update(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $item = self::find($id, $user);
        if (!in_array($item['status'], ['draft', 'rejected'], true)) {
            Http::json(['error' => 'invalid_state', 'message' => 'Si possono modificare solo bozze o contenuti rifiutati.'], 409);
        }
        $data = Http::body();
        $fields = [];
        if (array_key_exists('title', $data)) $fields['title'] = Http::requiredString($data, 'title', 220);
        if (array_key_exists('body', $data)) $fields['body'] = Http::requiredString($data, 'body', 20000);
        if (array_key_exists('channel', $data)) $fields['channel'] = Http::oneOf($data, 'channel', self::CHANNELS);
        if (array_key_exists('content_type', $data)) $fields['content_type'] = Http::requiredString($data, 'content_type', 80);
        if ($fields) {
            $fields['status'] = 'draft';
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            $stmt = Database::connection()->prepare("UPDATE content_items SET {$set} WHERE id=? AND organization_id=?");
            $stmt->execute([...array_values($fields), $id, $user['organization_id']]);
            Audit::log($user, 'content.updated', 'content_item', $id);
        }
        Http::json(['data' => self::find($id, $user)]);
    }

    public static function submit(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        self::transition($id, $user, ['draft', 'rejected'], 'in_review', null);
    }

    public static function approve(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $note = Http::optionalString(self::optionalBody(), 'note', 1000);
        self::transition($id, $user, ['in_review'], 'approved', $note);
    }

    public static function reject(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $note = Http::requiredString(self::optionalBody(), 'note', 1000);
        self::transition($id, $user, ['in_review'], 'rejected', $note);
    }

    /** Avvia la generazione AI in background: risponde subito con il lavoro da seguire. */
    public static function generate(int $projectId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('projects', $projectId, $user['organization_id'], 'id');
        $data = Http::body();
        $variants = Http::optionalInt($data, 'variants') ?? 3;
        if ($variants < 1 || $variants > 5) Http::validationError('Le varianti devono essere tra 1 e 5.');
        $payload = [
            'project_id' => $projectId,
            'channel' => Http::oneOf($data, 'channel', self::CHANNELS),
            'content_type' => Http::optionalString($data, 'content_type', 80) ?? 'post',
            'brief' => Http::requiredString($data, 'brief', 2000),
            'variants' => $variants,
        ];
        $job = Jobs::enqueue($user, 'generate_content', $payload);
        Audit::log($user, 'content.generation_requested', 'job', (int)$job['id']);
        Http::json(['data' => $job], 202);
    }

    private static function transition(int $id, array $user, array $from, string $to, ?string $note): never
    {
        $item = self::find($id, $user);
        if (!in_array($item['status'], $from, true)) {
            Http::json(['error' => 'invalid_state', 'message' => "Operazione non consentita per un contenuto in stato '{$item['status']}'."], 409);
        }
        $isReview = in_array($to, ['approved', 'rejected'], true);
        $stmt = Database::connection()->prepare('UPDATE content_items SET status=?, review_note=?, reviewed_by=?, reviewed_at=' . ($isReview ? 'UTC_TIMESTAMP()' : 'NULL') . ' WHERE id=? AND organization_id=?');
        $stmt->execute([$to, $isReview ? $note : null, $isReview ? $user['id'] : null, $id, $user['organization_id']]);
        Audit::log($user, "content.{$to}", 'content_item', $id);
        Http::json(['data' => self::find($id, $user)]);
    }

    private static function find(int $id, array $user): array
    {
        return Tenant::findOrFail('content_items', $id, $user['organization_id'], self::COLUMNS);
    }

    private static function optionalBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        return trim($raw) === '' ? [] : Http::body();
    }
}
