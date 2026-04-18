<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createApiSessionHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-session-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles', 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    mkdir($workspacePath . '/profiles/caelum/roles', 0755, true);
    mkdir($workspacePath . '/roles', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n\n# Caelum\n\nA calm companion.");
    file_put_contents($workspacePath . '/roles/analyst.md', <<<MD
---
name: analyst
display_name: Analyst
description: Baseline analyst role
access_level: readonly
model: openai/gpt-4.1-mini
---

You are an analyst.
MD);
    file_put_contents($workspacePath . '/profiles/caelum/roles/analyst.md', <<<MD
---
name: analyst
display_name: Analyst
description: Caelum analyst role override
access_level: readonly
model: anthropic/claude-sonnet-4-20250514
---

You are Caelum in analyst mode.
MD);

    $dbPath = $workspacePath . '/coqui.db';
    $storage = new SessionStorage($dbPath);
    $roleDiscovery = new RoleDiscovery($workspacePath, dirname(__DIR__, 4));
    $profileDiscovery = new ProfileDiscovery($workspacePath);
    $roleResolver = new RoleResolver(OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ]), roleDiscovery: $roleDiscovery, profileDiscovery: $profileDiscovery);

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
        expect($body['model'])->toBe('ollama/qwen3:latest');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
        expect($session['model'])->toBe('ollama/qwen3:latest');
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
                'model_role' => 'analyst',
                'profile' => 'caelum',
            ]) ?: '',
        );

        $setResponse = $fixture['handler']->update($setRequest, $sessionId);
        $setBody = json_decode((string) $setResponse->getBody(), true);

        expect($setResponse->getStatusCode())->toBe(200);
        expect($setBody['profile'])->toBe('caelum');
        expect($setBody['model'])->toBe('anthropic/claude-sonnet-4-20250514');

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
        expect($clearBody['model'])->toBe('openai/gpt-4.1-mini');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create resolves profile role override models when role and profile are both set', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'analyst',
                'profile' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['model_role'])->toBe('analyst');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler resolve reuses the latest scoped interactive session', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $profileSessionId = $fixture['storage']->createSession('orchestrator', 'anthropic/claude-sonnet-4-20250514', 'caelum');
        $backgroundSessionId = $fixture['storage']->createSession('orchestrator', 'anthropic/claude-sonnet-4-20250514', 'caelum');
        $fixture['storage']->createTask($backgroundSessionId, 'Background task');

        $fixture['storage']->getPdo()
            ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
            ->execute(['updated_at' => '2026-01-03T00:00:00+00:00', 'id' => $profileSessionId]);
        $fixture['storage']->getPdo()
            ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
            ->execute(['updated_at' => '2026-01-05T00:00:00+00:00', 'id' => $backgroundSessionId]);

        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions/resolve',
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'orchestrator',
                'profile' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->resolve($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['id'])->toBe($profileSessionId);
        expect($body['created'])->toBeFalse();
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler resolve creates a scoped session when none exists', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions/resolve',
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'orchestrator',
                'profile' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->resolve($request);
        $body = json_decode((string) $response->getBody(), true);
        $session = $fixture['storage']->getSession($body['id']);

        expect($response->getStatusCode())->toBe(201);
        expect($body['created'])->toBeTrue();
        expect($body['profile'])->toBe('caelum');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});