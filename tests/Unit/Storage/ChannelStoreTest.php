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

test('channel store records inbound conversations and deduplicated events', function (): void {
    $fixture = makeChannelStoreFixture();

    try {
        $channelId = $fixture['store']->upsertConfiguredInstance([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'enabled' => true,
            'display_name' => 'Signal Primary',
            'default_profile' => 'caelum',
            'settings' => ['account' => '+15551234567'],
            'allowed_scopes' => [],
            'security' => [],
            'source' => 'config',
        ]);

        $conversationId = $fixture['store']->upsertConversation(
            channelInstanceId: $channelId,
            remoteConversationKey: 'signal-dm:+15557654321',
            profile: 'caelum',
            metadata: ['driver' => 'signal'],
        );

        $eventId = $fixture['store']->createInboundEvent(
            channelInstanceId: $channelId,
            conversationId: $conversationId,
            providerEventId: '1713571200000',
            dedupeKey: 'signal:+15557654321:1713571200000:1',
            eventType: 'data_message',
            remoteUserKey: '+15557654321',
            payload: ['source' => '+15557654321'],
            normalized: ['message' => 'hello'],
            receivedAt: '2026-04-20T00:00:00Z',
        );

        $duplicateEventId = $fixture['store']->createInboundEvent(
            channelInstanceId: $channelId,
            conversationId: $conversationId,
            providerEventId: '1713571200000',
            dedupeKey: 'signal:+15557654321:1713571200000:1',
            eventType: 'data_message',
            remoteUserKey: '+15557654321',
            payload: ['source' => '+15557654321'],
            normalized: ['message' => 'hello'],
            receivedAt: '2026-04-20T00:00:00Z',
        );

        $events = $fixture['store']->listEvents($channelId);
        $conversations = $fixture['store']->listConversations($channelId);

        expect($conversationId)->toBeString();
        expect($eventId)->toBeString();
        expect($duplicateEventId)->toBeNull();
        expect($events)->toHaveCount(1);
        expect($events[0]['status'])->toBe('received');
        expect($conversations)->toHaveCount(1);
        expect($fixture['store']->countInboundBacklog($channelId))->toBe(1);
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});

test('channel store queues deliveries and records attempts', function (): void {
    $fixture = makeChannelStoreFixture();

    try {
        $channelId = $fixture['store']->upsertConfiguredInstance([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'enabled' => true,
            'display_name' => 'Signal Primary',
            'default_profile' => 'caelum',
            'settings' => ['account' => '+15551234567'],
            'allowed_scopes' => [],
            'security' => [],
            'source' => 'config',
        ]);
        $conversationId = $fixture['store']->upsertConversation(
            channelInstanceId: $channelId,
            remoteConversationKey: 'signal-dm:+15557654321',
            profile: 'caelum',
            metadata: ['driver' => 'signal'],
        );
        $sessionId = $fixture['storage']->createSession('orchestrator', 'channel:signal', 'caelum');

        $deliveryId = $fixture['store']->queueDelivery(
            channelInstanceId: $channelId,
            conversationId: $conversationId,
            sessionId: $sessionId,
            replyToEventId: 'event-1',
            idempotencyKey: 'task:1',
            payload: ['message' => 'hello', 'recipient' => '+15557654321'],
        );

        $attemptCount = $fixture['store']->recordDeliveryAttempt(
            deliveryId: $deliveryId,
            resultStatus: 'sent',
            providerResponseBody: '{"timestamp":123}',
        );
        $fixture['store']->markDeliverySent($deliveryId, $attemptCount, '123', '2026-04-20T00:00:00Z');

        $delivery = $fixture['store']->getDelivery($deliveryId);

        expect($fixture['store']->countQueuedDeliveries($channelId))->toBe(0);
        expect($delivery)->not->toBeNull();
        expect($delivery['status'])->toBe('sent');
        expect($delivery['attempt_count'])->toBe(1);
        expect($delivery['provider_message_id'])->toBe('123');
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});