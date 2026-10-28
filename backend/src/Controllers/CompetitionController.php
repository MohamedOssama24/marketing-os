<?php
declare(strict_types=1);

/** Confronto tra il cliente e i concorrenti seguiti, con una proposta di stile che lo distingue e si può applicare al brand kit. */
final class CompetitionController
{
    /** Parti della proposta che si possono applicare al brand kit => colonne del cliente. */
    private const APPLY = [
        'colors' => ['primary_color', 'secondary_color'],
        'font' => ['font_family'],
        'tone' => ['tone_of_voice'],
    ];

    public static function show(int $brandId): never
    {
        $user = Auth::user();
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        Http::json(['data' => self::state($brandId, $user['organization_id'])]);
    }

    public static function start(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM competitors WHERE organization_id=? AND brand_profile_id=? AND analysis IS NOT NULL');
        $stmt->execute([$user['organization_id'], $brandId]);
        if ((int)$stmt->fetchColumn() === 0) Http::validationError('Aggiungi almeno un concorrente e aspetta che la sua analisi sia pronta.');
        $job = Jobs::enqueue($user, 'competition_study', ['brand_id' => $brandId]);
        $pdo->prepare('INSERT INTO competition_studies (organization_id, brand_profile_id, job_id) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE job_id=VALUES(job_id)')
            ->execute([$user['organization_id'], $brandId, $job['id']]);
        Http::json(['data' => self::state($brandId, $user['organization_id'])], 202);
    }

    /** Copia nel brand kit le parti scelte della proposta, ricordando i valori di prima per poter tornare indietro. */
    public static function apply(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $brand = Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id, primary_color, secondary_color, font_family, tone_of_voice');
        $study = self::study($brandId, $user['organization_id']);
        $proposal = $study && $study['proposal'] ? json_decode($study['proposal'], true) : null;
        if (!$proposal) Http::validationError('Prima fai il confronto con i concorrenti.');
        $parts = Http::body()['parts'] ?? array_keys(self::APPLY);
        if (!is_array($parts)) Http::validationError('Scegli cosa applicare.');
        $columns = [];
        foreach ($parts as $part) {
            if (!is_string($part) || !isset(self::APPLY[$part])) Http::validationError('Scegli tra colori, carattere e tono.');
            $columns = [...$columns, ...self::APPLY[$part]];
        }
        $columns = array_values(array_unique($columns));
        $columns = array_values(array_filter($columns, fn($col) => trim((string)($proposal[$col] ?? '')) !== ''));
        if (!$columns) Http::validationError('Niente da applicare.');

        // Se la proposta era già applicata, si conservano i valori originali e non quelli della proposta.
        $previous = $study['applied_at'] && $study['previous_style'] ? json_decode($study['previous_style'], true) : [];
        foreach ($columns as $col) if (!array_key_exists($col, $previous)) $previous[$col] = $brand[$col];

        $pdo = Database::connection();
        $pdo->prepare('UPDATE brand_profiles SET ' . implode(', ', array_map(fn($col) => "{$col}=?", $columns)) . ' WHERE id=? AND organization_id=?')
            ->execute([...array_map(fn($col) => $proposal[$col], $columns), $brandId, $user['organization_id']]);
        $pdo->prepare('UPDATE competition_studies SET previous_style=?, applied_at=? WHERE organization_id=? AND brand_profile_id=?')
            ->execute([json_encode($previous, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $user['organization_id'], $brandId]);
        Audit::log($user, 'competition.style_applied', 'brand_profile', $brandId);
        Http::json(['data' => self::state($brandId, $user['organization_id'])]);
    }

    /** Rimette nel brand kit i valori di prima dell'applicazione. */
    public static function undo(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $study = self::study($brandId, $user['organization_id']);
        $previous = $study && $study['applied_at'] && $study['previous_style'] ? json_decode($study['previous_style'], true) : null;
        if (!$previous) Http::validationError('Non c\'è nessuno stile da annullare.');
        $columns = array_values(array_intersect(array_keys($previous), array_merge(...array_values(self::APPLY))));
        $pdo = Database::connection();
        if ($columns) {
            $pdo->prepare('UPDATE brand_profiles SET ' . implode(', ', array_map(fn($col) => "{$col}=?", $columns)) . ' WHERE id=? AND organization_id=?')
                ->execute([...array_map(fn($col) => $previous[$col], $columns), $brandId, $user['organization_id']]);
        }
        $pdo->prepare('UPDATE competition_studies SET previous_style=NULL, applied_at=NULL WHERE organization_id=? AND brand_profile_id=?')
            ->execute([$user['organization_id'], $brandId]);
        Audit::log($user, 'competition.style_undone', 'brand_profile', $brandId);
        Http::json(['data' => self::state($brandId, $user['organization_id'])]);
    }

    private static function study(int $brandId, int $orgId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT s.*, j.status AS job_status, j.error AS job_error FROM competition_studies s LEFT JOIN jobs j ON j.id=s.job_id
            WHERE s.organization_id=? AND s.brand_profile_id=? LIMIT 1');
        $stmt->execute([$orgId, $brandId]);
        return $stmt->fetch() ?: null;
    }

    private static function state(int $brandId, int $orgId): array
    {
        $stmt = Database::connection()->prepare('SELECT id, name, analysis FROM competitors WHERE organization_id=? AND brand_profile_id=? AND analysis IS NOT NULL ORDER BY id');
        $stmt->execute([$orgId, $brandId]);
        $competitors = [];
        foreach ($stmt->fetchAll() as $row) {
            $a = json_decode($row['analysis'], true);
            // Le analisi fatte prima del confronto non hanno controlli: si aggiornano alla prossima rilettura.
            if (!isset($a['checks'])) continue;
            $competitors[] = ['id' => (int)$row['id'], 'name' => $row['name'], 'score' => (int)$a['score'], 'checks' => $a['checks'], 'colors' => $a['colors'] ?? [], 'fonts' => $a['fonts'] ?? []];
        }
        $study = self::study($brandId, $orgId);
        $client = $study && $study['client_site'] ? json_decode($study['client_site'], true) : null;
        $comparison = Competition::compare($client['checks'] ?? null, $competitors);
        return [
            'client' => $client,
            'competitors' => array_map(fn($c) => array_diff_key($c, ['checks' => true]), $competitors),
            'rows' => $comparison['rows'],
            'wins' => $comparison['wins'],
            'losses' => $comparison['losses'],
            'market' => $study['market'] ?? null,
            'gaps' => $study && $study['gaps'] ? json_decode($study['gaps'], true) : [],
            'proposal' => $study && $study['proposal'] ? json_decode($study['proposal'], true) : null,
            'previous_style' => $study && $study['applied_at'] && $study['previous_style'] ? json_decode($study['previous_style'], true) : null,
            'applied_at' => $study['applied_at'] ?? null,
            'updated_at' => $study['updated_at'] ?? null,
            'job_id' => $study && $study['job_id'] ? (int)$study['job_id'] : null,
            'analyzing' => $study && in_array($study['job_status'], ['queued', 'running'], true),
            'job_error' => $study && $study['job_status'] === 'failed' ? $study['job_error'] : null,
        ];
    }
}
