<?php
declare(strict_types=1);

/** Il servizio AI non risponde o ha finito le richieste: si può riprovare con il provider di riserva. */
class ProviderUnavailableException extends GenerationException {}

/** Raggiunto il limite giornaliero di richieste AI impostato nel .env. */
final class AiQuotaException extends GenerationException {}

/**
 * Limiti giornalieri di richieste AI (AI_DAILY_LIMIT in totale, AI_DAILY_LIMIT_PER_CLIENT per cliente; 0 o vuoto = nessun limite)
 * e cache delle risposte. Il totale vale per tutta l'installazione, perché la quota gratuita è quella della chiave API.
 * Il worker indica per quale lavoro (e cliente) sono le richieste che seguono.
 */
final class AiUsage
{
    private const CACHE_DAYS = 30;

    private static ?int $organizationId = null;
    private static ?int $brandId = null;
    private static bool $automatic = false;

    /** Le richieste che seguono sono per questo lavoro; i lavori automatici possono usare le risposte salvate. */
    public static function forJob(?int $organizationId, bool $automatic): void
    {
        self::$organizationId = $organizationId;
        self::$brandId = null;
        self::$automatic = $automatic;
    }

    public static function forBrand(?int $brandId): void
    {
        self::$brandId = $brandId;
    }

    /** Risposta salvata per le stesse istruzioni, solo per i lavori automatici: chi chiede di generare vuole un testo nuovo. */
    public static function cached(string $key): ?array
    {
        if (!self::$automatic) return null;
        $stmt = Database::connection()->prepare('SELECT response FROM ai_cache WHERE cache_key=? AND created_at >= ?');
        $stmt->execute([$key, gmdate('Y-m-d H:i:s', strtotime('-' . self::CACHE_DAYS . ' days'))]);
        $response = $stmt->fetchColumn();
        return $response === false ? null : json_decode($response, true);
    }

    public static function remember(string $key, array $response): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO ai_cache (cache_key, response, created_at) VALUES (?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE response=VALUES(response), created_at=VALUES(created_at)')
            ->execute([$key, json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
        $pdo->prepare('DELETE FROM ai_cache WHERE created_at < ?')->execute([gmdate('Y-m-d H:i:s', strtotime('-' . self::CACHE_DAYS . ' days'))]);
    }

    public static function key(string $system, string $prompt, array $schema): string
    {
        return hash('sha256', json_encode([$system, $prompt, $schema], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /** Ferma la richiesta se oggi (giorno UTC) è già stato raggiunto un limite. */
    public static function check(): void
    {
        $usage = self::today();
        $limit = self::limit('AI_DAILY_LIMIT');
        if ($limit > 0 && $usage['total'] >= $limit) {
            throw new AiQuotaException("Raggiunto il limite di {$limit} richieste AI per oggi. Riprova domani oppure alza AI_DAILY_LIMIT.");
        }
        $perClient = self::limit('AI_DAILY_LIMIT_PER_CLIENT');
        if ($perClient > 0 && $usage['client'] !== null && $usage['client'] >= $perClient) {
            throw new AiQuotaException("Raggiunto il limite di {$perClient} richieste AI per oggi per questo cliente. Riprova domani oppure alza AI_DAILY_LIMIT_PER_CLIENT.");
        }
    }

    public static function record(string $provider): void
    {
        Database::connection()->prepare('INSERT INTO ai_usage (organization_id, brand_profile_id, provider, automatic, created_at) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([self::$organizationId, self::$brandId, $provider, (int)self::$automatic]);
    }

    /** @return array{total: int, client: ?int} richieste di oggi in totale e per il cliente del lavoro in corso */
    public static function today(): array
    {
        $pdo = Database::connection();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM ai_usage WHERE created_at >= UTC_DATE()')->fetchColumn();
        $client = null;
        if (self::$brandId !== null) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM ai_usage WHERE brand_profile_id=? AND created_at >= UTC_DATE()');
            $stmt->execute([self::$brandId]);
            $client = (int)$stmt->fetchColumn();
        }
        return ['total' => $total, 'client' => $client];
    }

    private static function limit(string $name): int
    {
        return max(0, (int)(getenv($name) ?: 0));
    }
}
