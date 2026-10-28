<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Http.php';
require_once __DIR__ . '/../src/Auth.php';

$origin = getenv('CORS_ORIGIN') ?: 'http://localhost:4200';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($requestOrigin !== '' && hash_equals($origin, $requestOrigin)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') { http_response_code(204); exit; }

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rtrim($path, '/') ?: '/';

try {
    if ($method === 'GET' && $path === '/api/v1/health') {
        Database::connection()->query('SELECT 1');
        Http::json(['status' => 'ok', 'service' => 'marketing-os-api', 'version' => '1.0.0']);
    }

    if ($method === 'POST' && $path === '/api/v1/auth/register') {
        $data = Http::body();
        $name = Http::requiredString($data, 'name', 120);
        $email = strtolower(Http::requiredString($data, 'email', 190));
        $password = (string)($data['password'] ?? '');
        $organizationName = Http::requiredString($data, 'organization_name', 160);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) Http::json(['error' => 'validation_error', 'message' => 'Email non valida.'], 422);
        if (strlen($password) < 12) Http::json(['error' => 'validation_error', 'message' => 'La password deve contenere almeno 12 caratteri.'], 422);
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

    if ($method === 'POST' && $path === '/api/v1/auth/login') {
        $data = Http::body();
        $email = strtolower(Http::requiredString($data, 'email', 190));
        $password = (string)($data['password'] ?? '');
        $stmt = Database::connection()->prepare('SELECT id, organization_id, name, email, password_hash, role FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) Http::json(['error' => 'invalid_credentials', 'message' => 'Credenziali non valide.'], 401);
        unset($user['password_hash']);
        Http::json(['data' => ['user' => $user, 'token' => Auth::issueToken((int)$user['id'])]]);
    }

    if ($method === 'GET' && $path === '/api/v1/me') {
        $user = Auth::user(); unset($user['token_id']);
        Http::json(['data' => ['user' => $user]]);
    }

    if ($method === 'POST' && $path === '/api/v1/auth/logout') {
        $token = Http::bearerToken();
        if (!$token) Http::json(['error' => 'unauthorized', 'message' => 'Token Bearer mancante.'], 401);
        $stmt = Database::connection()->prepare('DELETE FROM api_tokens WHERE token_hash=?');
        $stmt->execute([hash('sha256', $token)]);
        Http::json(['data' => ['logged_out' => true]]);
    }

    if ($method === 'GET' && $path === '/api/v1/projects') {
        $user = Auth::user();
        $stmt = Database::connection()->prepare('SELECT id, name, objective, target_audience, budget_monthly, status, brand_profile_id, created_at, updated_at FROM projects WHERE organization_id=? ORDER BY created_at DESC LIMIT 100');
        $stmt->execute([(int)$user['organization_id']]);
        Http::json(['data' => $stmt->fetchAll()]);
    }

    if ($method === 'POST' && $path === '/api/v1/projects') {
        $user = Auth::user();
        $data = Http::body();
        $name = Http::requiredString($data, 'name', 180);
        $objective = Http::requiredString($data, 'objective', 500);
        $audience = isset($data['target_audience']) ? trim((string)$data['target_audience']) : null;
        $budget = $data['budget_monthly'] ?? null;
        if ($budget !== null && (!is_numeric($budget) || (float)$budget < 0 || (float)$budget > 10000000)) Http::json(['error' => 'validation_error', 'message' => 'budget_monthly deve essere un numero non negativo valido.'], 422);
        $brandId = $data['brand_profile_id'] ?? null;
        $pdo = Database::connection();
        if ($brandId !== null) {
            $check = $pdo->prepare('SELECT id FROM brand_profiles WHERE id=? AND organization_id=?');
            $check->execute([(int)$brandId, (int)$user['organization_id']]);
            if (!$check->fetch()) Http::json(['error' => 'invalid_brand', 'message' => 'Il brand non esiste nella tua organizzazione.'], 422);
        }
        $stmt = $pdo->prepare('INSERT INTO projects (organization_id, brand_profile_id, created_by, name, objective, target_audience, budget_monthly) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([(int)$user['organization_id'], $brandId === null ? null : (int)$brandId, (int)$user['id'], $name, $objective, $audience, $budget]);
        $id = (int)$pdo->lastInsertId();
        $stmt = $pdo->prepare('SELECT id, name, objective, target_audience, budget_monthly, status, brand_profile_id, created_at, updated_at FROM projects WHERE id=? AND organization_id=?');
        $stmt->execute([$id, (int)$user['organization_id']]);
        Http::json(['data' => $stmt->fetch()], 201);
    }

    Http::json(['error' => 'not_found', 'message' => 'Endpoint non trovato.'], 404);
} catch (Throwable $e) {
    error_log((string)$e);
    $debug = (getenv('APP_DEBUG') ?: 'false') === 'true';
    Http::json(['error' => 'internal_server_error', 'message' => 'Errore interno.', 'detail' => $debug ? $e->getMessage() : null], 500);
}
