<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ApiHealthCheck;
use CoquiBot\Coqui\Support\RuntimeIdentity;

test('check returns error when API server is unreachable', function () {
    // Point to a port that nothing is listening on
    putenv('COQUI_API_HOST=127.0.0.1');
    putenv('COQUI_API_PORT=19999');

    $result = ApiHealthCheck::check();

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('Cannot reach API server');
    expect($result['error'])->toContain('19999');

    // Cleanup
    putenv('COQUI_API_HOST');
    putenv('COQUI_API_PORT');
});

test('check resolves host and port from environment', function () {
    putenv('COQUI_API_HOST=10.0.0.99');
    putenv('COQUI_API_PORT=4444');

    $result = ApiHealthCheck::check();

    // Will fail to connect, but the error should reference the custom host/port
    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('10.0.0.99');
    expect($result['error'])->toContain('4444');

    putenv('COQUI_API_HOST');
    putenv('COQUI_API_PORT');
});

test('check uses defaults when env vars are not set', function () {
    putenv('COQUI_API_HOST');
    putenv('COQUI_API_PORT');

    $result = ApiHealthCheck::check();

    // Unless the dev happens to have the API running on 3300, this will fail
    // Either way the error should reference the defaults
    if (!$result['ok']) {
        expect($result['error'])->toContain('127.0.0.1');
        expect($result['error'])->toContain('3300');
    } else {
        expect($result['error'])->toBeNull();
    }
});

test('validatePayload rejects workspace mismatch', function () {
    $payload = [
        'status' => 'ok',
        'workspace_id' => RuntimeIdentity::fingerprintPath('/tmp/other-workspace'),
        'managers' => [
            'tasks' => ['ready' => true],
            'loops' => ['ready' => true],
        ],
    ];

    $result = ApiHealthCheck::validatePayload(
        $payload,
        expectedWorkspacePath: '/tmp/current-workspace',
        requireTaskManager: true,
        requireLoopManager: true,
    );

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('different workspace');
});

test('validatePayload rejects missing ready loop manager', function () {
    $payload = [
        'status' => 'ok',
        'workspace_id' => RuntimeIdentity::fingerprintPath('/tmp/current-workspace'),
        'managers' => [
            'tasks' => ['ready' => true],
            'loops' => ['ready' => false],
        ],
    ];

    $result = ApiHealthCheck::validatePayload(
        $payload,
        expectedWorkspacePath: '/tmp/current-workspace',
        requireTaskManager: true,
        requireLoopManager: true,
    );

    expect($result['ok'])->toBeFalse();
    expect($result['error'])->toContain('loop manager');
});
