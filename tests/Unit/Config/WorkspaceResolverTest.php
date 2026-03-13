<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\WorkspaceResolver;
use CoquiBot\Coqui\Config\OpenClawConfig;

test('resolves default workspace to home directory', function () {
    $config = OpenClawConfig::fromArray([]);
    $resolver = new WorkspaceResolver($config, '/tmp/fake-project');

    $result = $resolver->resolve();

    $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? '';
    expect($result)->toBe($home . '/.coqui/.workspace');
});

test('resolves configured workspace path', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-ws-test-' . bin2hex(random_bytes(4));

    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'workspace' => $tmpDir,
            ],
        ],
    ]);

    $resolver = new WorkspaceResolver($config, '/tmp/fake-project');
    $result = $resolver->resolve();

    expect($result)->toBe($tmpDir);
    expect(is_dir($tmpDir))->toBeTrue();

    // Cleanup
    @unlink($tmpDir . '/.gitkeep');
    @rmdir($tmpDir);
});

test('resolves relative workspace path against project root', function () {
    $projectRoot = sys_get_temp_dir() . '/coqui-proj-test-' . bin2hex(random_bytes(4));
    mkdir($projectRoot, 0755, true);

    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'workspace' => 'my-workspace',
            ],
        ],
    ]);

    $resolver = new WorkspaceResolver($config, $projectRoot);
    $result = $resolver->resolve();

    expect($result)->toBe($projectRoot . '/my-workspace');
    expect(is_dir($result))->toBeTrue();

    // Cleanup
    @unlink($result . '/.gitkeep');
    @rmdir($result);
    @rmdir($projectRoot);
});

test('resolves tilde workspace path to home directory', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'workspace' => '~/custom-coqui-workspace',
            ],
        ],
    ]);

    $resolver = new WorkspaceResolver($config, '/tmp/fake-project');
    $result = $resolver->resolve();

    $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? '';
    expect($result)->toBe($home . '/custom-coqui-workspace');

    // Cleanup
    @unlink($result . '/.gitkeep');
    @rmdir($result);
});

test('explicit override takes precedence over config and default', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-override-test-' . bin2hex(random_bytes(4));

    // Config points somewhere different
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'workspace' => '/tmp/should-not-be-used',
            ],
        ],
    ]);

    $resolver = new WorkspaceResolver($config, '/tmp/fake-project', $tmpDir);
    $result = $resolver->resolve();

    expect($result)->toBe($tmpDir);
    expect(is_dir($tmpDir))->toBeTrue();

    // Cleanup
    @unlink($result . '/.gitkeep');
    @rmdir($result);
});

test('null override falls back to config value', function () {
    $tmpDir = sys_get_temp_dir() . '/coqui-fallback-test-' . bin2hex(random_bytes(4));

    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'workspace' => $tmpDir,
            ],
        ],
    ]);

    $resolver = new WorkspaceResolver($config, '/tmp/fake-project', null);
    $result = $resolver->resolve();

    expect($result)->toBe($tmpDir);

    // Cleanup
    @unlink($result . '/.gitkeep');
    @rmdir($result);
});
