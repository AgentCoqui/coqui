<?php

declare(strict_types=1);

use CoquiBot\Coqui\Channel\Builtin\PlaceholderChannelRuntime;
use CoquiBot\Coqui\Channel\Builtin\SignalChannelDriver;
use CoquiBot\Coqui\Channel\Builtin\SignalCliChannelRuntime;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\SessionStorage;

test('signal driver validates required runtime settings', function (): void {
    $driver = new SignalChannelDriver();

    $errors = $driver->validateInstanceConfig([
        'settings' => [
            'binary' => '',
            'ignoreAttachments' => 'yes',
            'sendReadReceipts' => 'no',
            'receiveMode' => 'manual',
        ],
    ]);

    expect($errors)->toContain(
        'signal settings.account is required',
        'signal settings.binary must be a non-empty string when provided',
        'signal settings.ignoreAttachments must be a boolean',
        'signal settings.sendReadReceipts must be a boolean',
        'signal settings.receiveMode currently only supports: on-start',
    );
});

test('signal driver returns signal-cli runtime when store context is available', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-signal-driver-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new ChannelStore($storage->getPdo());
    $driver = new SignalChannelDriver();

    try {
        $runtime = $driver->createRuntime([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'default_profile' => 'caelum',
            'settings' => ['account' => '+15551234567'],
        ], [
            'channelStore' => $store,
            'channelInstanceId' => 'channel-1',
            'workspacePath' => sys_get_temp_dir(),
        ]);

        $fallbackRuntime = $driver->createRuntime([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'settings' => ['account' => '+15551234567'],
        ]);

        expect($runtime)->toBeInstanceOf(SignalCliChannelRuntime::class);
        expect($fallbackRuntime)->toBeInstanceOf(PlaceholderChannelRuntime::class);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('signal runtime accepts direct envelope json lines', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-signal-runtime-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new ChannelStore($storage->getPdo());

    try {
        $channelId = $store->upsertConfiguredInstance([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'source' => 'config',
            'enabled' => true,
            'display_name' => 'Signal Primary',
            'default_profile' => 'trinity',
            'settings' => ['account' => '+12013380755'],
            'allowed_scopes' => [],
            'security' => [],
            'capabilities' => [],
        ]);

        $runtime = new SignalCliChannelRuntime(
            instanceDefinition: [
                'name' => 'signal-primary',
                'default_profile' => 'trinity',
                'settings' => ['account' => '+12013380755'],
            ],
            channelStore: $store,
            channelInstanceId: $channelId,
            workspacePath: sys_get_temp_dir(),
        );

        $reflection = new \ReflectionClass($runtime);
        $handleJsonLine = $reflection->getMethod('handleJsonLine');
        $handleJsonLine->setAccessible(true);

        $handleJsonLine->invoke($runtime, json_encode([
            'source' => '+18885551234',
            'sourceNumber' => '+18885551234',
            'sourceUuid' => 'b77a076c-6786-4c58-bc31-f7c1e35cc615',
            'sourceName' => 'Carmelo Santana',
            'sourceDevice' => 2,
            'timestamp' => 1776895496040,
            'dataMessage' => [
                'timestamp' => 1776895496040,
                'message' => 'hello',
                'expiresInSeconds' => 0,
                'isExpirationUpdate' => false,
                'viewOnce' => false,
            ],
        ], JSON_UNESCAPED_SLASHES));

        $events = $store->listEvents($channelId);
        $conversations = $store->listConversations($channelId);

        expect($conversations)->toHaveCount(1);
        expect($events)->toHaveCount(1);
        expect($events[0]['remote_user_key'])->toBe('+18885551234');
        expect($events[0]['status'])->toBe('received');
        expect($events[0]['normalized']['message'])->toBe('hello');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('signal runtime ignores typing and receipt envelopes', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-signal-runtime-ignore-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new ChannelStore($storage->getPdo());

    try {
        $channelId = $store->upsertConfiguredInstance([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'source' => 'config',
            'enabled' => true,
            'display_name' => 'Signal Primary',
            'default_profile' => 'trinity',
            'settings' => ['account' => '+12013380755'],
            'allowed_scopes' => [],
            'security' => [],
            'capabilities' => [],
        ]);

        $runtime = new SignalCliChannelRuntime(
            instanceDefinition: [
                'name' => 'signal-primary',
                'default_profile' => 'trinity',
                'settings' => ['account' => '+12013380755'],
            ],
            channelStore: $store,
            channelInstanceId: $channelId,
            workspacePath: sys_get_temp_dir(),
        );

        $reflection = new \ReflectionClass($runtime);
        $handleJsonLine = $reflection->getMethod('handleJsonLine');
        $handleJsonLine->setAccessible(true);

        $handleJsonLine->invoke($runtime, json_encode([
            'jsonrpc' => '2.0',
            'method' => 'receive',
            'params' => [
                'envelope' => [
                    'source' => '+18885551234',
                    'sourceNumber' => '+18885551234',
                    'sourceUuid' => 'b77a076c-6786-4c58-bc31-f7c1e35cc615',
                    'sourceName' => 'Carmelo Santana',
                    'sourceDevice' => 2,
                    'timestamp' => 1776897533978,
                    'typingMessage' => [
                        'action' => 'STARTED',
                        'timestamp' => 1776897533978,
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $handleJsonLine->invoke($runtime, json_encode([
            'source' => '+18885551234',
            'sourceNumber' => '+18885551234',
            'sourceUuid' => 'b77a076c-6786-4c58-bc31-f7c1e35cc615',
            'sourceName' => 'Carmelo Santana',
            'sourceDevice' => 2,
            'timestamp' => 1776897523408,
            'receiptMessage' => [
                'when' => 1776897523444,
                'isDelivery' => true,
                'isRead' => false,
                'isViewed' => false,
                'timestamps' => [1776897523408],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $events = $store->listEvents($channelId);
        $conversations = $store->listConversations($channelId);

        expect($events)->toHaveCount(0);
        expect($conversations)->toHaveCount(0);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('signal runtime ignores empty data messages', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-signal-runtime-empty-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new ChannelStore($storage->getPdo());

    try {
        $channelId = $store->upsertConfiguredInstance([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'source' => 'config',
            'enabled' => true,
            'display_name' => 'Signal Primary',
            'default_profile' => 'trinity',
            'settings' => ['account' => '+12013380755'],
            'allowed_scopes' => [],
            'security' => [],
            'capabilities' => [],
        ]);

        $runtime = new SignalCliChannelRuntime(
            instanceDefinition: [
                'name' => 'signal-primary',
                'default_profile' => 'trinity',
                'settings' => ['account' => '+12013380755'],
            ],
            channelStore: $store,
            channelInstanceId: $channelId,
            workspacePath: sys_get_temp_dir(),
        );

        $reflection = new \ReflectionClass($runtime);
        $handleJsonLine = $reflection->getMethod('handleJsonLine');
        $handleJsonLine->setAccessible(true);

        $handleJsonLine->invoke($runtime, json_encode([
            'source' => '+18885551234',
            'sourceNumber' => '+18885551234',
            'sourceUuid' => 'b77a076c-6786-4c58-bc31-f7c1e35cc615',
            'sourceName' => 'Carmelo Santana',
            'sourceDevice' => 2,
            'timestamp' => 1776728589033,
            'dataMessage' => [
                'timestamp' => 1776728589033,
                'message' => null,
                'expiresInSeconds' => 0,
                'isExpirationUpdate' => false,
                'viewOnce' => false,
            ],
        ], JSON_UNESCAPED_SLASHES));

        $events = $store->listEvents($channelId);
        $conversations = $store->listConversations($channelId);

        expect($events)->toHaveCount(0);
        expect($conversations)->toHaveCount(0);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('signal runtime builds json-rpc send requests for outbound deliveries', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-signal-runtime-send-request-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new ChannelStore($storage->getPdo());

    try {
        $runtime = new SignalCliChannelRuntime(
            instanceDefinition: [
                'name' => 'signal-primary',
                'default_profile' => 'trinity',
                'settings' => ['account' => '+12013380755'],
            ],
            channelStore: $store,
            channelInstanceId: 'channel-1',
            workspacePath: sys_get_temp_dir(),
        );

        $reflection = new \ReflectionClass($runtime);
        $buildSendRequest = $reflection->getMethod('buildSendRequest');
        $buildSendRequest->setAccessible(true);

        $recipientRequest = json_decode((string) $buildSendRequest->invoke($runtime, 'send-1', 'hello', '+18885551234', null), true, 512, JSON_THROW_ON_ERROR);
        $groupRequest = json_decode((string) $buildSendRequest->invoke($runtime, 'send-2', 'hello group', null, 'group-123'), true, 512, JSON_THROW_ON_ERROR);

        expect($recipientRequest)->toBe([
            'jsonrpc' => '2.0',
            'method' => 'send',
            'params' => [
                'message' => 'hello',
                'recipient' => '+18885551234',
            ],
            'id' => 'send-1',
        ]);

        expect($groupRequest)->toBe([
            'jsonrpc' => '2.0',
            'method' => 'send',
            'params' => [
                'message' => 'hello group',
                'groupId' => 'group-123',
            ],
            'id' => 'send-2',
        ]);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('signal runtime isolates child commands from inherited listener sockets', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-signal-runtime-isolated-command-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $store = new ChannelStore($storage->getPdo());

    try {
        $runtime = new SignalCliChannelRuntime(
            instanceDefinition: [
                'name' => 'signal-primary',
                'default_profile' => 'trinity',
                'settings' => ['account' => '+12013380755'],
            ],
            channelStore: $store,
            channelInstanceId: 'channel-1',
            workspacePath: sys_get_temp_dir(),
        );

        $reflection = new \ReflectionClass($runtime);
        $buildIsolatedCommand = $reflection->getMethod('buildIsolatedCommand');
        $buildIsolatedCommand->setAccessible(true);

        $wrapped = $buildIsolatedCommand->invoke($runtime, ['signal-cli', '--version']);

        expect($wrapped[0])->toBe('/bin/sh');
        expect($wrapped[1])->toBe('-c');
        expect($wrapped[2])->toContain('/dev/fd/*');
        expect($wrapped[2])->toContain('exec "$@"');
        expect($wrapped[3])->toBe('signal-cli-wrapper');
        expect(array_slice($wrapped, 4))->toBe(['signal-cli', '--version']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});