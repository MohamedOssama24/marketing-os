<?php
declare(strict_types=1);

/**
 * Fatturazione ai clienti: una fattura al mese per cliente con canone, sponsorizzate da rifatturare
 * e commissione sulla spesa. Stati: da fatturare → fatturata → pagata. La fattura elettronica vera
 * si emette nel proprio gestionale o tramite il commercialista: qui si prepara e si tiene traccia.
 */
final class BillingController
{
    private const BILLING_COLUMNS = 'id, name, primary_color, monthly_fee, ad_fee_percent, billing_name, vat_number, tax_code, sdi_code, pec, billing_address, billing_email';
    private const BILLING_TEXT = ['billing_name' => 190, 'vat_number' => 30, 'tax_code' => 30, 'pec' => 190, 'billing_address' => 500, 'billing_email' => 190];
    private const MONTHS = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

    /** Clienti con canone, commissione e dati di fatturazione. */
    public static function clients(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $stmt = Database::connection()->prepare('SELECT ' . self::BILLING_COLUMNS . ' FROM brand_profiles WHERE organization_id=? ORDER BY name');
        $stmt->execute([$user['organization_id']]);
        Http::json(['data' => array_map([self::class, 'presentClient'], $stmt->fetchAll())]);
    }

    public static function updateClient(int $brandId): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        Tenant::findOrFail('brand_profiles', $brandId, $user['organization_id'], 'id');
        $data = Http::body();
        $fields = [];
        if (array_key_exists('monthly_fee', $data)) $fields['monthly_fee'] = AdSpendController::amount($data, 'monthly_fee', false);
        if (array_key_exists('ad_fee_percent', $data)) {
            $percent = AdSpendController::amount($data, 'ad_fee_percent', false) ?? 0.0;
            if ($percent > 100) Http::validationError('La commissione non può superare il 100%.');
            $fields['ad_fee_percent'] = $percent;
        }
        foreach (self::BILLING_TEXT as $key => $max) {
            if (array_key_exists($key, $data)) $fields[$key] = Http::optionalString($data, $key, $max);
        }
        if (array_key_exists('sdi_code', $data)) {
            $sdi = Http::optionalString($data, 'sdi_code', 7);
            if ($sdi !== null && !preg_match('/^[A-Za-z0-9]{6,7}$/', $sdi)) Http::validationError('Il codice destinatario SDI ha 7 caratteri (6 per la pubblica amministrazione).');
            $fields['sdi_code'] = $sdi === null ? null : strtoupper($sdi);
        }
        foreach (['pec', 'billing_email'] as $key) {
            if (($fields[$key] ?? null) !== null && !filter_var($fields[$key], FILTER_VALIDATE_EMAIL)) Http::validationError("Campo '{$key}' deve essere un indirizzo email valido.");
        }
        if ($fields) {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            Database::connection()->prepare("UPDATE brand_profiles SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($fields), $brandId, $user['organization_id']]);
            // Le bozze aperte seguono subito il nuovo canone o la nuova commissione.
            $stmt = Database::connection()->prepare("SELECT id FROM invoices WHERE organization_id=? AND brand_profile_id=? AND status='to_invoice'");
            $stmt->execute([$user['organization_id'], $brandId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $invoiceId) self::rebuild((int)$invoiceId, $user['organization_id']);
            Audit::log($user, 'billing.client_updated', 'brand_profile', $brandId);
        }
        $stmt = Database::connection()->prepare('SELECT ' . self::BILLING_COLUMNS . ' FROM brand_profiles WHERE id=?');
        $stmt->execute([$brandId]);
        Http::json(['data' => self::presentClient($stmt->fetch())]);
    }

    /** Fatture di un mese (?period=AAAA-MM) con i totali per stato. */
    public static function index(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $period = self::period($_GET['period'] ?? date('Y-m'));
        $invoices = self::list($user['organization_id'], $period);
        $totals = [];
        foreach (['to_invoice', 'invoiced', 'paid'] as $status) {
            $subset = array_filter($invoices, fn($i) => $i['status'] === $status);
            $totals[$status] = ['count' => count($subset), 'total' => round(array_sum(array_column($subset, 'total')), 2)];
        }
        $stmt = Database::connection()->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(spent), 0) AS spent FROM ad_spend WHERE organization_id=? AND invoice_id IS NULL AND period_end <= ?");
        $stmt->execute([$user['organization_id'], self::lastDay($period)]);
        $unbilled = $stmt->fetch();
        Http::json(['data' => [
            'period' => $period,
            'invoices' => $invoices,
            'totals' => $totals,
            'unbilled_ad_spend' => ['count' => (int)$unbilled['n'], 'spent' => round((float)$unbilled['spent'], 2)],
        ]]);
    }

