<?php
declare(strict_types=1);

/**
 * Ore lavorate per cliente e redditività: quanto incassa l'agenzia da ogni cliente, quanto le costa in ore
 * e il guadagno orario effettivo. Le spese pubblicitarie rifatturate non sono un ricavo, quindi non contano.
 */
final class ProfitController
{
    public static function entries(): never
    {
        $user = Auth::user();
        $period = self::period($_GET['period'] ?? null);
        $params = [$user['organization_id'], "{$period}-01", self::lastDay($period)];
        $brandFilter = '';
        if (($brandId = filter_var($_GET['brand_id'] ?? null, FILTER_VALIDATE_INT)) !== false && $brandId !== null) {
            $brandFilter = ' AND t.brand_profile_id=?';
            $params[] = $brandId;
        }
        $stmt = Database::connection()->prepare("SELECT t.id, t.brand_profile_id, b.name AS brand_name, t.user_id, u.name AS user_name, t.worked_on, t.minutes, t.note, t.created_at
            FROM time_entries t JOIN brand_profiles b ON b.id=t.brand_profile_id AND b.organization_id=t.organization_id LEFT JOIN users u ON u.id=t.user_id
            WHERE t.organization_id=? AND t.worked_on BETWEEN ? AND ?{$brandFilter} ORDER BY t.worked_on DESC, t.id DESC LIMIT 500");
        $stmt->execute($params);
        Http::json(['data' => array_map(fn($r) => ['minutes' => (int)$r['minutes']] + $r, $stmt->fetchAll())]);
    }

    public static function storeEntry(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        $brandId = Http::optionalInt($data, 'brand_id') ?? Http::validationError('Scegli il cliente.');
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $minutes = Http::optionalInt($data, 'minutes') ?? 0;
        if ($minutes < 1 || $minutes > 24 * 60) Http::validationError('Il tempo deve essere tra 1 minuto e 24 ore.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($data['worked_on'] ?? date('Y-m-d')));
        if (!$date || $date > new DateTimeImmutable('tomorrow')) Http::validationError('Data non valida.');
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO time_entries (organization_id, brand_profile_id, user_id, worked_on, minutes, note) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$user['organization_id'], $brandId, $user['id'], $date->format('Y-m-d'), $minutes, Http::optionalString($data, 'note', 300)]);
        $id = (int)$pdo->lastInsertId();
        Audit::log($user, 'time_entry.created', 'time_entry', $id);
        Http::json(['data' => ['id' => $id]], 201);
    }

