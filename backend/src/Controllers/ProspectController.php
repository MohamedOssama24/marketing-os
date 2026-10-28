<?php
declare(strict_types=1);

/**
 * Potenziali clienti: l'agenzia inserisce il sito di un'attività, l'AI prepara un audit gratuito con una proposta
 * di servizi, e l'agenzia lo manda con un link che si apre senza account. Se l'attività accetta, diventa cliente.
 */
final class ProspectController
{
    private const STATUSES = ['new', 'sent', 'won', 'lost'];
    private const COLUMNS = 'p.id, p.url, p.name, p.status, p.audit, p.job_id, p.monthly_fee, p.agency_note, p.brand_profile_id, p.share_token, p.shared_at, p.views, p.last_viewed_at, p.created_at, j.status AS job_status, j.error AS job_error';

    public static function index(): never
    {
        $user = Auth::user();
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM prospects p LEFT JOIN jobs j ON j.id=p.job_id WHERE p.organization_id=? ORDER BY p.created_at DESC, p.id DESC LIMIT 300');
        $stmt->execute([$user['organization_id']]);
        // Nella lista bastano punteggio e nome: l'audit completo si legge nel dettaglio.
        Http::json(['data' => array_map(function ($row) {
            $p = self::present($row);
            $p['score'] = $p['audit']['score'] ?? null;
            unset($p['audit']);
            return $p;
        }, $stmt->fetchAll())]);
    }

