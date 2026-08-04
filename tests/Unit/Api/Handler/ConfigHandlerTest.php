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
use CoquiBot\Coqui\Storage\ObjectVersionStore;
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
    $personaDiscovery = new PersonaDiscovery($workspacePath);
    $roleDiscovery = new RoleDiscovery($workspacePath, dirname(__DIR__, 4));
    $roleResolver = new RoleResolver($config, roleDiscovery: $roleDiscovery, personaDiscovery: $personaDiscovery);
    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS object_versions (
            object_type TEXT NOT NULL,
            object_name TEXT NOT NULL,
            version     INTEGER NOT NULL DEFAULT 1,
            updated_at  TEXT NOT NULL,
            PRIMARY KEY (object_type, object_name)
        )
    SQL);
    $objectVersions = new ObjectVersionStore($pdo);
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
            $personaDiscovery,
            null,
            $roleResolver,
            $configManager,
            new ConfigGuard(),
            $lifecycle,
            $objectVersions,
        ),
    ];
}

function cleanupApiConfigHandlerFixture(array $fixture): void
{
    cleanupTestTree($fixture['workspacePath']);
    cleanupTestTree($fixture['projectRoot']);
}

test('config handler lists discovered personas and default persona', function () {
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

test('config handler returns a curated persona preference schema for the app', function () {
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

test('config handler returns persona detail for picker UIs', function () {
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

test('config handler creates a persona from the CAP authoring shape and serves version 1', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->createPersona(new ServerRequest(
            'POST',
            '/api/v1/personas',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'nova',
                'avatar' => ['tint' => '#2b3a52'],
                'model' => 'anthropic/claude-sonnet-4',
                'allowed_roles' => ['orchestrator'],
                'soul' => "# Nova\n\nA bold collaborative strategist.",
                'backstory' => "## Origins\n\nBuilt for focused strategic support.",
            ], JSON_THROW_ON_ERROR),
        ));
        $body = json_decode((string) $response->getBody(), true);

        $personasResponse = $fixture['handler']->personas(new ServerRequest('GET', '/api/v1/config/personas'));
        $personasBody = json_decode((string) $personasResponse->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['version'])->toBe(1);
        expect($body['model'])->toBe('anthropic/claude-sonnet-4');
        expect($body['allowed_roles'])->toBe(['orchestrator']);
        expect($body['avatar'])->toBe(['tint' => '#2b3a52']);
        expect($body['soul'])->toContain('A bold collaborative strategist.');
        expect($body['backstory'])->toContain('## Origins');
        expect($personasBody['count'])->toBe(3);
        expect(array_column($personasBody['personas'], 'name'))->toBe(['caelum', 'nova', 'trinity']);
        expect(file_get_contents($fixture['workspacePath'] . '/personas/nova/soul.md'))->toContain('A bold collaborative strategist.');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/nova/soul.md'))->toContain('model: anthropic/claude-sonnet-4');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/nova/backstory.md'))->toContain('## Origins');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/nova/identity.json'))->toContain('#2b3a52');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects duplicate personas and server-owned create fields', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $duplicateResponse = $fixture['handler']->createPersona(new ServerRequest(
            'POST',
            '/api/v1/personas',
            ['Content-Type' => 'application/json'],
            json_encode([
                'name' => 'caelum',
                'avatar' => new stdClass(),
                'model' => 'anthropic/claude-sonnet-4',
                'allowed_roles' => ['orchestrator'],
                'soul' => 'A duplicate.',
            ], JSON_THROW_ON_ERROR),
        ));
        $duplicateBody = json_decode((string) $duplicateResponse->getBody(), true);

        $serverOwnedResponse = $fixture['handler']->createPersona(new ServerRequest(
            'POST',
            '/api/v1/personas',
            ['Content-Type' => 'application/json'],
            json_encode([
                'id' => '01J000000000000000000PERSONA',
                'name' => 'nova',
                'avatar' => new stdClass(),
                'model' => 'anthropic/claude-sonnet-4',
                'allowed_roles' => ['orchestrator'],
                'soul' => 'x',
            ], JSON_THROW_ON_ERROR),
        ));
        $serverOwnedBody = json_decode((string) $serverOwnedResponse->getBody(), true);

        expect($duplicateResponse->getStatusCode())->toBe(409);
        expect($duplicateBody['code'])->toBe('conflict');
        expect($serverOwnedResponse->getStatusCode())->toBe(422);
        expect($serverOwnedBody['code'])->toBe('validation_error');
        expect($serverOwnedBody['details']['unexpected_fields'])->toContain('id');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler patches persona soul/backstory, preserves the model, and bumps version', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
            ['Content-Type' => 'application/json'],
            json_encode([
                'soul' => "# Caelum\n\nA calmer guide for long-running conversations.",
                'backstory' => "## Revisions\n\nUpdated during onboarding.",
            ], JSON_THROW_ON_ERROR),
        ), 'caelum');
        $body = json_decode((string) $response->getBody(), true);
        $soulFile = file_get_contents($fixture['workspacePath'] . '/personas/caelum/soul.md');

        expect($response->getStatusCode())->toBe(200);
        expect($body['soul'])->toContain('A calmer guide for long-running conversations.');
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect($body['version'])->toBe(2);
        expect($body['backstory'])->toContain('## Revisions');
        expect($soulFile)->toContain('model: anthropic/claude-sonnet-4-20250514');
        expect($soulFile)->toContain('A calmer guide for long-running conversations.');
        expect(file_get_contents($fixture['workspacePath'] . '/personas/caelum/backstory.md'))->toContain('## Revisions');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects a stale If-Match with a version conflict', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        // caelum starts at the implicit version 1; If-Match: 2 is stale.
        $response = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
            ['Content-Type' => 'application/json', 'If-Match' => '2'],
            json_encode(['soul' => "# Caelum\n\nRewritten."], JSON_THROW_ON_ERROR),
        ), 'caelum');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('version_conflict');
        expect($body['details']['current_version'])->toBe(1);
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler can clear optional persona files during a patch', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
            ['Content-Type' => 'application/json'],
            json_encode([
                'soul' => "# Caelum\n\nA direct soul rewrite.",
                'backstory' => null,
            ], JSON_THROW_ON_ERROR),
        ), 'caelum');
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['soul'])->toContain('A direct soul rewrite.');
        expect($body['backstory'])->toBeNull();
        expect($body['model'])->toBe('anthropic/claude-sonnet-4-20250514');
        expect(is_file($fixture['workspacePath'] . '/personas/caelum/backstory.md'))->toBeFalse();
        expect(file_get_contents($fixture['workspacePath'] . '/personas/caelum/soul.md'))->toContain('model: anthropic/claude-sonnet-4-20250514');
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler rejects renaming, unknown patch fields, and an empty patch', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $rename = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
            ['Content-Type' => 'application/json'],
            json_encode(['name' => 'renamed-persona'], JSON_THROW_ON_ERROR),
        ), 'caelum');
        expect($rename->getStatusCode())->toBe(422);
        expect(json_decode((string) $rename->getBody(), true)['code'])->toBe('validation_error');

        $unknown = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
            ['Content-Type' => 'application/json'],
            json_encode(['telepathy' => true], JSON_THROW_ON_ERROR),
        ), 'caelum');
        expect($unknown->getStatusCode())->toBe(422);

        $empty = $fixture['handler']->updatePersona(new ServerRequest(
            'PATCH',
            '/api/v1/personas/caelum',
            ['Content-Type' => 'application/json'],
            json_encode([], JSON_THROW_ON_ERROR),
        ), 'caelum');
        expect($empty->getStatusCode())->toBe(422);
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler deletes a non-default persona and invalidates discovery', function () {
    $fixture = createApiConfigHandlerFixture();

    try {
        $response = $fixture['handler']->deletePersona(
            new ServerRequest('DELETE', '/api/v1/personas/trinity'),
            'trinity',
        );
        $body = json_decode((string) $response->getBody(), true);

        $personasResponse = $fixture['handler']->personas(new ServerRequest('GET', '/api/v1/config/personas'));
        $personasBody = json_decode((string) $personasResponse->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body)->toBe([
            'deleted' => true,
            'name' => 'trinity',
        ]);
        expect($personasBody['count'])->toBe(1);
        expect(array_column($personasBody['personas'], 'name'))->toBe(['caelum']);
        expect(is_dir($fixture['workspacePath'] . '/personas/trinity'))->toBeFalse();
    } finally {
        cleanupApiConfigHandlerFixture($fixture);
    }
});

test('config handler refuses to delete the configured default persona', function () {
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

