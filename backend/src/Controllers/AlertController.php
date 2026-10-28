<?php
declare(strict_types=1);

/**
 * Avvisi calcolati al momento sui dati dell'organizzazione: cose che rischiano di sfuggire
 * (approvazioni ferme, budget superati, report non aperti, settimane senza post, fatture scadute, richieste dei clienti).
 */
final class AlertController
{
    public static function index(): never
    {
        $user = Auth::user();
        Http::json(['data' => self::forUser($user)]);
    }

    /** @return list<array{key: string, severity: string, icon: string, title: string, detail: string, link: string, query: array}> */
    public static function forUser(array $user): array
    {
        $pdo = Database::connection();
        $org = $user['organization_id'];
        $now = new DateTimeImmutable();
        $alerts = [];
        $rows = function (string $sql, array $params) use ($pdo): array {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        };

        // Richieste arrivate dal portale del cliente.
        foreach ($rows("SELECT b.id, b.name, COUNT(*) AS n FROM client_requests r JOIN brand_profiles b ON b.id=r.brand_profile_id
            WHERE r.organization_id=? AND r.status='open' GROUP BY b.id, b.name", [$org]) as $r) {
            $alerts[] = self::alert("request-{$r['id']}", 'info', 'ti-message-circle', "{$r['name']}: " . ($r['n'] == 1 ? 'una nuova richiesta' : "{$r['n']} nuove richieste"),
                'Il cliente ha scritto dal portale.', "/clients/{$r['id']}", ['tab' => 'portal']);
        }

        // Post fermi in approvazione da più di 3 giorni.
        foreach ($rows("SELECT b.id, b.name, COUNT(*) AS n FROM content_items c JOIN projects p ON p.id=c.project_id JOIN brand_profiles b ON b.id=p.brand_profile_id
            WHERE c.organization_id=? AND c.status='in_review' AND c.updated_at < ? GROUP BY b.id, b.name", [$org, $now->modify('-3 days')->format('Y-m-d H:i:s')]) as $r) {
            $alerts[] = self::alert("review-{$r['id']}", 'warning', 'ti-hourglass', "{$r['name']}: " . ($r['n'] == 1 ? 'un post aspetta' : "{$r['n']} post aspettano") . ' l\'approvazione',
                'Da più di 3 giorni. Sollecita il cliente o manda il link del portale.', '/approvals', []);
        }

        // Post rifiutati dal cliente nel portale, da rivedere.
        foreach ($rows("SELECT b.id, b.name, COUNT(*) AS n FROM content_items c JOIN projects p ON p.id=c.project_id JOIN brand_profiles b ON b.id=p.brand_profile_id
            WHERE c.organization_id=? AND c.status='rejected' AND c.reviewed_via='portal' GROUP BY b.id, b.name", [$org]) as $r) {
            $alerts[] = self::alert("portal-rejected-{$r['id']}", 'warning', 'ti-message-2-exclamation', "{$r['name']}: il cliente chiede modifiche",
                ($r['n'] == 1 ? 'Un post' : "{$r['n']} post") . ' da rivedere con le sue note.', "/clients/{$r['id']}", []);
        }

        // Sponsorizzate del mese che hanno superato il budget.
        foreach ($rows('SELECT a.campaign_name, a.budget, a.spent, b.name FROM ad_spend a JOIN brand_profiles b ON b.id=a.brand_profile_id
            WHERE a.organization_id=? AND a.period_end >= ? AND a.budget > 0 AND a.spent > a.budget LIMIT 10', [$org, $now->format('Y-m-01')]) as $r) {
            $alerts[] = self::alert('budget-' . md5($r['name'] . $r['campaign_name']), 'danger', 'ti-coin-euro', "{$r['name']}: budget superato",
                sprintf('"%s" ha speso %s € su %s € previsti.', $r['campaign_name'], number_format((float)$r['spent'], 2, ',', '.'), number_format((float)$r['budget'], 2, ',', '.')), '/ads', []);
        }

        // Clienti con una campagna attiva ma niente in programma nei prossimi 7 giorni.
        foreach ($rows("SELECT b.id, b.name FROM brand_profiles b WHERE b.organization_id=?
            AND EXISTS (SELECT 1 FROM projects p WHERE p.brand_profile_id=b.id AND p.organization_id=b.organization_id AND p.status='active')
            AND NOT EXISTS (SELECT 1 FROM content_items c JOIN projects p ON p.id=c.project_id WHERE p.brand_profile_id=b.id AND c.organization_id=b.organization_id
                AND c.status<>'rejected' AND c.scheduled_at BETWEEN ? AND ?)", [$org, $now->format('Y-m-d H:i:s'), $now->modify('+7 days')->format('Y-m-d H:i:s')]) as $r) {
            $alerts[] = self::alert("empty-week-{$r['id']}", 'warning', 'ti-calendar-off', "{$r['name']}: nessun post nei prossimi 7 giorni",
                'La campagna è attiva ma il calendario è vuoto.', "/clients/{$r['id']}", []);
        }

        // Report condivisi da almeno 3 giorni e mai aperti.
        foreach ($rows('SELECT r.period, b.id, b.name FROM client_reports r JOIN brand_profiles b ON b.id=r.brand_profile_id
            WHERE r.organization_id=? AND r.share_token IS NOT NULL AND r.views=0 AND r.shared_at < ?', [$org, $now->modify('-3 days')->format('Y-m-d H:i:s')]) as $r) {
            $alerts[] = self::alert("report-{$r['id']}-{$r['period']}", 'info', 'ti-mail-opened', "{$r['name']}: il report non è stato aperto",
                'Condiviso da più di 3 giorni. Rimandalo o chiedi conferma al cliente.', '/reports', ['client' => (int)$r['id'], 'period' => $r['period']]);
        }

        // Fatture emesse e scadute (solo per chi gestisce la fatturazione).
        if (in_array($user['role'], Auth::MANAGERS, true)) {
            foreach ($rows("SELECT i.number, i.total, i.due_on, b.name FROM invoices i JOIN brand_profiles b ON b.id=i.brand_profile_id
                WHERE i.organization_id=? AND i.status='invoiced' AND i.due_on < ? ORDER BY i.due_on LIMIT 10", [$org, $now->format('Y-m-d')]) as $r) {
                $alerts[] = self::alert('invoice-' . md5($r['name'] . $r['number']), 'danger', 'ti-receipt', "{$r['name']}: fattura " . ($r['number'] ?: '') . ' scaduta',
                    sprintf('%s € da incassare, scaduta il %s.', number_format((float)$r['total'], 2, ',', '.'), (new DateTimeImmutable($r['due_on']))->format('d/m/Y')), '/billing', []);
            }
        }

        $order = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, fn($a, $b) => $order[$a['severity']] <=> $order[$b['severity']]);
        return $alerts;
    }

    private static function alert(string $key, string $severity, string $icon, string $title, string $detail, string $link, array $query): array
    {
        return ['key' => $key, 'severity' => $severity, 'icon' => $icon, 'title' => $title, 'detail' => $detail, 'link' => $link, 'query' => (object)$query];
    }
}
