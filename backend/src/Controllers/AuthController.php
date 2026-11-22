<?php
declare(strict_types=1);

final class AuthController
{
    private const LOGIN_MAX_PER_ACCOUNT = 5;   // tentativi falliti per email+IP
    private const LOGIN_MAX_PER_IP = 30;       // tentativi falliti per IP, su tutti gli account
    private const LOGIN_WINDOW = 900;          // 15 minuti

    public static function register(): never
    {
        $registerKey = 'register:' . Http::clientIp();
        RateLimiter::check($registerKey, max(1, (int)(getenv('RATE_LIMIT_REGISTER_PER_HOUR') ?: 10)), 3600);

        $data = Http::body();
        $name = Http::requiredString($data, 'name', 120);
        $email = strtolower(Http::requiredString($data, 'email', 190));
        $password = (string)($data['password'] ?? '');
        $organizationName = Http::requiredString($data, 'organization_name', 160);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::validationError('Email non valida.');
        self::assertPassword($password);
        RateLimiter::record($registerKey);
        // Con REGISTRATION_INVITE_CODE impostato si registra solo chi ha il codice (app esposta su internet).
        // Spazi e maiuscole non contano: un codice copiato con uno spazio in più resta valido.
        $inviteCode = strtolower(trim((string)(getenv('REGISTRATION_INVITE_CODE') ?: '')));
        if ($inviteCode !== '' && !hash_equals($inviteCode, strtolower(trim((string)($data['invite_code'] ?? ''))))) {
            Http::json(['error' => 'invalid_invite_code', 'message' => 'Codice di invito non valido.'], 403);
        }

        $slugBase = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $organizationName) ?: $organizationName), '-'));
        $slug = ($slugBase ?: 'organization') . '-' . bin2hex(random_bytes(3));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            // Ogni nuova agenzia parte con la prova gratuita; allo scadere serve l'abbonamento.
            $stmt = $pdo->prepare('INSERT INTO organizations (name, slug, plan, trial_ends_at) VALUES (?, ?, \'trial\', UTC_TIMESTAMP() + INTERVAL ? DAY)');
            $stmt->execute([$organizationName, $slug, Subscription::trialDays()]);
            $organizationId = (int)$pdo->lastInsertId();
            // La registrazione apre già una sessione: conta come primo accesso.
            $stmt = $pdo->prepare('INSERT INTO users (organization_id, name, email, password_hash, role, last_login_at) VALUES (?, ?, ?, ?, \'owner\', UTC_TIMESTAMP())');
            $stmt->execute([$organizationId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') Http::json(['error' => 'email_already_exists', 'message' => 'Questa email è già registrata.'], 409);
            throw $e;
        }
        Http::json(['data' => ['user' => ['id' => $userId, 'organization_id' => $organizationId, 'name' => $name, 'email' => $email, 'role' => 'owner',
            'subscription' => Subscription::summary(Subscription::load($organizationId))], 'token' => Auth::issueToken($userId)]], 201);
    }

    public static function login(): never
    {
        $data = Http::body();
        $email = strtolower(Http::requiredString($data, 'email', 190));
        $password = (string)($data['password'] ?? '');
        $accountKey = 'login:' . Http::clientIp() . ':' . $email;
        $ipKey = 'login-ip:' . Http::clientIp();
        RateLimiter::check($accountKey, self::LOGIN_MAX_PER_ACCOUNT, self::LOGIN_WINDOW);
        RateLimiter::check($ipKey, self::LOGIN_MAX_PER_IP, self::LOGIN_WINDOW);

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT u.id, u.organization_id, u.name, u.email, u.password_hash, u.role, u.is_platform_admin, o.status AS organization_status
            FROM users u JOIN organizations o ON o.id=u.organization_id WHERE u.email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            RateLimiter::record($accountKey);
            RateLimiter::record($ipKey);
            Http::json(['error' => 'invalid_credentials', 'message' => 'Credenziali non valide.'], 401);
        }
        RateLimiter::clear($accountKey);
        // Lo stato si controlla solo dopo la password, così non si rivela se un account esiste ed è sospeso.
        if ($user['organization_status'] === 'suspended') Auth::suspended();
        $pdo->prepare('UPDATE users SET last_login_at=UTC_TIMESTAMP() WHERE id=?')->execute([$user['id']]);
        unset($user['password_hash'], $user['organization_status']);
        $user['is_platform_admin'] = (bool)$user['is_platform_admin'];
        // Anche a prova scaduta si entra: l'app mostra subito la pagina per abbonarsi.
        $user['subscription'] = Subscription::summary(Subscription::load((int)$user['organization_id']));
        Http::json(['data' => ['user' => $user, 'token' => Auth::issueToken((int)$user['id'])]]);
    }

    public static function logout(): never
    {
        $token = Http::bearerToken();
        if (!$token) Http::json(['error' => 'unauthorized', 'message' => 'Token Bearer mancante.'], 401);
        $stmt = Database::connection()->prepare('DELETE FROM api_tokens WHERE token_hash=?');
        $stmt->execute([hash('sha256', $token)]);
        Http::json(['data' => ['logged_out' => true]]);
    }

    public static function me(): never
    {
        $user = Auth::user(allowExpired: true);
        unset($user['token_id']);
        $user['subscription'] = Subscription::summary(Subscription::load($user['organization_id']));
        Http::json(['data' => ['user' => $user]]);
    }

    public static function assertPassword(string $password): void
    {
        if (strlen($password) < 12) Http::validationError('La password deve contenere almeno 12 caratteri.');
        if (strlen($password) > 200) Http::validationError('La password è troppo lunga.');
    }
}
