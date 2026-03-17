<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\ToolkitVisibility;

function createRegistryWorkspace(): string
{
    $dir = sys_get_temp_dir() . '/coqui-vis-' . uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function cleanRegistryWorkspace(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }

    rmdir($dir);
}

test('returns Enabled for missing packages (default)', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    expect($registry->getPackageVisibility('vendor/pkg'))->toBe(ToolkitVisibility::Enabled);

    cleanRegistryWorkspace($dir);
});

test('returns Enabled for missing tools (default)', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    expect($registry->getToolVisibility('custom_tool'))->toBe(ToolkitVisibility::Enabled);

    cleanRegistryWorkspace($dir);
});

test('persists and reads back package visibility', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    $registry->setPackageVisibility('vendor/pkg', ToolkitVisibility::Stub);

    $registry2 = new ToolkitVisibilityRegistry($dir);
    expect($registry2->getPackageVisibility('vendor/pkg'))->toBe(ToolkitVisibility::Stub);

    cleanRegistryWorkspace($dir);
});

test('persists and reads back tool visibility', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    $registry->setToolVisibility('spawn_agent', ToolkitVisibility::Stub);

    $registry2 = new ToolkitVisibilityRegistry($dir);
    expect($registry2->getToolVisibility('spawn_agent'))->toBe(ToolkitVisibility::Stub);

    cleanRegistryWorkspace($dir);
});

test('setting Enabled removes entry from file', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    $registry->setPackageVisibility('vendor/pkg', ToolkitVisibility::Disabled);
    $registry->setPackageVisibility('vendor/pkg', ToolkitVisibility::Enabled);

    $data = json_decode((string) file_get_contents($dir . '/toolkit-visibility.json'), true);
    expect($data['packages'] ?? [])->not->toHaveKey('vendor/pkg');

    cleanRegistryWorkspace($dir);
});

test('all() returns current state', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    $registry->setPackageVisibility('vendor/a', ToolkitVisibility::Stub);
    $registry->setToolVisibility('spawn_agent', ToolkitVisibility::Stub);

    $all = $registry->all();
    expect($all['packages']['vendor/a'])->toBe('stub');
    expect($all['tools']['spawn_agent'])->toBe('stub');

    cleanRegistryWorkspace($dir);
});

test('invalidateCache forces reload from disk', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    // Prime the cache
    $registry->getPackageVisibility('vendor/pkg');

    // Write directly to disk bypassing the cache
    file_put_contents($dir . '/toolkit-visibility.json', json_encode([
        'packages' => ['vendor/pkg' => 'disabled'],
        'tools' => [],
    ]));

    // Before invalidation, cache returns old value
    expect($registry->getPackageVisibility('vendor/pkg'))->toBe(ToolkitVisibility::Enabled);

    // After invalidation, reads new value from disk
    $registry->invalidateCache();
    expect($registry->getPackageVisibility('vendor/pkg'))->toBe(ToolkitVisibility::Disabled);

    cleanRegistryWorkspace($dir);
});

test('setToolVisibility throws for ALWAYS_ENABLED tools', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    expect(fn() => $registry->setToolVisibility('tool_search', ToolkitVisibility::Stub))
        ->toThrow(\InvalidArgumentException::class);

    expect(fn() => $registry->setToolVisibility('credentials', ToolkitVisibility::Disabled))
        ->toThrow(\InvalidArgumentException::class);

    cleanRegistryWorkspace($dir);
});

test('setToolVisibility throws when disabling a CANNOT_DISABLE tool', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    expect(fn() => $registry->setToolVisibility('spawn_agent', ToolkitVisibility::Disabled))
        ->toThrow(\InvalidArgumentException::class);

    cleanRegistryWorkspace($dir);
});

test('setToolVisibility allows stubbing a CANNOT_DISABLE tool', function () {
    $dir = createRegistryWorkspace();
    $registry = new ToolkitVisibilityRegistry($dir);

    $registry->setToolVisibility('spawn_agent', ToolkitVisibility::Stub);

    expect($registry->getToolVisibility('spawn_agent'))->toBe(ToolkitVisibility::Stub);

    cleanRegistryWorkspace($dir);
});

test('getToolVisibility always returns Enabled for ALWAYS_ENABLED tools regardless of file', function () {
    $dir = createRegistryWorkspace();

    // Write a file claiming tool_search is disabled
    file_put_contents($dir . '/toolkit-visibility.json', json_encode([
        'packages' => [],
        'tools' => ['tool_search' => 'disabled'],
    ]));

    $registry = new ToolkitVisibilityRegistry($dir);

    // Must still return Enabled due to guard
    expect($registry->getToolVisibility('tool_search'))->toBe(ToolkitVisibility::Enabled);

    cleanRegistryWorkspace($dir);
});
