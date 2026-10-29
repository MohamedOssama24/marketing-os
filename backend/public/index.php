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

// Link tracciati pubblici: fuori dalle API perché li apre chiunque clicchi su un post.
if (preg_match('#^/r/([A-Za-z0-9]{4,16})$#', $path, $linkMatch) && in_array($method, ['GET', 'HEAD'], true)) {
    LinkController::redirect($linkMatch[1]);
}
// Report mensile aperto dal cliente con il link pubblico, senza accesso.
if (preg_match('#^/api/v1/public/reports/([a-f0-9]{32})(/logo)?$#', $path, $reportMatch) && $method === 'GET') {
    empty($reportMatch[2]) ? ReportController::publicShow($reportMatch[1]) : ReportController::publicLogo($reportMatch[1]);
}
// Audit gratuito aperto dal potenziale cliente.
if (preg_match('#^/api/v1/public/audits/([a-f0-9]{32})$#', $path, $auditMatch) && $method === 'GET') {
    ProspectController::publicShow($auditMatch[1]);
}
// Portale del cliente: approvazioni, programmazione, report e richieste, senza accesso.
if (preg_match('#^/api/v1/public/portal/([a-f0-9]{32})(/.*)?$#', $path, $portalMatch)) {
    PortalController::publicRoute($portalMatch[1], $portalMatch[2] ?? '', $method);
}

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
$router->add('POST', '/api/v1/content/{id}/video', [ContentController::class, 'createVideo']);
$router->add('POST', '/api/v1/content/{id}/publish', [ContentController::class, 'markPublished']);
$router->add('POST', '/api/v1/content/{id}/unpublish', [ContentController::class, 'unpublish']);
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

// Sponsorizzate e fatturazione ai clienti
$router->add('GET', '/api/v1/ad-spend', [AdSpendController::class, 'index']);
$router->add('POST', '/api/v1/ad-spend', [AdSpendController::class, 'store']);
$router->add('GET', '/api/v1/ad-spend/summary', [AdSpendController::class, 'summary']);
$router->add('PATCH', '/api/v1/ad-spend/{id}', [AdSpendController::class, 'update']);
$router->add('DELETE', '/api/v1/ad-spend/{id}', [AdSpendController::class, 'destroy']);
$router->add('POST', '/api/v1/brands/{brandId}/ad-spend/import', [AdSpendController::class, 'import']);
$router->add('GET', '/api/v1/brands/{brandId}/ads-setup', [AdsSetupController::class, 'show']);
$router->add('PATCH', '/api/v1/brands/{brandId}/ads-setup', [AdsSetupController::class, 'update']);
$router->add('GET', '/api/v1/meta-rules', [AdPlanController::class, 'rules']);
$router->add('POST', '/api/v1/meta-rules/seen', [AdPlanController::class, 'markRulesSeen']);
$router->add('POST', '/api/v1/meta-rules/refresh', [AdPlanController::class, 'refreshRules']);
$router->add('POST', '/api/v1/ad-plans/check', [AdPlanController::class, 'check']);
$router->add('GET', '/api/v1/ad-plans', [AdPlanController::class, 'index']);
$router->add('POST', '/api/v1/ad-plans', [AdPlanController::class, 'store']);
$router->add('GET', '/api/v1/ad-plans/{id}', [AdPlanController::class, 'show']);
$router->add('PATCH', '/api/v1/ad-plans/{id}', [AdPlanController::class, 'update']);
$router->add('DELETE', '/api/v1/ad-plans/{id}', [AdPlanController::class, 'destroy']);
$router->add('POST', '/api/v1/ad-plans/{id}/review', [AdPlanController::class, 'review']);

$router->add('GET', '/api/v1/billing/clients', [BillingController::class, 'clients']);
$router->add('PATCH', '/api/v1/billing/clients/{brandId}', [BillingController::class, 'updateClient']);
$router->add('GET', '/api/v1/invoices', [BillingController::class, 'index']);
$router->add('POST', '/api/v1/invoices/prepare', [BillingController::class, 'prepare']);
$router->add('GET', '/api/v1/invoices/export', [BillingController::class, 'export']);
$router->add('GET', '/api/v1/invoices/{id}', [BillingController::class, 'show']);
$router->add('PATCH', '/api/v1/invoices/{id}', [BillingController::class, 'update']);
$router->add('DELETE', '/api/v1/invoices/{id}', [BillingController::class, 'destroy']);
$router->add('POST', '/api/v1/invoices/{id}/lines', [BillingController::class, 'addLine']);
$router->add('DELETE', '/api/v1/invoices/{id}/lines/{lineId}', [BillingController::class, 'deleteLine']);
$router->add('POST', '/api/v1/invoices/{id}/issue', [BillingController::class, 'issue']);
$router->add('POST', '/api/v1/invoices/{id}/paid', [BillingController::class, 'markPaid']);
$router->add('POST', '/api/v1/invoices/{id}/reopen', [BillingController::class, 'reopen']);

// Link tracciati e clic
$router->add('GET', '/api/v1/links', [LinkController::class, 'index']);
$router->add('POST', '/api/v1/links', [LinkController::class, 'store']);
$router->add('GET', '/api/v1/links/summary', [LinkController::class, 'summary']);
$router->add('DELETE', '/api/v1/links/{id}', [LinkController::class, 'destroy']);

