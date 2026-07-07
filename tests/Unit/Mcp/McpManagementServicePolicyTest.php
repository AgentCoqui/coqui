<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Coqui\Mcp\McpServerManager;
use CoquiBot\Coqui\Mcp\Support\McpServerPolicy;

test('mcp management service rejects add server commands blocked by policy', function () {
    $path = sys_get_temp_dir() . '/mcp-policy-add-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        oauth: null,
        loadingStore: null,
        policy: new McpServerPolicy(
            allowedStdioCommands: [['npx', '-y', '@modelcontextprotocol/server-github']],
        ),
    );

    expect(fn() => $service->addServer('fetch', 'uvx', ['mcp-server-fetch']))
        ->toThrow(InvalidArgumentException::class, 'allowed policy');

    rmdir($path);
});

test('mcp management service rejects updates that move a server onto a blocked command tuple', function () {
    $path = sys_get_temp_dir() . '/mcp-policy-update-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->addServer('github', [
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-github'],
    ]);
    $config->save();

    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        oauth: null,
        loadingStore: null,
        policy: new McpServerPolicy(
            deniedStdioCommands: [['uvx', 'mcp-server-fetch']],
        ),
    );

    expect(fn() => $service->updateServer('github', 'uvx', ['mcp-server-fetch']))
        ->toThrow(InvalidArgumentException::class, 'blocked by policy');

    unlink($path . '/mcp.json');
    rmdir($path);
});
