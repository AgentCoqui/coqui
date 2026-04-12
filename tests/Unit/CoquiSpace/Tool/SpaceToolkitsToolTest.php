<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\CoquiSpace\Installer\ComposerRunner;
use CoquiBot\Coqui\CoquiSpace\Installer\ToolkitInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\Tool\SpaceToolkitsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-toolkits-tool-' . uniqid();
    mkdir($this->tmpDir, 0755, true);

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

    $discovery = new ToolkitDiscovery($this->tmpDir, $this->tmpDir);
    $composer = new ComposerRunner($this->tmpDir);
    $this->installer = new ToolkitInstaller($this->client, $composer, $discovery, $this->tmpDir);

    $this->tool = new SpaceToolkitsTool($this->client, $this->installer);
});

afterEach(function () {
    @unlink($this->fakeBin);
    putenv('COMPOSER_BIN=');
    cleanupTestTree($this->tmpDir);
});

// ── Contract ─────────────────────────────────────────────────────────────────

test('name returns space_toolkits', function () {
    expect($this->tool->name())->toBe('coqui_space_toolkits');
});

test('description is non-empty string', function () {
    expect(strlen($this->tool->description()))->toBeGreaterThan(0);
});

test('toFunctionSchema returns valid schema', function () {
    $schema = $this->tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('coqui_space_toolkits');
    expect($schema['function'])->toHaveKey('parameters');
});

// ── Error paths ───────────────────────────────────────────────────────────────

test('execute with unknown action returns error', function () {
    expect($this->tool->execute(['action' => 'unknown'])->status->value)->toBe('error');
});

test('execute search without query returns error mentioning query', function () {
    $result = $this->tool->execute(['action' => 'search']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('query');
});

test('execute details without package or owner/name returns error', function () {
    expect($this->tool->execute(['action' => 'details'])->status->value)->toBe('error');
});

test('execute install without package returns error mentioning package', function () {
    $result = $this->tool->execute(['action' => 'install']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('package');
});

test('execute install with package missing slash returns error', function () {
    expect($this->tool->execute(['action' => 'install', 'package' => 'noslash'])->status->value)->toBe('error');
});

test('execute update without package returns error mentioning package', function () {
    $result = $this->tool->execute(['action' => 'update']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('package');
});

test('execute update with package missing slash returns error', function () {
    expect($this->tool->execute(['action' => 'update', 'package' => 'noslash'])->status->value)->toBe('error');
});

// ── Success paths ─────────────────────────────────────────────────────────────

test('search action returns success with markdown table when results exist', function () {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getContent')->willReturn(json_encode([
        'results' => [
            [
                'name' => 'coquibot/coqui-toolkit-brave-search',
                'description' => 'Brave Search toolkit',
                'downloads' => 10,
                'favers' => 2,
                'verified_publisher' => true,
                'owner' => 'carmelosantana',
            ],
        ],
    ]));
    $this->http->method('request')->willReturn($response);

    $result = $this->tool->execute(['action' => 'search', 'query' => 'brave']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('brave-search');
});