// Report mensili per i clienti
$router->add('GET', '/api/v1/reports', [ReportController::class, 'index']);
$router->add('GET', '/api/v1/brands/{brandId}/report', [ReportController::class, 'show']);
$router->add('PATCH', '/api/v1/brands/{brandId}/report', [ReportController::class, 'update']);
$router->add('POST', '/api/v1/brands/{brandId}/report/share', [ReportController::class, 'share']);
$router->add('POST', '/api/v1/brands/{brandId}/report/unshare', [ReportController::class, 'unshare']);

// Potenziali clienti e audit gratuito
$router->add('GET', '/api/v1/prospects', [ProspectController::class, 'index']);
$router->add('POST', '/api/v1/prospects', [ProspectController::class, 'store']);
$router->add('GET', '/api/v1/prospects/{id}', [ProspectController::class, 'show']);
$router->add('PATCH', '/api/v1/prospects/{id}', [ProspectController::class, 'update']);
$router->add('DELETE', '/api/v1/prospects/{id}', [ProspectController::class, 'destroy']);
$router->add('POST', '/api/v1/prospects/{id}/reanalyze', [ProspectController::class, 'reanalyze']);
$router->add('POST', '/api/v1/prospects/{id}/share', [ProspectController::class, 'share']);
$router->add('POST', '/api/v1/prospects/{id}/unshare', [ProspectController::class, 'unshare']);
$router->add('POST', '/api/v1/prospects/{id}/convert', [ProspectController::class, 'convert']);

// Stile appreso dall'AI, nuovi formati da un contenuto
$router->add('GET', '/api/v1/brands/{brandId}/style-examples', [StyleController::class, 'index']);
$router->add('DELETE', '/api/v1/style-examples/{id}', [StyleController::class, 'destroy']);
$router->add('POST', '/api/v1/content/{id}/repurpose', [ContentController::class, 'repurpose']);

// Ore lavorate e redditività per cliente
$router->add('GET', '/api/v1/time-entries', [ProfitController::class, 'entries']);
$router->add('POST', '/api/v1/time-entries', [ProfitController::class, 'storeEntry']);
$router->add('DELETE', '/api/v1/time-entries/{id}', [ProfitController::class, 'destroyEntry']);
$router->add('GET', '/api/v1/profitability', [ProfitController::class, 'summary']);
$router->add('PATCH', '/api/v1/profitability/settings', [ProfitController::class, 'updateSettings']);

// Recensioni, ricorrenze, avvisi
$router->add('GET', '/api/v1/review-replies', [ReviewController::class, 'index']);
$router->add('POST', '/api/v1/review-replies', [ReviewController::class, 'store']);
$router->add('DELETE', '/api/v1/review-replies/{id}', [ReviewController::class, 'destroy']);
$router->add('POST', '/api/v1/brands/{brandId}/review-replies/suggest', [ReviewController::class, 'suggest']);
$router->add('GET', '/api/v1/occasions', [OccasionController::class, 'index']);
$router->add('GET', '/api/v1/alerts', [AlertController::class, 'index']);

// Portale del cliente e concorrenti
$router->add('GET', '/api/v1/brands/{brandId}/portal', [PortalController::class, 'show']);
$router->add('POST', '/api/v1/brands/{brandId}/portal/enable', [PortalController::class, 'enable']);
$router->add('POST', '/api/v1/brands/{brandId}/portal/disable', [PortalController::class, 'disable']);
$router->add('PATCH', '/api/v1/client-requests/{id}', [PortalController::class, 'updateRequest']);
$router->add('GET', '/api/v1/brands/{brandId}/competitors', [CompetitorController::class, 'index']);
$router->add('POST', '/api/v1/brands/{brandId}/competitors', [CompetitorController::class, 'store']);
$router->add('POST', '/api/v1/competitors/{id}/refresh', [CompetitorController::class, 'refresh']);
$router->add('DELETE', '/api/v1/competitors/{id}', [CompetitorController::class, 'destroy']);
$router->add('GET', '/api/v1/brands/{brandId}/competition', [CompetitionController::class, 'show']);
$router->add('POST', '/api/v1/brands/{brandId}/competition', [CompetitionController::class, 'start']);
$router->add('POST', '/api/v1/brands/{brandId}/competition/apply', [CompetitionController::class, 'apply']);
$router->add('POST', '/api/v1/brands/{brandId}/competition/undo', [CompetitionController::class, 'undo']);
$router->add('GET', '/api/v1/brands/{brandId}/site-analysis', [SiteAnalysisController::class, 'show']);
$router->add('POST', '/api/v1/brands/{brandId}/site-analysis', [SiteAnalysisController::class, 'start']);

// Pannello della piattaforma (solo amministratori di piattaforma)
$router->add('GET', '/api/v1/admin/overview', [AdminController::class, 'overview']);
$router->add('GET', '/api/v1/admin/organizations', [AdminController::class, 'organizations']);
$router->add('GET', '/api/v1/admin/organizations/{id}', [AdminController::class, 'organization']);
$router->add('PATCH', '/api/v1/admin/organizations/{id}', [AdminController::class, 'updateNotes']);
$router->add('POST', '/api/v1/admin/organizations/{id}/suspend', [AdminController::class, 'suspend']);
$router->add('POST', '/api/v1/admin/organizations/{id}/reactivate', [AdminController::class, 'reactivate']);

try {
    $router->dispatch($method, $path);
} catch (Throwable $e) {
    error_log((string)$e);
    $debug = (getenv('APP_DEBUG') ?: 'false') === 'true';
    Http::json(['error' => 'internal_server_error', 'message' => 'Errore interno.', 'detail' => $debug ? $e->getMessage() : null], 500);
}
