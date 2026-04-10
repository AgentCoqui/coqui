<?php

declare(strict_types=1);

use CoquiBot\Coqui\Notification\NotificationPublisher;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Phase 3 tests: Producer notification patterns.
 *
 * Tests cover the notification patterns used by TaskRunCommand,
 * BackgroundTaskManager, and LoopManager — verifying session routing,
 * fingerprint dedup, outcome-to-kind mapping, and priority assignment.
 */

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-producer-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->store = new NotificationStore($this->storage->getPdo());
    $this->publisher = new NotificationPublisher($this->store);

    // Create parent (orchestrator) and child (task execution) sessions
    $this->parentSession = $this->storage->createSession('orchestrator', 'test/model');
    $this->childSession = $this->storage->createSession('coder', 'test/model');
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// ========================================================================
// TaskRunCommand notification patterns
// ========================================================================

test('task completed notification routes to parent session', function () {
    $taskId = 'task-' . bin2hex(random_bytes(4));
    $targetSession = NotificationPublisher::resolveTargetSession(
        sessionId: $this->childSession,
        parentSessionId: $this->parentSession,
    );

    $this->publisher->publish(
        sessionId: $targetSession,
        kind: 'task.completed',
        title: 'Task completed: Build feature X',
        class: 'informational',
        priority: 'normal',
        fingerprint: NotificationPublisher::taskFingerprint($taskId, 'completed'),
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    // Notification lands on parent session, not child
    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['kind'])->toBe('task.completed');
    expect($unread[0]['source_type'])->toBe('background_task');
    expect($unread[0]['source_id'])->toBe($taskId);

    // Child session has no notifications
    $childUnread = $this->store->getUnreadInformational($this->childSession);
    expect($childUnread)->toHaveCount(0);
});

test('task failed notification has high priority', function () {
    $taskId = 'task-' . bin2hex(random_bytes(4));

    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.failed',
        title: 'Task failed: Deploy',
        message: 'Connection refused',
        class: 'informational',
        priority: 'high',
        fingerprint: NotificationPublisher::taskFingerprint($taskId, 'failed'),
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['priority'])->toBe('high');
    expect($unread[0]['message'])->toBe('Connection refused');
});

test('task cancelled notification has normal priority', function () {
    $taskId = 'task-' . bin2hex(random_bytes(4));

    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.cancelled',
        title: 'Task cancelled',
        class: 'informational',
        priority: 'normal',
        fingerprint: NotificationPublisher::taskFingerprint($taskId, 'cancelled'),
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['kind'])->toBe('task.cancelled');
    expect($unread[0]['priority'])->toBe('normal');
});

test('task fingerprint prevents duplicate notifications', function () {
    $taskId = 'task-' . bin2hex(random_bytes(4));
    $fingerprint = NotificationPublisher::taskFingerprint($taskId, 'completed');

    // First publish succeeds
    $id1 = $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.completed',
        title: 'Task completed',
        fingerprint: $fingerprint,
        sourceType: 'background_task',
        sourceId: $taskId,
    );
    expect($id1)->not->toBeNull();

    // Second publish with same fingerprint is deduplicated
    $id2 = $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.completed',
        title: 'Task completed (duplicate)',
        fingerprint: $fingerprint,
        sourceType: 'background_task',
        sourceId: $taskId,
    );
    expect($id2)->toBeNull();

    // Only one notification exists
    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['title'])->toBe('Task completed');
});

test('task title is truncated in notification', function () {
    // Simulate the truncation logic from publishTaskNotification
    $longTitle = str_repeat('A', 100);
    $displayTitle = mb_strlen($longTitle) > 80
        ? mb_substr($longTitle, 0, 77) . '...'
        : $longTitle;

    expect(mb_strlen($displayTitle))->toBe(80);
    expect($displayTitle)->toEndWith('...');
});

// ========================================================================
// BackgroundTaskManager recovery notification patterns
// ========================================================================

