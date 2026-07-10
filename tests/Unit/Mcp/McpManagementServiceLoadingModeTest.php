<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Coqui\Mcp\McpServerManager;
use CoquiBot\Coqui\Mcp\Support\ServerLoadingModeStore;

test('mcp management service persists per-server loading mode overrides', function () {
    $path = sys_get_temp_dir() . '/mcp-loading-test-' . uniqid();
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
        loadingStore: new ServerLoadingModeStore($path),
    );

    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('auto');

    $service->promoteServer('github');
    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('eager');

    $service->demoteServer('github');
    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('deferred');

    $service->autoServer('github');
    expect($service->getServerSnapshot('github')['loadingMode'])->toBe('auto');

    unlink($path . '/mcp.json');
    unlink($path . '/toolkit-loading.json');
    rmdir($path);
});

test('mcp management service can rename servers and preserve description plus loading mode', function () {
    $path = sys_get_temp_dir() . '/mcp-loading-test-' . uniqid();
    mkdir($path, 0o755, true);

    $config = new McpConfig($path);
    $config->addServer('github', [
        'command' => 'npx',
        'args' => ['-y', '@modelcontextprotocol/server-github'],
        'description' => 'GitHub integration',
    ]);
    $config->save();

    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        oauth: null,
        loadingStore: new ServerLoadingModeStore($path),
    );

    $service->promoteServer('github');
    $result = $service->updateServer(
        'github',
        newName: 'github-prod',
        descriptionProvided: true,
        description: 'Primary GitHub integration',
    );

    expect($result['name'])->toBe('github-prod')
        ->and($result['applied'])->toBe('next_turn')
        ->and($service->getServerSnapshot('github-prod')['description'])->toBe('Primary GitHub integration')
        ->and($service->getServerSnapshot('github-prod')['loadingMode'])->toBe('eager')
        ->and(fn() => $service->getServerSnapshot('github'))->toThrow(RuntimeException::class);

    unlink($path . '/mcp.json');
    unlink($path . '/toolkit-loading.json');
    rmdir($path);
});

test('mcp management service re-reads externally modified config files', function () {
    $path = sys_get_temp_dir() . '/mcp-loading-test-' . uniqid();
    mkdir($path, 0o755, true);

    file_put_contents($path . '/mcp.json', json_encode([
        'mcpServers' => [
            'github' => [
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-github'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    $config = new McpConfig($path);
    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        oauth: null,
        loadingStore: new ServerLoadingModeStore($path),
    );

    expect($service->configuredServerNames())->toBe(['github']);

    file_put_contents($path . '/mcp.json', json_encode([
        'mcpServers' => [
            'sonic-pi' => [
                'command' => 'npx',
                'args' => ['-y', 'sonic-pi-mcp'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    expect($service->configuredServerNames())->toBe(['sonic-pi'])
        ->and($service->getServerSnapshot('sonic-pi')['command'])->toBe('npx');

    unlink($path . '/mcp.json');
    rmdir($path);
});

test('server loading mode store trims windows style trailing separators', function () {
    $store = new ServerLoadingModeStore('C:\\workspace\\');
    $property = new ReflectionProperty(ServerLoadingModeStore::class, 'filePath');

    expect($property->getValue($store))->toBe('C:\\workspace/toolkit-loading.json');
});
