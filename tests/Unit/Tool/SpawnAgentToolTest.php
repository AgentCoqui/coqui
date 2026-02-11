<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use Coqui\Config\RoleResolver;
use Coqui\Tool\SpawnAgentTool;

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
        workDir: '/tmp',
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
        workDir: '/tmp',
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
        workDir: '/tmp',
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
        workDir: '/tmp',
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
        workDir: '/tmp',
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
        workDir: '/tmp',
    );

    $description = $tool->description();

    expect($description)->toContain('coder');
    expect($description)->toContain('reviewer');
});
