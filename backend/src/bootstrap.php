<?php
declare(strict_types=1);

$vendorAutoload = getenv('COMPOSER_AUTOLOAD') ?: '/opt/deps/vendor/autoload.php';
if (is_file($vendorAutoload)) require_once $vendorAutoload;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Tenant.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/Audit.php';
require_once __DIR__ . '/Router.php';
require_once __DIR__ . '/Jobs.php';
require_once __DIR__ . '/Ai/MarketingAi.php';
require_once __DIR__ . '/Ai/AiUsage.php';
require_once __DIR__ . '/Ai/FakeMarketingAi.php';
require_once __DIR__ . '/Ai/ClaudeMarketingAi.php';
require_once __DIR__ . '/Ai/GeminiMarketingAi.php';
require_once __DIR__ . '/Ai/GroqMarketingAi.php';
require_once __DIR__ . '/Ai/Growth.php';
require_once __DIR__ . '/Site/SafeHttp.php';
require_once __DIR__ . '/Site/SiteReader.php';
require_once __DIR__ . '/Video/FontProvider.php';
require_once __DIR__ . '/Video/VideoStoryboard.php';
require_once __DIR__ . '/Video/VideoRenderer.php';
require_once __DIR__ . '/Ads/AdsCsvParser.php';
require_once __DIR__ . '/Ads/MetaRules.php';
require_once __DIR__ . '/Ads/MetaPolicyMonitor.php';
require_once __DIR__ . '/Ads/MetaAdLibrary.php';
foreach (glob(__DIR__ . '/Controllers/*.php') ?: [] as $controller) require_once $controller;
