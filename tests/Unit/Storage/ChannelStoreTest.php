<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * @return array{dbPath: string, storage: SessionStorage, store: ChannelStore}
 */
function makeChannelStoreFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-channel-store-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'store' => new ChannelStore($storage->getPdo()),
    ];
}

test('upsertConfiguredInstance inserts and updates channel instances', function (): void {
    $fixture = makeChannelStoreFixture();

    try {
    $id = $fixture['store']->upsertConfiguredInstance([
        'name' => 'signal-primary',
        'driver' => 'signal',
        'enabled' => true,
        'display_name' => 'Signal Primary',
        'default_profile' => 'assistant',
        'settings' => ['transport' => 'signal-cli'],
        'allowed_scopes' => ['group-alpha'],
        'security' => ['mode' => 'linked-only'],
        'source' => 'config',
    ], ['direct_messages' => true]);

    $row = $fixture['store']->getByName('signal-primary');

        expect($id)->toBeString();
        expect($row)->not->toBeNull();
        expect($row['driver'])->toBe('signal');
        expect($row['display_name'])->toBe('Signal Primary');

        $sameId = $fixture['store']->upsertConfiguredInstance([
        'name' => 'signal-primary',
        'driver' => 'signal',
        'enabled' => false,
        'display_name' => 'Signal Primary',
        'default_profile' => 'assistant',
        'settings' => ['transport' => 'signal-cli'],
        'allowed_scopes' => ['group-alpha'],
        'security' => ['mode' => 'linked-only'],
        'source' => 'config',
    ], ['direct_messages' => true]);

        expect($sameId)->toBe($id);
        expect((int) $fixture['store']->getByName('signal-primary')['enabled'])->toBe(0);
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});

test('updateRuntimeState stores health alongside instance metadata', function (): void {
    $fixture = makeChannelStoreFixture();

    try {
    $id = $fixture['store']->upsertConfiguredInstance([
        'name' => 'telegram-bot',
        'driver' => 'telegram',
        'enabled' => true,
        'display_name' => 'Telegram Bot',
        'default_profile' => null,
        'settings' => [],
        'allowed_scopes' => [],
        'security' => [],
        'source' => 'config',
    ]);

        $fixture['store']->updateRuntimeState($id, [
        'worker_status' => 'placeholder',
        'ready' => false,
        'summary' => 'Telegram runtime scaffold registered.',
        'last_heartbeat_at' => '2026-04-20T00:00:00Z',
        'last_receive_at' => null,
        'last_send_at' => null,
        'inbound_backlog' => 0,
        'outbound_backlog' => 0,
        'consecutive_failures' => 0,
        'last_error' => null,
    ]);

        $row = $fixture['store']->getByName('telegram-bot');

        expect($row['worker_status'])->toBe('placeholder');
        expect((int) $row['ready'])->toBe(0);
        expect($row['summary'])->toBe('Telegram runtime scaffold registered.');
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});

test('getStats reports configured and enabled channel counts', function (): void {
    $fixture = makeChannelStoreFixture();

    try {
    $fixture['store']->upsertConfiguredInstance([
        'name' => 'signal-primary',
        'driver' => 'signal',
        'enabled' => true,
        'display_name' => 'Signal Primary',
        'default_profile' => null,
        'settings' => [],
        'allowed_scopes' => [],
        'security' => [],
        'source' => 'config',
    ]);
        $fixture['store']->upsertConfiguredInstance([
        'name' => 'discord-ops',
        'driver' => 'discord',
        'enabled' => false,
        'display_name' => 'Discord Ops',
        'default_profile' => null,
        'settings' => [],
        'allowed_scopes' => [],
        'security' => [],
        'source' => 'config',
    ]);

        $stats = $fixture['store']->getStats();

        expect($stats['total'])->toBe(2);
        expect($stats['enabled'])->toBe(1);
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});