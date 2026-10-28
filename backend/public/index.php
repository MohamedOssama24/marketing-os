<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

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

$router = new Router();
$router->add('GET', '/api/v1/health', function (): never {
    Database::connection()->query('SELECT 1');
    Http::json(['status' => 'ok', 'service' => 'marketing-os-api', 'version' => '1.1.0']);
});

$router->add('POST', '/api/v1/auth/register', [AuthController::class, 'register']);
$router->add('POST', '/api/v1/auth/login', [AuthController::class, 'login']);
$router->add('POST', '/api/v1/auth/logout', [AuthController::class, 'logout']);
$router->add('GET', '/api/v1/me', [AuthController::class, 'me']);

$router->add('GET', '/api/v1/users', [UserController::class, 'index']);
$router->add('POST', '/api/v1/users', [UserController::class, 'store']);

$router->add('GET', '/api/v1/brands', [BrandController::class, 'index']);
$router->add('POST', '/api/v1/brands', [BrandController::class, 'store']);
$router->add('GET', '/api/v1/brands/{id}', [BrandController::class, 'show']);
$router->add('PATCH', '/api/v1/brands/{id}', [BrandController::class, 'update']);
$router->add('DELETE', '/api/v1/brands/{id}', [BrandController::class, 'destroy']);

$router->add('GET', '/api/v1/assets', [AssetController::class, 'index']);
$router->add('POST', '/api/v1/assets', [AssetController::class, 'store']);
$router->add('GET', '/api/v1/assets/{id}/file', [AssetController::class, 'download']);
$router->add('DELETE', '/api/v1/assets/{id}', [AssetController::class, 'destroy']);

$router->add('GET', '/api/v1/projects', [ProjectController::class, 'index']);
$router->add('POST', '/api/v1/projects', [ProjectController::class, 'store']);
$router->add('GET', '/api/v1/projects/{id}', [ProjectController::class, 'show']);
$router->add('PATCH', '/api/v1/projects/{id}', [ProjectController::class, 'update']);
$router->add('DELETE', '/api/v1/projects/{id}', [ProjectController::class, 'destroy']);

$router->add('GET', '/api/v1/projects/{projectId}/content', [ContentController::class, 'index']);
$router->add('POST', '/api/v1/projects/{projectId}/content', [ContentController::class, 'store']);
$router->add('POST', '/api/v1/projects/{projectId}/generate', [ContentController::class, 'generate']);
$router->add('GET', '/api/v1/content/{id}', [ContentController::class, 'show']);
$router->add('PATCH', '/api/v1/content/{id}', [ContentController::class, 'update']);
$router->add('DELETE', '/api/v1/content/{id}', [ContentController::class, 'destroy']);
$router->add('POST', '/api/v1/content/{id}/rewrite', [ContentController::class, 'rewrite']);
$router->add('POST', '/api/v1/content/{id}/photo', [ContentController::class, 'uploadPhoto']);
$router->add('POST', '/api/v1/content/{id}/submit', [ContentController::class, 'submit']);
$router->add('POST', '/api/v1/content/{id}/approve', [ContentController::class, 'approve']);
$router->add('POST', '/api/v1/content/{id}/reject', [ContentController::class, 'reject']);
$router->add('POST', '/api/v1/content/{id}/schedule', [ContentController::class, 'schedule']);
$router->add('GET', '/api/v1/approvals', [ContentController::class, 'pendingApproval']);

$router->add('POST', '/api/v1/onboarding/analyze', [CampaignController::class, 'analyzeSite']);
$router->add('POST', '/api/v1/brands/{brandId}/campaigns', [CampaignController::class, 'create']);
$router->add('POST', '/api/v1/brands/{brandId}/analyze', [CampaignController::class, 'reanalyzeSite']);
$router->add('POST', '/api/v1/brands/{brandId}/goal-suggestions', [CampaignController::class, 'suggestGoals']);

$router->add('GET', '/api/v1/dashboard', [DashboardController::class, 'dashboard']);
$router->add('GET', '/api/v1/calendar', [DashboardController::class, 'calendar']);

$router->add('GET', '/api/v1/jobs/{id}', [Jobs::class, 'show']);

try {
    $router->dispatch($method, $path);
} catch (Throwable $e) {
    error_log((string)$e);
    $debug = (getenv('APP_DEBUG') ?: 'false') === 'true';
    Http::json(['error' => 'internal_server_error', 'message' => 'Errore interno.', 'detail' => $debug ? $e->getMessage() : null], 500);
}