    /**
     * Prepara le bozze del mese per ogni cliente con un canone o con sponsorizzate non ancora fatturate.
     * Le bozze già presenti vengono ricalcolate; quelle emesse o pagate restano come sono.
     */
    public static function prepare(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $period = self::period(Http::body()['period'] ?? null);
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT b.id FROM brand_profiles b WHERE b.organization_id=? AND (b.monthly_fee > 0
            OR EXISTS (SELECT 1 FROM ad_spend s WHERE s.brand_profile_id=b.id AND s.invoice_id IS NULL AND s.period_end <= ?))');
        $stmt->execute([$user['organization_id'], self::lastDay($period)]);
        $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $brandId) {
            $existing = $pdo->prepare('SELECT id, status FROM invoices WHERE organization_id=? AND brand_profile_id=? AND period=?');
            $existing->execute([$user['organization_id'], $brandId, $period]);
            $invoice = $existing->fetch();
            if ($invoice && $invoice['status'] !== 'to_invoice') { $counts['skipped']++; continue; }
            if (!$invoice) {
                $pdo->prepare('INSERT INTO invoices (organization_id, brand_profile_id, period, created_by) VALUES (?, ?, ?, ?)')
                    ->execute([$user['organization_id'], $brandId, $period, $user['id']]);
                $invoiceId = (int)$pdo->lastInsertId();
                $counts['created']++;
            } else {
                $invoiceId = (int)$invoice['id'];
                $counts['updated']++;
            }
            self::rebuild($invoiceId, $user['organization_id']);
        }
        // Una bozza nuova senza voci (canone a zero e nessuna spesa) non serve.
        $pdo->prepare("DELETE FROM invoices WHERE organization_id=? AND period=? AND status='to_invoice' AND total=0
            AND NOT EXISTS (SELECT 1 FROM invoice_lines l WHERE l.invoice_id=invoices.id)")->execute([$user['organization_id'], $period]);
        Audit::log($user, 'billing.prepared', 'organization', $user['organization_id']);
        Http::json(['data' => $counts + ['period' => $period, 'invoices' => self::list($user['organization_id'], $period)]]);
    }

    public static function show(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        Http::json(['data' => self::detail($id, $user['organization_id'])]);
    }

    /** IVA, scadenza e note; solo sulle bozze. */
    public static function update(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        self::draft($id, $user);
        $data = Http::body();
        $fields = [];
        if (array_key_exists('vat_rate', $data)) {
            $rate = AdSpendController::amount($data, 'vat_rate', true);
            if ($rate > 100) Http::validationError('Aliquota IVA non valida.');
            $fields['vat_rate'] = $rate;
        }
        if (array_key_exists('notes', $data)) $fields['notes'] = Http::optionalString($data, 'notes', 1000);
        if (array_key_exists('due_on', $data)) $fields['due_on'] = self::optionalDate($data, 'due_on');
        if ($fields) {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            Database::connection()->prepare("UPDATE invoices SET {$set} WHERE id=?")->execute([...array_values($fields), $id]);
            self::rebuild($id, $user['organization_id']);
        }
        Http::json(['data' => self::detail($id, $user['organization_id'])]);
    }

    /** Voce libera (ad esempio un servizio extra o uno sconto con importo negativo). */
    public static function addLine(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        self::draft($id, $user);
        $data = Http::body();
        $description = Http::requiredString($data, 'description', 300);
        $amount = $data['amount'] ?? null;
        if (!is_numeric($amount) || abs((float)$amount) > 9999999999 || (float)$amount == 0) Http::validationError("Campo 'amount' deve essere un importo diverso da zero.");
        $pdo = Database::connection();
        $position = (int)$pdo->query("SELECT COALESCE(MAX(position), 999) + 1 FROM invoice_lines WHERE invoice_id={$id} AND kind='other'")->fetchColumn();
        $pdo->prepare("INSERT INTO invoice_lines (invoice_id, kind, description, amount, position) VALUES (?, 'other', ?, ?, ?)")
            ->execute([$id, $description, round((float)$amount, 2), max(1000, $position)]);
        self::rebuild($id, $user['organization_id']);
        Http::json(['data' => self::detail($id, $user['organization_id'])], 201);
    }

