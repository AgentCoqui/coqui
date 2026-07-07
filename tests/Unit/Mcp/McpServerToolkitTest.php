<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Coqui\Mcp\McpServerManager;
use CoquiBot\Coqui\Mcp\McpServerToolkit;

it('derives a stable loading key from the server name', function (): void {
    expect(McpServerToolkit::loadingKeyForServer('GitHub'))->toBe('McpServer:github');
});

it('exposes the server toolkit loading key via the instance', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-test-' . uniqid();
    $config = new McpConfig($workspace);
    $service = new McpManagementService($config, new McpServerManager($config));
    $toolkit = new McpServerToolkit('github', $service);

    expect($toolkit->toolkitLoadingKey())->toBe('McpServer:github');
});
