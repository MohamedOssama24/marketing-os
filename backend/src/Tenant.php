<?php
declare(strict_types=1);

/**
 * Accesso alle righe di un'organizzazione. Ogni lettura per id passa da qui, così una risorsa
 * di un'altra organizzazione risponde 404 esattamente come una inesistente.
 */
final class Tenant
{
    private const TABLES = ['brand_profiles', 'projects', 'content_items', 'assets', 'jobs', 'users', 'ad_spend', 'invoices', 'prospects', 'style_examples', 'time_entries', 'review_replies', 'client_requests', 'competitors'];

    public static function find(string $table, int $id, int $organizationId, string $columns = '*'): ?array
    {
        if (!in_array($table, self::TABLES, true)) throw new InvalidArgumentException("Tabella non ammessa: {$table}");
        $stmt = Database::connection()->prepare("SELECT {$columns} FROM {$table} WHERE id=? AND organization_id=? LIMIT 1");
        $stmt->execute([$id, $organizationId]);
        return $stmt->fetch() ?: null;
    }

    public static function findOrFail(string $table, int $id, int $organizationId, string $columns = '*'): array
    {
        return self::find($table, $id, $organizationId, $columns)
            ?? Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404);
    }
}
