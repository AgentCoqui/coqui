<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\CoquiSpace\Installer\ComposerRunner;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\Tool\SpaceManageTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-manage-tool-' . uniqid();
    mkdir($this->tmpDir . '/skills', 0755, true);

    // SpaceClient is final — mock the injected HttpClientInterface (interface)
    $this->http = $this->createMock(HttpClientInterface::class);
    $this->client = new SpaceClient(
        fn() => 'https://coqui.space/api/v1',
        fn() => '',
        $this->http,
    );

    // Fake composer binary so ComposerRunner never calls the real composer
    $this->fakeBin = sys_get_temp_dir() . '/fake-composer-' . uniqid();
    file_put_contents($this->fakeBin, "#!/bin/sh\nexit 0\n");
    chmod($this->fakeBin, 0755);
    putenv("COMPOSER_BIN={$this->fakeBin}");

    $skillDiscovery = new SkillDiscovery($this->tmpDir);
    $toolkitDiscovery = new ToolkitDiscovery($this->tmpDir, $this->tmpDir);
    $composer = new ComposerRunner($this->tmpDir);

    $this->skillInstaller = new SkillInstaller($this->client, $skillDiscovery, $this->tmpDir . '/skills');
    $this->toolkitInstaller = new ToolkitInstaller($this->client, $composer, $toolkitDiscovery, $this->tmpDir);

    $this->tool = new SpaceManageTool(
        $this->client,
        $this->skillInstaller,
        $this->toolkitInstaller,
    );
});

afterEach(function () {
    if (file_exists($this->fakeBin)) {
        unlink($this->fakeBin);
    }
    putenv('COMPOSER_BIN=');

    if (is_dir($this->tmpDir)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getRealPath()) : unlink($file->getRealPath());
        }
        rmdir($this->tmpDir);
    }
});

// ── Contract ─────────────────────────────────────────────────────────────────

test('name returns space', function () {
    expect($this->tool->name())->toBe('space');
});

test('description is non-empty string', function () {
    $desc = $this->tool->description();
    expect($desc)->toBeString();
    expect(strlen($desc))->toBeGreaterThan(0);
});

test('toFunctionSchema returns valid schema', function () {
    $schema = $this->tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema)->toHaveKey('function');
    expect($schema['function'])->toHaveKey('name');
    expect($schema['function'])->toHaveKey('parameters');
    expect($schema['function']['name'])->toBe('space');
});

// ── Error paths ───────────────────────────────────────────────────────────────

test('execute with unknown action returns error', function () {
    $result = $this->tool->execute(['action' => 'unknown']);

    expect($result->status->value)->toBe('error');
});

test('execute star without entity_type/owner/name returns error', function () {
    $result = $this->tool->execute(['action' => 'star']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('entity_type');
});

test('execute star with partial params returns error', function () {
    $result = $this->tool->execute([
        'action' => 'star',
        'entity_type' => 'skill',
        // missing owner and name
    ]);

    expect($result->status->value)->toBe('error');
});

test('execute unstar without required params returns error', function () {
    $result = $this->tool->execute(['action' => 'unstar']);

    expect($result->status->value)->toBe('error');
});

test('execute submit without source_url returns error', function () {
    $result = $this->tool->execute([
        'action' => 'submit',
        'type' => 'skill',
        // missing source_url
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('source_url');
});

test('execute submit without type returns error', function () {
    $result = $this->tool->execute([
        'action' => 'submit',
        'source_url' => 'https://github.com/user/repo',
        // missing type
    ]);

    expect($result->status->value)->toBe('error');
});

test('execute search_all without query returns error', function () {
    $result = $this->tool->execute(['action' => 'search_all']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('query');
});

test('execute review without required params returns error', function () {
    $result = $this->tool->execute(['action' => 'review']);

    expect($result->status->value)->toBe('error');
});

test('execute review with invalid rating returns error', function () {
    $result = $this->tool->execute([
        'action' => 'review',
        'entity_type' => 'skill',
        'owner' => 'carmelosantana',
        'name' => 'code-review',
        'rating' => 6, // out of range
    ]);

    expect($result->status->value)->toBe('error');
});

test('execute disable without name returns error', function () {
    $result = $this->tool->execute(['action' => 'disable']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('name');
});

test('execute enable without name returns error', function () {
    $result = $this->tool->execute(['action' => 'enable']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('name');
});

test('execute remove without name returns error', function () {
    $result = $this->tool->execute(['action' => 'remove']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('name');
});

// ── Success paths ─────────────────────────────────────────────────────────────

test('execute health returns success with ok status', function () {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getContent')->willReturn(json_encode([
        'status' => 'ok',
        'version' => '1.0.0',
        'timestamp' => '2025-01-01T00:00:00Z',
    ]));
    $this->http->method('request')->willReturn($response);

    $result = $this->tool->execute(['action' => 'health']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('ok');
});

test('execute installed returns success with empty lists when nothing installed', function () {
    file_put_contents($this->tmpDir . '/composer.json', json_encode(['require' => []]));

    $result = $this->tool->execute(['action' => 'installed']);

    expect($result->status->value)->toBe('success');
});
