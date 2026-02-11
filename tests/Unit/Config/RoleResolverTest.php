<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use Coqui\Config\RoleResolver;

test('resolves configured role to model', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                    'coder' => 'anthropic/claude-sonnet-4-20250514',
                ],
            ],
        ],
    ]);

    $resolver = new RoleResolver($config);

    expect($resolver->resolve('orchestrator'))->toBe('ollama/qwen3:latest');
    expect($resolver->resolve('coder'))->toBe('anthropic/claude-sonnet-4-20250514');
});

test('falls back to primary model for undefined role', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/llama3.2:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                ],
            ],
        ],
    ]);

    $resolver = new RoleResolver($config);

    expect($resolver->resolve('undefined-role'))->toBe('ollama/llama3.2:latest');
});

test('hasRole returns true for configured roles', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'coder' => 'anthropic/claude-sonnet-4-20250514',
                ],
            ],
        ],
    ]);

    $resolver = new RoleResolver($config);

    expect($resolver->hasRole('coder'))->toBeTrue();
    expect($resolver->hasRole('reviewer'))->toBeFalse();
});

test('availableRoles returns all role names', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                    'coder' => 'anthropic/claude-sonnet-4-20250514',
                    'reviewer' => 'openai/gpt-4o',
                ],
            ],
        ],
    ]);

    $resolver = new RoleResolver($config);

    expect($resolver->availableRoles())->toBe(['orchestrator', 'coder', 'reviewer']);
});

test('toArray returns resolved mappings', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                    'coder' => 'anthropic/claude-sonnet-4-20250514',
                ],
            ],
        ],
    ]);

    $resolver = new RoleResolver($config);

    expect($resolver->toArray())->toBe([
        'orchestrator' => 'ollama/qwen3:latest',
        'coder' => 'anthropic/claude-sonnet-4-20250514',
    ]);
});

test('resolves aliases through config', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'models' => [
                    'anthropic/claude-sonnet-4-20250514' => ['alias' => 'claude'],
                ],
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'coder' => 'claude',
                ],
            ],
        ],
    ]);

    $resolver = new RoleResolver($config);

    expect($resolver->resolve('coder'))->toBe('anthropic/claude-sonnet-4-20250514');
});
