<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\McpServerHandler;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Toolkits\Mcp\Auth\OAuthHandler;
use CoquiBot\Toolkits\Mcp\Config\McpConfig;
use CoquiBot\Toolkits\Mcp\McpManagementService;
use CoquiBot\Toolkits\Mcp\McpServerManager;
use CoquiBot\Toolkits\Mcp\Support\ServerLoadingModeStore;
use React\Http\Message\ServerRequest;

function createMcpHandlerWorkspace(): string
{
    $dir = sys_get_temp_dir() . '/coqui-mcp-handler-' . uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function cleanMcpHandlerWorkspace(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($dir);
}

function createMcpHandlerFixture(): array
{
    $workspace = createMcpHandlerWorkspace();
    $config = new McpConfig($workspace);
    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        new OAuthHandler($workspace),
        new ServerLoadingModeStore($workspace),
    );

    $router = new Router();
    (new McpServerHandler($service))->register($router);

    return [
        'workspace' => $workspace,
        'router' => $router,
    ];
}

test('mcp server api creates servers with auto loading mode', function () {
    $fixture = createMcpHandlerFixture();

    try {
        $response = $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/mcp/servers',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'github',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-github'],
            ]) ?: '',
        ));

        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['server']['name'])->toBe('github');
        expect($body['server']['loadingMode'])->toBe('auto');
    } finally {
        cleanMcpHandlerWorkspace($fixture['workspace']);
    }
});

test('mcp server api updates server loading mode through promote demote and auto routes', function () {
    $fixture = createMcpHandlerFixture();

    try {
        $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/mcp/servers',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'github',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-github'],
            ]) ?: '',
        ));

        $promote = $fixture['router']->dispatch(new ServerRequest('POST', '/api/v1/mcp/servers/github/promote'));
        $promoteBody = json_decode((string) $promote->getBody(), true);
        expect($promote->getStatusCode())->toBe(200);
        expect($promoteBody['server']['loadingMode'])->toBe('eager');

        $demote = $fixture['router']->dispatch(new ServerRequest('POST', '/api/v1/mcp/servers/github/demote'));
        $demoteBody = json_decode((string) $demote->getBody(), true);
        expect($demote->getStatusCode())->toBe(200);
        expect($demoteBody['server']['loadingMode'])->toBe('deferred');

        $auto = $fixture['router']->dispatch(new ServerRequest('POST', '/api/v1/mcp/servers/github/auto'));
        $autoBody = json_decode((string) $auto->getBody(), true);
        expect($auto->getStatusCode())->toBe(200);
        expect($autoBody['server']['loadingMode'])->toBe('auto');
    } finally {
        cleanMcpHandlerWorkspace($fixture['workspace']);
    }
});