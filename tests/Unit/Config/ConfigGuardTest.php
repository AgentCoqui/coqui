<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ConfigGuard;

beforeEach(function () {
    $this->guard = new ConfigGuard();
});

// Allowed keys
test('allows primary model modification', function () {
    expect($this->guard->canModify('agents.defaults.model.primary'))->toBeTrue();
});

test('allows fallbacks modification', function () {
    expect($this->guard->canModify('agents.defaults.model.fallbacks'))->toBeTrue();
});

test('allows role assignments', function () {
    expect($this->guard->canModify('agents.defaults.roles.orchestrator'))->toBeTrue();
    expect($this->guard->canModify('agents.defaults.roles.coder'))->toBeTrue();
    expect($this->guard->canModify('agents.defaults.roles.reviewer'))->toBeTrue();
});

test('allows maxIterations modification', function () {
    expect($this->guard->canModify('agents.defaults.maxIterations'))->toBeTrue();
});

test('allows maxTools modification', function () {
    expect($this->guard->canModify('agents.defaults.maxTools'))->toBeTrue();
});

// Denied keys
test('denies blacklist modification', function () {
    expect($this->guard->canModify('agents.defaults.blacklist'))->toBeFalse();
});

test('denies shellAllowedCommands modification', function () {
    expect($this->guard->canModify('agents.defaults.shellAllowedCommands'))->toBeFalse();
});

test('denies MCP stdio policy modification', function () {
    expect($this->guard->canModify('agents.defaults.mcp.allowedStdioCommands'))->toBeFalse();
    expect($this->guard->canModify('agents.defaults.mcp.deniedStdioCommands'))->toBeFalse();
});

test('denies workspace path modification', function () {
    expect($this->guard->canModify('agents.defaults.workspace'))->toBeFalse();
});

test('denies mount modification', function () {
    expect($this->guard->canModify('agents.defaults.mounts'))->toBeFalse();
    expect($this->guard->canModify('agents.defaults.mounts.0.path'))->toBeFalse();
});

test('denies API configuration modification', function () {
    expect($this->guard->canModify('api.key'))->toBeFalse();
    expect($this->guard->canModify('api.rateLimit.maxRequests'))->toBeFalse();
});

test('denies provider configuration modification', function () {
    expect($this->guard->canModify('models.providers.openai.apiKey'))->toBeFalse();
    expect($this->guard->canModify('models.providers.openai.baseUrl'))->toBeFalse();
});

// Unrecognized keys
test('denies unrecognized keys', function () {
    expect($this->guard->canModify('agents.defaults.somethingNew'))->toBeFalse();
    expect($this->guard->canModify('random.key'))->toBeFalse();
});

// denyReason
test('denyReason returns null for allowed keys', function () {
    expect($this->guard->denyReason('agents.defaults.model.primary'))->toBeNull();
});

test('denyReason returns specific message for blacklist', function () {
    $reason = $this->guard->denyReason('agents.defaults.blacklist');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('blacklist');
});

test('denyReason returns specific message for shell commands', function () {
    $reason = $this->guard->denyReason('agents.defaults.shellAllowedCommands');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('Shell');
});

test('denyReason returns specific message for MCP stdio policy', function () {
    $reason = $this->guard->denyReason('agents.defaults.mcp.allowedStdioCommands');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('MCP stdio allowlist');
});

test('denyReason returns specific message for workspace', function () {
    $reason = $this->guard->denyReason('agents.defaults.workspace');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('Workspace');
});

test('denyReason returns specific message for mounts', function () {
    $reason = $this->guard->denyReason('agents.defaults.mounts');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('Mount');
});

test('denyReason returns specific message for API', function () {
    $reason = $this->guard->denyReason('api.key');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('API');
});

test('denyReason returns specific message for providers', function () {
    $reason = $this->guard->denyReason('models.providers.openai.apiKey');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('Provider');
});

test('denyReason returns generic message for unrecognized keys', function () {
    $reason = $this->guard->denyReason('unknown.key');

    expect($reason)->not->toBeNull();
    expect($reason)->toContain('not in the allowed');
});

// filterWritableKeys
test('filterWritableKeys keeps only allowed entries', function () {
    $flat = [
        'agents.defaults.model.primary' => 'openai/gpt-4o',
        'agents.defaults.blacklist' => ['pattern'],
        'agents.defaults.roles.coder' => 'anthropic/claude-sonnet-4-20250514',
        'api.key' => 'secret',
    ];

    $result = $this->guard->filterWritableKeys($flat);

    expect($result)->toHaveKeys([
        'agents.defaults.model.primary',
        'agents.defaults.roles.coder',
    ]);
    expect($result)->not->toHaveKeys([
        'agents.defaults.blacklist',
        'api.key',
    ]);
});

test('filterWritableKeys returns empty for all-denied input', function () {
    $flat = [
        'agents.defaults.blacklist' => ['pattern'],
        'api.key' => 'secret',
        'models.providers.openai.apiKey' => 'sk-123',
    ];

    $result = $this->guard->filterWritableKeys($flat);

    expect($result)->toBeEmpty();
});
