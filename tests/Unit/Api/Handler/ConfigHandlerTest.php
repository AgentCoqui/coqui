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