    public static function show(int $id): never
    {
        $user = Auth::user();
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    /** Avvia l'audit del sito in background. */
    public static function store(): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $data = Http::body();
        try {
            $url = SafeHttp::normalizeUrl(Http::requiredString($data, 'url', 2048));
        } catch (SiteException $e) {
            Http::validationError($e->getMessage());
        }
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO prospects (organization_id, created_by, url, name) VALUES (?, ?, ?, ?)')
            ->execute([$user['organization_id'], $user['id'], $url, Http::optionalString($data, 'name', 160)]);
        $id = (int)$pdo->lastInsertId();
        self::startAudit($id, $user);
        Audit::log($user, 'prospect.created', 'prospect', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])], 202);
    }

    public static function reanalyze(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        self::find($id, $user['organization_id']);
        self::startAudit($id, $user);
        Http::json(['data' => self::find($id, $user['organization_id'])], 202);
    }

    /** Stato commerciale, canone proposto e messaggio dell'agenzia mostrato nell'audit. */
    public static function update(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        self::find($id, $user['organization_id']);
        $data = Http::body();
        $fields = [];
        if (array_key_exists('status', $data)) $fields['status'] = Http::oneOf($data, 'status', self::STATUSES);
        if (array_key_exists('name', $data)) $fields['name'] = Http::optionalString($data, 'name', 160);
        if (array_key_exists('agency_note', $data)) $fields['agency_note'] = Http::optionalString($data, 'agency_note', 3000);
        if (array_key_exists('monthly_fee', $data)) {
            $fee = $data['monthly_fee'];
            if ($fee !== null && $fee !== '' && (!is_numeric($fee) || $fee < 0 || $fee > 1000000)) Http::validationError('Il canone proposto deve essere un importo valido.');
            $fields['monthly_fee'] = $fee === null || $fee === '' ? null : round((float)$fee, 2);
        }
        if ($fields) {
            $set = implode(', ', array_map(fn($c) => "{$c}=?", array_keys($fields)));
            Database::connection()->prepare("UPDATE prospects SET {$set} WHERE id=? AND organization_id=?")->execute([...array_values($fields), $id, $user['organization_id']]);
            Audit::log($user, 'prospect.updated', 'prospect', $id);
        }
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    public static function destroy(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        self::find($id, $user['organization_id']);
        Database::connection()->prepare('DELETE FROM prospects WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Audit::log($user, 'prospect.deleted', 'prospect', $id);
        Http::json(['data' => ['deleted' => true]]);
    }

    /** Link pubblico all'audit: lo stato passa a "inviato" se era nuovo. */
    public static function share(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $prospect = self::find($id, $user['organization_id']);
        if (!$prospect['audit']) Http::json(['error' => 'invalid_state', 'message' => 'L\'audit non è ancora pronto.'], 409);
        Database::connection()->prepare("UPDATE prospects SET share_token=COALESCE(share_token, ?), shared_at=COALESCE(shared_at, ?), status=IF(status='new', 'sent', status) WHERE id=? AND organization_id=?")
            ->execute([bin2hex(random_bytes(16)), date('Y-m-d H:i:s'), $id, $user['organization_id']]);
        Audit::log($user, 'prospect.shared', 'prospect', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    public static function unshare(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        self::find($id, $user['organization_id']);
        Database::connection()->prepare('UPDATE prospects SET share_token=NULL, shared_at=NULL WHERE id=? AND organization_id=?')->execute([$id, $user['organization_id']]);
        Audit::log($user, 'prospect.unshared', 'prospect', $id);
        Http::json(['data' => self::find($id, $user['organization_id'])]);
    }

    /** L'attività ha accettato: diventa cliente, con il brand kit letto dallo stesso sito. */
    public static function convert(int $id): never
    {
        $user = Auth::userWithRole(Auth::EDITORS);
        $prospect = self::find($id, $user['organization_id']);
        if ($prospect['brand_profile_id']) Http::json(['error' => 'invalid_state', 'message' => 'È già un cliente.'], 409);
        $job = Jobs::enqueue($user, 'analyze_site', ['url' => $prospect['url'], 'prospect_id' => $id]);
        Database::connection()->prepare("UPDATE prospects SET status='won' WHERE id=? AND organization_id=?")->execute([$id, $user['organization_id']]);
        Audit::log($user, 'prospect.converted', 'prospect', $id);
        Http::json(['data' => $job], 202);
    }

    /** L'audit come lo apre l'attività dal link ricevuto, senza accesso. */
    public static function publicShow(string $token): never
    {
        $stmt = Database::connection()->prepare('SELECT p.id, p.url, p.name, p.audit, p.monthly_fee, p.agency_note, o.name AS agency_name
            FROM prospects p JOIN organizations o ON o.id=p.organization_id WHERE p.share_token=? AND o.status=\'active\' LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch();
        if (!$row || !$row['audit']) Http::json(['error' => 'not_found', 'message' => 'Audit non disponibile.'], 404);
        Database::connection()->prepare('UPDATE prospects SET views=views+1, last_viewed_at=? WHERE id=?')->execute([date('Y-m-d H:i:s'), $row['id']]);
        Http::json(['data' => [
            'name' => $row['name'], 'url' => $row['url'], 'audit' => json_decode($row['audit'], true),
            'monthly_fee' => $row['monthly_fee'] === null ? null : (float)$row['monthly_fee'],
            'agency_note' => $row['agency_note'], 'agency' => ['name' => $row['agency_name']],
        ]]);
    }

    private static function startAudit(int $id, array $user): void
    {
        $job = Jobs::enqueue($user, 'audit_prospect', ['prospect_id' => $id]);
        Database::connection()->prepare('UPDATE prospects SET job_id=? WHERE id=? AND organization_id=?')->execute([$job['id'], $id, $user['organization_id']]);
    }

    private static function find(int $id, int $orgId): array
    {
        $stmt = Database::connection()->prepare('SELECT ' . self::COLUMNS . ' FROM prospects p LEFT JOIN jobs j ON j.id=p.job_id WHERE p.id=? AND p.organization_id=? LIMIT 1');
        $stmt->execute([$id, $orgId]);
        return self::present($stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Risorsa non trovata.'], 404));
    }

    private static function present(array $p): array
    {
        $p['audit'] = $p['audit'] === null ? null : json_decode($p['audit'], true);
        $p['monthly_fee'] = $p['monthly_fee'] === null ? null : (float)$p['monthly_fee'];
        $p['views'] = (int)$p['views'];
        $p['analyzing'] = in_array($p['job_status'], ['queued', 'running'], true);
        return $p;
    }
}
