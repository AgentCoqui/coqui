<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createApiSessionHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-session-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles', 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "# Caelum\n\nA calm companion.");

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $roleResolver = new RoleResolver(OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                    'coder' => 'anthropic/claude-sonnet-4-20250514',
                ],
            ],
        ],
    ]));
    $profileDiscovery = new ProfileDiscovery($workspacePath);

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new SessionHandler($storage, $roleResolver, $profileDiscovery),
    ];
}

function cleanupApiSessionHandlerFixture(array $fixture): void
{
    cleanupSqliteTestDb($fixture['dbPath']);
    cleanupTestTree($fixture['workspacePath']);
}

test('session handler create rejects unknown profile', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'orchestrator',
                'profile' => 'unknown-profile',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
        expect($body['error'])->toContain('Unknown profile "unknown-profile"');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create persists a valid profile', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'orchestrator',
                'profile' => 'Caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $session = $fixture['storage']->getSession($body['id']);

        expect($response->getStatusCode())->toBe(201);
        expect($body['profile'])->toBe('caelum');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler update rejects unknown role instead of silently resolving fallback model', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $request = new ServerRequest(
            'PATCH',
            '/api/v1/sessions/' . $sessionId,
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'does-not-exist',
            ]) ?: '',
        );

        $response = $fixture['handler']->update($request, $sessionId);
        $body = json_decode((string) $response->getBody(), true);
        $session = $fixture['storage']->getSession($sessionId);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
        expect($session['model_role'])->toBe('orchestrator');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler update accepts clearing or setting a profile', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $setRequest = new ServerRequest(
            'PATCH',
            '/api/v1/sessions/' . $sessionId,
            ['Content-Type' => 'application/json'],
            json_encode([
                'profile' => 'caelum',
            ]) ?: '',
        );

        $setResponse = $fixture['handler']->update($setRequest, $sessionId);
        $setBody = json_decode((string) $setResponse->getBody(), true);

        expect($setResponse->getStatusCode())->toBe(200);
        expect($setBody['profile'])->toBe('caelum');

        $clearRequest = new ServerRequest(
            'PATCH',
            '/api/v1/sessions/' . $sessionId,
            ['Content-Type' => 'application/json'],
            json_encode([
                'profile' => '',
            ]) ?: '',
        );

        $clearResponse = $fixture['handler']->update($clearRequest, $sessionId);
        $clearBody = json_decode((string) $clearResponse->getBody(), true);

        expect($clearResponse->getStatusCode())->toBe(200);
        expect($clearBody['profile'])->toBeNull();
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});