<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;

function createLoadingRegistryWorkspace(): string
{
    $dir = sys_get_temp_dir() . '/coqui-loading-' . uniqid();
    mkdir($dir, 0755, true);

    return $dir;
}

function cleanLoadingRegistryWorkspace(string $dir): void
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

test('system toolkits always return system mode', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    foreach (CoquiDefaults::SYSTEM_TOOLKITS as $name) {
        expect($registry->getMode($name))->toBe(ToolkitLoadingMode::System);
        expect($registry->isSystem($name))->toBeTrue();
        expect($registry->getMode($name)->isLoaded())->toBeTrue();
    }

    cleanLoadingRegistryWorkspace($dir);
});

test('non-system toolkits default to auto mode', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    expect($registry->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Auto);
    expect($registry->isSystem('BraveSearchToolkit'))->toBeFalse();

    cleanLoadingRegistryWorkspace($dir);
});

test('setting a toolkit to eager persists and reads back', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('BraveSearchToolkit', ToolkitLoadingMode::Eager);
    expect($registry->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Eager);
    expect($registry->getMode('BraveSearchToolkit')->isLoaded())->toBeTrue();

    // Verify persistence by creating a fresh instance
    $registry2 = new ToolkitLoadingRegistry($dir);
    expect($registry2->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Eager);

    cleanLoadingRegistryWorkspace($dir);
});

test('setting a toolkit to deferred persists and reads back', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('BraveSearchToolkit', ToolkitLoadingMode::Deferred);
    expect($registry->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Deferred);
    expect($registry->getMode('BraveSearchToolkit')->isLoaded())->toBeFalse();

    // Verify persistence by creating a fresh instance
    $registry2 = new ToolkitLoadingRegistry($dir);
    expect($registry2->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Deferred);

    cleanLoadingRegistryWorkspace($dir);
});

test('resetMode returns toolkit to auto', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('BraveSearchToolkit', ToolkitLoadingMode::Eager);
    expect($registry->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Eager);

    $registry->resetMode('BraveSearchToolkit');
    expect($registry->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Auto);
    expect($registry->all())->not->toHaveKey('BraveSearchToolkit');

    cleanLoadingRegistryWorkspace($dir);
});

test('cannot change system toolkit mode', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('FileSystemToolkit', ToolkitLoadingMode::Deferred);

    cleanLoadingRegistryWorkspace($dir);
})->throws(\InvalidArgumentException::class);

test('cannot set non-persistable mode (Auto)', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('SomeToolkit', ToolkitLoadingMode::Auto);

    cleanLoadingRegistryWorkspace($dir);
})->throws(\InvalidArgumentException::class);

test('cannot set non-persistable mode (System)', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('SomeToolkit', ToolkitLoadingMode::System);

    cleanLoadingRegistryWorkspace($dir);
})->throws(\InvalidArgumentException::class);

test('handles corrupted JSON file gracefully', function () {
    $dir = createLoadingRegistryWorkspace();
    file_put_contents($dir . '/toolkit-loading.json', 'not json');

    $registry = new ToolkitLoadingRegistry($dir);
    expect($registry->getMode('SomeToolkit'))->toBe(ToolkitLoadingMode::Auto);

    cleanLoadingRegistryWorkspace($dir);
});

test('all() returns only explicit overrides', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    expect($registry->all())->toBe([]);

    $registry->setMode('BraveSearchToolkit', ToolkitLoadingMode::Eager);
    $registry->setMode('BrowserToolkit', ToolkitLoadingMode::Deferred);

    $all = $registry->all();
    expect($all)->toHaveCount(2);
    expect($all['BraveSearchToolkit'])->toBe('eager');
    expect($all['BrowserToolkit'])->toBe('deferred');

    cleanLoadingRegistryWorkspace($dir);
});

test('invalidateCache forces re-read from disk', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('BraveSearchToolkit', ToolkitLoadingMode::Eager);

    // Externally modify the file
    file_put_contents($dir . '/toolkit-loading.json', '{}');

    // Without invalidation, cache returns stale value
    expect($registry->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Eager);

    // After invalidation, reads fresh from disk
    $registry->invalidateCache();
    expect($registry->getMode('BraveSearchToolkit'))->toBe(ToolkitLoadingMode::Auto);

    cleanLoadingRegistryWorkspace($dir);
});

test('cannot reset system toolkit', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->resetMode('FileSystemToolkit');

    cleanLoadingRegistryWorkspace($dir);
})->throws(\InvalidArgumentException::class);