    /** Ognuno cancella le proprie ore; titolari e amministratori anche quelle degli altri. */
    public static function destroyEntry(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $entry = Tenant::findOrFail('time_entries', $id, $user['organization_id'], 'id, user_id');
        if ((int)$entry['user_id'] !== $user['id'] && !in_array($user['role'], Auth::MANAGERS, true)) {
            Http::json(['error' => 'forbidden', 'message' => 'Puoi eliminare solo le tue ore.'], 403);
        }
        Database::connection()->prepare('DELETE FROM time_entries WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Audit::log($user, 'time_entry.deleted', 'time_entry', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** Ricavi, ore, costo e margine per cliente nel mese. */
    public static function summary(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $period = self::period($_GET['period'] ?? null);
        Http::json(['data' => self::build($user['organization_id'], $period)]);
    }

    /** Costo orario medio dell'agenzia (stipendi, collaboratori e spese generali divisi per le ore lavorate). */
    public static function updateSettings(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $cost = AdSpendController::amount(Http::body(), 'hourly_cost', false);
        if ($cost !== null && $cost > 10000) Http::validationError('Costo orario non valido.');
        Database::connection()->prepare('UPDATE organizations SET hourly_cost=? WHERE id=?')->execute([$cost, $user['organization_id']]);
        Audit::log($user, 'organization.hourly_cost_updated', 'organization', $user['organization_id']);
        Http::json(['data' => self::build($user['organization_id'], self::period($_GET['period'] ?? null))]);
    }

    public static function build(int $orgId, string $period): array
    {
        $pdo = Database::connection();
        $start = "{$period}-01";
        $end = self::lastDay($period);
        $stmt = $pdo->prepare('SELECT hourly_cost FROM organizations WHERE id=?');
        $stmt->execute([$orgId]);
        $hourlyCost = ($c = $stmt->fetchColumn()) === null ? null : (float)$c;

        $stmt = $pdo->prepare('SELECT id, name, primary_color, logo_asset_id, monthly_fee, ad_fee_percent FROM brand_profiles WHERE organization_id=? ORDER BY name');
        $stmt->execute([$orgId]);
        $brands = $stmt->fetchAll();

        $minutes = self::column($pdo, 'SELECT brand_profile_id, SUM(minutes) FROM time_entries WHERE organization_id=? AND worked_on BETWEEN ? AND ? GROUP BY brand_profile_id', [$orgId, $start, $end]);
        // Ricavo dell'agenzia dalla fattura del mese: canone, commissione e altre voci, senza le sponsorizzate rifatturate.
        $invoiced = self::column($pdo, "SELECT i.brand_profile_id, SUM(l.amount) FROM invoices i JOIN invoice_lines l ON l.invoice_id=i.id
            WHERE i.organization_id=? AND i.period=? AND l.kind<>'ad_spend' GROUP BY i.brand_profile_id", [$orgId, $period]);
        $spent = self::column($pdo, 'SELECT brand_profile_id, SUM(spent) FROM ad_spend WHERE organization_id=? AND period_end BETWEEN ? AND ? GROUP BY brand_profile_id', [$orgId, $start, $end]);

        $clients = [];
        foreach ($brands as $b) {
            $id = (int)$b['id'];
            $estimated = !isset($invoiced[$id]);
            // Senza fattura del mese, si stima da canone e commissione impostati nella scheda di fatturazione.
            $revenue = $estimated ? (float)$b['monthly_fee'] + (float)($spent[$id] ?? 0) * (float)$b['ad_fee_percent'] / 100 : (float)$invoiced[$id];
            $mins = (int)($minutes[$id] ?? 0);
            if ($mins === 0 && $revenue == 0) continue;
            $cost = $hourlyCost === null ? null : round($mins / 60 * $hourlyCost, 2);
            $clients[] = [
                'id' => $id, 'name' => $b['name'], 'primary_color' => $b['primary_color'], 'logo_asset_id' => $b['logo_asset_id'] === null ? null : (int)$b['logo_asset_id'],
                'minutes' => $mins, 'revenue' => round($revenue, 2), 'revenue_estimated' => $estimated,
                'cost' => $cost, 'margin' => $cost === null ? null : round($revenue - $cost, 2),
                'margin_percent' => $cost === null || $revenue <= 0 ? null : round(($revenue - $cost) / $revenue * 100, 1),
                'effective_rate' => $mins > 0 ? round($revenue / ($mins / 60), 2) : null,
            ];
        }
        // Prima i clienti che rendono meno: sono quelli su cui intervenire.
        usort($clients, fn($a, $b) => ($a['margin_percent'] ?? $a['effective_rate'] ?? INF) <=> ($b['margin_percent'] ?? $b['effective_rate'] ?? INF));

        $totalMinutes = array_sum(array_column($clients, 'minutes'));
        $totalRevenue = round(array_sum(array_column($clients, 'revenue')), 2);
        $totalCost = $hourlyCost === null ? null : round($totalMinutes / 60 * $hourlyCost, 2);
        return [
            'period' => $period, 'hourly_cost' => $hourlyCost, 'clients' => $clients,
            'totals' => [
                'minutes' => $totalMinutes, 'revenue' => $totalRevenue, 'cost' => $totalCost,
                'margin' => $totalCost === null ? null : round($totalRevenue - $totalCost, 2),
                'effective_rate' => $totalMinutes > 0 ? round($totalRevenue / ($totalMinutes / 60), 2) : null,
            ],
        ];
    }

    private static function column(PDO $pdo, string $sql, array $params): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private static function period(mixed $value): string
    {
        if ($value === null || $value === '') return date('Y-m');
        if (!is_string($value) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) Http::validationError('Mese non valido (formato AAAA-MM).');
        return $value;
    }

    private static function lastDay(string $period): string
    {
        return (new DateTimeImmutable("{$period}-01"))->format('Y-m-t');
    }
}
