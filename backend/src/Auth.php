<?php
declare(strict_types=1);

final class Auth
{
    public const MANAGERS = ['owner', 'admin'];
    public const EDITORS = ['owner', 'admin', 'editor'];

    public static function user(): array
    {
        $token = Http::bearerToken();
        if (!$token) Http::json(['error' => 'unauthorized', 'message' => 'Token Bearer mancante.'], 401);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT u.id, u.organization_id, u.name, u.email, u.role, u.is_platform_admin, o.status AS organization_status, t.id AS token_id
            FROM api_tokens t JOIN users u ON u.id=t.user_id JOIN organizations o ON o.id=u.organization_id
            WHERE t.token_hash=? AND t.expires_at > UTC_TIMESTAMP() LIMIT 1');
        $stmt->execute([hash('sha256', $token)]);
        $user = $stmt->fetch();
        if (!$user) Http::json(['error' => 'unauthorized', 'message' => 'Token non valido o scaduto.'], 401);
        if ($user['organization_status'] === 'suspended') self::suspended();
        $user['id'] = (int)$user['id'];
        $user['organization_id'] = (int)$user['organization_id'];
        $user['is_platform_admin'] = (bool)$user['is_platform_admin'];
        unset($user['organization_status']);
        return $user;
    }

    /** Amministratore della piattaforma (gestisce tutte le organizzazioni), altrimenti 403. */
    public static function platformAdmin(): array
    {
        $user = self::user();
        if (!$user['is_platform_admin']) Http::json(['error' => 'forbidden', 'message' => 'Area riservata agli amministratori della piattaforma.'], 403);
        return $user;
    }

    public static function suspended(): never
    {
        Http::json(['error' => 'account_suspended', 'message' => 'Questo account è sospeso. Contatta l\'assistenza.'], 403);
    }

    /** Utente autenticato con uno dei ruoli indicati, altrimenti 403. */
    public static function userWithRole(array $roles): array
    {
        $user = self::user();
        if (!in_array($user['role'], $roles, true)) {
            Http::json(['error' => 'forbidden', 'message' => 'Il tuo ruolo non consente questa operazione.'], 403);
        }
        return $user;
    }

    public static function issueToken(int $userId): array
    {
        $plain = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hours = max(1, min(168, (int)(getenv('TOKEN_TTL_HOURS') ?: 24)));
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$hours} hours")->format('Y-m-d H:i:s');
        $stmt = Database::connection()->prepare('INSERT INTO api_tokens (user_id, token_hash, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$userId, hash('sha256', $plain), $expires]);
        return ['access_token' => $plain, 'token_type' => 'Bearer', 'expires_at' => $expires . ' UTC'];
    }
}
