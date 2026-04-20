<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ChannelExecutionManager;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * @return array{dbPath: string, storage: SessionStorage, channelStore: ChannelStore}
 */
function makeChannelExecutionFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-channel-execution-test-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'channelStore' => new ChannelStore($storage->getPdo()),
    ];
}

test('channel execution manager turns linked inbound events into background tasks', function (): void {
    $fixture = makeChannelExecutionFixture();
    $config = OpenClawConfig::fromArray([
        'channels' => [
            'defaults' => [
                'unknownUserPolicy' => 'deny',
                'defaultProfile' => 'caelum',
            ],
        ],
    ]);

    try {
        $channelId = $fixture['channelStore']->upsertConfiguredInstance([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'enabled' => true,
            'display_name' => 'Signal Primary',
            'default_profile' => 'caelum',
            'settings' => ['account' => '+15551234567'],
            'allowed_scopes' => [],
            'security' => ['linkRequired' => true],
            'source' => 'config',
        ]);
        $fixture['channelStore']->createLink($channelId, '+15557654321', 'caelum');
        $conversationId = $fixture['channelStore']->upsertConversation(
            channelInstanceId: $channelId,
            remoteConversationKey: 'signal-dm:+15557654321',
            metadata: ['driver' => 'signal'],
        );
        $eventId = $fixture['channelStore']->createInboundEvent(
            channelInstanceId: $channelId,
            conversationId: $conversationId,
            providerEventId: '1713571200000',
            dedupeKey: 'signal:+15557654321:1713571200000:1',
            eventType: 'data_message',
            remoteUserKey: '+15557654321',
            payload: ['source' => '+15557654321'],
            normalized: [
                'message' => 'Hello from Signal',
                'source_name' => 'Test User',
                'group_id' => null,
            ],
            receivedAt: '2026-04-20T00:00:00Z',
        );

        $manager = new ChannelExecutionManager($config, $fixture['channelStore'], $fixture['storage']);
        $manager->tick();

        $event = $fixture['channelStore']->getInboundEvent((string) $eventId);
        $conversation = $fixture['channelStore']->getConversation($conversationId);
        $task = $event !== null && is_string($event['task_id'] ?? null)
            ? $fixture['storage']->getTask((string) $event['task_id'])
            : null;

        expect($event)->not->toBeNull();
        expect($event['status'])->toBe('task_queued');
        expect($event['session_id'])->toBeString();
        expect($event['task_id'])->toBeString();
        expect($conversation)->not->toBeNull();
        expect($conversation['session_id'])->toBe($event['session_id']);
        expect($task)->not->toBeNull();
        expect($task['status'])->toBe('pending');
        expect($task['prompt'])->toContain('Hello from Signal');
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});

test('channel execution manager queues deliveries for completed channel tasks and marks sent deliveries processed', function (): void {
    $fixture = makeChannelExecutionFixture();
    $config = OpenClawConfig::fromArray([
        'channels' => [
            'defaults' => [
                'unknownUserPolicy' => 'allow',
                'defaultProfile' => 'caelum',
            ],
        ],
    ]);

    try {
        $channelId = $fixture['channelStore']->upsertConfiguredInstance([
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
        $conversationId = $fixture['channelStore']->upsertConversation(
            channelInstanceId: $channelId,
            remoteConversationKey: 'signal-dm:+15557654321',
            metadata: ['driver' => 'signal'],
        );
        $eventId = $fixture['channelStore']->createInboundEvent(
            channelInstanceId: $channelId,
            conversationId: $conversationId,
            providerEventId: '1713571200001',
            dedupeKey: 'signal:+15557654321:1713571200001:1',
            eventType: 'data_message',
            remoteUserKey: '+15557654321',
            payload: ['source' => '+15557654321'],
            normalized: ['message' => 'Ping'],
            receivedAt: '2026-04-20T00:01:00Z',
        );

        $manager = new ChannelExecutionManager($config, $fixture['channelStore'], $fixture['storage']);
        $manager->tick();

        $event = $fixture['channelStore']->getInboundEvent((string) $eventId);
        expect($event)->not->toBeNull();

        $fixture['storage']->updateTaskStatus((string) $event['task_id'], 'completed', ['result' => 'Pong']);
        $manager->tick();

        $delivery = $fixture['channelStore']->getDeliveryByReplyToEventId((string) $eventId);
        $event = $fixture['channelStore']->getInboundEvent((string) $eventId);

        expect($delivery)->not->toBeNull();
        expect($delivery['status'])->toBe('queued');
        expect($delivery['payload']['message'])->toBe('Pong');
        expect($event['status'])->toBe('delivery_queued');

        $attemptCount = $fixture['channelStore']->recordDeliveryAttempt((string) $delivery['id'], 'sent');
        $fixture['channelStore']->markDeliverySent((string) $delivery['id'], $attemptCount, '123', '2026-04-20T00:02:00Z');
        $manager->tick();

        $event = $fixture['channelStore']->getInboundEvent((string) $eventId);

        expect($event['status'])->toBe('processed');
        expect($event['processed_at'])->toBe('2026-04-20T00:02:00Z');
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});

test('channel execution manager rejects unknown users when channel policy requires links', function (): void {
    $fixture = makeChannelExecutionFixture();
    $config = OpenClawConfig::fromArray([
        'channels' => [
            'defaults' => [
                'unknownUserPolicy' => 'deny',
            ],
        ],
    ]);

    try {
        $channelId = $fixture['channelStore']->upsertConfiguredInstance([
            'name' => 'signal-primary',
            'driver' => 'signal',
            'enabled' => true,
            'display_name' => 'Signal Primary',
            'default_profile' => null,
            'settings' => ['account' => '+15551234567'],
            'allowed_scopes' => [],
            'security' => ['linkRequired' => true],
            'source' => 'config',
        ]);
        $conversationId = $fixture['channelStore']->upsertConversation(
            channelInstanceId: $channelId,
            remoteConversationKey: 'signal-dm:+15550001111',
            metadata: ['driver' => 'signal'],
        );
        $eventId = $fixture['channelStore']->createInboundEvent(
            channelInstanceId: $channelId,
            conversationId: $conversationId,
            providerEventId: '1713571200002',
            dedupeKey: 'signal:+15550001111:1713571200002:1',
            eventType: 'data_message',
            remoteUserKey: '+15550001111',
            payload: ['source' => '+15550001111'],
            normalized: ['message' => 'Hello?'],
            receivedAt: '2026-04-20T00:03:00Z',
        );

        $manager = new ChannelExecutionManager($config, $fixture['channelStore'], $fixture['storage']);
        $manager->tick();

        $event = $fixture['channelStore']->getInboundEvent((string) $eventId);

        expect($event)->not->toBeNull();
        expect($event['status'])->toBe('rejected');
        expect($event['error'])->toBe('Inbound sender is not linked to a Coqui profile.');
    } finally {
        releaseTestObjectProperties((object) $fixture);
        cleanupSqliteTestDb($fixture['dbPath']);
    }
});