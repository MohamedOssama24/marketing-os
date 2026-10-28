<?php
declare(strict_types=1);

final class ProjectController
{
    public const COLUMNS = 'id, name, objective, target_audience, budget_monthly, status, strategy, brand_profile_id, created_at, updated_at';
    private const STATUSES = ['draft', 'active', 'paused', 'completed', 'archived'];

    /** Elenco con il conteggio dei contenuti per stato, per le schede delle campagne. */
    public static function index(): never
    {
        $user = Auth::user();
        $columns = implode(', ', array_map(fn($c) => 'p.' . trim($c), explode(',', self::COLUMNS)));
        $stmt = Database::connection()->prepare("SELECT {$columns},
                COUNT(c.id) AS content_total,
                COALESCE(SUM(c.status='approved'), 0) AS content_approved,
                COALESCE(SUM(c.status='in_review'), 0) AS content_in_review
            FROM projects p LEFT JOIN content_items c ON c.project_id=p.id AND c.organization_id=p.organization_id
            WHERE p.organization_id=? GROUP BY p.id ORDER BY p.created_at DESC LIMIT 100");
        $stmt->execute([$user['organization_id']]);
        Http::json(['data' => array_map([self::class, 'present'], $stmt->fetchAll())]);
    }

    public static function show(int $id): never
    {
        $user = Auth::user();
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    /** Elimina la campagna con tutti i suoi contenuti. */
    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        Tenant::findOrFail('projects', $id, $user['organization_id'], 'id');
        self::deleteWithContent([$id], $user['organization_id']);
        Audit::log($user, 'project.deleted', 'project', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** I contenuti sono collegati con ON DELETE CASCADE, ma li elimino esplicitamente per non dipendere dallo schema. */
    public static function deleteWithContent(array $projectIds, int $organizationId): void
    {
        if (!$projectIds) return;
        $marks = implode(',', array_fill(0, count($projectIds), '?'));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM content_items WHERE organization_id=? AND project_id IN ({$marks})")->execute([$organizationId, ...$projectIds]);
            $pdo->prepare("DELETE FROM projects WHERE organization_id=? AND id IN ({$marks})")->execute([$organizationId, ...$projectIds]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function find(int $id, int $organizationId): array
    {
        return self::present(Tenant::findOrFail('projects', $id, $organizationId, self::COLUMNS));
    }

    private static function present(array $project): array
    {
        $project['strategy'] = $project['strategy'] === null ? null : json_decode($project['strategy'], true);
        return $project;
    }

    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        $name = Http::requiredString($data, 'name', 180);
        $objective = Http::requiredString($data, 'objective', 500);
        $audience = Http::optionalString($data, 'target_audience', 2000);
        $budget = self::budget($data);
        $brandId = self::brandId($data, $user);

        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO projects (organization_id, brand_profile_id, created_by, name, objective, target_audience, budget_monthly) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$user['organization_id'], $brandId, $user['id'], $name, $objective, $audience, $budget]);
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'project.created', 'project', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])], 201);
    }

    public static function update(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('projects', $id, $user['organization_id'], 'id');
        $data = Http::body();
        $fields = [];
        if (array_key_exists('name', $data)) $fields['name'] = Http::requiredString($data, 'name', 180);
        if (array_key_exists('objective', $data)) $fields['objective'] = Http::requiredString($data, 'objective', 500);
        if (array_key_exists('target_audience', $data)) $fields['target_audience'] = Http::optionalString($data, 'target_audience', 2000);
        if (array_key_exists('budget_monthly', $data)) $fields['budget_monthly'] = self::budget($data);
        if (array_key_exists('status', $data)) $fields['status'] = Http::oneOf($data, 'status', self::STATUSES);
        if (array_key_exists('brand_profile_id', $data)) $fields['brand_profile_id'] = self::brandId($data, $user);

        if ($fields) {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            $stmt = Database::connection()->prepare("UPDATE projects SET {$set} WHERE id=? AND organization_id=?");
            $stmt->execute([...array_values($fields), $id, $user['organization_id']]);
            Audit::log($user, 'project.updated', 'project', $id);
        }
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    private static function budget(array $data): ?string
    {
        $budget = $data['budget_monthly'] ?? null;
        if ($budget === null || $budget === '') return null;
        if (!is_numeric($budget) || (float)$budget < 0 || (float)$budget > 10000000) Http::validationError('budget_monthly deve essere un numero non negativo valido.');
        return number_format((float)$budget, 2, '.', '');
    }

    /** Il brand indicato deve appartenere all'organizzazione dell'utente. */
    private static function brandId(array $data, array $user): ?int
    {
        $brandId = Http::optionalInt($data, 'brand_profile_id');
        if ($brandId !== null && !Tenant::find('brand_profiles', $brandId, $user['organization_id'], 'id')) {
            Http::json(['error' => 'invalid_brand', 'message' => 'Il brand non esiste nella tua organizzazione.'], 422);
        }
        return $brandId;
    }
}
