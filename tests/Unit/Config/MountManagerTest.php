<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Contract\MountDefinition;

beforeEach(function () {
    // Create isolated temp workspace
    $this->workspace = sys_get_temp_dir() . '/coqui-mount-test-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);

    // Create real directories to mount
    $this->mountA = sys_get_temp_dir() . '/coqui-mount-a-' . bin2hex(random_bytes(4));
    $this->mountB = sys_get_temp_dir() . '/coqui-mount-b-' . bin2hex(random_bytes(4));
    mkdir($this->mountA, 0755, true);
    mkdir($this->mountB, 0755, true);
});

afterEach(function () {
    // Clean up symlinks in mnt/
    $mntDir = $this->workspace . '/mnt';
    if (is_dir($mntDir)) {
        $entries = scandir($mntDir) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $mntDir . '/' . $entry;
            if (is_link($path)) {
                unlink($path);
            }
        }
        rmdir($mntDir);
    }
    if (is_dir($this->workspace)) {
        rmdir($this->workspace);
    }
    if (is_dir($this->mountA)) {
        rmdir($this->mountA);
    }
    if (is_dir($this->mountB)) {
        rmdir($this->mountB);
    }
});

test('hasMounts returns false when no mounts', function () {
    $manager = new MountManager($this->workspace);

    expect($manager->hasMounts())->toBeFalse();
});

test('hasMounts returns true when mounts exist', function () {
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha'),
    ]);

    expect($manager->hasMounts())->toBeTrue();
});

test('mounts returns declared mounts', function () {
    $mount = new MountDefinition($this->mountA, 'alpha');
    $manager = new MountManager($this->workspace, [$mount]);

    expect($manager->mounts())->toHaveCount(1);
    expect($manager->mounts()[0]->alias)->toBe('alpha');
});

test('initialize creates symlinks in mnt directory', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symlinks require Developer Mode on Windows.');
    }

    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha'),
        new MountDefinition($this->mountB, 'beta', 'rw'),
    ]);

    $manager->initialize();

    $linkA = $this->workspace . '/mnt/alpha';
    $linkB = $this->workspace . '/mnt/beta';

    expect(is_link($linkA))->toBeTrue();
    expect(is_link($linkB))->toBeTrue();
    expect(readlink($linkA))->toBe($this->mountA);
    expect(readlink($linkB))->toBe($this->mountB);
});

test('initialize skips when no mounts', function () {
    $manager = new MountManager($this->workspace);

    $manager->initialize();

    expect(is_dir($this->workspace . '/mnt'))->toBeFalse();
});

test('initialize cleans up stale symlinks', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symlinks require Developer Mode on Windows.');
    }

    $mntDir = $this->workspace . '/mnt';
    mkdir($mntDir, 0755, true);

    // Create a stale symlink manually
    symlink($this->mountA, $mntDir . '/stale');

    // Initialize with a different mount — stale should be removed
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountB, 'beta'),
    ]);

    $manager->initialize();

    expect(is_link($mntDir . '/stale'))->toBeFalse();
    expect(is_link($mntDir . '/beta'))->toBeTrue();
});

test('initialize updates symlink if target changed', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symlinks require Developer Mode on Windows.');
    }

    $mntDir = $this->workspace . '/mnt';
    mkdir($mntDir, 0755, true);

    // Create symlink pointing to mountA
    symlink($this->mountA, $mntDir . '/data');

    // Now initialize with same alias pointing to mountB
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountB, 'data'),
    ]);

    $manager->initialize();

    expect(readlink($mntDir . '/data'))->toBe($this->mountB);
});

test('initialize is idempotent for correct symlinks', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Symlinks require Developer Mode on Windows.');
    }

    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha'),
    ]);

    $manager->initialize();
    $manager->initialize(); // Second call should not error

    expect(is_link($this->workspace . '/mnt/alpha'))->toBeTrue();
    expect(readlink($this->workspace . '/mnt/alpha'))->toBe($this->mountA);
});

test('allowedPaths returns correct structure', function () {
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha', 'ro'),
        new MountDefinition($this->mountB, 'beta', 'rw'),
    ]);

    $paths = $manager->allowedPaths();

    expect($paths)->toHaveCount(2);
    expect($paths[0])->toHaveKeys(['realPath', 'readOnly']);
    expect($paths[0]['realPath'])->toBe(realpath($this->mountA));
    expect($paths[0]['readOnly'])->toBeTrue();
    expect($paths[1]['realPath'])->toBe(realpath($this->mountB));
    expect($paths[1]['readOnly'])->toBeFalse();
});

test('allowedPaths returns empty array when no mounts', function () {
    $manager = new MountManager($this->workspace);

    expect($manager->allowedPaths())->toBe([]);
});

test('allowedPathsReadOnly forces all mounts to read-only', function () {
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha', 'rw'),
        new MountDefinition($this->mountB, 'beta', 'rw'),
    ]);

    $paths = $manager->allowedPathsReadOnly();

    expect($paths)->toHaveCount(2);
    expect($paths[0]['readOnly'])->toBeTrue();
    expect($paths[1]['readOnly'])->toBeTrue();
});

test('storageMap returns empty string when no mounts', function () {
    $manager = new MountManager($this->workspace);

    expect($manager->storageMap())->toBe('');
});

test('storageMap renders markdown table with mount details', function () {
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha', 'ro', 'Read-only datasets'),
        new MountDefinition($this->mountB, 'beta', 'rw', 'Source code'),
    ]);

    $map = $manager->storageMap();

    expect($map)->toContain('### Mounted Directories');
    expect($map)->toContain('| Mount Path | Real Path | Access | Description |');
    expect($map)->toContain('`mnt/alpha/`');
    expect($map)->toContain('Read-only');
    expect($map)->toContain('Read-only datasets');
    expect($map)->toContain('`mnt/beta/`');
    expect($map)->toContain('Read/Write');
    expect($map)->toContain('Source code');
    expect($map)->toContain('Write to mounted directories only when explicitly instructed');
});

test('storageMap uses dash for missing descriptions', function () {
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha'),
    ]);

    $map = $manager->storageMap();

    // Null description should render as em-dash
    expect($map)->toContain('—');
});

test('openBasedirPaths returns real paths', function () {
    $manager = new MountManager($this->workspace, [
        new MountDefinition($this->mountA, 'alpha'),
        new MountDefinition($this->mountB, 'beta'),
    ]);

    $paths = $manager->openBasedirPaths();

    expect($paths)->toHaveCount(2);
    expect($paths[0])->toBe(realpath($this->mountA));
    expect($paths[1])->toBe(realpath($this->mountB));
});

test('openBasedirPaths returns empty array when no mounts', function () {
    $manager = new MountManager($this->workspace);

    expect($manager->openBasedirPaths())->toBe([]);
});
