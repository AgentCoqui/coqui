<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use React\Http\Message\ServerRequest;

function createApiConfigHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-config-handler-' . bin2hex(random_bytes(8));
    $projectRoot = sys_get_temp_dir() . '/coqui-config-handler-project-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($projectRoot, 0755, true);
    mkdir($workspacePath . '/profiles/caelum', 0755, true);
    mkdir($workspacePath . '/profiles/trinity', 0755, true);
    mkdir($workspacePath . '/roles', 0755, true);
    file_put_contents($workspacePath . '/profiles/caelum/soul.md', "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n\n# Caelum\n\nA calm companion.");
    file_put_contents($workspacePath . '/profiles/caelum/backstory.md', "## Past\n\nKeeps continuity across sessions.\n");
    file_put_contents($workspacePath . '/profiles/trinity/soul.md', "# Trinity\n\nA precise hacker and guide.");
    file_put_contents($workspacePath . '/profiles/caelum/preferences.json', json_encode([
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

    $configData = [
        'agents' => [
            'defaults' => [
                'profile' => 'caelum',
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ];
    file_put_contents(
        $workspacePath . '/openclaw.json',
        json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    );

    $configManager = new ConfigManager($workspacePath, $projectRoot, new DefaultsLoader(), new ConfigValidator());
    $config = $configManager->load();
    $profileDiscovery = new ProfileDiscovery($workspacePath);
    $roleDiscovery = new RoleDiscovery($workspacePath, dirname(__DIR__, 4));
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery, profileDiscovery: $profileDiscovery);

    return [
        'workspacePath' => $workspacePath,
        'projectRoot' => $projectRoot,
        'configManager' => $configManager,
        'handler' => new ConfigHandler(
            $config,
            new ConfigValidator(),
            $profileDiscovery,
            null,
            $roleResolver,
            $configManager,
            new ConfigGuard(),
        ),
    ];
}

function cleanupApiConfigHandlerFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
    cleanupTestTree($fixture['projectRoot']);
}

test('config handler lists discovered profiles and default profile', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->profiles(new ServerRequest('GET', '/api/v1/config/profiles'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['count'])->toBe(2);
        expect($body['default_profile'])->toBe('caelum');
        expect(array_column($body['profiles'], 'name'))->toBe(['caelum', 'trinity']);
        expect($body['profiles'][0])->toHaveKeys(['name', 'display_name', 'description', 'model', 'is_default', 'allowed_roles', 'role_restrictions', 'has_role_restrictions']);
        expect($body['profiles'][0]['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['profiles'][0]['is_default'])->toBeTrue();
        expect($body['profiles'][0]['allowed_roles'])->toBe(['analyst', 'orchestrator']);
        expect($body['profiles'][1]['allowed_roles'])->toBe(['analyst', 'orchestrator']);
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler returns profile detail for picker UIs', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->profile(new ServerRequest('GET', '/api/v1/profiles/caelum'), 'caelum');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['name'])->toBe('caelum');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['role_restrictions']['allow'])->toBe(['orchestrator', 'analyst']);
        expect($body['preferences']['roles']['allow'])->toBe(['orchestrator', 'analyst']);
        expect($body['soul'])->toContain('A calm companion.');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler creates a profile and makes it immediately discoverable', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->createProfile(new ServerRequest(
            'POST',
            '/api/v1/profiles',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'nova',
                'description' => 'A bold collaborative strategist.',
                'backstory' => "## Origins\n\nBuilt for focused strategic support.",
                'preferences' => [
                    'behavior' => [
                        'planning_mode' => 'structured',
                    ],
                    'prompts' => [
                        'features' => [
                            'projects' => false,
                            'todos' => true,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);

        $profilesResponse = $fixture['handler']->profiles(new ServerRequest('GET', '/api/v1/config/profiles'));
        $profilesBody = json_decode((string) $profilesResponse->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['name'])->toBe('nova');
        expect($body['description'])->toBe('A bold collaborative strategist.');
        expect($body['soul'])->toContain('# Nova');
        expect($body['preferences']['features']['projects'])->toBeFalse();
        expect($body['preferences']['features']['todos'])->toBeTrue();
        expect($profilesBody['count'])->toBe(3);
        expect(array_column($profilesBody['profiles'], 'name'))->toBe(['caelum', 'nova', 'trinity']);
        expect(file_get_contents($fixture['workspacePath'] . '/profiles/nova/soul.md'))->toContain('A bold collaborative strategist.');
        expect(file_get_contents($fixture['workspacePath'] . '/profiles/nova/backstory.md'))->toContain('## Origins');
        expect(file_get_contents($fixture['workspacePath'] . '/profiles/nova/preferences.json'))->toContain('planning_mode');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects duplicate or invalid profile creation payloads', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $duplicateResponse = $fixture['handler']->createProfile(new ServerRequest(
            'POST',
            '/api/v1/profiles',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'caelum',
                'description' => 'Duplicate profile',
            ], JSON_THROW_ON_ERROR),
        ));
        $duplicateBody = json_decode((string) $duplicateResponse->getBody(), true);

        $invalidResponse = $fixture['handler']->createProfile(new ServerRequest(
            'POST',
            '/api/v1/profiles',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'not valid',
                'description' => 'Bad name',
            ], JSON_THROW_ON_ERROR),
        ));
        $invalidBody = json_decode((string) $invalidResponse->getBody(), true);

        expect($duplicateResponse->getStatusCode())->toBe(409);
        expect($duplicateBody['code'])->toBe('conflict');
        expect($invalidResponse->getStatusCode())->toBe(400);
        expect($invalidBody['code'])->toBe('validation_error');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler updates a profile and preserves existing frontmatter', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updateProfile(new ServerRequest(
            'PATCH',
            '/api/v1/profiles/caelum',
            ['Content-Type' => 'application/json'],
            json_encode([
                'description' => 'A calmer guide for long-running conversations.',
                'backstory' => "## Revisions\n\nUpdated during onboarding.",
                'preferences' => [
                    'prompts' => [
                        'features' => [
                            'projects' => false,
                            'todos' => true,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ), 'caelum');
        $body = json_decode((string) $response->getBody(), true);
        $soulFile = file_get_contents($fixture['workspacePath'] . '/profiles/caelum/soul.md');

        expect($response->getStatusCode())->toBe(200);
        expect($body['description'])->toBe('A calmer guide for long-running conversations.');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['preferences']['features']['projects'])->toBeFalse();
        expect($body['preferences']['features']['todos'])->toBeTrue();
        expect($soulFile)->toContain('model: anthropic/claude-sonnet-4-20250514');
        expect($soulFile)->toContain('A calmer guide for long-running conversations.');
        expect(file_get_contents($fixture['workspacePath'] . '/profiles/caelum/backstory.md'))->toContain('## Revisions');
        expect(file_get_contents($fixture['workspacePath'] . '/profiles/caelum/preferences.json'))->toContain('"projects": false');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler can remove optional profile files during update', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updateProfile(new ServerRequest(
            'PATCH',
            '/api/v1/profiles/caelum',
            ['Content-Type' => 'application/json'],
            json_encode([
                'soul' => "# Caelum\n\nA direct soul rewrite.",
                'backstory' => null,
                'preferences' => null,
            ], JSON_THROW_ON_ERROR),
        ), 'caelum');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['description'])->toBe('A direct soul rewrite.');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect(is_file($fixture['workspacePath'] . '/profiles/caelum/backstory.md'))->toBeFalse();
        expect(is_file($fixture['workspacePath'] . '/profiles/caelum/preferences.json'))->toBeFalse();
        expect(file_get_contents($fixture['workspacePath'] . '/profiles/caelum/soul.md'))->toContain('model: anthropic/claude-sonnet-4-20250514');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects invalid profile update payloads', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updateProfile(new ServerRequest(
            'PATCH',
            '/api/v1/profiles/caelum',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'renamed-profile',
            ], JSON_THROW_ON_ERROR),
        ), 'caelum');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler deletes a non-default profile and invalidates discovery', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->deleteProfile(
            new ServerRequest('DELETE', '/api/v1/profiles/trinity'),
            'trinity',
        );
        $body = json_decode((string) $response->getBody(), true);

        $profilesResponse = $fixture['handler']->profiles(new ServerRequest('GET', '/api/v1/config/profiles'));
        $profilesBody = json_decode((string) $profilesResponse->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body)->toBe([
            'deleted' => true,
            'name' => 'trinity',
        ]);
        expect($profilesBody['count'])->toBe(1);
        expect(array_column($profilesBody['profiles'], 'name'))->toBe(['caelum']);
        expect(is_dir($fixture['workspacePath'] . '/profiles/trinity'))->toBeFalse();
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler refuses to delete the configured default profile', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->deleteProfile(
            new ServerRequest('DELETE', '/api/v1/profiles/caelum'),
            'caelum',
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('conflict');
        expect(is_dir($fixture['workspacePath'] . '/profiles/caelum'))->toBeTrue();
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler updates conversation history prompt mode', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updateContext(new ServerRequest(
            'PATCH',
            '/api/v1/config/context',
            ['Content-Type' => 'application/json'],
            json_encode(['conversationHistoryInSystemPrompt' => true], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['context']['conversationHistoryInSystemPrompt'])->toBeTrue();
        expect($body['restart_required'])->toBeTrue();
        expect($fixture['configManager']->config()->useConversationHistoryInSystemPrompt())->toBeTrue();
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects invalid conversation history prompt mode payloads', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updateContext(new ServerRequest(
            'PATCH',
            '/api/v1/config/context',
            ['Content-Type' => 'application/json'],
            json_encode(['conversationHistoryInSystemPrompt' => 'yes'], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['error'])->toContain('conversationHistoryInSystemPrompt must be a boolean');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

