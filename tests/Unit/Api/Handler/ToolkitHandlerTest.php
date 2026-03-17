<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\ToolkitHandler;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use React\Http\Message\ServerRequest;

function createHandlerWorkspace(): string
{
    $dir = sys_get_temp_dir() . '/coqui-handler-' . uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function cleanHandlerWorkspace(string $dir): void
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

test('GET /toolkits returns 200 with empty toolkits list when no packages registered', function () {
    $dir = createHandlerWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);
    $discovery = new ToolkitDiscovery(
        projectRoot: $dir,
        workspacePath: $dir,
        visibilityRegistry: $registry,
    );

    $handler = new ToolkitHandler($discovery, $registry);
    $request = new ServerRequest('GET', '/api/v1/toolkits');

    $response = $handler->list($request);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body['toolkits'])->toBe([]);
    expect($body['tools'])->toBe([]);

    cleanHandlerWorkspace($dir);
});

test('POST /toolkits/visibility sets package visibility', function () {
    $dir = createHandlerWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);
    $discovery = new ToolkitDiscovery(
        projectRoot: $dir,
        workspacePath: $dir,
        visibilityRegistry: $registry,
    );

    $handler = new ToolkitHandler($discovery, $registry);
    $request = new ServerRequest(
        'POST',
        '/api/v1/toolkits/visibility',
        ['Content-Type' => 'application/json'],
        json_encode(['target' => 'package', 'name' => 'vendor/pkg', 'visibility' => 'stub']) ?: '',
    );

    $response = $handler->setVisibility($request);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body['visibility'])->toBe('stub');
    expect($registry->getPackageVisibility('vendor/pkg'))->toBe(ToolkitVisibility::Stub);

    cleanHandlerWorkspace($dir);
});

test('POST /toolkits/visibility sets tool visibility', function () {
    $dir = createHandlerWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);
    $discovery = new ToolkitDiscovery(
        projectRoot: $dir,
        workspacePath: $dir,
        visibilityRegistry: $registry,
    );

    $handler = new ToolkitHandler($discovery, $registry);
    $request = new ServerRequest(
        'POST',
        '/api/v1/toolkits/visibility',
        ['Content-Type' => 'application/json'],
        json_encode(['target' => 'tool', 'name' => 'spawn_agent', 'visibility' => 'stub']) ?: '',
    );

    $response = $handler->setVisibility($request);
    $body = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($body['visibility'])->toBe('stub');

    cleanHandlerWorkspace($dir);
});

test('POST /toolkits/visibility returns 400 for missing fields', function () {
    $dir = createHandlerWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);
    $discovery = new ToolkitDiscovery(
        projectRoot: $dir,
        workspacePath: $dir,
        visibilityRegistry: $registry,
    );

    $handler = new ToolkitHandler($discovery, $registry);
    $request = new ServerRequest(
        'POST',
        '/api/v1/toolkits/visibility',
        ['Content-Type' => 'application/json'],
        json_encode(['target' => 'tool']) ?: '',
    );

    $response = $handler->setVisibility($request);

    expect($response->getStatusCode())->toBe(400);

    cleanHandlerWorkspace($dir);
});

test('POST /toolkits/visibility returns 403 when disabling a CANNOT_DISABLE tool', function () {
    $dir = createHandlerWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);
    $discovery = new ToolkitDiscovery(
        projectRoot: $dir,
        workspacePath: $dir,
        visibilityRegistry: $registry,
    );

    $handler = new ToolkitHandler($discovery, $registry);
    $request = new ServerRequest(
        'POST',
        '/api/v1/toolkits/visibility',
        ['Content-Type' => 'application/json'],
        json_encode(['target' => 'tool', 'name' => 'spawn_agent', 'visibility' => 'disabled']) ?: '',
    );

    $response = $handler->setVisibility($request);

    expect($response->getStatusCode())->toBe(403);

    cleanHandlerWorkspace($dir);
});

test('POST /toolkits/visibility returns 400 for invalid visibility value', function () {
    $dir = createHandlerWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);
    $discovery = new ToolkitDiscovery(
        projectRoot: $dir,
        workspacePath: $dir,
        visibilityRegistry: $registry,
    );

    $handler = new ToolkitHandler($discovery, $registry);
    $request = new ServerRequest(
        'POST',
        '/api/v1/toolkits/visibility',
        ['Content-Type' => 'application/json'],
        json_encode(['target' => 'package', 'name' => 'vendor/pkg', 'visibility' => 'invisible']) ?: '',
    );

    $response = $handler->setVisibility($request);

    expect($response->getStatusCode())->toBe(400);

    cleanHandlerWorkspace($dir);
});
