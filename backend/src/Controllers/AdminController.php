<?php
declare(strict_types=1);

/**
 * Pannello della piattaforma: vede tutte le organizzazioni (unico punto dell'API che non è limitato a una sola),
 * quindi ogni metodo richiede un amministratore di piattaforma.
 */
final class AdminController
{
    /** Conteggi per organizzazione, calcolati con sottoquery per non moltiplicare le righe dei join. */
    private const ORG_SELECT = "SELECT o.id, o.name, o.slug, o.status, o.suspended_at, o.suspended_reason, o.created_at,
            o.plan, o.trial_ends_at, o.subscription_status, o.current_period_end, o.cancel_at_period_end, o.stripe_customer_id,
            (SELECT u.email FROM users u WHERE u.organization_id=o.id AND u.role='owner' ORDER BY u.id LIMIT 1) AS owner_email,
            (SELECT COUNT(*) FROM users u WHERE u.organization_id=o.id) AS users_count,
            (SELECT MAX(u.last_login_at) FROM users u WHERE u.organization_id=o.id) AS last_login_at,
            (SELECT COUNT(*) FROM brand_profiles b WHERE b.organization_id=o.id) AS clients_count,
            (SELECT COUNT(*) FROM projects p WHERE p.organization_id=o.id) AS campaigns_count,
            (SELECT COUNT(*) FROM content_items c WHERE c.organization_id=o.id) AS content_count,
            (SELECT COUNT(*) FROM content_items c WHERE c.organization_id=o.id AND c.status='published') AS published_count,
            (SELECT COUNT(*) FROM jobs j WHERE j.organization_id=o.id AND j.created_at >= ?) AS jobs_this_month,
            (SELECT COALESCE(SUM(a.size_bytes), 0) FROM assets a WHERE a.organization_id=o.id) AS storage_bytes
        FROM organizations o";

    public static function overview(): never
    {
        Auth::platformAdmin();
        $pdo = Database::connection();
        $count = fn(string $sql, array $params = []) => (function () use ($pdo, $sql, $params) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        })();
        $monthStart = (new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00');

        Http::json(['data' => [
            'organizations' => $count('SELECT COUNT(*) FROM organizations'),
            'suspended' => $count("SELECT COUNT(*) FROM organizations WHERE status='suspended'"),
            'new_last_30_days' => $count('SELECT COUNT(*) FROM organizations WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY'),
            'users' => $count('SELECT COUNT(*) FROM users'),
            'active_users_7_days' => $count('SELECT COUNT(*) FROM users WHERE last_login_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY'),
            'clients' => $count('SELECT COUNT(*) FROM brand_profiles'),
            'campaigns' => $count('SELECT COUNT(*) FROM projects'),
            'content' => $count('SELECT COUNT(*) FROM content_items'),
            'published_this_month' => $count("SELECT COUNT(*) FROM content_items WHERE status='published' AND published_at >= ?", [$monthStart]),
            'ai_jobs_this_month' => $count("SELECT COUNT(*) FROM jobs WHERE type IN ('generate_content','generate_campaign','analyze_site','suggest_goals','rewrite_content') AND created_at >= ?", [$monthStart]),
            'videos_this_month' => $count("SELECT COUNT(*) FROM jobs WHERE type='render_video' AND status='done' AND created_at >= ?", [$monthStart]),
            'failed_jobs_7_days' => $count("SELECT COUNT(*) FROM jobs WHERE status='failed' AND created_at >= UTC_TIMESTAMP() - INTERVAL 7 DAY"),
            'storage_bytes' => $count('SELECT COALESCE(SUM(size_bytes), 0) FROM assets'),
        ]]);
    }

