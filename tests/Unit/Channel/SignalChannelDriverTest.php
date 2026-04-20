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