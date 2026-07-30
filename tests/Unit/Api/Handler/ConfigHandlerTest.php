<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ApiLifecycleController;
use CoquiBot\Coqui\Api\Handler\ConfigHandler;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\RuntimeStateStore;
use React\Http\Message\ServerRequest;

function createApiConfigHandlerFixture(): array
{
    $workspacePath = sys_get_temp_dir() . '/coqui-config-handler-' . bin2hex(random_bytes(8));
    $projectRoot = sys_get_temp_dir() . '/coqui-config-handler-project-' . bin2hex(random_bytes(8));
    mkdir($workspacePath, 0755, true);
    mkdir($projectRoot, 0755, true);
    mkdir($workspacePath . '/personas/caelum', 0755, true);
    mkdir($workspacePath . '/personas/trinity', 0755, true);
    mkdir($workspacePath . '/roles', 0755, true);
    file_put_contents($workspacePath . '/personas/caelum/soul.md', "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n\n# Caelum\n\nA calm companion.");
    file_put_contents($workspacePath . '/personas/caelum/backstory.md', "## Past\n\nKeeps continuity across sessions.\n");
    file_put_contents($workspacePath . '/personas/trinity/soul.md', "# Trinity\n\nA precise hacker and guide.");
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
                'persona' => 'caelum',
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
    $profileDiscovery = new PersonaDiscovery($workspacePath);
    $roleDiscovery = new RoleDiscovery($workspacePath, dirname(__DIR__, 4));
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery, profileDiscovery: $profileDiscovery);
    $pdo = new PDO('sqlite::memory:');
    $runtimeStateStore = new RuntimeStateStore($pdo);
    $lifecycle = new ApiLifecycleController(
        runtimeStateStore: $runtimeStateStore,
        managedByLauncher: true,
        startedAt: '2026-05-10T00:00:00Z',
        pid: 12345,
    );
    $lifecycle->configureRestartHandler(static function (string $reason): void {
    });

    return [
        'workspacePath' => $workspacePath,
        'projectRoot' => $projectRoot,
        'configManager' => $configManager,
        'lifecycle' => $lifecycle,
        'handler' => new ConfigHandler(
            $config,
            new ConfigValidator(),
            $profileDiscovery,
            null,
            $roleResolver,
            $configManager,
            new ConfigGuard(),
            $lifecycle,
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
        $response = $fixture['handler']->personas(new ServerRequest('GET', '/api/v1/config/personas'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['count'])->toBe(2);
        expect($body['default_persona'])->toBe('caelum');
        expect(array_column($body['personas'], 'name'))->toBe(['caelum', 'trinity']);
        expect($body['personas'][0])->toHaveKeys(['name', 'display_name', 'description', 'model', 'is_default', 'allowed_roles', 'role_restrictions', 'has_role_restrictions']);
        expect($body['personas'][0]['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['personas'][0]['is_default'])->toBeTrue();
        expect($body['personas'][0]['allowed_roles'])->toBe(['analyst', 'orchestrator']);
        expect($body['personas'][1]['allowed_roles'])->toBe(['analyst', 'orchestrator']);
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler returns a curated profile preference schema for the app', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->personaPreferenceSchema(
            new ServerRequest('GET', '/api/v1/config/persona-preferences/schema'),
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['version'])->toBe(1);
        expect(array_column($body['sections'], 'id'))->toBe([
            'communication_style',
            'planning_reasoning',
            'capabilities_tools',
            'roles_autonomy',
        ]);
        expect($body['sections'][0]['fields'][0]['storage_path'])->toBe('prompt_directives.response_style');
        expect($body['sections'][1]['fields'][1]['options'])->toBe(['deliberate', 'structured']);
        expect($body['sections'][2]['fields'][0]['id'])->toBe('artifacts');
        expect($body['sections'][3]['fields'][0]['options'])->toContain([
            'value' => 'analyst',
            'label' => 'Analyst',
        ]);
        expect($body['sections'][3]['fields'][0]['options'])->not->toContain([
            'value' => 'title-generator',
            'label' => 'Title Generator',
        ]);
        expect($body['deferred']['advanced_editor'])->toBeTrue();
        expect(json_encode($body, JSON_THROW_ON_ERROR))->not->toContain('prompt_sections');
        expect(json_encode($body, JSON_THROW_ON_ERROR))->not->toContain('labels');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler returns profile detail for picker UIs', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->persona(new ServerRequest('GET', '/api/v1/personas/caelum'), 'caelum');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['name'])->toBe('caelum');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['role_restrictions']['allow'])->toBe(['orchestrator', 'analyst']);
        expect($body['preferences']['roles']['allow'])->toBe(['orchestrator', 'analyst']);
        expect($body['preference_values']['prompts']['roles']['allow'])->toBe(['orchestrator', 'analyst']);
        expect($body['preference_document']['prompts']['roles']['allow'])->toBe(['orchestrator', 'analyst']);
        expect($body['preference_values']['prompt_directives'])->toBe([]);
        expect($body['soul'])->toContain('A calm companion.');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler creates a profile and makes it immediately discoverable', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->createPersona(new ServerRequest(
            'POST',
            '/api/v1/personas',
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
                            'loops' => true,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);

        $profilesResponse = $fixture['handler']->personas(new ServerRequest('GET', '/api/v1/config/personas'));
        $profilesBody = json_decode((string) $profilesResponse->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['name'])->toBe('nova');
        expect($body['description'])->toBe('A bold collaborative strategist.');
        expect($body['soul'])->toContain('# Nova');
        expect($body['preferences']['features']['projects'])->toBeFalse();
        expect($body['preferences']['features']['loops'])->toBeTrue();
        expect($profilesBody['count'])->toBe(3);
        expect(array_column($profilesBody['personas'], 'name'))->toBe(['caelum', 'nova', 'trinity']);
        expect(file_get_contents($fixture['workspacePath'] . '/personas/nova/soul.md'))->toContain('A bold collaborative strategist.');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/nova/backstory.md'))->toContain('## Origins');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/nova/preferences.json'))->toContain('planning_mode');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects duplicate or invalid profile creation payloads', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $duplicateResponse = $fixture['handler']->createPersona(new ServerRequest(
            'POST',
            '/api/v1/personas',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'caelum',
                'description' => 'Duplicate profile',
            ], JSON_THROW_ON_ERROR),
        ));
        $duplicateBody = json_decode((string) $duplicateResponse->getBody(), true);

        $invalidResponse = $fixture['handler']->createPersona(new ServerRequest(
            'POST',
            '/api/v1/personas',
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
        $response = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
            ['Content-Type' => 'application/json'],
            json_encode([
                'description' => 'A calmer guide for long-running conversations.',
                'backstory' => "## Revisions\n\nUpdated during onboarding.",
                'preferences' => [
                    'prompts' => [
                        'features' => [
                            'projects' => false,
                            'loops' => true,
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ), 'caelum');
        $body = json_decode((string) $response->getBody(), true);
        $soulFile = file_get_contents($fixture['workspacePath'] . '/personas/caelum/soul.md');

        expect($response->getStatusCode())->toBe(200);
        expect($body['description'])->toBe('A calmer guide for long-running conversations.');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['preferences']['features']['projects'])->toBeFalse();
        expect($body['preferences']['features']['loops'])->toBeTrue();
        expect($soulFile)->toContain('model: anthropic/claude-sonnet-4-20250514');
        expect($soulFile)->toContain('A calmer guide for long-running conversations.');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/caelum/backstory.md'))->toContain('## Revisions');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/caelum/preferences.json'))->toContain('"projects": false');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler can remove optional profile files during update', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
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
        expect(is_file($fixture['workspacePath'] . '/personas/caelum/backstory.md'))->toBeFalse();
        expect(is_file($fixture['workspacePath'] . '/personas/caelum/preferences.json'))->toBeFalse();
        expect(file_get_contents($fixture['workspacePath'] . '/personas/caelum/soul.md'))->toContain('model: anthropic/claude-sonnet-4-20250514');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects invalid profile update payloads', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
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
        $response = $fixture['handler']->deletePersona(
            new ServerRequest('DELETE', '/api/v1/personas/trinity'),
            'trinity',
        );
        $body = json_decode((string) $response->getBody(), true);

        $profilesResponse = $fixture['handler']->personas(new ServerRequest('GET', '/api/v1/config/personas'));
        $profilesBody = json_decode((string) $profilesResponse->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body)->toBe([
            'deleted' => true,
            'name' => 'trinity',
        ]);
        expect($profilesBody['count'])->toBe(1);
        expect(array_column($profilesBody['personas'], 'name'))->toBe(['caelum']);
        expect(is_dir($fixture['workspacePath'] . '/personas/trinity'))->toBeFalse();
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler refuses to delete the configured default profile', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->deletePersona(
            new ServerRequest('DELETE', '/api/v1/personas/caelum'),
            'caelum',
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('conflict');
        expect(is_dir($fixture['workspacePath'] . '/personas/caelum'))->toBeTrue();
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

test('config handler returns supported context settings metadata', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->getContext(new ServerRequest('GET', '/api/v1/config/context'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['context'])->toHaveKeys([
            'conversationHistoryInSystemPrompt',
            'autoSummarizeMode',
            'autoSummarizeThreshold',
            'autoSummarizeTurnThreshold',
            'autoSummarizeKeepRecent',
        ]);
        expect($body['defaults']['autoSummarizeMode'])->toBe('token');
        expect($body['defaults']['autoSummarizeThreshold'])->toEqual(64.0);
        expect($body['defaults']['autoSummarizeTurnThreshold'])->toBe(32);
        expect($body['fields']['autoSummarizeMode']['options'])->toBe(['token', 'turn', 'manual']);
        expect($body['fields']['autoSummarizeKeepRecent']['maximum'])->toBe(20);
        expect($body['restart']['required'])->toBeFalse();
        expect($body['restart']['managed_by_launcher'])->toBeTrue();
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler updates multiple safe context settings and marks restart required', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updateContext(new ServerRequest(
            'PATCH',
            '/api/v1/config/context',
            ['Content-Type' => 'application/json'],
            json_encode([
                'autoSummarizeMode' => 'turn',
                'autoSummarizeTurnThreshold' => 12,
                'autoSummarizeKeepRecent' => 8,
            ], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['context']['autoSummarizeMode'])->toBe('turn');
        expect($body['context']['autoSummarizeTurnThreshold'])->toBe(12);
        expect($body['context']['autoSummarizeKeepRecent'])->toBe(8);
        expect($body['updated'])->toBe([
            'autoSummarizeMode',
            'autoSummarizeTurnThreshold',
            'autoSummarizeKeepRecent',
        ]);
        expect($body['restart_required'])->toBeTrue();
        expect($body['restart']['required'])->toBeTrue();
        expect($body['restart']['source'])->toBe('api.config.context.update');
        expect($body['restart']['context']['updated_keys'])->toBe([
            'autoSummarizeMode',
            'autoSummarizeTurnThreshold',
            'autoSummarizeKeepRecent',
        ]);
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler can reset supported context settings to defaults', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $fixture['handler']->updateContext(new ServerRequest(
            'PATCH',
            '/api/v1/config/context',
            ['Content-Type' => 'application/json'],
            json_encode([
                'autoSummarizeMode' => 'manual',
                'autoSummarizeKeepRecent' => 5,
            ], JSON_THROW_ON_ERROR),
        ));

        $response = $fixture['handler']->updateContext(new ServerRequest(
            'PATCH',
            '/api/v1/config/context',
            ['Content-Type' => 'application/json'],
            json_encode([
                'reset' => ['autoSummarizeMode', 'autoSummarizeKeepRecent'],
            ], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);
        $configArray = $fixture['configManager']->toArray();

        expect($response->getStatusCode())->toBe(200);
        expect($body['context']['autoSummarizeMode'])->toBe('token');
        expect($body['context']['autoSummarizeKeepRecent'])->toBe(15);
        expect($body['reset'])->toBe(['autoSummarizeMode', 'autoSummarizeKeepRecent']);
        expect(isset($configArray['agents']['defaults']['context']['autoSummarizeMode']))->toBeFalse();
        expect(isset($configArray['agents']['defaults']['context']['autoSummarizeKeepRecent']))->toBeFalse();
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

test('config handler rejects invalid auto summarize payloads', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updateContext(new ServerRequest(
            'PATCH',
            '/api/v1/config/context',
            ['Content-Type' => 'application/json'],
            json_encode([
                'autoSummarizeMode' => 'always',
                'autoSummarizeKeepRecent' => 40,
            ], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['error'])->toBe('Validation failed');
        expect($body['details']['errors'])->toContain('autoSummarizeMode must be one of: token, turn, manual');
        expect($body['details']['errors'])->toContain('autoSummarizeKeepRecent must be an integer between 1 and 20');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

