<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// ── Helpers ──────────────────────────────────────────────────────────────────

function makeSkillDir(string $skillsDir, string $name, array $origin = []): void
{
    $dir = $skillsDir . '/' . $name;
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/SKILL.md', "---\nname: {$name}\ndescription: test\n---\n");
    if ($origin !== []) {
        file_put_contents($dir . '/.space-origin.json', json_encode($origin));
    }
}

function cleanupSkillInstallerDir(string $dir): void
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
    $this->tmpDir = sys_get_temp_dir() . '/coqui-skill-installer-' . uniqid();
    $this->skillsDir = $this->tmpDir . '/skills';
    mkdir($this->skillsDir, 0755, true);

    // SpaceClient is final — mock the injected HttpClientInterface instead
    $this->http = $this->createMock(HttpClientInterface::class);
    $this->client = new SpaceClient(
        fn() => 'https://coqui.space/api/v1',
        fn() => '',
        $this->http,
    );

    // SkillDiscovery is final — use a real instance with the temp workspace
    $this->discovery = new SkillDiscovery($this->tmpDir);

    $this->installer = new SkillInstaller(
        $this->client,
        $this->discovery,
        $this->skillsDir,
    );
});

afterEach(function () {
    cleanupSkillInstallerDir($this->tmpDir);
});

// ── list() ───────────────────────────────────────────────────────────────────

test('list returns empty array when skills dir does not exist', function () {
    $installer = new SkillInstaller(
        $this->client,
        $this->discovery,
        $this->tmpDir . '/nonexistent-skills',
    );

    expect($installer->list())->toBeEmpty();
});

test('list returns discovered skills sorted by name', function () {
    makeSkillDir($this->skillsDir, 'zebra-skill');
    makeSkillDir($this->skillsDir, 'alpha-skill');
    makeSkillDir($this->skillsDir, 'middle-skill');

    $skills = $this->installer->list();

    expect($skills)->toHaveCount(3);
    expect($skills[0]['name'])->toBe('alpha-skill');
    expect($skills[1]['name'])->toBe('middle-skill');
    expect($skills[2]['name'])->toBe('zebra-skill');
});

test('list identifies disabled skills with .disabled suffix', function () {
    makeSkillDir($this->skillsDir, 'my-skill.disabled');

    $skills = $this->installer->list();

    expect($skills)->toHaveCount(1);
    expect($skills[0]['name'])->toBe('my-skill');
    expect($skills[0]['status'])->toBe('disabled');
});

test('list identifies enabled skills without .disabled suffix', function () {
    makeSkillDir($this->skillsDir, 'enabled-skill');

    $skills = $this->installer->list();

    expect($skills[0]['status'])->toBe('enabled');
});

test('list identifies skills with .space-origin.json as source coqui.space', function () {
    makeSkillDir($this->skillsDir, 'remote-skill', [
        'source' => 'coqui.space',
        'owner' => 'testowner',
        'name' => 'remote-skill',
        'version' => '1.0.0',
    ]);

    $skills = $this->installer->list();

    expect($skills[0]['source'])->toBe('coqui.space');
    expect($skills[0]['owner'])->toBe('testowner');
});

test('list identifies skills without origin as source local', function () {
    makeSkillDir($this->skillsDir, 'local-skill');

    $skills = $this->installer->list();

    expect($skills[0]['source'])->toBe('local');
});

// ── disable() ────────────────────────────────────────────────────────────────

test('disable renames dir to .disabled suffix', function () {
    makeSkillDir($this->skillsDir, 'my-skill');

    $result = $this->installer->disable('my-skill');

    expect($result)->toContain('disabled');
    expect(is_dir($this->skillsDir . '/my-skill'))->toBeFalse();
    expect(is_dir($this->skillsDir . '/my-skill.disabled'))->toBeTrue();
});

test('disable returns already disabled message when dir already has .disabled suffix', function () {
    makeSkillDir($this->skillsDir, 'my-skill.disabled');

    $result = $this->installer->disable('my-skill');

    expect($result)->toContain('already disabled');
    // Dir should remain unchanged
    expect(is_dir($this->skillsDir . '/my-skill.disabled'))->toBeTrue();
});

test('disable throws RuntimeException when skill not found', function () {
    expect(fn() => $this->installer->disable('nonexistent'))
        ->toThrow(RuntimeException::class);
});

// ── enable() ─────────────────────────────────────────────────────────────────

test('enable renames .disabled dir back to active name', function () {
    makeSkillDir($this->skillsDir, 'my-skill.disabled');

    $result = $this->installer->enable('my-skill');

    expect($result)->toContain('enabled');
    expect(is_dir($this->skillsDir . '/my-skill.disabled'))->toBeFalse();
    expect(is_dir($this->skillsDir . '/my-skill'))->toBeTrue();
});

test('enable returns already enabled message when dir exists without .disabled', function () {
    makeSkillDir($this->skillsDir, 'my-skill');

    $result = $this->installer->enable('my-skill');

    expect($result)->toContain('already enabled');
});

test('enable throws RuntimeException when neither enabled nor disabled dir found', function () {
    expect(fn() => $this->installer->enable('nonexistent'))
        ->toThrow(RuntimeException::class);
});

// ── remove() ─────────────────────────────────────────────────────────────────

test('remove with purge false delegates to disable', function () {
    makeSkillDir($this->skillsDir, 'my-skill');

    $result = $this->installer->remove('my-skill', false);

    // Should have been disabled (renamed), not deleted
    expect(is_dir($this->skillsDir . '/my-skill'))->toBeFalse();
    expect(is_dir($this->skillsDir . '/my-skill.disabled'))->toBeTrue();
    expect($result)->toContain('disabled');
});

test('remove with purge true deletes the directory', function () {
    makeSkillDir($this->skillsDir, 'my-skill');

    $result = $this->installer->remove('my-skill', true);

    expect(is_dir($this->skillsDir . '/my-skill'))->toBeFalse();
    expect(is_dir($this->skillsDir . '/my-skill.disabled'))->toBeFalse();
    expect($result)->toContain('removed');
});

test('remove with purge true on a disabled skill also deletes it', function () {
    makeSkillDir($this->skillsDir, 'my-skill.disabled');

    $result = $this->installer->remove('my-skill', true);

    expect(is_dir($this->skillsDir . '/my-skill.disabled'))->toBeFalse();
    expect($result)->toContain('removed');
});

test('remove with purge true throws RuntimeException when skill not found', function () {
    expect(fn() => $this->installer->remove('nonexistent', true))
        ->toThrow(RuntimeException::class);
});
