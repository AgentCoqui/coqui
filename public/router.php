<?php

declare(strict_types=1);

/**
 * Coqui Dashboard — Router Entry Point
 *
 * Serves as the router script for PHP's built-in server:
 *   php -S localhost:8080 -t public/ public/router.php
 *
 * Static files (CSS, JS, vendor assets) are served directly.
 * API routes are dispatched via bramus/router.
 * All other requests serve the SPA shell (index.html).
 */

// Serve static files directly when using php -S
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if ($uri !== '/' && $uri !== null) {
    $staticFile = __DIR__ . $uri;

    if (is_file($staticFile)) {
        // Let PHP's built-in server handle it
        return false;
    }
}

// Bootstrap
require_once __DIR__ . '/vendor/autoload.php';

use CoquiBot\Dashboard\Controller\ApiController;
use CoquiBot\Dashboard\Controller\ConfigController;
use CoquiBot\Dashboard\Controller\DocsController;
use CoquiBot\Dashboard\Controller\FileController;
use CoquiBot\Dashboard\Controller\WallpaperController;
use CoquiBot\Dashboard\Service\DashboardQueryService;

// Resolve paths
$projectRoot = dirname(__DIR__);
$workspacePath = getenv('COQUI_WORKSPACE') ?: $projectRoot . '/.workspace';
$configPath = getenv('COQUI_CONFIG') ?: $projectRoot . '/openclaw.json';
$dbPath = $workspacePath . '/data/coqui.db';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS for local development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Initialize database connection
$db = null;
$queryService = null;

if (is_file($dbPath)) {
    try {
        $db = new PDO("sqlite:{$dbPath}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
        $queryService = new DashboardQueryService($db);
    } catch (PDOException $e) {
        // DB not available — API endpoints will return errors
    }
}

// Initialize controllers
$apiController = ($db !== null && $queryService !== null)
    ? new ApiController($db, $queryService)
    : null;

$configController = new ConfigController($configPath, $workspacePath);
$fileController = new FileController($workspacePath);
$docsController = new DocsController($projectRoot . '/docs');
$wallpaperController = new WallpaperController($workspacePath);

// Set up router
$router = new \Bramus\Router\Router();

// Health check
$router->get('/api/health', function () use ($db, $workspacePath, $configPath) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'database' => $db !== null ? 'connected' : 'unavailable',
        'workspace' => is_dir($workspacePath),
        'config' => is_file($configPath),
        'workspace_path' => $workspacePath,
        'config_path' => $configPath,
    ]);
});

// Stats endpoints (flat — /api/stats, /api/tokens, etc.)
$router->get('/api/stats', function () use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->stats();
});

$router->get('/api/tokens', function () use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->tokensOverTime();
});

$router->get('/api/tools', function () use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->toolUsage();
});

$router->get('/api/models', function () use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->modelUsage();
});

$router->get('/api/filters', function () use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->filterOptions();
});

// Session endpoints (flat — session IDs may contain hyphens)
$router->get('/api/sessions', function () use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->sessions();
});

$router->get('/api/sessions/([\w-]+)', function (string $id) use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->session($id);
});

$router->get('/api/sessions/([\w-]+)/messages', function (string $id) use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->sessionMessages($id);
});

$router->get('/api/sessions/([\w-]+)/turns', function (string $id) use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->sessionTurns($id);
});

$router->get('/api/sessions/([\w-]+)/turns/([\w-]+)', function (string $id, string $turnId) use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->sessionTurn($id, $turnId);
});

$router->get('/api/sessions/([\w-]+)/child-runs', function (string $id) use ($apiController) {
    if ($apiController === null) { jsonError('Database not available'); return; }
    $apiController->sessionChildRuns($id);
});

// Audit log
$router->get('/api/audit', function () use ($apiController) {
    if ($apiController === null) {
        jsonError('Database not available');
        return;
    }
    $apiController->auditLog();
});

// Config endpoints
$router->get('/api/config', fn() => $configController->getConfig());
$router->put('/api/config', fn() => $configController->updateConfig());

// Credential endpoints
$router->get('/api/credentials', fn() => $configController->listCredentials());
$router->post('/api/credentials', fn() => $configController->setCredential());
$router->delete('/api/credentials/([\w-]+)', fn(string $key) => $configController->deleteCredential($key));

// Env endpoints
$router->get('/api/env', fn() => $configController->getEnv());
$router->put('/api/env', fn() => $configController->updateEnv());

// File endpoints
$router->get('/api/files/tree', fn() => $fileController->tree());
$router->get('/api/files/read', fn() => $fileController->readFile());
$router->put('/api/files/write', fn() => $fileController->writeFile());
$router->get('/api/files', fn() => $fileController->listFiles());

// Documentation endpoints
$router->get('/api/docs', fn() => $docsController->list());
$router->get('/api/docs/([^/]+)', fn(string $name) => $docsController->read($name));

// Wallpaper endpoints
$router->get('/api/wallpapers', fn() => $wallpaperController->list());
$router->post('/api/wallpapers', fn() => $wallpaperController->upload());
$router->get('/api/wallpapers/([^/]+)/file', fn(string $name) => $wallpaperController->serve($name));
$router->delete('/api/wallpapers/([^/]+)', fn(string $name) => $wallpaperController->delete($name));

// SPA fallback — serve index.html for all non-API routes
$router->set404(function () {
    $indexPath = __DIR__ . '/index.html';

    if (is_file($indexPath)) {
        header('Content-Type: text/html');
        readfile($indexPath);
    } else {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not found']);
    }
});

$router->run();

// Helper
function jsonError(string $message, int $status = 503): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
}
