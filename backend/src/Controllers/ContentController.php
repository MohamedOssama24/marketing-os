<?php
declare(strict_types=1);

final class ContentController
{
    public const CHANNELS = MarketingAi::CHANNELS;
    public const COLUMNS = 'id, project_id, channel, content_type, title, body, visual, status, ai_generated, job_id, review_note, reviewed_by, reviewed_at, scheduled_at, created_at, updated_at';

    public static function index(int $projectId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('projects', $projectId, $user['organization_id'], 'id');
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM content_items WHERE project_id=? AND organization_id=? ORDER BY scheduled_at IS NULL, scheduled_at, created_at DESC, id DESC LIMIT 300');
        $stmt->execute([$projectId, $user['organization_id']]);
        Http::json(['data' => array_map([self::class, 'present'], $stmt->fetchAll())]);
    }

    /** Contenuti in attesa di approvazione in tutta l'organizzazione, con progetto e brand. */
    public static function pendingApproval(): never
    {
        $user = Auth::user();
        $columns = implode(', ', array_map(fn($c) => 'c.' . trim($c), explode(',', self::COLUMNS)));
        $stmt = Database::connection()->prepare("SELECT {$columns}, p.name AS project_name, p.brand_profile_id FROM content_items c JOIN projects p ON p.id=c.project_id AND p.organization_id=c.organization_id WHERE c.organization_id=? AND c.status='in_review' ORDER BY c.scheduled_at IS NULL, c.scheduled_at, c.updated_at LIMIT 200");
        $stmt->execute([$user['organization_id']]);
        Http::json(['data' => array_map([self::class, 'present'], $stmt->fetchAll())]);
    }

    public static function show(int $id): never
    {
        $user = Auth::user();
        Http::json(['data' => self::find($id, $user)]);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $item = self::find($id, $user);
        if ($item['status'] === 'published') Http::json(['error' => 'invalid_state', 'message' => 'Un contenuto pubblicato non si può eliminare.'], 409);
        Database::connection()->prepare('DELETE FROM content_items WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Audit::log($user, 'content.deleted', 'content_item', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** Fa riscrivere il post all'AI seguendo un'istruzione. Solo bozze o contenuti rifiutati. */
    public static function rewrite(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $item = self::find($id, $user);
        if (!in_array($item['status'], ['draft', 'rejected'], true)) {
            Http::json(['error' => 'invalid_state', 'message' => 'Si possono riscrivere solo bozze o contenuti rifiutati.'], 409);
        }
        $instruction = Http::requiredString(Http::body(), 'instruction', 300);
        $job = Jobs::enqueue($user, 'rewrite_content', ['content_id' => $id, 'instruction' => $instruction]);
        Http::json(['data' => $job], 202);
    }

    /** Sposta un contenuto nel calendario. I contenuti pubblicati non si spostano. */
    public static function schedule(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $item = self::find($id, $user);
        if ($item['status'] === 'published') Http::json(['error' => 'invalid_state', 'message' => 'Un contenuto pubblicato non si può spostare.'], 409);
        $data = Http::body();
        $when = null;
        if (($data['scheduled_at'] ?? null) !== null) {
            $date = is_string($data['scheduled_at']) ? DateTimeImmutable::createFromFormat('!Y-m-d H:i', str_replace('T', ' ', substr($data['scheduled_at'], 0, 16))) : false;
            if (!$date) Http::validationError("Campo 'scheduled_at' deve essere nel formato AAAA-MM-GG HH:MM.");
            $when = $date->format('Y-m-d H:i:s');
        }
        $stmt = Database::connection()->prepare('UPDATE content_items SET scheduled_at=? WHERE id=? AND organization_id=?');
        $stmt->execute([$when, $id, $user['organization_id']]);
        Audit::log($user, 'content.scheduled', 'content_item', $id);
        Http::json(['data' => self::find($id, $user)]);
    }

    public static function present(array $item): array
    {
        $item['visual'] = $item['visual'] === null ? null : json_decode($item['visual'], true);
        return $item;
    }

    /**
     * Valida la grafica di un post: testi brevi, layout ammesso e, se indicata, una foto della libreria.
     * Usata anche dal worker, quindi su dati non validi tronca invece di rispondere 422 quando $strict è false.
     */
    public static function visual(mixed $input, int $organizationId, bool $strict = true): ?array
    {
        if ($input === null) return null;
        if (!is_array($input)) {
            if ($strict) Http::validationError("Campo 'visual' non valido.");
            return null;
        }
        $limits = ['headline' => 80, 'subheadline' => 140, 'badge' => 24];
        $visual = [];
        foreach ($limits as $key => $max) {
            $value = is_scalar($input[$key] ?? null) ? trim((string)$input[$key]) : '';
            if ($strict && mb_strlen($value) > $max) Http::validationError("Campo 'visual.{$key}' troppo lungo (massimo {$max} caratteri).");
            $visual[$key] = mb_substr($value, 0, $max);
        }
        $layout = $input['layout'] ?? 'bold';
        if (!in_array($layout, MarketingAi::LAYOUTS, true)) {
            if ($strict) Http::validationError("Campo 'visual.layout' non valido.");
            $layout = 'bold';
        }
        $visual['layout'] = $layout;
        $assetId = isset($input['asset_id']) && filter_var($input['asset_id'], FILTER_VALIDATE_INT) !== false ? (int)$input['asset_id'] : null;
        if ($assetId !== null) {
            $asset = Tenant::find('assets', $assetId, $organizationId, 'kind');
            if (!$asset || $asset['kind'] !== 'image') {
                if ($strict) Http::validationError('La foto deve essere un\'immagine della tua libreria file.');
                $assetId = null;
            }
        }
        $visual['asset_id'] = $assetId;
        return $visual;
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
        if (array_key_exists('visual', $data)) {
            $visual = self::visual($data['visual'], $user['organization_id']);
            $fields['visual'] = $visual === null ? null : json_encode($visual, JSON_UNESCAPED_UNICODE);
        }
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
        return self::present(Tenant::findOrFail('content_items', $id, $user['organization_id'], self::COLUMNS));
    }

    private static function optionalBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        return trim($raw) === '' ? [] : Http::body();
    }
}