    /** Elenco organizzazioni, con ricerca (?q= nome o email del titolare) e filtro (?status=active|suspended). */
    public static function organizations(): never
    {
        Auth::platformAdmin();
        $where = [];
        $params = [(new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00')];
        $q = Http::optionalString($_GET, 'q', 100);
        if ($q !== null) {
            $where[] = "(o.name LIKE ? OR EXISTS (SELECT 1 FROM users u WHERE u.organization_id=o.id AND u.email LIKE ?))";
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if (isset($_GET['status']) && $_GET['status'] !== '') {
            $where[] = 'o.status=?';
            $params[] = Http::oneOf($_GET, 'status', ['active', 'suspended']);
        }
        $sql = self::ORG_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.created_at DESC LIMIT 500';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        Http::json(['data' => array_map([self::class, 'castOrg'], $stmt->fetchAll())]);
    }

    public static function organization(int $id): never
    {
        Auth::platformAdmin();
        $pdo = Database::connection();
        $stmt = $pdo->prepare(self::ORG_SELECT . ' WHERE o.id=?');
        $stmt->execute([(new DateTimeImmutable('first day of this month'))->format('Y-m-d 00:00:00'), $id]);
        $org = $stmt->fetch() ?: Http::json(['error' => 'not_found', 'message' => 'Organizzazione non trovata.'], 404);
        $org = self::castOrg($org);
        $notes = $pdo->prepare('SELECT admin_notes FROM organizations WHERE id=?');
        $notes->execute([$id]);
        $org['admin_notes'] = $notes->fetchColumn() ?: null;

        $query = function (string $sql) use ($pdo, $id) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetchAll();
        };
        Http::json(['data' => [
            'organization' => $org,
            'users' => array_map(fn($u) => ['is_platform_admin' => (bool)$u['is_platform_admin']] + $u,
                $query('SELECT id, name, email, role, is_platform_admin, last_login_at, created_at FROM users WHERE organization_id=? ORDER BY created_at')),
            'clients' => $query('SELECT b.id, b.name, b.website_url, b.primary_color, b.created_at,
                (SELECT COUNT(*) FROM projects p WHERE p.brand_profile_id=b.id) AS campaigns_count
                FROM brand_profiles b WHERE b.organization_id=? ORDER BY b.name'),
            'recent_jobs' => $query('SELECT id, type, status, error, created_at, finished_at FROM jobs WHERE organization_id=? ORDER BY id DESC LIMIT 15'),
            'activity' => $query('SELECT l.action, l.entity_type, l.entity_id, l.created_at, u.name AS user_name
                FROM audit_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.organization_id=? ORDER BY l.id DESC LIMIT 25'),
        ]]);
    }

    /** Sospende l'organizzazione: blocca il login e chiude subito tutte le sessioni dei suoi utenti. */
    public static function suspend(int $id): never
    {
        $admin = Auth::platformAdmin();
        if ($id === $admin['organization_id']) {
            Http::json(['error' => 'invalid_operation', 'message' => 'Non puoi sospendere la tua organizzazione.'], 409);
        }
        self::requireOrg($id);
        $reason = Http::requiredString(Http::body(), 'reason', 500);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE organizations SET status='suspended', suspended_at=UTC_TIMESTAMP(), suspended_reason=? WHERE id=?")->execute([$reason, $id]);
            $pdo->prepare('DELETE t FROM api_tokens t JOIN users u ON u.id=t.user_id WHERE u.organization_id=?')->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        self::log($admin, $id, 'admin.organization_suspended');
        self::organization($id);
    }

    public static function reactivate(int $id): never
    {
        $admin = Auth::platformAdmin();
        self::requireOrg($id);
        Database::connection()->prepare("UPDATE organizations SET status='active', suspended_at=NULL, suspended_reason=NULL WHERE id=?")->execute([$id]);
        self::log($admin, $id, 'admin.organization_reactivated');
        self::organization($id);
    }

    /**
     * Accesso deciso dall'amministratore: allunga la prova (extend_trial + days), la riapre da oggi (restart_trial + days)
     * o regala l'accesso senza pagamento (free). Un abbonamento pagato su Stripe non viene toccato.
     */
    public static function updateSubscription(int $id): never
    {
        $admin = Auth::platformAdmin();
        self::requireOrg($id);
        $data = Http::body();
        $action = Http::oneOf($data, 'action', ['extend_trial', 'restart_trial', 'free']);
        $pdo = Database::connection();
        if ($action === 'free') {
            $pdo->prepare("UPDATE organizations SET plan='free' WHERE id=?")->execute([$id]);
        } else {
            $days = max(1, min(365, (int)($data['days'] ?? Subscription::trialDays())));
            // extend_trial aggiunge giorni alla scadenza attuale (o a oggi, se è già passata).
            $sql = $action === 'extend_trial'
                ? "UPDATE organizations SET plan='trial', trial_ends_at=GREATEST(COALESCE(trial_ends_at, UTC_TIMESTAMP()), UTC_TIMESTAMP()) + INTERVAL ? DAY WHERE id=?"
                : "UPDATE organizations SET plan='trial', trial_ends_at=UTC_TIMESTAMP() + INTERVAL ? DAY WHERE id=?";
            $pdo->prepare($sql)->execute([$days, $id]);
        }
        self::log($admin, $id, 'admin.subscription_' . $action);
        self::organization($id);
    }

    /** Note interne sull'organizzazione, visibili solo agli amministratori. */
    public static function updateNotes(int $id): never
    {
        $admin = Auth::platformAdmin();
        self::requireOrg($id);
        $notes = Http::optionalString(Http::body(), 'admin_notes', 5000);
        Database::connection()->prepare('UPDATE organizations SET admin_notes=? WHERE id=?')->execute([$notes, $id]);
        self::log($admin, $id, 'admin.notes_updated');
        self::organization($id);
    }

    private static function requireOrg(int $id): void
    {
        $stmt = Database::connection()->prepare('SELECT id FROM organizations WHERE id=?');
        $stmt->execute([$id]);
        if (!$stmt->fetch()) Http::json(['error' => 'not_found', 'message' => 'Organizzazione non trovata.'], 404);
    }

    /** Le azioni dell'amministratore finiscono nel registro dell'organizzazione interessata. */
    private static function log(array $admin, int $organizationId, string $action): void
    {
        Database::connection()->prepare('INSERT INTO audit_logs (organization_id, user_id, action, entity_type, entity_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$organizationId, $admin['id'], $action, 'organization', $organizationId]);
    }

    private static function castOrg(array $org): array
    {
        foreach (['users_count', 'clients_count', 'campaigns_count', 'content_count', 'published_count', 'jobs_this_month', 'storage_bytes'] as $key) {
            $org[$key] = (int)$org[$key];
        }
        $org['id'] = (int)$org['id'];
        $org['subscription'] = Subscription::summary($org);
        unset($org['subscription_status'], $org['current_period_end'], $org['cancel_at_period_end'], $org['stripe_customer_id']);
        return $org;
    }
}
