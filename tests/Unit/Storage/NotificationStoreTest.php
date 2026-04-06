<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-notification-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store = new NotificationStore($this->storage->getPdo());
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

// --- Create ---

test('creates an informational notification', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Background task finished',
        message: 'Task xyz completed successfully.',
        sourceType: 'background_task',
        sourceId: 'task-123',
        priority: 'normal',
    );

    expect($id)->toBeString()->not->toBeEmpty();

    $notification = $this->store->get($id);
    expect($notification)->not->toBeNull();
    expect($notification['session_id'])->toBe($this->sessionId);
    expect($notification['class'])->toBe('informational');
    expect($notification['kind'])->toBe('task.completed');
    expect($notification['title'])->toBe('Background task finished');
    expect($notification['message'])->toBe('Task xyz completed successfully.');
    expect($notification['source_type'])->toBe('background_task');
    expect($notification['source_id'])->toBe('task-123');
    expect($notification['priority'])->toBe('normal');
    expect($notification['read_at'])->toBeNull();
    // Informational notifications have no claim_status
    expect($notification['claim_status'])->toBeNull();
});

test('creates an actionable notification', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Task failed — needs retry',
        priority: 'high',
    );

    $notification = $this->store->get($id);
    expect($notification['class'])->toBe('actionable');
    expect($notification['priority'])->toBe('high');
    expect($notification['claim_status'])->toBe('pending');
});

test('creates notification with metadata', function () {
    $metadata = ['task_id' => 'abc', 'exit_code' => 1];

    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.failed',
        title: 'Task failed',
        metadata: $metadata,
    );

    $notification = $this->store->get($id);
    $decoded = json_decode($notification['metadata'], true);
    expect($decoded)->toBe($metadata);
});

test('creates notification with expiry', function () {
    $expires = (new DateTimeImmutable('+1 hour'))->format('Y-m-d\TH:i:s\Z');

    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Expiring notification',
        expiresAt: $expires,
    );

    $notification = $this->store->get($id);
    expect($notification['expires_at'])->toBe($expires);
});

// --- Fingerprint Deduplication ---

test('deduplicates by fingerprint within session', function () {
    $id1 = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Task done',
        fingerprint: 'task:abc:completed',
    );

    $id2 = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Task done duplicate',
        fingerprint: 'task:abc:completed',
    );

    // Second insert should be silently ignored
    expect($id1)->toBeString()->not->toBeEmpty();
    expect($id2)->toBeNull();
});

test('allows same fingerprint after original is read', function () {
    $id1 = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Task done',
        fingerprint: 'task:abc:completed',
    );

    $this->store->markRead([$id1]);

    $id2 = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Task done again',
        fingerprint: 'task:abc:completed',
    );

    expect($id2)->toBeString()->not->toBeEmpty();
});

test('allows same fingerprint in different sessions', function () {
    $session2 = $this->storage->createSession('orchestrator', 'test/model');

    $id1 = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Task done in session 1',
        fingerprint: 'task:abc:completed',
    );

    $id2 = $this->store->create(
        sessionId: $session2,
        class: 'informational',
        kind: 'task.completed',
        title: 'Task done in session 2',
        fingerprint: 'task:abc:completed',
    );

    expect($id1)->toBeString()->not->toBeEmpty();
    expect($id2)->toBeString()->not->toBeEmpty();
});

// --- Read / Count ---

test('gets unread informational notifications', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Notification 1',
        priority: 'high',
    );
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Notification 2',
        priority: 'normal',
    );

    $unread = $this->store->getUnreadInformational($this->sessionId);
    expect($unread)->toHaveCount(2);
    // High priority should come first
    expect($unread[0]['title'])->toBe('Notification 1');
    expect($unread[1]['title'])->toBe('Notification 2');
});

test('counts unread notifications', function () {
    expect($this->store->countUnread($this->sessionId))->toBe(0);

    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Notification',
    );

    expect($this->store->countUnread($this->sessionId))->toBe(1);

    $this->store->markRead([$id]);

    expect($this->store->countUnread($this->sessionId))->toBe(0);
});

test('gets recent notifications including read', function () {
    $id1 = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Old notification',
    );
    $this->store->markRead([$id1]);

    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'New notification',
    );

    $recent = $this->store->getRecent($this->sessionId, limit: 10);
    expect($recent)->toHaveCount(2);
});

test('respects limit on getUnreadInformational', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->store->create(
            sessionId: $this->sessionId,
            class: 'informational',
            kind: 'task.completed',
            title: "Notification {$i}",
        );
    }

    $limited = $this->store->getUnreadInformational($this->sessionId, limit: 3);
    expect($limited)->toHaveCount(3);
});

// --- Mark Read ---

test('marks single notification as read', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'To be read',
    );

    $this->store->markRead([$id]);

    $notification = $this->store->get($id);
    expect($notification['read_at'])->not->toBeNull();
});

