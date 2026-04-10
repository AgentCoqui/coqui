<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\CoquiSpace\Installer\ComposerRunner;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\SpaceToolkit;
use Symfony\Contracts\HttpClient\HttpClientInterface;

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-space-toolkit-' . uniqid();
    mkdir($this->tmpDir . '/skills', 0755, true);

    // SpaceClient is final — mock the injected HttpClientInterface (interface)
    $this->http = $this->createMock(HttpClientInterface::class);
    $this->client = new SpaceClient(
        fn() => 'https://coqui.space/api/v1',
        fn() => '',
        $this->http,
    );

    // Fake composer binary so ComposerRunner never calls the real composer
    $this->fakeBin = createFakeComposerBinary();
    putenv("COMPOSER_BIN={$this->fakeBin}");

    $skillDiscovery = new SkillDiscovery($this->tmpDir);
    $toolkitDiscovery = new ToolkitDiscovery($this->tmpDir, $this->tmpDir);
    $composer = new ComposerRunner($this->tmpDir);

    $this->skillInstaller = new SkillInstaller($this->client, $skillDiscovery, $this->tmpDir . '/skills');
    $this->toolkitInstaller = new ToolkitInstaller($this->client, $composer, $toolkitDiscovery, $this->tmpDir);

    // Unauthenticated by default
    $this->tokenResolver = static fn(): string => '';

    $this->toolkit = new SpaceToolkit(
        $this->client,
        $this->skillInstaller,
        $this->toolkitInstaller,
        $this->tokenResolver,
    );
});

afterEach(function () {
    @unlink($this->fakeBin);
    putenv('COMPOSER_BIN=');
    cleanupTestTree($this->tmpDir);
});

// ── Contract ─────────────────────────────────────────────────────────────────

test('implements ToolkitInterface', function () {
    expect($this->toolkit)->toBeInstanceOf(ToolkitInterface::class);
});

// ── tools() ──────────────────────────────────────────────────────────────────

test('tools returns 3 tools when not authenticated', function () {
    $tools = $this->toolkit->tools();

    expect(count($tools))->toBe(3);
});

test('tools returns 4 tools when authenticated', function () {
    $authenticated = new SpaceToolkit(
        $this->client,
        $this->skillInstaller,
        $this->toolkitInstaller,
        static fn(): string => 'my-secret-token',
    );

    $tools = $authenticated->tools();

    expect(count($tools))->toBe(4);
});

test('tool names include space_skills', function () {
    $names = array_map(fn($t) => $t->name(), $this->toolkit->tools());

    expect($names)->toContain('space_skills');
});

test('tool names include space_toolkits', function () {
    $names = array_map(fn($t) => $t->name(), $this->toolkit->tools());

    expect($names)->toContain('space_toolkits');
});

test('tool names include space', function () {
    $names = array_map(fn($t) => $t->name(), $this->toolkit->tools());

    expect($names)->toContain('space');
});

test('when authenticated tools include space_account', function () {
    $authenticated = new SpaceToolkit(
        $this->client,
        $this->skillInstaller,
        $this->toolkitInstaller,
        static fn(): string => 'my-secret-token',
    );

    $names = array_map(fn($t) => $t->name(), $authenticated->tools());

    expect($names)->toContain('space_account');
});

test('when not authenticated tools do not include space_account', function () {
    $names = array_map(fn($t) => $t->name(), $this->toolkit->tools());

    expect($names)->not->toContain('space_account');
});

// ── guidelines() ─────────────────────────────────────────────────────────────

test('guidelines is non-empty string', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toBeString();
    expect(strlen($guidelines))->toBeGreaterThan(0);
});

test('guidelines contains space_manager', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toContain('space_manager');
});

// ── Accessors ─────────────────────────────────────────────────────────────────

test('client returns the injected SpaceClient', function () {
    expect($this->toolkit->client())->toBe($this->client);
});

test('skillInstaller returns the injected SkillInstaller', function () {
    expect($this->toolkit->skillInstaller())->toBe($this->skillInstaller);
});

test('toolkitInstaller returns the injected ToolkitInstaller', function () {
    expect($this->toolkit->toolkitInstaller())->toBe($this->toolkitInstaller);
});
