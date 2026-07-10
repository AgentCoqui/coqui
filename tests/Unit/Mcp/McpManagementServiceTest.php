<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Coqui\Mcp\McpServerManager;

it('throws a clear error when authorizing without an OAuth provider', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    $config = new McpConfig($workspace);
    $manager = new McpServerManager($config);
    $service = new McpManagementService($config, $manager, oauth: null);

    $service->authorizeServer('github', 'https://auth', 'https://token');
})->throws(RuntimeException::class, 'OAuth requires the management toolkit');
