<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\WorkspaceResolver;
use CoquiBot\Coqui\Config\HomeDirectory;
use CoquiBot\Coqui\Config\OpenClawConfig;

test('resolves default workspace to home directory', function () {
    $config = OpenClawConfig::fromArray([]);
    $resolver = new WorkspaceResolver($config, '/tmp/fake-project');

    $result = $resolver->resolve();

    $home = HomeDirectory::resolve();
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

    $resolver = new WorkspaceResolver($config, sys_get_temp_dir() . '/fake-project');
    $result = $resolver->resolve();

    // Normalize path separators for cross-platform comparison
    expect(str_replace('\\', '/', $result))->toBe(str_replace('\\', '/', $tmpDir));
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

    $resolver = new WorkspaceResolver($config, sys_get_temp_dir() . '/fake-project');
    $result = $resolver->resolve();

    $home = HomeDirectory::resolve();
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
                'workspace' => sys_get_temp_dir() . '/should-not-be-used',
            ],
        ],
    ]);

    $resolver = new WorkspaceResolver($config, sys_get_temp_dir() . '/fake-project', $tmpDir);
    $result = $resolver->resolve();

    expect(str_replace('\\', '/', $result))->toBe(str_replace('\\', '/', $tmpDir));
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

    $resolver = new WorkspaceResolver($config, sys_get_temp_dir() . '/fake-project', null);
    $result = $resolver->resolve();

    expect(str_replace('\\', '/', $result))->toBe(str_replace('\\', '/', $tmpDir));

    // Cleanup
    @unlink($result . '/.gitkeep');
    @rmdir($result);
});

test('detects Windows absolute paths', function () {
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'workspace' => 'C:\\Users\\test\\workspace',
            ],
        ],
    ]);

    $resolver = new WorkspaceResolver($config, sys_get_temp_dir() . '/fake-project');

    // On Windows, this path exists; on Linux it won't but the path should be
    // treated as absolute (not resolved against project root).
    // We test via reflection to avoid creating C:\Users\test on Linux.
    $method = new ReflectionMethod(WorkspaceResolver::class, 'expandPath');
    $result = $method->invoke($resolver, 'C:\\Users\\test\\workspace');

    expect($result)->toBe('C:\\Users\\test\\workspace');
});

test('detects Windows absolute paths with forward slashes', function () {
    $config = OpenClawConfig::fromArray([]);
    $resolver = new WorkspaceResolver($config, sys_get_temp_dir() . '/fake-project');

    $method = new ReflectionMethod(WorkspaceResolver::class, 'expandPath');
    $result = $method->invoke($resolver, 'D:/some/path');

    expect($result)->toBe('D:/some/path');
});
