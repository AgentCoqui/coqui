<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\CoquiDefaults;

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
        expect($registry->getMode($name))->toBe('system');
        expect($registry->isSystem($name))->toBeTrue();
        expect($registry->shouldLoadEagerly($name))->toBeTrue();
    }

    cleanLoadingRegistryWorkspace($dir);
});

test('non-system toolkits default to deferred mode', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    expect($registry->getMode('BraveSearchToolkit'))->toBe('deferred');
    expect($registry->isSystem('BraveSearchToolkit'))->toBeFalse();
    expect($registry->shouldLoadEagerly('BraveSearchToolkit'))->toBeFalse();

    cleanLoadingRegistryWorkspace($dir);
});

test('setting a toolkit to eager persists and reads back', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('BraveSearchToolkit', 'eager');
    expect($registry->getMode('BraveSearchToolkit'))->toBe('eager');
    expect($registry->shouldLoadEagerly('BraveSearchToolkit'))->toBeTrue();

    // Verify persistence by creating a fresh instance
    $registry2 = new ToolkitLoadingRegistry($dir);
    expect($registry2->getMode('BraveSearchToolkit'))->toBe('eager');

    cleanLoadingRegistryWorkspace($dir);
});

test('setting a toolkit back to deferred removes the entry', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('BraveSearchToolkit', 'eager');
    expect($registry->getMode('BraveSearchToolkit'))->toBe('eager');

    $registry->setMode('BraveSearchToolkit', 'deferred');
    expect($registry->getMode('BraveSearchToolkit'))->toBe('deferred');
    expect($registry->all())->not->toHaveKey('BraveSearchToolkit');

    cleanLoadingRegistryWorkspace($dir);
});

test('cannot change system toolkit mode', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('FileSystemToolkit', 'deferred');

    cleanLoadingRegistryWorkspace($dir);
})->throws(\InvalidArgumentException::class);

test('rejects invalid mode strings', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('SomeToolkit', 'invalid');

    cleanLoadingRegistryWorkspace($dir);
})->throws(\InvalidArgumentException::class);

test('handles corrupted JSON file gracefully', function () {
    $dir = createLoadingRegistryWorkspace();
    file_put_contents($dir . '/toolkit-loading.json', 'not json');

    $registry = new ToolkitLoadingRegistry($dir);
    expect($registry->getMode('SomeToolkit'))->toBe('deferred');

    cleanLoadingRegistryWorkspace($dir);
});

test('all() returns only explicit overrides', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    expect($registry->all())->toBe([]);

    $registry->setMode('BraveSearchToolkit', 'eager');
    $registry->setMode('BrowserToolkit', 'eager');

    $all = $registry->all();
    expect($all)->toHaveCount(2);
    expect($all['BraveSearchToolkit'])->toBe('eager');
    expect($all['BrowserToolkit'])->toBe('eager');

    cleanLoadingRegistryWorkspace($dir);
});

test('invalidateCache forces re-read from disk', function () {
    $dir = createLoadingRegistryWorkspace();
    $registry = new ToolkitLoadingRegistry($dir);

    $registry->setMode('BraveSearchToolkit', 'eager');

    // Externally modify the file
    file_put_contents($dir . '/toolkit-loading.json', '{}');

    // Without invalidation, cache returns stale value
    expect($registry->getMode('BraveSearchToolkit'))->toBe('eager');

    // After invalidation, reads fresh from disk
    $registry->invalidateCache();
    expect($registry->getMode('BraveSearchToolkit'))->toBe('deferred');

    cleanLoadingRegistryWorkspace($dir);
});