test('marks all notifications as read', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Notification 1',
    );
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Notification 2',
    );

    $this->store->markAllRead($this->sessionId);

    expect($this->store->countUnread($this->sessionId))->toBe(0);
});

// --- Snapshot and Clear ---

test('snapshotAndClear atomically reads and marks informational notifications', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Snapshot me 1',
    );
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Snapshot me 2',
    );

    $snapshot = $this->store->snapshotAndClear($this->sessionId);
    expect($snapshot)->toHaveCount(2);

    // All should be marked read now
    expect($this->store->countUnread($this->sessionId))->toBe(0);

    // Second snapshot should return empty
    $second = $this->store->snapshotAndClear($this->sessionId);
    expect($second)->toHaveCount(0);
});

test('snapshotAndClear respects limit', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->store->create(
            sessionId: $this->sessionId,
            class: 'informational',
            kind: 'task.completed',
            title: "Notification {$i}",
        );
    }

    $snapshot = $this->store->snapshotAndClear($this->sessionId, limit: 3);
    expect($snapshot)->toHaveCount(3);

    // 2 should still be unread
    expect($this->store->countUnread($this->sessionId))->toBe(2);
});

// --- Claim Semantics ---

test('claims an actionable notification', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Failed task needs retry',
    );

    $claimed = $this->store->claim($id, 'automation_runner');
    expect($claimed)->toBeTrue();

    $notification = $this->store->get($id);
    expect($notification['claim_status'])->toBe('claimed');
    expect($notification['claimed_by'])->toBe('automation_runner');
    expect($notification['claimed_at'])->not->toBeNull();
});

test('cannot claim an already claimed notification', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Failed task',
    );

    $this->store->claim($id, 'runner_1');
    $second = $this->store->claim($id, 'runner_2');
    expect($second)->toBeFalse();
});

test('completes a claimed notification', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Retry task',
    );

    $this->store->claim($id, 'runner');
    $this->store->completeClaim($id);

    $notification = $this->store->get($id);
    expect($notification['claim_status'])->toBe('completed');
    expect($notification['read_at'])->not->toBeNull();
});

test('fails a claimed notification', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Retry task',
    );

    $this->store->claim($id, 'runner');
    $this->store->failClaim($id);

    $notification = $this->store->get($id);
    expect($notification['claim_status'])->toBe('failed');
});

test('gets unclaimed actionable notifications', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Actionable 1',
    );
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Informational',
    );
    $id3 = $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Actionable 2 (claimed)',
    );
    $this->store->claim($id3, 'runner');

    $unclaimed = $this->store->getUnclaimedActionable($this->sessionId);
    expect($unclaimed)->toHaveCount(1);
    expect($unclaimed[0]['title'])->toBe('Actionable 1');
});

test('filters unclaimed actionable by kind', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Failed task',
    );
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'loop.failed',
        title: 'Failed loop',
    );

    $taskOnly = $this->store->getUnclaimedActionable($this->sessionId, kinds: ['task.failed']);
    expect($taskOnly)->toHaveCount(1);
    expect($taskOnly[0]['title'])->toBe('Failed task');
});

// --- Delete ---

test('deletes a notification', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Delete me',
    );

    $this->store->delete($id);

    expect($this->store->get($id))->toBeNull();
});

// --- Fingerprint Existence Check ---

test('checks fingerprint existence', function () {
    expect($this->store->existsByFingerprint($this->sessionId, 'fp:1'))->toBeFalse();

    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Has fingerprint',
        fingerprint: 'fp:1',
    );

    expect($this->store->existsByFingerprint($this->sessionId, 'fp:1'))->toBeTrue();
});

// --- Prune ---

test('prune removes old informational notifications', function () {
    // Create a notification then manually set created_at to 48h ago
    $id = $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Old notification',
    );
    $this->store->markRead([$id]);

    $pdo = $this->storage->getPdo();
    $past = (new DateTimeImmutable('-48 hours'))->format('Y-m-d\TH:i:s\Z');
    $pdo->prepare('UPDATE notifications SET created_at = ? WHERE id = ?')->execute([$past, $id]);

    $pruned = $this->store->prune(informationalRetentionHours: 24, actionableRetentionHours: 72);
    expect($pruned)->toBeGreaterThanOrEqual(1);
    expect($this->store->get($id))->toBeNull();
});

// --- Session Isolation ---

test('notifications are isolated per session', function () {
    $session2 = $this->storage->createSession('orchestrator', 'test/model');

    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Session 1 notification',
    );
    $this->store->create(
        sessionId: $session2,
        class: 'informational',
        kind: 'task.completed',
        title: 'Session 2 notification',
    );

    $s1 = $this->store->getUnreadInformational($this->sessionId);
    $s2 = $this->store->getUnreadInformational($session2);

    expect($s1)->toHaveCount(1);
    expect($s1[0]['title'])->toBe('Session 1 notification');
    expect($s2)->toHaveCount(1);
    expect($s2[0]['title'])->toBe('Session 2 notification');
});
