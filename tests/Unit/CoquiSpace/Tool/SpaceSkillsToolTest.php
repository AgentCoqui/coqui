<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\CoquiSpace\Installer\SkillInstaller;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\Tool\SpaceSkillsTool;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

// ── Setup ────────────────────────────────────────────────────────────────────

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-skills-tool-' . uniqid();
    mkdir($this->tmpDir . '/skills', 0755, true);

    // SpaceClient is final — mock the injected HttpClientInterface (interface)
    $this->http = $this->createMock(HttpClientInterface::class);
    $this->client = new SpaceClient(
        fn() => 'https://coqui.space/api/v1',
        fn() => '',
        $this->http,
    );

    $discovery = new SkillDiscovery($this->tmpDir);
    $this->installer = new SkillInstaller($this->client, $discovery, $this->tmpDir . '/skills');
    $this->tool = new SpaceSkillsTool($this->client, $this->installer);
});

afterEach(function () {
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

test('name returns space_skills', function () {
    expect($this->tool->name())->toBe('space_skills');
});

test('description is non-empty string', function () {
    expect(strlen($this->tool->description()))->toBeGreaterThan(0);
});

test('parameters returns at least 5 items', function () {
    expect(count($this->tool->parameters()))->toBeGreaterThanOrEqual(5);
});

test('toFunctionSchema returns valid schema', function () {
    $schema = $this->tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('space_skills');
    expect($schema['function'])->toHaveKey('parameters');
});

// ── Error paths ───────────────────────────────────────────────────────────────

test('execute with unknown action returns error result', function () {
    $result = $this->tool->execute(['action' => 'unknown']);

    expect($result->status->value)->toBe('error');
});

test('execute search without query returns error containing query', function () {
    $result = $this->tool->execute(['action' => 'search']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('query');
});

test('execute details without owner and name returns error', function () {
    expect($this->tool->execute(['action' => 'details'])->status->value)->toBe('error');
});

test('execute versions without owner and name returns error', function () {
    expect($this->tool->execute(['action' => 'versions'])->status->value)->toBe('error');
});

test('execute reviews without owner and name returns error', function () {
    expect($this->tool->execute(['action' => 'reviews'])->status->value)->toBe('error');
});

test('execute file without owner and name returns error', function () {
    expect($this->tool->execute(['action' => 'file'])->status->value)->toBe('error');
});

test('execute install without owner and name returns error', function () {
    expect($this->tool->execute(['action' => 'install'])->status->value)->toBe('error');
});

test('execute update without skill_name returns error mentioning skill_name', function () {
    $result = $this->tool->execute(['action' => 'update']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('skill_name');
});

// ── Success paths ─────────────────────────────────────────────────────────────

test('search action returns success with markdown table when results exist', function () {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getContent')->willReturn(json_encode([
        'results' => [
            [
                'name' => 'code-review',
                'displayName' => 'Code Review',
                'owner' => 'carmelosantana',
                'version' => '1.0.0',
                'score' => 9.5,
                'verified_publisher' => true,
            ],
        ],
    ]));
    $this->http->method('request')->willReturn($response);

    $result = $this->tool->execute(['action' => 'search', 'query' => 'code review']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('Code Review');
    expect($result->content)->toContain('carmelosantana');
});

test('search action returns success no skills found when results are empty', function () {
    $response = $this->createMock(ResponseInterface::class);
    $response->method('getContent')->willReturn(json_encode(['results' => []]));
    $this->http->method('request')->willReturn($response);

    $result = $this->tool->execute(['action' => 'search', 'query' => 'something obscure']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('No skills found');
});
