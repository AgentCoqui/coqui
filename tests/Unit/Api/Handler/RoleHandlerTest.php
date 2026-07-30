<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\RoleHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use React\Http\Message\ServerRequest;

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

    $profileDiscovery = new PersonaDiscovery($workspacePath);
    $roleDiscovery = new RoleDiscovery($workspacePath, dirname(__DIR__, 4));
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery, profileDiscovery: $profileDiscovery);

    return [
        'workspacePath' => $workspacePath,
        'handler' => new RoleHandler($roleDiscovery, $roleResolver, $profileDiscovery),
    ];
}

function cleanupApiRoleHandlerFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
}

test('role handler app picker route defaults to selectable roles and honors profile restrictions', function () {
    $fixture = createApiRoleHandlerFixture();

    try {
        $response = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/roles?profile=caelum'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['profile'])->toBe('caelum');
        expect($body['selectable_only'])->toBeTrue();

        $rolesByName = [];
        foreach ($body['roles'] as $role) {
            $rolesByName[$role['name']] = $role;
        }

        expect(array_keys($rolesByName))->toContain('analyst', 'orchestrator');
        expect($rolesByName['analyst']['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($rolesByName['analyst']['profile_override'])->toBeTrue();
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

test('role handler detail resolves profile-specific instructions and model overrides', function () {
    $fixture = createApiRoleHandlerFixture();

    try {
        $response = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/roles/analyst?profile=caelum'), 'analyst');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['profile'])->toBe('caelum');
        expect($body['profile_override'])->toBeTrue();
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['instructions'])->toContain('Caelum in analyst mode');
    } finally {
        cleanupApiRoleHandlerFixture($fixture);
    }
});