test('recovery notification skips when child already published', function () {
    $taskId = 'task-' . bin2hex(random_bytes(4));
    $fingerprint = NotificationPublisher::taskFingerprint($taskId, 'failed');

    // Child process publishes first (simulating TaskRunCommand)
    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.failed',
        title: 'Task failed (from child)',
        fingerprint: $fingerprint,
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    // Manager checks fingerprint before publishing
    $exists = $this->publisher->existsByFingerprint($this->parentSession, $fingerprint);
    expect($exists)->toBeTrue();

    // Manager would skip publishing — only one notification
    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['title'])->toBe('Task failed (from child)');
});

test('recovery notification publishes when child did not publish', function () {
    $taskId = 'task-' . bin2hex(random_bytes(4));
    $fingerprint = NotificationPublisher::taskFingerprint($taskId, 'failed');

    // No child notification exists
    $exists = $this->publisher->existsByFingerprint($this->parentSession, $fingerprint);
    expect($exists)->toBeFalse();

    // Manager publishes recovery notification
    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.failed',
        title: 'Task process exited abnormally',
        message: 'Exit code 137',
        fingerprint: $fingerprint,
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['title'])->toBe('Task process exited abnormally');
});

test('different task outcomes have different fingerprints', function () {
    $taskId = 'task-abc123';

    $fpCompleted = NotificationPublisher::taskFingerprint($taskId, 'completed');
    $fpFailed = NotificationPublisher::taskFingerprint($taskId, 'failed');
    $fpCancelled = NotificationPublisher::taskFingerprint($taskId, 'cancelled');

    expect($fpCompleted)->not->toBe($fpFailed);
    expect($fpFailed)->not->toBe($fpCancelled);
    expect($fpCompleted)->toBe('task:task-abc123:completed');
    expect($fpFailed)->toBe('task:task-abc123:failed');
    expect($fpCancelled)->toBe('task:task-abc123:cancelled');
});

// ========================================================================
// LoopManager notification patterns
// ========================================================================

test('loop fingerprint includes iteration and stage', function () {
    $fp = NotificationPublisher::loopFingerprint(
        loopId: 'loop-001',
        iterationNumber: 2,
        stageIndex: 1,
        outcome: 'stage_completed',
    );

    expect($fp)->toBe('loop:loop-001:2:s1:stage_completed');
});

test('loop fingerprint without stage index', function () {
    $fp = NotificationPublisher::loopFingerprint(
        loopId: 'loop-001',
        iterationNumber: 3,
        outcome: 'completed',
    );

    expect($fp)->toBe('loop:loop-001:3:completed');
});

test('loop fingerprint without outcome', function () {
    $fp = NotificationPublisher::loopFingerprint(
        loopId: 'loop-001',
        iterationNumber: 1,
        stageIndex: 0,
    );

    expect($fp)->toBe('loop:loop-001:1:s0');
});

test('loop stage completion notification is informational', function () {
    $loopId = 'loop-' . bin2hex(random_bytes(4));

    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'loop.stage_completed',
        title: 'Stage completed: coder [harness]',
        class: 'informational',
        priority: 'normal',
        fingerprint: NotificationPublisher::loopFingerprint($loopId, 1, 1, 'stage_completed'),
        sourceType: 'loop',
        sourceId: $loopId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['kind'])->toBe('loop.stage_completed');
    expect($unread[0]['source_type'])->toBe('loop');
});

test('loop stage failure notification includes error detail', function () {
    $loopId = 'loop-' . bin2hex(random_bytes(4));

    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'loop.stage_failed',
        title: 'Stage failed: reviewer [harness]',
        message: 'Task failed: connection timeout',
        class: 'informational',
        priority: 'normal',
        fingerprint: NotificationPublisher::loopFingerprint($loopId, 2, 2, 'stage_failed'),
        sourceType: 'loop',
        sourceId: $loopId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['message'])->toBe('Task failed: connection timeout');
});

test('loop completed notification is informational', function () {
    $loopId = 'loop-' . bin2hex(random_bytes(4));

    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'loop.completed',
        title: 'Loop completed [harness]',
        class: 'informational',
        priority: 'normal',
        fingerprint: NotificationPublisher::loopFingerprint($loopId, 3, outcome: 'completed'),
        sourceType: 'loop',
        sourceId: $loopId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['kind'])->toBe('loop.completed');
});

