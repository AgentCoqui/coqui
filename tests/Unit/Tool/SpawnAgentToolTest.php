<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tool\SpawnAgentTool;

/**
 * Collect every tool name exposed by SpawnAgentTool::buildToolkits() for a role.
 *
 * @return list<string>
 */
function spawnChildToolNames(SpawnAgentTool $tool, string $role): array
{
    $method = new ReflectionMethod($tool, 'buildToolkits');
    $method->setAccessible(true);

    $names = [];
    foreach ($method->invoke($tool, $role) as $toolkit) {
        foreach ($toolkit->tools() as $childTool) {
            $names[] = $childTool->name();
        }
    }

    return $names;
}

test('has correct name', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'anthropic/claude'],
            ],
        ],
    ]);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp', workspacePath: '/tmp',
    );

    expect($tool->name())->toBe('spawn_agent');
});

test('has role and task parameters', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'anthropic/claude'],
            ],
        ],
    ]);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp', workspacePath: '/tmp',
    );

    $params = $tool->parameters();

    expect($params)->toHaveCount(3);
    expect($params[0]->name)->toBe('role');
    expect($params[1]->name)->toBe('task');
    expect($params[2]->name)->toBe('context');
});

test('execute returns error for empty role', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'anthropic/claude'],
            ],
        ],
    ]);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp', workspacePath: '/tmp',
    );

    $result = $tool->execute(['role' => '', 'task' => 'test']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('required');
});

test('execute returns error for empty task', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'anthropic/claude'],
            ],
        ],
    ]);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp', workspacePath: '/tmp',
    );

    $result = $tool->execute(['role' => 'coder', 'task' => '']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('required');
});

test('generates valid function schema', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'coder' => 'anthropic/claude',
                    'reviewer' => 'openai/gpt-4o',
                ],
            ],
        ],
    ]);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp', workspacePath: '/tmp',
    );

    $schema = $tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('spawn_agent');
    expect($schema['function']['parameters']['properties']['role']['enum'])->toBe(['coder', 'reviewer']);
    expect($schema['function']['parameters']['required'])->toBe(['role', 'task']);
});

test('description includes available roles', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'coder' => 'anthropic/claude',
                    'reviewer' => 'openai/gpt-4o',
                ],
            ],
        ],
    ]);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp', workspacePath: '/tmp',
    );

    $description = $tool->description();

    expect($description)->toContain('coder');
    expect($description)->toContain('reviewer');
});

test('description groups roles by category with descriptions when discovery is available', function () {
    $workspace = sys_get_temp_dir() . '/coqui-spawn-desc-' . bin2hex(random_bytes(4));
    $projectRoot = $workspace . '/project';
    mkdir($workspace . '/roles', 0755, true);
    mkdir($projectRoot . '/config/roles', 0755, true);

    file_put_contents($workspace . '/roles/builder.md', <<<'MD'
---
name: builder
display_name: Builder
description: Writes working code
access_level: full
category: build
---
# Builder
MD);

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => ['defaults' => ['model' => ['primary' => 'ollama/qwen3:latest']]],
        ]);

        $discovery = new \CoquiBot\Coqui\Config\RoleDiscovery($workspace, $projectRoot);
        $tool = new SpawnAgentTool(
            roleResolver: new RoleResolver($config, null, $discovery),
            config: $config,
            projectRoot: $projectRoot,
            workspacePath: $workspace,
            roleDiscovery: $discovery,
        );

        $description = $tool->description();

        expect($description)->toContain('build:');
        expect($description)->toContain('builder: Writes working code');
    } finally {
        cleanupTestTree($workspace);
    }
});

test('description falls back to a names list when discovery is unavailable', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'anthropic/claude'],
            ],
        ],
    ]);

    $tool = new SpawnAgentTool(
        roleResolver: new RoleResolver($config),
        config: $config,
        projectRoot: '/tmp', workspacePath: '/tmp',
    );

    expect($tool->description())->toContain('coder');
});

test('accepts visibility registry constructor parameter', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4));
    mkdir($tmpDir);

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                ],
            ],
        ]);

        $registry = new ToolkitVisibilityRegistry($tmpDir);

        $tool = new SpawnAgentTool(
            roleResolver: new RoleResolver($config),
            config: $config,
            projectRoot: '/tmp',
            workspacePath: '/tmp',
            visibilityRegistry: $registry,
        );

        expect($tool->name())->toBe('spawn_agent');
    } finally {
        // Cleanup
        array_map('unlink', glob($tmpDir . '/*') ?: []);
        rmdir($tmpDir);
    }
});

test('artifact-creating child roles can also save and forget memory (memory-on-promotion)', function () {
    $workspace = sys_get_temp_dir() . '/coqui-spawn-mem-' . bin2hex(random_bytes(4));
    mkdir($workspace, 0755, true);

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => ['coder' => 'anthropic/claude', 'reviewer' => 'openai/gpt-4o'],
                ],
            ],
        ]);

        $storage = new SessionStorage($workspace . '/sessions.sqlite');
        $memoryStore = new MemoryStore($workspace . '/memory.sqlite');

        $tool = new SpawnAgentTool(
            roleResolver: new RoleResolver($config),
            config: $config,
            projectRoot: $workspace,
            workspacePath: $workspace,
            storage: $storage,
            sessionId: 'sess-123',
            memoryStore: $memoryStore,
        );

        // 'reviewer' resolves to the non-full default access level, yet it still
        // receives a create-capable artifact toolkit. It must therefore also be
        // able to record + supersede a durable memory pointer to a canonical one.
        $reviewerTools = spawnChildToolNames($tool, 'reviewer');

        expect($reviewerTools)->toContain('artifact_create');
        expect($reviewerTools)->toContain('memory_save');
        expect($reviewerTools)->toContain('memory_forget');

        // 'coder' resolves to full access and must keep the same capabilities.
        $coderTools = spawnChildToolNames($tool, 'coder');

        expect($coderTools)->toContain('artifact_create');
        expect($coderTools)->toContain('memory_save');
        expect($coderTools)->toContain('memory_forget');
    } finally {
        cleanupTestTree($workspace);
    }
});

test('filters available roles and rejects disallowed child roles for the active profile', function () {
    $workspace = sys_get_temp_dir() . '/coqui-spawn-profile-' . bin2hex(random_bytes(4));
    mkdir($workspace . '/profiles/caelum', 0755, true);
    file_put_contents($workspace . '/profiles/caelum/preferences.json', json_encode([
        'prompts' => [
            'roles' => [
                'allow' => ['orchestrator', 'reviewer'],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => [
                        'coder' => 'anthropic/claude',
                        'reviewer' => 'openai/gpt-4o',
                    ],
                ],
            ],
        ]);

        $tool = new SpawnAgentTool(
            roleResolver: new RoleResolver($config),
            config: $config,
            projectRoot: $workspace,
            workspacePath: $workspace,
            activeProfile: 'caelum',
            activeProfilePath: $workspace . '/profiles/caelum',
        );

        $schema = $tool->toFunctionSchema();
        $result = $tool->execute(['role' => 'coder', 'task' => 'Write code']);

        expect($schema['function']['parameters']['properties']['role']['enum'])->toBe(['reviewer']);
        expect($result->status->value)->toBe('error');
        expect($result->content)->toContain('does not allow role "coder"');
    } finally {
        cleanupTestTree($workspace);
    }
});
