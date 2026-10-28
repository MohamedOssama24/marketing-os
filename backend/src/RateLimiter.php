<?php
declare(strict_types=1);

/** Limite a finestra mobile sui tentativi di login e registrazione, salvato in MySQL. */
final class RateLimiter
{
    public static function check(string $key, int $max, int $windowSeconds): void
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM auth_attempts WHERE key_hash=? AND created_at > UTC_TIMESTAMP() - INTERVAL ? SECOND');
        $stmt->execute([hash('sha256', $key), $windowSeconds]);
        if ((int)$stmt->fetchColumn() >= $max) {
            header('Retry-After: ' . $windowSeconds);
            Http::json(['error' => 'too_many_attempts', 'message' => 'Troppi tentativi. Riprova tra qualche minuto.'], 429);
        }
    }

    public static function record(string $key): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO auth_attempts (key_hash, created_at) VALUES (?, UTC_TIMESTAMP())');
        $stmt->execute([hash('sha256', $key)]);
    }

    public static function clear(string $key): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM auth_attempts WHERE key_hash=?');
        $stmt->execute([hash('sha256', $key)]);
    }
}