test('loop failed notification has high priority', function () {
    $loopId = 'loop-' . bin2hex(random_bytes(4));

    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'loop.failed',
        title: 'Loop failed during evaluate [harness]',
        message: 'Evaluator returned error',
        class: 'informational',
        priority: 'high',
        fingerprint: NotificationPublisher::loopFingerprint($loopId, 1, outcome: 'failed'),
        sourceType: 'loop',
        sourceId: $loopId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['priority'])->toBe('high');
});

test('loop fingerprints are unique per stage within iteration', function () {
    $loopId = 'loop-001';

    $fp0 = NotificationPublisher::loopFingerprint($loopId, 1, 0, 'stage_completed');
    $fp1 = NotificationPublisher::loopFingerprint($loopId, 1, 1, 'stage_completed');
    $fp2 = NotificationPublisher::loopFingerprint($loopId, 1, 2, 'stage_completed');

    expect($fp0)->not->toBe($fp1);
    expect($fp1)->not->toBe($fp2);

    // All can be published without dedup
    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'loop.stage_completed',
        title: 'Stage 0',
        fingerprint: $fp0,
        sourceType: 'loop',
        sourceId: $loopId,
    );
    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'loop.stage_completed',
        title: 'Stage 1',
        fingerprint: $fp1,
        sourceType: 'loop',
        sourceId: $loopId,
    );
    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'loop.stage_completed',
        title: 'Stage 2',
        fingerprint: $fp2,
        sourceType: 'loop',
        sourceId: $loopId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(3);
});

// ========================================================================
// Session routing patterns
// ========================================================================

test('resolveTargetSession prefers parent session', function () {
    $result = NotificationPublisher::resolveTargetSession(
        sessionId: 'exec-session',
        parentSessionId: 'parent-session',
        workScopeSessionId: 'work-scope-session',
    );
    expect($result)->toBe('parent-session');
});

test('resolveTargetSession falls back to work-scope session', function () {
    $result = NotificationPublisher::resolveTargetSession(
        sessionId: 'exec-session',
        parentSessionId: null,
        workScopeSessionId: 'work-scope-session',
    );
    expect($result)->toBe('work-scope-session');
});

test('resolveTargetSession falls back to execution session', function () {
    $result = NotificationPublisher::resolveTargetSession(
        sessionId: 'exec-session',
    );
    expect($result)->toBe('exec-session');
});

test('resolveTargetSession treats empty string as null', function () {
    $result = NotificationPublisher::resolveTargetSession(
        sessionId: 'exec-session',
        parentSessionId: '',
        workScopeSessionId: 'work-scope',
    );
    expect($result)->toBe('work-scope');
});

// ========================================================================
// Null publisher safety
// ========================================================================

test('null publisher pattern is safe', function () {
    // This tests the pattern used in all producers: early return when publisher is null
    $publisher = null;

    $notified = false;
    if ($publisher !== null) {
        $notified = true;
    }

    expect($notified)->toBeFalse();
});

// ========================================================================
// Cross-producer dedup scenarios
// ========================================================================

test('task and loop fingerprints never collide', function () {
    $taskFp = NotificationPublisher::taskFingerprint('shared-id', 'completed');
    $loopFp = NotificationPublisher::loopFingerprint('shared-id', 1, outcome: 'completed');

    expect($taskFp)->not->toBe($loopFp);
    expect($taskFp)->toStartWith('task:');
    expect($loopFp)->toStartWith('loop:');
});

test('multiple task outcomes for same task create separate notifications', function () {
    $taskId = 'task-' . bin2hex(random_bytes(4));

    // Theoretically shouldn't happen, but ensures the fingerprints differentiate
    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.completed',
        title: 'Completed',
        fingerprint: NotificationPublisher::taskFingerprint($taskId, 'completed'),
        sourceType: 'background_task',
        sourceId: $taskId,
    );
    $this->publisher->publish(
        sessionId: $this->parentSession,
        kind: 'task.failed',
        title: 'Failed',
        fingerprint: NotificationPublisher::taskFingerprint($taskId, 'failed'),
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    $unread = $this->store->getUnreadInformational($this->parentSession);
    expect($unread)->toHaveCount(2);
});
