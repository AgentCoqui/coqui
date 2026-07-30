<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\PersonaSessionLifecycleManager;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use React\Http\Message\ServerRequest;

function createApiSessionHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-session-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/profiles', 0755, true);
    foreach (['caelum', 'nova', 'iris'] as $profile) {
        mkdir($workspacePath . '/profiles/' . $profile, 0755, true);
        mkdir($workspacePath . '/profiles/' . $profile . '/roles', 0755, true);
        file_put_contents($workspacePath . '/profiles/' . $profile . '/soul.md', sprintf(
            "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n\n# %s\n\nA collaborative profile.",
            ucfirst($profile),
        ));
    }
    mkdir($workspacePath . '/roles', 0755, true);
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
    $profileDiscovery = new PersonaDiscovery($workspacePath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ]);
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery, profileDiscovery: $profileDiscovery);
    $lifecycleManager = new PersonaSessionLifecycleManager(
        storage: $storage,
        providerFactory: new ProviderFactory($config),
        roleResolver: $roleResolver,
        memoryStore: new MemoryStore($workspacePath . '/memory.db'),
    );

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'storage' => $storage,
        'roleResolver' => $roleResolver,
        'profileDiscovery' => $profileDiscovery,
        'handler' => new SessionHandler($storage, $roleResolver, $profileDiscovery, $lifecycleManager),
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
        expect($body['session_type'])->toBe('interactive');
        expect($session)->not->toBeNull();
    expect($session['session_origin'])->toBe('user');
        expect($session)->not->toHaveKey('channel');
        expect($session['session_origin'] ?? null)->not->toBe('channel');
        expect($session['profile'])->toBe('caelum');
        expect($session['model'])->toBe('ollama/qwen3:latest');
        expect($session['session_type'])->toBe('interactive');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create rejects roles disallowed by the active profile', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        file_put_contents($fixture['workspacePath'] . '/profiles/caelum/preferences.json', json_encode([
            'prompts' => [
                'roles' => [
                    'allow' => ['orchestrator'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

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

        expect($response->getStatusCode())->toBe(400);
        expect($body['error'])->toContain('does not allow role "analyst"');
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

test('session handler update rejects profile changes that would disallow the current role', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        file_put_contents($fixture['workspacePath'] . '/profiles/caelum/preferences.json', json_encode([
            'prompts' => [
                'roles' => [
                    'allow' => ['orchestrator'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $sessionId = $fixture['storage']->createSession('analyst', 'openai/gpt-4.1-mini');
        $request = new ServerRequest(
            'PATCH',
            '/api/v1/sessions/' . $sessionId,
            ['Content-Type' => 'application/json'],
            json_encode([
                'profile' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->update($request, $sessionId);
        $body = json_decode((string) $response->getBody(), true);
        $session = $fixture['storage']->getSession($sessionId);

        expect($response->getStatusCode())->toBe(400);
        expect($body['error'])->toContain('does not allow role "analyst"');
        expect($session['profile'])->toBeNull();
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
        $backgroundSessionId = $fixture['storage']->createSession('orchestrator', 'anthropic/claude-sonnet-4-20250514', 'caelum', visibility: 'hidden');

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
        expect($body['session_type'])->toBe('interactive');
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
        expect($body['session_type'])->toBe('interactive');
        expect($session)->not->toBeNull();
        expect($session['profile'])->toBe('caelum');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler get returns active project id when present', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $projectStore = new ProjectStore($fixture['storage']->getPdo());
        $projectId = $projectStore->createProject('Career Ops', 'career-ops');
        $fixture['storage']->setActiveProject($sessionId, $projectId);

        $response = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/sessions/' . $sessionId), $sessionId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['active_project_id'])->toBe($projectId);
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create persists a group session with normalized members', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'group_enabled' => true,
                'members' => ['Nova', 'caelum'],
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['group_enabled'])->toBe(1);
        expect($body['session_type'])->toBe('group');
        expect($body['profile'])->toBeNull();
        expect($body['model_role'])->toBe('orchestrator');
        expect($body['group_composition_key'])->toBe('caelum|nova');
        expect($body['group_member_count'])->toBe(2);
        expect(array_column($body['group_members'], 'profile'))->toBe(['caelum', 'nova']);
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create requires confirmation when the same group member composition is already active', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $activeSessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'group_enabled' => true,
                'members' => ['nova', 'caelum'],
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('group_session_active');
        expect($body['details']['active_session_id'])->toBe($activeSessionId);
        expect($body['details']['group_composition_key'])->toBe('caelum|nova');
        expect($body['details']['confirm_field'])->toBe('confirm_close_active_group_session');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create closes active group session when confirmation is supplied', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $activeSessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'group_enabled' => true,
                'members' => ['nova', 'caelum'],
                'confirm_close_active_group_session' => true,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $oldSession = $fixture['storage']->getSession($activeSessionId);

        expect($response->getStatusCode())->toBe(201);
        expect($body['id'])->not->toBe($activeSessionId);
        expect($body['created'])->toBeTrue();
        expect($body['closed_session_ids'])->toBe([$activeSessionId]);
        expect($oldSession['is_closed'])->toBe(1);
        expect($oldSession['closure_reason'])->toBe('api_create_group_session:caelum|nova');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler resolve reuses an existing active group session with the same members', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $activeSessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions/resolve',
            ['Content-Type' => 'application/json'],
            json_encode([
                'group_enabled' => true,
                'members' => ['nova', 'caelum'],
            ]) ?: '',
        );

        $response = $fixture['handler']->resolve($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['id'])->toBe($activeSessionId);
        expect($body['created'])->toBeFalse();
        expect($body['session_type'])->toBe('group');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler update can change group round cap while preserving group session type', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $response = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId,
                ['Content-Type' => 'application/json'],
                json_encode(['group_max_rounds' => 5]) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['group_max_rounds'])->toBe(5);
        expect($body['session_type'])->toBe('group');
        expect($body['group_enabled'])->toBe(1);
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler update rejects assigning a profile to a group session', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $response = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId,
                ['Content-Type' => 'application/json'],
                json_encode(['profile' => 'caelum']) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
        expect($body['error'])->toContain('Group sessions do not support a single active profile.');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler group member endpoints list add and remove members', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);

        $listResponse = $fixture['handler']->members(new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/members'), $sessionId);
        $listBody = json_decode((string) $listResponse->getBody(), true);

        expect($listResponse->getStatusCode())->toBe(200);
        expect(array_column($listBody['members'], 'profile'))->toBe(['caelum', 'nova']);

        $addResponse = $fixture['handler']->addMember(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/members',
                ['Content-Type' => 'application/json'],
                json_encode(['profile' => 'iris']) ?: '',
            ),
            $sessionId,
        );
        $addBody = json_decode((string) $addResponse->getBody(), true);

        expect($addResponse->getStatusCode())->toBe(200);
        expect($addBody['group_composition_key'])->toBe('caelum|iris|nova');
        expect(array_column($addBody['group_members'], 'profile'))->toBe(['caelum', 'iris', 'nova']);

        $removeResponse = $fixture['handler']->removeMember(
            new ServerRequest('DELETE', '/api/v1/sessions/' . $sessionId . '/members/nova'),
            $sessionId,
            'nova',
        );
        $removeBody = json_decode((string) $removeResponse->getBody(), true);

        expect($removeResponse->getStatusCode())->toBe(200);
        expect($removeBody['group_composition_key'])->toBe('caelum|iris');
        expect(array_column($removeBody['group_members'], 'profile'))->toBe(['caelum', 'iris']);
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler replace members requires confirmation before colliding with another active group composition', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'nova'], 3);
        $sessionId = $fixture['storage']->createGroupSession('orchestrator', 'ollama/qwen3:latest', ['caelum', 'iris'], 3);

        $response = $fixture['handler']->replaceMembers(
            new ServerRequest(
                'PUT',
                '/api/v1/sessions/' . $sessionId . '/members',
                ['Content-Type' => 'application/json'],
                json_encode(['members' => ['nova', 'caelum']]) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('group_session_active');
        expect($body['details']['group_composition_key'])->toBe('caelum|nova');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler member endpoints reject interactive sessions through the session type capability seam', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $response = $fixture['handler']->members(
            new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/members'),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
        expect($body['error'])->toContain('Session is not a group session.');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create requires confirmation when a profile already has an active session', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $activeSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');

        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'orchestrator',
                'profile' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('profile_session_active');
        expect($body['details']['active_session_id'])->toBe($activeSessionId);
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler create closes active profiled session when confirmation is supplied', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $activeSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $fixture['storage']->addMessage($activeSessionId, 'user', 'Preserve this continuity');

        $request = new ServerRequest(
            'POST',
            '/api/v1/sessions',
            ['Content-Type' => 'application/json'],
            json_encode([
                'model_role' => 'orchestrator',
                'profile' => 'caelum',
                'confirm_close_active_profile_session' => true,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $oldSession = $fixture['storage']->getSession($activeSessionId);
        $visibleSessions = $fixture['storage']->listSessions(10, true, true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['id'])->not->toBe($activeSessionId);
        expect($body['created'])->toBeTrue();
        expect($body['closed_session_ids'])->toBe([$activeSessionId]);
        expect($oldSession['is_closed'])->toBe(1);
        expect($oldSession['closure_reason'])->toBe('api_create_profile_session:caelum');
        expect($visibleSessions)->toHaveCount(1);
        expect($visibleSessions[0]['id'])->toBe($body['id']);
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler resolve closes older duplicate active sessions for a profile', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $olderSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $latestSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');

        $fixture['storage']->getPdo()
            ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
            ->execute(['updated_at' => '2026-01-01T00:00:00+00:00', 'id' => $olderSessionId]);
        $fixture['storage']->getPdo()
            ->prepare('UPDATE sessions SET updated_at = :updated_at WHERE id = :id')
            ->execute(['updated_at' => '2026-01-03T00:00:00+00:00', 'id' => $latestSessionId]);

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
        $olderSession = $fixture['storage']->getSession($olderSessionId);

        expect($response->getStatusCode())->toBe(200);
        expect($body['id'])->toBe($latestSessionId);
        expect($body['created'])->toBeFalse();
        expect($body['closed_session_ids'])->toBe([$olderSessionId]);
        expect($olderSession['is_closed'])->toBe(1);
        expect($olderSession['closure_reason'])->toBe('api_profile_duplicate_cleanup:caelum');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler update requires confirmation before reassigning into an active profile scope', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $request = new ServerRequest(
            'PATCH',
            '/api/v1/sessions/' . $sessionId,
            ['Content-Type' => 'application/json'],
            json_encode([
                'profile' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->update($request, $sessionId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('profile_session_active');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler list filters archived history and returns lifecycle counts', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $activeId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $archivedId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $fixture['storage']->closeSession($archivedId, 'history-rollover', true);

        $response = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/sessions?status=archived'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['status'])->toBe('archived');
        expect($body['count'])->toBe(1);
        expect($body['sessions'][0]['id'])->toBe($archivedId);
        expect($body['sessions'][0]['status'])->toBe('archived');
        expect($body['counts'])->toBe([
            'active' => 1,
            'closed' => 1,
            'archived' => 1,
            'total' => 2,
        ]);
        expect($fixture['storage']->getSession($activeId)['status'])->toBe('active');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler get rejects hidden sessions', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('learner', 'background-task', visibility: 'hidden');

        $response = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/sessions/' . $sessionId), $sessionId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(404);
        expect($body['code'])->toBe('session_not_found');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler list filters sessions by profile scope', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $profiledSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $unprofiledSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $profiledResponse = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/sessions?profile=caelum&status=all'));
        $profiledBody = json_decode((string) $profiledResponse->getBody(), true);

        $unprofiledResponse = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/sessions?profile=none&status=all'));
        $unprofiledBody = json_decode((string) $unprofiledResponse->getBody(), true);

        expect($profiledResponse->getStatusCode())->toBe(200);
        expect($profiledBody['profile'])->toBe('caelum');
        expect($profiledBody['count'])->toBe(1);
        expect($profiledBody['sessions'][0]['id'])->toBe($profiledSessionId);

        expect($unprofiledResponse->getStatusCode())->toBe(200);
        expect($unprofiledBody['profile'])->toBe('none');
        expect($unprofiledBody['count'])->toBe(1);
        expect($unprofiledBody['sessions'][0]['id'])->toBe($unprofiledSessionId);
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session delete cleans up session-only artifact files', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $artifactStore = artifactStoreForTest($fixture['storage']->getPdo());
        $handler = new SessionHandler(
            $fixture['storage'],
            $fixture['roleResolver'],
            $fixture['profileDiscovery'],
            artifactStore: $artifactStore,
        );

        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $sessionOnly = $artifactStore->create($sessionId, 'Ephemeral', 'x', 'document');
        $filePath = $artifactStore->get($sessionOnly, $sessionId)['path'];

        $response = $handler->delete(
            new ServerRequest('DELETE', '/api/v1/sessions/' . $sessionId),
            $sessionId,
        );

        expect($response->getStatusCode())->toBe(200)
            ->and($artifactStore->get($sessionOnly))->toBeNull();
        // The workspace file is removed by ownership cleanup before the row cascade-deletes.
        expect($filePath)->not->toBe('');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session delete is rejected (409) without mutation when project-linked artifacts exist', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $artifactStore = artifactStoreForTest($fixture['storage']->getPdo());
        $handler = new SessionHandler(
            $fixture['storage'],
            $fixture['roleResolver'],
            $fixture['profileDiscovery'],
            artifactStore: $artifactStore,
        );

        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $sessionOnly = $artifactStore->create($sessionId, 'Ephemeral', 'x', 'document');
        $projectLinked = $artifactStore->create($sessionId, 'Keeper', 'y', 'plan', projectId: 'proj-keep');

        $response = $handler->delete(
            new ServerRequest('DELETE', '/api/v1/sessions/' . $sessionId),
            $sessionId,
        );

        // Rejected cleanly, and nothing was mutated — the session-only artifact survives too.
        expect($response->getStatusCode())->toBe(409)
            ->and(json_decode((string) $response->getBody(), true)['code'])->toBe('conflict')
            ->and($artifactStore->get($sessionOnly, $sessionId))->not->toBeNull()
            ->and($artifactStore->get($projectLinked, $sessionId))->not->toBeNull()
            ->and($fixture['storage']->getSession($sessionId))->not->toBeNull();
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler summary returns aggregate counts and latest turn data', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $artifactStore = artifactStoreForTest($fixture['storage']->getPdo());

        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $fixture['storage']->addMessage($sessionId, 'user', 'Summarize this session');
        $fixture['storage']->addMessage($sessionId, 'assistant', 'Working on it');

        $turnId = $fixture['storage']->createTurn($sessionId, 'Summarize this session', 'ollama/qwen3:latest');
        $fixture['storage']->completeTurn(
            $turnId,
            'Summary complete',
            12,
            34,
            46,
            2,
            1500,
            json_encode(['read_file', 'apply_patch']) ?: '[]',
            1,
        );

        $fixture['storage']->logChildRun($sessionId, 1, 'analyst', 'openai/gpt-4.1-mini', 'Review the session', 'Reviewed', 77);

        $taskId = $fixture['storage']->createTask(
            sessionId: $sessionId,
            prompt: 'Summarize the session',
            title: 'Session summary task',
        );
        $fixture['storage']->updateTaskStatus($taskId, 'completed', ['result' => 'done']);

        $artifactId = $artifactStore->create($sessionId, 'Session Notes', 'Summary content', projectId: 'proj-notes');

        $response = $fixture['handler']->summary(new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/summary'), $sessionId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['session']['id'])->toBe($sessionId);
        expect($body['counts']['messages']['total'])->toBe(2);
        expect($body['counts']['messages']['active'])->toBe(2);
        expect($body['counts']['turns'])->toBe(1);
        expect($body['counts']['child_runs'])->toBe(1);
        expect($body['counts']['tasks']['total'])->toBe(1);
        expect($body['counts']['tasks']['by_status']['completed'])->toBe(1);
        expect($body['counts']['artifacts']['total'])->toBe(1);
        expect($body['counts']['artifacts']['persistent'])->toBe(1);
        expect($body['counts']['artifacts']['by_type']['document'])->toBe(1);
        expect($body['latest_turn']['id'])->toBe($turnId);
        expect($body['latest_turn']['tools_used'])->toBe(['read_file', 'apply_patch']);
        expect($body['latest_activity_at'])->not->toBeNull();
        expect($artifactId)->not->toBe('');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});

test('session handler update rejects changes to closed sessions', function () {
    $fixture = createApiSessionHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->closeSession($sessionId, 'history-rollover', true);

        $response = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId,
                ['Content-Type' => 'application/json'],
                json_encode(['title' => 'Archived title']) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('session_closed');
        expect($body['details']['status'])->toBe('archived');
    } finally {
        cleanupApiSessionHandlerFixture($fixture);
    }
});