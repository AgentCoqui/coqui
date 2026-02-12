<?php

declare(strict_types=1);

use Coqui\Tool\CredentialTool;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-test-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir, 0755, true);
    $this->tool = new CredentialTool(workspacePath: $this->tmpDir);
});

afterEach(function () {
    $envFile = $this->tmpDir . '/.env';
    if (file_exists($envFile)) {
        unlink($envFile);
    }
    if (is_dir($this->tmpDir)) {
        rmdir($this->tmpDir);
    }
});

test('has correct name', function () {
    expect($this->tool->name())->toBe('credentials');
});

test('set stores a credential', function () {
    $result = $this->tool->execute(['action' => 'set', 'key' => 'API_KEY', 'value' => 'secret123']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('API_KEY');
    expect($result->content)->toContain('getenv');
    // Value should NOT appear in the result
    expect($result->content)->not->toContain('secret123');
});

test('get confirms credential exists without revealing value', function () {
    $this->tool->execute(['action' => 'set', 'key' => 'MY_SECRET', 'value' => 'hidden']);

    $result = $this->tool->execute(['action' => 'get', 'key' => 'MY_SECRET']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('MY_SECRET');
    expect($result->content)->not->toContain('hidden');
});

test('get returns error for missing key', function () {
    $result = $this->tool->execute(['action' => 'get', 'key' => 'NONEXISTENT']);

    expect($result->status->value)->toBe('error');
});

test('list shows all key names', function () {
    $this->tool->execute(['action' => 'set', 'key' => 'KEY_A', 'value' => 'val1']);
    $this->tool->execute(['action' => 'set', 'key' => 'KEY_B', 'value' => 'val2']);

    $result = $this->tool->execute(['action' => 'list']);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('KEY_A');
    expect($result->content)->toContain('KEY_B');
    // Values must not appear
    expect($result->content)->not->toContain('val1');
    expect($result->content)->not->toContain('val2');
});

test('delete removes a credential', function () {
    $this->tool->execute(['action' => 'set', 'key' => 'TO_DELETE', 'value' => 'temp']);

    $result = $this->tool->execute(['action' => 'delete', 'key' => 'TO_DELETE']);
    expect($result->status->value)->toBe('success');

    $result = $this->tool->execute(['action' => 'get', 'key' => 'TO_DELETE']);
    expect($result->status->value)->toBe('error');
});

test('rejects invalid key names', function () {
    $result = $this->tool->execute(['action' => 'set', 'key' => 'invalid-key', 'value' => 'x']);
    expect($result->status->value)->toBe('error');

    $result = $this->tool->execute(['action' => 'set', 'key' => '123_BAD', 'value' => 'x']);
    expect($result->status->value)->toBe('error');
});

test('requires key for set, get, delete', function () {
    expect($this->tool->execute(['action' => 'set', 'key' => ''])->status->value)->toBe('error');
    expect($this->tool->execute(['action' => 'get', 'key' => ''])->status->value)->toBe('error');
    expect($this->tool->execute(['action' => 'delete', 'key' => ''])->status->value)->toBe('error');
});

test('requires value for set', function () {
    $result = $this->tool->execute(['action' => 'set', 'key' => 'VALID_KEY', 'value' => '']);
    expect($result->status->value)->toBe('error');
});

test('env file has restricted permissions', function () {
    $this->tool->execute(['action' => 'set', 'key' => 'TEST_KEY', 'value' => 'test']);

    $envFile = $this->tmpDir . '/.env';
    $perms = fileperms($envFile) & 0777;

    expect($perms)->toBe(0600);
});

test('handles values with special characters', function () {
    $this->tool->execute(['action' => 'set', 'key' => 'COMPLEX_VAL', 'value' => 'has spaces & "quotes"']);

    // Verify we can read the env file and the key is persisted
    $result = $this->tool->execute(['action' => 'get', 'key' => 'COMPLEX_VAL']);
    expect($result->status->value)->toBe('success');
});
