<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\CoquiSpace\Installer\ComposerRunner;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\SpaceRegistry;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// ── Helpers ──────────────────────────────────────────────────────────────────

function cleanupToolkitInstallerDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
    }
    rmdir($dir);
}

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-toolkit-installer-' . uniqid();
    mkdir($this->tmpDir, 0755, true);

    // SpaceClient is final — mock the injected HttpClientInterface instead
    $this->http = $this->createMock(HttpClientInterface::class);
    $this->client = new SpaceClient(
        fn() => 'https://coqui.space/api/v1',
        fn() => '',
        $this->http,
    );

    // Create a fake composer binary that always exits 0
    $this->fakeBin = sys_get_temp_dir() . '/fake-composer-' . uniqid();
    file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");
    chmod($this->fakeBin, 0755);
    putenv("COMPOSER_BIN={$this->fakeBin}");

    // ToolkitDiscovery is final — use a real instance with temp dirs
    $this->discovery = new ToolkitDiscovery($this->tmpDir, $this->tmpDir);

    $this->composer = new ComposerRunner($this->tmpDir);

    $this->installer = new ToolkitInstaller(
        $this->client,
        $this->composer,
        $this->discovery,
        $this->tmpDir,
    );
});

afterEach(function () {
    if (file_exists($this->fakeBin)) {
        unlink($this->fakeBin);
    }
    putenv('COMPOSER_BIN=');
    cleanupToolkitInstallerDir($this->tmpDir);
});

// ── Static validation ─────────────────────────────────────────────────────────

test('install with invalid package name throws InvalidArgumentException', function () {
    expect(fn() => $this->installer->install('noslash'))
        ->toThrow(InvalidArgumentException::class);
});

test('install with excluded package throws RuntimeException', function () {
    expect(fn() => $this->installer->install('coquibot/coqui-toolkit-composer'))
        ->toThrow(RuntimeException::class);
});

test('update with invalid package name throws InvalidArgumentException', function () {
    expect(fn() => $this->installer->update('noslash'))
        ->toThrow(InvalidArgumentException::class);
});

test('update with excluded package throws RuntimeException', function () {
    expect(fn() => $this->installer->update('coquibot/coqui-toolkit-composer'))
        ->toThrow(RuntimeException::class);
});

test('disable with excluded package throws RuntimeException', function () {
    expect(fn() => $this->installer->disable('coquibot/coqui-toolkit-composer'))
        ->toThrow(RuntimeException::class);
});

test('remove with excluded package throws RuntimeException', function () {
    expect(fn() => $this->installer->remove('coquibot/coqui-toolkit-composer'))
        ->toThrow(RuntimeException::class);
});

// ── Package name validation ───────────────────────────────────────────────────

test('validatePackageName accepts vendor/package format', function () {
    file_put_contents($this->tmpDir . '/composer.json', json_encode(['require' => []]));

    expect(fn() => $this->installer->install('vendor/package'))
        ->not->toThrow(InvalidArgumentException::class);
});

test('validatePackageName accepts vendor/pkg-name format', function () {
    file_put_contents($this->tmpDir . '/composer.json', json_encode(['require' => []]));

    expect(fn() => $this->installer->install('vendor/pkg-name'))
        ->not->toThrow(InvalidArgumentException::class);
});

test('validatePackageName accepts mixed-case package name', function () {
    file_put_contents($this->tmpDir . '/composer.json', json_encode(['require' => []]));

    expect(fn() => $this->installer->install('Vendor/Package'))
        ->not->toThrow(InvalidArgumentException::class);
});

test('validatePackageName rejects name with no slash', function () {
    expect(fn() => $this->installer->install('noslash'))
        ->toThrow(InvalidArgumentException::class);
});

test('validatePackageName rejects name with spaces', function () {
    expect(fn() => $this->installer->install('vendor/ pkg'))
        ->toThrow(InvalidArgumentException::class);
});

// ── disable() state file management ──────────────────────────────────────────

test('disable writes state file with constraint', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Fake shell-script Composer binary cannot execute on Windows.');
    }

    file_put_contents($this->tmpDir . '/composer.json', json_encode([
        'require' => ['vendor/my-toolkit' => '^1.0'],
    ]));

    $result = $this->installer->disable('vendor/my-toolkit');

    expect($result)->toContain('disabled');
    expect(file_exists($this->tmpDir . '/' . SpaceRegistry::STATE_FILE))->toBeTrue();
});

