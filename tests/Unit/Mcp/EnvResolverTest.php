<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\Config\EnvResolver;

// -- Resolve --

test('resolve replaces placeholder with env value', function () {
    $original = getenv('TEST_MCP_KEY_1');
    putenv('TEST_MCP_KEY_1=secret123');

    $resolver = new EnvResolver();
    $result = $resolver->resolve([
        'API_KEY' => '${TEST_MCP_KEY_1}',
    ]);

    expect($result['resolved']['API_KEY'])->toBe('secret123')
        ->and($result['unresolved'])->toBe([]);

    // Restore
    if ($original !== false) {
        putenv("TEST_MCP_KEY_1={$original}");
    } else {
        putenv('TEST_MCP_KEY_1');
    }
});

test('resolve tracks unresolved placeholders', function () {
    // Ensure this var does not exist
    putenv('MCP_TEST_UNDEFINED_VAR_XYZ');

    $resolver = new EnvResolver();
    $result = $resolver->resolve([
        'TOKEN' => '${MCP_TEST_UNDEFINED_VAR_XYZ}',
    ]);

    expect($result['resolved']['TOKEN'])->toBe('')
        ->and($result['unresolved'])->toBe(['MCP_TEST_UNDEFINED_VAR_XYZ']);
});

test('resolve passes through literal values unchanged', function () {
    $resolver = new EnvResolver();
    $result = $resolver->resolve([
        'PORT' => '8080',
        'HOST' => 'localhost',
    ]);

    expect($result['resolved'])->toBe(['PORT' => '8080', 'HOST' => 'localhost'])
        ->and($result['unresolved'])->toBe([]);
});

test('resolve handles mixed literal and placeholder values', function () {
    $original = getenv('TEST_MCP_KEY_2');
    putenv('TEST_MCP_KEY_2=mykey');

    $resolver = new EnvResolver();
    $result = $resolver->resolve([
        'API_KEY' => '${TEST_MCP_KEY_2}',
        'STATIC' => 'hello',
    ]);

    expect($result['resolved']['API_KEY'])->toBe('mykey')
        ->and($result['resolved']['STATIC'])->toBe('hello')
        ->and($result['unresolved'])->toBe([]);

    if ($original !== false) {
        putenv("TEST_MCP_KEY_2={$original}");
    } else {
        putenv('TEST_MCP_KEY_2');
    }
});

test('resolve deduplicates unresolved vars', function () {
    putenv('MCP_DEDUP_TEST_VAR');

    $resolver = new EnvResolver();
    $result = $resolver->resolve([
        'A' => '${MCP_DEDUP_TEST_VAR}',
        'B' => '${MCP_DEDUP_TEST_VAR}',
    ]);

    expect($result['unresolved'])->toBe(['MCP_DEDUP_TEST_VAR']);
});

test('resolve handles empty env map', function () {
    $resolver = new EnvResolver();
    $result = $resolver->resolve([]);

    expect($result['resolved'])->toBe([])
        ->and($result['unresolved'])->toBe([]);
});

// -- FindMissing --

test('findMissing returns missing variable names', function () {
    putenv('MCP_MISSING_TEST_VAR');

    $resolver = new EnvResolver();
    $missing = $resolver->findMissing([
        'TOKEN' => '${MCP_MISSING_TEST_VAR}',
    ]);

    expect($missing)->toBe(['MCP_MISSING_TEST_VAR']);
});

test('findMissing returns empty for set variables', function () {
    $original = getenv('TEST_MCP_KEY_3');
    putenv('TEST_MCP_KEY_3=exists');

    $resolver = new EnvResolver();
    $missing = $resolver->findMissing([
        'KEY' => '${TEST_MCP_KEY_3}',
    ]);

    expect($missing)->toBe([]);

    if ($original !== false) {
        putenv("TEST_MCP_KEY_3={$original}");
    } else {
        putenv('TEST_MCP_KEY_3');
    }
});

test('findMissing ignores literal values', function () {
    $resolver = new EnvResolver();
    $missing = $resolver->findMissing([
        'PORT' => '3000',
    ]);

    expect($missing)->toBe([]);
});

test('findMissing deduplicates results', function () {
    putenv('MCP_DEDUP_MISSING_VAR');

    $resolver = new EnvResolver();
    $missing = $resolver->findMissing([
        'A' => '${MCP_DEDUP_MISSING_VAR}',
        'B' => '${MCP_DEDUP_MISSING_VAR}',
    ]);

    expect($missing)->toBe(['MCP_DEDUP_MISSING_VAR']);
});