    public static function deleteLine(int $id, int $lineId): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        self::draft($id, $user);
        $stmt = Database::connection()->prepare("DELETE FROM invoice_lines WHERE id=? AND invoice_id=? AND kind='other'");
        $stmt->execute([$lineId, $id]);
        if ($stmt->rowCount() === 0) Http::json(['error' => 'not_found', 'message' => 'Si possono togliere solo le voci aggiunte a mano.'], 404);
        self::rebuild($id, $user['organization_id']);
        Http::json(['data' => self::detail($id, $user['organization_id'])]);
    }

    /** Segna come fatturata, con il numero della fattura emessa nel gestionale. */
    public static function issue(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $invoice = self::draft($id, $user);
        $data = Http::body();
        $number = Http::requiredString($data, 'number', 40);
        $issuedOn = self::optionalDate($data, 'issued_on') ?? date('Y-m-d');
        $dueOn = self::optionalDate($data, 'due_on') ?? $invoice['due_on'];
        if ((float)$invoice['total'] == 0.0) Http::validationError('La fattura non ha importi.');
        $stmt = Database::connection()->prepare('SELECT id FROM invoices WHERE organization_id=? AND number=? AND YEAR(issued_on)=YEAR(?) AND id<>?');
        $stmt->execute([$user['organization_id'], $number, $issuedOn, $id]);
        if ($stmt->fetch()) Http::json(['error' => 'duplicate_number', 'message' => "Il numero {$number} è già usato da un'altra fattura di quest'anno."], 409);
        Database::connection()->prepare("UPDATE invoices SET status='invoiced', number=?, issued_on=?, due_on=? WHERE id=?")->execute([$number, $issuedOn, $dueOn, $id]);
        Audit::log($user, 'invoice.issued', 'invoice', $id);
        Http::json(['data' => self::detail($id, $user['organization_id'])]);
    }

    public static function markPaid(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $invoice = self::invoice($id, $user);
        if ($invoice['status'] !== 'invoiced') Http::json(['error' => 'invalid_status', 'message' => 'Si può segnare come pagata solo una fattura emessa.'], 409);
        $paidOn = self::optionalDate(Http::body(), 'paid_on') ?? date('Y-m-d');
        Database::connection()->prepare("UPDATE invoices SET status='paid', paid_on=? WHERE id=?")->execute([$paidOn, $id]);
        Audit::log($user, 'invoice.paid', 'invoice', $id);
        Http::json(['data' => self::detail($id, $user['organization_id'])]);
    }

    /** Torna indietro di uno stato: pagata → fatturata, fatturata → da fatturare (per correggerla). */
    public static function reopen(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $invoice = self::invoice($id, $user);
        $pdo = Database::connection();
        if ($invoice['status'] === 'paid') {
            $pdo->prepare("UPDATE invoices SET status='invoiced', paid_on=NULL WHERE id=?")->execute([$id]);
        } elseif ($invoice['status'] === 'invoiced') {
            $pdo->prepare("UPDATE invoices SET status='to_invoice' WHERE id=?")->execute([$id]);
            self::rebuild($id, $user['organization_id']);
        } else {
            Http::json(['error' => 'invalid_status', 'message' => 'La fattura è già da fatturare.'], 409);
        }
        Audit::log($user, 'invoice.reopened', 'invoice', $id);
        Http::json(['data' => self::detail($id, $user['organization_id'])]);
    }

    /** Elimina una bozza: le sue sponsorizzate tornano da fatturare. */
    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        self::draft($id, $user);
        $pdo = Database::connection();
        $pdo->prepare('UPDATE ad_spend SET invoice_id=NULL WHERE invoice_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM invoices WHERE id=?')->execute([$id]);
        Audit::log($user, 'invoice.deleted', 'invoice', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** CSV per il commercialista: una riga per voce, separatore ";" e virgola decimale, pronto per Excel. */
    public static function export(): never
    {
        $user = Auth::userWithRole(Auth::MANAGERS);
        $period = self::period($_GET['period'] ?? null);
        $stmt = Database::connection()->prepare('SELECT i.id, i.status, i.number, i.issued_on, i.due_on, i.paid_on, i.vat_rate, i.subtotal, i.vat_amount, i.total,
                b.name, b.billing_name, b.vat_number, b.tax_code, b.sdi_code, b.pec, b.billing_address, b.billing_email, l.kind, l.description, l.amount
            FROM invoices i JOIN brand_profiles b ON b.id=i.brand_profile_id JOIN invoice_lines l ON l.invoice_id=i.id
            WHERE i.organization_id=? AND i.period=? ORDER BY b.name, l.position, l.id');
        $stmt->execute([$user['organization_id'], $period]);
        $status = ['to_invoice' => 'Da fatturare', 'invoiced' => 'Fatturata', 'paid' => 'Pagata'];
        $kind = ['fee' => 'Canone', 'ad_spend' => 'Sponsorizzata', 'ad_fee' => 'Commissione', 'other' => 'Altro'];
        $number = fn($v) => number_format((float)$v, 2, ',', '');
        $date = fn($v) => $v ? date('d/m/Y', strtotime($v)) : '';
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Mese', 'Cliente', 'Ragione sociale', 'Partita IVA', 'Codice fiscale', 'Codice SDI', 'PEC', 'Indirizzo', 'Email', 'Stato', 'Numero', 'Data', 'Scadenza', 'Pagata il',
            'Tipo voce', 'Descrizione', 'Importo voce', 'Imponibile fattura', 'Aliquota IVA', 'IVA', 'Totale fattura'], ';', '"', '');
        foreach ($stmt->fetchAll() as $r) {
            fputcsv($out, [$period, $r['name'], $r['billing_name'], $r['vat_number'], $r['tax_code'], $r['sdi_code'], $r['pec'], $r['billing_address'], $r['billing_email'],
                $status[$r['status']], $r['number'], $date($r['issued_on']), $date($r['due_on']), $date($r['paid_on']),
                $kind[$r['kind']], $r['description'], $number($r['amount']), $number($r['subtotal']), $number($r['vat_rate']), $number($r['vat_amount']), $number($r['total'])], ';', '"', '');
        }
        rewind($out);
        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"fatture-{$period}.csv\"");
        header('Cache-Control: no-store');
        echo stream_get_contents($out);
        exit;
    }

    /**
     * Ricalcola una bozza: aggancia le sponsorizzate del cliente non ancora fatturate fino alla fine del mese,
     * rigenera canone, spese e commissione (le voci aggiunte a mano restano) e aggiorna i totali.
     * Le fatture emesse o pagate non cambiano.
     */
    public static function rebuild(int $invoiceId, int $organizationId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT i.id, i.status, i.period, i.vat_rate, i.brand_profile_id, b.monthly_fee, b.ad_fee_percent
            FROM invoices i JOIN brand_profiles b ON b.id=i.brand_profile_id WHERE i.id=? AND i.organization_id=?');
        $stmt->execute([$invoiceId, $organizationId]);
        $invoice = $stmt->fetch();
        if (!$invoice || $invoice['status'] !== 'to_invoice') return;

        $pdo->prepare('UPDATE ad_spend SET invoice_id=? WHERE organization_id=? AND brand_profile_id=? AND invoice_id IS NULL AND period_end <= ?')
            ->execute([$invoiceId, $organizationId, $invoice['brand_profile_id'], self::lastDay($invoice['period'])]);
        $pdo->prepare("DELETE FROM invoice_lines WHERE invoice_id=? AND kind<>'other'")->execute([$invoiceId]);
        $insert = $pdo->prepare('INSERT INTO invoice_lines (invoice_id, kind, description, amount, ad_spend_id, position) VALUES (?, ?, ?, ?, ?, ?)');

        if ((float)$invoice['monthly_fee'] > 0) {
            $insert->execute([$invoiceId, 'fee', 'Canone gestione marketing — ' . self::monthLabel($invoice['period']), $invoice['monthly_fee'], null, 0]);
        }
        $stmt = $pdo->prepare('SELECT id, platform, campaign_name, period_start, period_end, spent FROM ad_spend WHERE invoice_id=? ORDER BY period_start, id');
        $stmt->execute([$invoiceId]);
        $platforms = ['meta' => 'Meta', 'google' => 'Google', 'tiktok' => 'TikTok', 'linkedin' => 'LinkedIn', 'other' => 'Altra piattaforma'];
        $adTotal = 0.0;
        foreach ($stmt->fetchAll() as $position => $spend) {
            $range = date('d/m', strtotime($spend['period_start'])) . '–' . date('d/m/Y', strtotime($spend['period_end']));
            $description = mb_substr("Sponsorizzata {$platforms[$spend['platform']]}: {$spend['campaign_name']} ({$range})", 0, 300);
            $insert->execute([$invoiceId, 'ad_spend', $description, $spend['spent'], $spend['id'], 10 + $position]);
            $adTotal += (float)$spend['spent'];
        }
        $percent = (float)$invoice['ad_fee_percent'];
        if ($percent > 0 && $adTotal > 0) {
            $label = rtrim(rtrim(number_format($percent, 2, ',', ''), '0'), ',');
            $insert->execute([$invoiceId, 'ad_fee', "Commissione gestione sponsorizzate ({$label}% di € " . number_format($adTotal, 2, ',', '.') . ')', round($adTotal * $percent / 100, 2), null, 900]);
        }

        $subtotal = round((float)$pdo->query("SELECT COALESCE(SUM(amount), 0) FROM invoice_lines WHERE invoice_id={$invoiceId}")->fetchColumn(), 2);
        $vat = round($subtotal * (float)$invoice['vat_rate'] / 100, 2);
        $pdo->prepare('UPDATE invoices SET subtotal=?, vat_amount=?, total=? WHERE id=?')->execute([$subtotal, $vat, round($subtotal + $vat, 2), $invoiceId]);
    }

    private static function list(int $organizationId, string $period): array
    {
        $stmt = Database::connection()->prepare('SELECT i.id, i.brand_profile_id, b.name AS brand_name, b.primary_color AS brand_color, i.period, i.status, i.number, i.issued_on, i.due_on, i.paid_on,
                i.vat_rate, i.subtotal, i.vat_amount, i.total, (SELECT COUNT(*) FROM invoice_lines l WHERE l.invoice_id=i.id) AS lines_count,
                (SELECT COALESCE(SUM(l.amount), 0) FROM invoice_lines l WHERE l.invoice_id=i.id AND l.kind=\'ad_spend\') AS ad_spend_total
            FROM invoices i JOIN brand_profiles b ON b.id=i.brand_profile_id WHERE i.organization_id=? AND i.period=? ORDER BY b.name');
        $stmt->execute([$organizationId, $period]);
        return array_map([self::class, 'present'], $stmt->fetchAll());
    }

    private static function detail(int $id, int $organizationId): array
    {
        $stmt = Database::connection()->prepare('SELECT i.*, b.name AS brand_name, b.primary_color AS brand_color FROM invoices i JOIN brand_profiles b ON b.id=i.brand_profile_id WHERE i.id=? AND i.organization_id=?');
        $stmt->execute([$id, $organizationId]);
        $invoice = $stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404);
        unset($invoice['organization_id'], $invoice['created_by']);
        $stmt = Database::connection()->prepare('SELECT id, kind, description, amount, ad_spend_id FROM invoice_lines WHERE invoice_id=? ORDER BY position, id');
        $stmt->execute([$id]);
        $lines = array_map(fn($l) => ['id' => (int)$l['id'], 'kind' => $l['kind'], 'description' => $l['description'], 'amount' => (float)$l['amount'], 'ad_spend_id' => $l['ad_spend_id'] === null ? null : (int)$l['ad_spend_id']], $stmt->fetchAll());
        $stmt = Database::connection()->prepare('SELECT ' . self::BILLING_COLUMNS . ' FROM brand_profiles WHERE id=?');
        $stmt->execute([$invoice['brand_profile_id']]);
        return self::present($invoice) + ['lines' => $lines, 'client' => self::presentClient($stmt->fetch())];
    }

    private static function invoice(int $id, array $user): array
    {
        return Tenant::findOrFail('invoices', $id, $user['organization_id']);
    }

    private static function draft(int $id, array $user): array
    {
        $invoice = self::invoice($id, $user);
        if ($invoice['status'] !== 'to_invoice') Http::json(['error' => 'invalid_status', 'message' => 'La fattura è già emessa: riaprila per modificarla.'], 409);
        return $invoice;
    }

    private static function period(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(20\d\d)-(0[1-9]|1[0-2])$/', $value)) Http::validationError("Campo 'period' deve essere un mese AAAA-MM.");
        return $value;
    }

    private static function lastDay(string $period): string
    {
        return date('Y-m-t', strtotime("{$period}-01"));
    }

    private static function monthLabel(string $period): string
    {
        [$year, $month] = explode('-', $period);
        return self::MONTHS[(int)$month - 1] . " {$year}";
    }

    private static function optionalDate(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;
        if ($value === null || $value === '') return null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if (!$date || $date->format('Y-m-d') !== $value) Http::validationError("Campo '{$key}' deve essere una data AAAA-MM-GG.");
        return $value;
    }

    private static function present(array $row): array
    {
        foreach (['id', 'brand_profile_id', 'lines_count'] as $key) if (isset($row[$key])) $row[$key] = (int)$row[$key];
        foreach (['vat_rate', 'subtotal', 'vat_amount', 'total', 'ad_spend_total'] as $key) if (isset($row[$key])) $row[$key] = (float)$row[$key];
        return $row;
    }

    private static function presentClient(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['monthly_fee'] = $row['monthly_fee'] === null ? null : (float)$row['monthly_fee'];
        $row['ad_fee_percent'] = (float)$row['ad_fee_percent'];
        return $row;
    }
}
