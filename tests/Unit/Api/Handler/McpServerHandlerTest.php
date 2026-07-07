<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\McpServerHandler;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\McpManagementService;
use CoquiBot\Coqui\Mcp\McpServerManager;
use CoquiBot\Coqui\Mcp\Support\McpServerPolicy;
use CoquiBot\Coqui\Mcp\Support\ServerLoadingModeStore;
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

function createMcpHandlerFixture(?McpServerPolicy $policy = null): array
{
    $workspace = createMcpHandlerWorkspace();
    $config = new McpConfig($workspace);
    $service = new McpManagementService(
        $config,
        new McpServerManager($config),
        null,
        new ServerLoadingModeStore($workspace),
        $policy,
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

test('mcp server api persists description and supports rename via patch', function () {
    $fixture = createMcpHandlerFixture();

    try {
        $create = $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/mcp/servers',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'github',
                'description' => 'GitHub MCP server',
                'command' => 'npx',
                'args' => ['-y', '@modelcontextprotocol/server-github'],
            ]) ?: '',
        ));

        $createBody = json_decode((string) $create->getBody(), true);

        expect($create->getStatusCode())->toBe(201);
        expect($createBody['server']['description'])->toBe('GitHub MCP server');

        $update = $fixture['router']->dispatch(new ServerRequest(
            'PATCH',
            '/api/v1/mcp/servers/github',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'github-primary',
                'description' => 'Primary GitHub MCP server',
            ]) ?: '',
        ));

        $updateBody = json_decode((string) $update->getBody(), true);

        expect($update->getStatusCode())->toBe(200);
        expect($updateBody['server']['name'])->toBe('github-primary');
        expect($updateBody['server']['description'])->toBe('Primary GitHub MCP server');

        $old = $fixture['router']->dispatch(new ServerRequest('GET', '/api/v1/mcp/servers/github'));
        expect($old->getStatusCode())->toBe(404);
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

test('mcp server api rejects create requests blocked by stdio command policy', function () {
    $fixture = createMcpHandlerFixture(new McpServerPolicy(
        allowedStdioCommands: [['npx', '-y', '@modelcontextprotocol/server-github']],
    ));

    try {
        $response = $fixture['router']->dispatch(new ServerRequest(
            'POST',
            '/api/v1/mcp/servers',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'fetch',
                'command' => 'uvx',
                'args' => ['mcp-server-fetch'],
            ]) ?: '',
        ));

        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
        expect($body['error'])->toContain('allowed policy');
    } finally {
        cleanMcpHandlerWorkspace($fixture['workspace']);
    }
});