test('state file records correct constraint after disable', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Fake shell-script Composer binary cannot execute on Windows.');
    }

    file_put_contents($this->tmpDir . '/composer.json', json_encode([
        'require' => ['vendor/my-toolkit' => '^2.0'],
    ]));

    $this->installer->disable('vendor/my-toolkit');

    $state = json_decode(file_get_contents($this->tmpDir . '/' . SpaceRegistry::STATE_FILE), true);
    expect($state)->toHaveKey('vendor/my-toolkit');
    expect($state['vendor/my-toolkit']['constraint'])->toBe('^2.0');
});

test('enable with no saved state and not installed throws RuntimeException', function () {
    file_put_contents($this->tmpDir . '/composer.json', json_encode(['require' => []]));

    expect(fn() => $this->installer->enable('vendor/nonexistent'))
        ->toThrow(RuntimeException::class);
});

test('state file entry is removed after disable then enable', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('Fake shell-script Composer binary cannot execute on Windows.');
    }

    file_put_contents($this->tmpDir . '/composer.json', json_encode([
        'require' => ['vendor/my-toolkit' => '^1.0'],
    ]));

    $this->installer->disable('vendor/my-toolkit');

    $stateFile = $this->tmpDir . '/' . SpaceRegistry::STATE_FILE;
    expect(file_exists($stateFile))->toBeTrue();

    $this->installer->enable('vendor/my-toolkit');

    if (file_exists($stateFile)) {
        $state = json_decode(file_get_contents($stateFile), true);
        expect($state)->not->toHaveKey('vendor/my-toolkit');
    } else {
        // File deleted when state is empty — also correct
        expect(true)->toBeTrue();
    }
});

// ── list() ───────────────────────────────────────────────────────────────────

test('list returns enabled entries from registry merged with disabled from state file', function () {
    // Write toolkits.json for ToolkitDiscovery::loadRegistry()
    file_put_contents($this->tmpDir . '/toolkits.json', json_encode([
        'vendor/pkg' => ['VendorClass'],
    ]));

    // composer.json with constraint
    file_put_contents($this->tmpDir . '/composer.json', json_encode([
        'require' => ['vendor/pkg' => '^1.0'],
    ]));

    // Disabled state entry
    file_put_contents($this->tmpDir . '/' . SpaceRegistry::STATE_FILE, json_encode([
        'vendor/disabled-pkg' => [
            'constraint' => '^2.0',
            'disabledAt' => '2025-01-01T00:00:00+00:00',
        ],
    ]));

    $toolkits = $this->installer->list();

    $packages = array_column($toolkits, 'package');
    expect($packages)->toContain('vendor/pkg');
    expect($packages)->toContain('vendor/disabled-pkg');

    $pkgEntry = array_values(array_filter($toolkits, fn($t) => $t['package'] === 'vendor/pkg'))[0];
    expect($pkgEntry['status'])->toBe('enabled');

    $disabledEntry = array_values(array_filter($toolkits, fn($t) => $t['package'] === 'vendor/disabled-pkg'))[0];
    expect($disabledEntry['status'])->toBe('disabled');
});

test('list excludes excluded packages', function () {
    file_put_contents($this->tmpDir . '/toolkits.json', json_encode([
        'coquibot/coqui-toolkit-composer' => ['SomeClass'],
        'vendor/good-toolkit' => ['GoodClass'],
    ]));

    file_put_contents($this->tmpDir . '/composer.json', json_encode([
        'require' => [
            'coquibot/coqui-toolkit-composer' => '*',
            'vendor/good-toolkit' => '^1.0',
        ],
    ]));

    $toolkits = $this->installer->list();

    $packages = array_column($toolkits, 'package');
    expect($packages)->not->toContain('coquibot/coqui-toolkit-composer');
    expect($packages)->toContain('vendor/good-toolkit');
});

test('list sorts by package name', function () {
    file_put_contents($this->tmpDir . '/toolkits.json', json_encode([
        'zoo/pkg' => ['ZooClass'],
        'alpha/pkg' => ['AlphaClass'],
        'middle/pkg' => ['MiddleClass'],
    ]));

    file_put_contents($this->tmpDir . '/composer.json', json_encode([
        'require' => ['zoo/pkg' => '*', 'alpha/pkg' => '*', 'middle/pkg' => '*'],
    ]));

    $toolkits = $this->installer->list();

    expect($toolkits[0]['package'])->toBe('alpha/pkg');
    expect($toolkits[1]['package'])->toBe('middle/pkg');
    expect($toolkits[2]['package'])->toBe('zoo/pkg');
});
