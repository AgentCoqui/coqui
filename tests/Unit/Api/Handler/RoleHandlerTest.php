<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\RoleHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\ObjectVersionStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;
use React\Http\Message\ServerRequest;

/**
 * Build a RoleHandler wired to a real ObjectVersionStore over a temp workspace
 * and temp SQLite db, for exercising the PUT create/update write path.
 */
function createRolePutFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-role-put-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/roles', 0755, true);

    $dbPath = sys_get_temp_dir() . '/coqui-role-put-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    $config = OpenClawConfig::fromArray([
        'agents' => ['defaults' => ['model' => ['primary' => 'ollama/qwen3:latest']]],
    ]);

    $roleDiscovery = new RoleDiscovery($workspacePath);
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery);

    return [
        'workspacePath' => $workspacePath,
        'dbPath' => $dbPath,
        'roleDiscovery' => $roleDiscovery,
        'handler' => new RoleHandler($roleDiscovery, $roleResolver, null, new ObjectVersionStore($storage->getPdo())),
    ];
}

function cleanupRolePutFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
    cleanupSqliteTestDb($fixture['dbPath']);
}

/**
 * Build a PUT request for a role, with optional precondition headers.
 *
 * @param array<string, mixed> $body
 */
function rolePutRequest(string $name, array $body, ?string $ifNoneMatch = null, ?int $ifMatch = null): ServerRequest
{
    $headers = ['Content-Type' => 'application/json'];
    if ($ifNoneMatch !== null) {
        $headers['If-None-Match'] = $ifNoneMatch;
    }
    if ($ifMatch !== null) {
        $headers['If-Match'] = (string) $ifMatch;
    }

    return new ServerRequest('PUT', '/api/v1/roles/' . $name, $headers, json_encode($body) ?: '');
}

function createApiRoleHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-role-handler-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($workspacePath . '/personas/caelum/roles', 0755, true);
    mkdir($workspacePath . '/roles', 0755, true);

    file_put_contents($workspacePath . '/personas/caelum/soul.md', "# Caelum\n\nA calm companion.");
    file_put_contents($workspacePath . '/personas/caelum/preferences.json', json_encode([
        'prompts' => [
            'roles' => [
                'allow' => ['orchestrator', 'analyst'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

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

    file_put_contents($workspacePath . '/personas/caelum/roles/analyst.md', <<<MD
---
name: analyst
display_name: Analyst
description: Caelum analyst role override
access_level: readonly
model: anthropic/claude-sonnet-4-20250514
---

You are Caelum in analyst mode.
MD);

    file_put_contents($workspacePath . '/roles/title-generator.md', <<<MD
---
name: title-generator
display_name: Title Generator
description: Internal utility role
access_level: readonly
model: openai/gpt-4.1-mini
is_template: true
---

Generate concise titles.
MD);

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

    $personaDiscovery = new PersonaDiscovery($workspacePath);
    $roleDiscovery = new RoleDiscovery($workspacePath, dirname(__DIR__, 4));
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery, personaDiscovery: $personaDiscovery);

    return [
        'workspacePath' => $workspacePath,
        'handler' => new RoleHandler($roleDiscovery, $roleResolver, $personaDiscovery),
    ];
}

function cleanupApiRoleHandlerFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
}

test('role handler app picker route defaults to selectable roles and honors persona restrictions', function () {
    $fixture = createApiRoleHandlerFixture();

    try {
        $response = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/roles?persona=caelum'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['persona'])->toBe('caelum');
        expect($body['selectable_only'])->toBeTrue();

        $rolesByName = [];
        foreach ($body['roles'] as $role) {
            $rolesByName[$role['name']] = $role;
        }

        expect(array_keys($rolesByName))->toContain('analyst', 'orchestrator');
        expect($rolesByName['analyst']['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($rolesByName['analyst']['persona_override'])->toBeTrue();
    } finally {
        cleanupApiRoleHandlerFixture($fixture);
    }
});

test('role handler config route keeps non-selectable roles available', function () {
    $fixture = createApiRoleHandlerFixture();

    try {
        $response = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/config/roles'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect(array_column($body['roles'], 'name'))->toContain('title-generator');
        expect($body['selectable_only'])->toBeFalse();
    } finally {
        cleanupApiRoleHandlerFixture($fixture);
    }
});

test('role handler detail resolves persona-specific instructions and model overrides', function () {
    $fixture = createApiRoleHandlerFixture();

    try {
        $response = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/roles/analyst?persona=caelum'), 'analyst');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['persona'])->toBe('caelum');
        expect($body['persona_override'])->toBeTrue();
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['instructions'])->toContain('Caelum in analyst mode');
    } finally {
        cleanupApiRoleHandlerFixture($fixture);
    }
});

test('role PUT create via If-None-Match:* seeds version 1 and serves a schema-valid role.json', function () {
    $fixture = createRolePutFixture();

    try {
        $created = $fixture['handler']->put(
            rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly'], ifNoneMatch: '*'),
            'reviewer',
        );

        expect($created->getStatusCode())->toBe(201);
        $body = json_decode((string) $created->getBody(), true);
        expect($body['name'])->toBe('reviewer');
        expect($body['version'])->toBe(1);

        $v = new ConformanceValidator();
        $wire = $fixture['handler']->servedRoleWire('reviewer');
        expect($v->isValid('role.json', $wire))->toBeTrue($v->errorText('role.json', $wire));

        // The on-disk authoring file never persists a version token.
        $onDisk = file_get_contents($fixture['workspacePath'] . '/roles/reviewer.md');
        expect($onDisk)->not->toContain('version:');
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT create conflicts (409) when the role already exists', function () {
    $fixture = createRolePutFixture();

    try {
        $fixture['handler']->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'reviewer');

        $dup = $fixture['handler']->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'reviewer');
        expect($dup->getStatusCode())->toBe(409);
        expect(json_decode((string) $dup->getBody(), true)['code'])->toBe('conflict');
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT update via If-Match bumps to version 2 and persists the new fields', function () {
    $fixture = createRolePutFixture();

    try {
        $fixture['handler']->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'reviewer');

        $updated = $fixture['handler']->put(
            rolePutRequest('reviewer', [
                'name' => 'reviewer',
                'access_level' => 'full',
                'model' => 'anthropic/claude-sonnet-4',
                'toolkits' => ['filesystem', 'shell'],
                'max_iterations' => 8,
            ], ifMatch: 1),
            'reviewer',
        );

        expect($updated->getStatusCode())->toBe(200);
        $body = json_decode((string) $updated->getBody(), true);
        expect($body['version'])->toBe(2);
        expect($body['access_level'])->toBe('full');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4');
        expect($body['toolkits'])->toBe(['filesystem', 'shell']);
        expect($body['max_iterations'])->toBe(8);
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT update with a stale If-Match returns 409 version_conflict', function () {
    $fixture = createRolePutFixture();

    try {
        $fixture['handler']->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly'], ifNoneMatch: '*'), 'reviewer');
        $fixture['handler']->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'full'], ifMatch: 1), 'reviewer');

        $stale = $fixture['handler']->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'full'], ifMatch: 1), 'reviewer');
        expect($stale->getStatusCode())->toBe(409);
        $body = json_decode((string) $stale->getBody(), true);
        expect($body['code'])->toBe('version_conflict');
        expect($body['details']['current_version'])->toBe(2);
        expect($body['details']['expected_version'])->toBe(1);
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT with a body carrying a server-owned field is a 422', function () {
    $fixture = createRolePutFixture();

    try {
        $response = $fixture['handler']->put(
            rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly', 'version' => 7, 'id' => 'role_reviewer'], ifNoneMatch: '*'),
            'reviewer',
        );
        expect($response->getStatusCode())->toBe(422);
        $body = json_decode((string) $response->getBody(), true);
        expect($body['code'])->toBe('validation_error');
        expect($body['details']['unexpected_fields'])->toContain('version');
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT update If-Match on a missing role is a 404 role_not_found', function () {
    $fixture = createRolePutFixture();

    try {
        $response = $fixture['handler']->put(rolePutRequest('ghost', ['name' => 'ghost', 'access_level' => 'readonly'], ifMatch: 1), 'ghost');
        expect($response->getStatusCode())->toBe(404);
        expect(json_decode((string) $response->getBody(), true)['code'])->toBe('role_not_found');
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT without a precondition header is a 409 (precondition required)', function () {
    $fixture = createRolePutFixture();

    try {
        $response = $fixture['handler']->put(rolePutRequest('reviewer', ['name' => 'reviewer', 'access_level' => 'readonly']), 'reviewer');
        expect($response->getStatusCode())->toBe(409);
        $body = json_decode((string) $response->getBody(), true);
        expect($body['code'])->toBe('conflict');
        expect($body['details']['reason'])->toBe('precondition_required');
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT rejects a system/reserved role name with 409 role_reserved', function () {
    $fixture = createRolePutFixture();

    try {
        $response = $fixture['handler']->put(
            rolePutRequest('orchestrator', ['name' => 'orchestrator', 'access_level' => 'full'], ifNoneMatch: '*'),
            'orchestrator',
        );
        expect($response->getStatusCode())->toBe(409);
        expect(json_decode((string) $response->getBody(), true)['code'])->toBe('role_reserved');
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT rejects modifying a built-in role file with 409 role_builtin', function () {
    $fixture = createRolePutFixture();

    try {
        file_put_contents($fixture['workspacePath'] . '/roles/guardian.md', <<<MD
        ---
        name: guardian
        display_name: Guardian
        description: A seeded built-in role
        access_level: readonly
        is_builtin: true
        ---

        Guard the gates.
        MD);
        $fixture['roleDiscovery']->invalidateCache();

        $response = $fixture['handler']->put(
            rolePutRequest('guardian', ['name' => 'guardian', 'access_level' => 'full'], ifMatch: 1),
            'guardian',
        );
        expect($response->getStatusCode())->toBe(409);
        expect(json_decode((string) $response->getBody(), true)['code'])->toBe('role_builtin');
    } finally {
        cleanupRolePutFixture($fixture);
    }
});

test('role PUT rejects an invalid role name (path traversal) with 422', function () {
    $fixture = createRolePutFixture();

    try {
        $response = $fixture['handler']->put(
            rolePutRequest('../evil', ['name' => 'evil', 'access_level' => 'readonly'], ifNoneMatch: '*'),
            '../evil',
        );
        expect($response->getStatusCode())->toBe(422);
    } finally {
        cleanupRolePutFixture($fixture);
    }
});