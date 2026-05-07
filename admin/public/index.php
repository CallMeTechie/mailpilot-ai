<?php
declare(strict_types=1);

/**
 * MailPilot Admin UI — Front Controller
 *
 * Separate app from the public API. Session-auth only (not JWT),
 * never exposed publicly — protect via nginx/VPN in production.
 */

require_once __DIR__ . '/../../backend/vendor/autoload.php';

use MailPilot\Admin\Controllers\AuthController;
use MailPilot\Admin\Controllers\DashboardController;
use MailPilot\Admin\Controllers\TenantController;
use MailPilot\Admin\Controllers\UserController;
use MailPilot\Admin\Controllers\PromptController;
use MailPilot\Admin\Controllers\AuditController;
use MailPilot\Admin\Controllers\CacheController;
use MailPilot\Admin\Kernel as AdminKernel;

session_start();

$config = require __DIR__ . '/../../backend/config/config.php';
$config['admin'] = require __DIR__ . '/../config/admin.php';

$kernel = new AdminKernel($config);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Public routes
$publicRoutes = ['/admin/login', '/admin/logout'];

// Enforce auth for everything else
if (!in_array($path, $publicRoutes, true) && empty($_SESSION['admin_user_id'])) {
	header('Location: /admin/login');
	exit;
}

$routes = [
	['GET',  '#^/admin/?$#',                   DashboardController::class, 'index'],
	['GET',  '#^/admin/login$#',               AuthController::class,      'showLogin'],
	['POST', '#^/admin/login$#',               AuthController::class,      'doLogin'],
	['GET',  '#^/admin/logout$#',              AuthController::class,      'logout'],

	['GET',  '#^/admin/tenants$#',             TenantController::class,    'list'],
	['GET',  '#^/admin/tenants/(?P<id>[^/]+)$#', TenantController::class,  'show'],
	['POST', '#^/admin/tenants/(?P<id>[^/]+)/plan$#', TenantController::class, 'updatePlan'],
	['POST', '#^/admin/tenants/(?P<id>[^/]+)/delete$#', TenantController::class, 'delete'],

	['GET',  '#^/admin/users$#',               UserController::class,      'list'],
	['GET',  '#^/admin/users/(?P<id>[^/]+)$#', UserController::class,      'show'],
	['POST', '#^/admin/users/(?P<id>[^/]+)/delete$#', UserController::class, 'delete'],

	['GET',  '#^/admin/prompts$#',             PromptController::class,    'list'],
	['GET',  '#^/admin/prompts/new$#',         PromptController::class,    'create'],
	['POST', '#^/admin/prompts$#',             PromptController::class,    'store'],
	['GET',  '#^/admin/prompts/(?P<id>[^/]+)$#', PromptController::class,  'show'],
	['POST', '#^/admin/prompts/(?P<id>[^/]+)/activate$#', PromptController::class, 'activate'],

	['GET',  '#^/admin/audit$#',               AuditController::class,     'list'],
	['GET',  '#^/admin/cache$#',               CacheController::class,     'list'],
	['POST', '#^/admin/cache/purge$#',         CacheController::class,     'purge'],
];

foreach ($routes as [$m, $pattern, $class, $action]) {
	if ($m !== $method) continue;
	if (!preg_match($pattern, $path, $matches)) continue;

	$params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
	$controller = new $class($kernel);
	$controller->$action($params);
	return;
}

http_response_code(404);
echo '<h1>404</h1>';
