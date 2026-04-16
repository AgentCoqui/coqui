<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;

function createRoleResolverTestRole(string $path, string $name, string $model, ?int $maxIterations = null): void
{
    $lines = [
        '---',
        "name: {$name}",
        'display_name: ' . ucfirst($name),
        "description: {$name} role",
        'access_level: full',
        "model: {$model}",
    ];

    if ($maxIterations !== null) {
        $lines[] = "max_iterations: {$maxIterations}";
    }

    $lines[] = '---';
    $lines[] = '';
    $lines[] = "# {$name}";

    file_put_contents($path, implode("\n", $lines));
}

function removeRoleResolverTestTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . '/' . $entry;
        is_dir($path) ? removeRoleResolverTestTree($path) : unlink($path);
    }

    rmdir($dir);
}

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

    expect($resolver->availableRoles())->toEqualCanonicalizing(['orchestrator', 'coder', 'reviewer']);
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

    $array = $resolver->toArray();

    // System role (orchestrator) returns rich metadata
    expect($array['orchestrator'])->toMatchArray([
        'name' => 'orchestrator',
        'model' => 'ollama/qwen3:latest',
        'display_name' => 'Orchestrator',
        'is_system' => true,
        'editable' => false,
    ]);

    // Config-defined role returns name + model
    expect($array['coder'])->toMatchArray([
        'name' => 'coder',
        'model' => 'anthropic/claude-sonnet-4-20250514',
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

test('uses profile soul frontmatter model when no role-specific override exists', function () {
    $workspacePath = sys_get_temp_dir() . '/coqui-role-resolver-' . bin2hex(random_bytes(4));
    $projectRoot = $workspacePath . '/project';
    mkdir($workspacePath . '/profiles/artist', 0755, true);
    mkdir($projectRoot . '/config/roles', 0755, true);
    file_put_contents($workspacePath . '/profiles/artist/soul.md', "---\nmodel: openai/gpt-4.1-mini\n---\n# Artist\n");

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                ],
            ],
        ]);

        $resolver = new RoleResolver(
            $config,
            null,
            new RoleDiscovery($workspacePath, $projectRoot),
            new ProfileDiscovery($workspacePath),
        );

        expect($resolver->resolve('reviewer', 'artist'))->toBe('openai/gpt-4.1-mini');
    } finally {
        removeRoleResolverTestTree($workspacePath);
    }
});

test('profile role override beats global role config and profile default model', function () {
    $workspacePath = sys_get_temp_dir() . '/coqui-role-resolver-' . bin2hex(random_bytes(4));
    $projectRoot = $workspacePath . '/project';
    mkdir($workspacePath . '/roles', 0755, true);
    mkdir($workspacePath . '/profiles/artist/roles', 0755, true);
    mkdir($projectRoot . '/config/roles', 0755, true);

    file_put_contents($workspacePath . '/profiles/artist/soul.md', "---\nmodel: ollama/mistral:latest\n---\n# Artist\n");
    createRoleResolverTestRole($workspacePath . '/roles/coder.md', 'coder', 'openai/gpt-4o');
    createRoleResolverTestRole($workspacePath . '/profiles/artist/roles/coder.md', 'coder', 'anthropic/claude-sonnet-4-20250514', 11);

    try {
        $config = OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => 'ollama/qwen3:latest'],
                    'roles' => [
                        'coder' => 'google/gemini-2.5-pro',
                    ],
                ],
            ],
        ]);

        $resolver = new RoleResolver(
            $config,
            null,
            new RoleDiscovery($workspacePath, $projectRoot),
            new ProfileDiscovery($workspacePath),
        );

        expect($resolver->resolve('coder', 'artist'))->toBe('anthropic/claude-sonnet-4-20250514');
        expect($resolver->resolveMaxIterations('coder', 'artist'))->toBe(11);
    } finally {
        removeRoleResolverTestTree($workspacePath);
    }
});
