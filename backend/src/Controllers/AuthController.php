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

        $slugBase = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $organizationName) ?: $organizationName), '-'));
        $slug = ($slugBase ?: 'organization') . '-' . bin2hex(random_bytes(3));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO organizations (name, slug) VALUES (?, ?)');
            $stmt->execute([$organizationName, $slug]);
            $organizationId = (int)$pdo->lastInsertId();
            $stmt = $pdo->prepare('INSERT INTO users (organization_id, name, email, password_hash, role) VALUES (?, ?, ?, ?, \'owner\')');
            $stmt->execute([$organizationId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $userId = (int)$pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e instanceof PDOException && $e->getCode() === '23000') Http::json(['error' => 'email_already_exists', 'message' => 'Questa email è già registrata.'], 409);
            throw $e;
        }
        Http::json(['data' => ['user' => ['id' => $userId, 'organization_id' => $organizationId, 'name' => $name, 'email' => $email, 'role' => 'owner'], 'token' => Auth::issueToken($userId)]], 201);
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

        $stmt = Database::connection()->prepare('SELECT id, organization_id, name, email, password_hash, role FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            RateLimiter::record($accountKey);
            RateLimiter::record($ipKey);
            Http::json(['error' => 'invalid_credentials', 'message' => 'Credenziali non valide.'], 401);
        }
        RateLimiter::clear($accountKey);
        unset($user['password_hash']);
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
        $user = Auth::user();
        unset($user['token_id']);
        Http::json(['data' => ['user' => $user]]);
    }

    public static function assertPassword(string $password): void
    {
        if (strlen($password) < 12) Http::validationError('La password deve contenere almeno 12 caratteri.');
        if (strlen($password) > 200) Http::validationError('La password è troppo lunga.');
    }
}
