<?php
declare(strict_types=1);

final class Audit
{
    public static function log(array $user, string $action, string $entityType, ?int $entityId = null): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO audit_logs (organization_id, user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$user['organization_id'], $user['id'], $action, $entityType, $entityId]);
    }
}
