<?php
declare(strict_types=1);

/** Lettura approfondita del sito del cliente (contatti, strumenti, controlli) e diagnosi strategica dell'AI. */
final class SiteAnalysisController
{
    public static function show(int $brandId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        Http::json(['data' => self::state($brandId, $user['organization_id'])]);
    }

    public static function start(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $brand = Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id, website_url');
        if (trim((string)$brand['website_url']) === '') Http::validationError('Aggiungi l\'indirizzo del sito nel brand kit.');
        $current = self::row($brandId, $user['organization_id']);
        if ($current && in_array($current['job_status'], ['queued', 'running'], true)) Http::json(['data' => self::state($brandId, $user['organization_id'])], 202);
        $job = Jobs::enqueue($user, 'site_analysis', ['brand_id' => $brandId]);
        Database::connection()->prepare('INSERT INTO site_analyses (organization_id, brand_profile_id, job_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id)')
            ->execute([$user['organization_id'], $brandId, $job['id']]);
        Audit::log($user, 'site_analysis.started', 'brand_profile', $brandId);
        Http::json(['data' => self::state($brandId, $user['organization_id'])], 202);
    }

    private static function row(int $brandId, int $orgId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT s.*, j.status AS job_status, j.error AS job_error FROM site_analyses s LEFT JOIN jobs j ON j.id=s.job_id
            WHERE s.organization_id=? AND s.brand_profile_id=? LIMIT 1');
        $stmt->execute([$orgId, $brandId]);
        return $stmt->fetch() ?: null;
    }

    private static function state(int $brandId, int $orgId): array
    {
        $row = self::row($brandId, $orgId);
        return [
            'profile' => $row && $row['profile'] ? json_decode($row['profile'], true) : null,
            'analysis' => $row && $row['analysis'] ? json_decode($row['analysis'], true) : null,
            'profile_at' => $row['profile_at'] ?? null,
            'analyzed_at' => $row['analyzed_at'] ?? null,
            'job_id' => $row && $row['job_id'] ? (int)$row['job_id'] : null,
            'analyzing' => $row && in_array($row['job_status'], ['queued', 'running'], true),
            'job_error' => $row && $row['job_status'] === 'failed' ? $row['job_error'] : null,
        ];
    }
}
