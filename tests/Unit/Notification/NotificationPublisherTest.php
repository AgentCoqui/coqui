<?php

declare(strict_types=1);

use CoquiBot\Coqui\Notification\NotificationPublisher;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-notif-pub-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store = new NotificationStore($this->storage->getPdo());
    $this->publisher = new NotificationPublisher($this->store);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// --- info() ---

test('publishes informational notification', function () {
    $this->publisher->info(
        sessionId: $this->sessionId,
        kind: 'task.completed',
        title: 'Task finished',
        message: 'Details here',
    );

    $unread = $this->store->getUnreadInformational($this->sessionId);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['class'])->toBe('informational');
    expect($unread[0]['kind'])->toBe('task.completed');
    expect($unread[0]['title'])->toBe('Task finished');
    expect($unread[0]['message'])->toBe('Details here');
});

// --- actionable() ---

test('publishes actionable notification with high priority', function () {
    $this->publisher->actionable(
        sessionId: $this->sessionId,
        kind: 'task.failed',
        title: 'Task needs retry',
    );

    $unclaimed = $this->store->getUnclaimedActionable($this->sessionId);
    expect($unclaimed)->toHaveCount(1);
    expect($unclaimed[0]['class'])->toBe('actionable');
    expect($unclaimed[0]['priority'])->toBe('high');
});

// --- Disabled mode ---

test('does nothing when disabled', function () {
    $publisher = new NotificationPublisher($this->store, enabled: false);

    $publisher->info(
        sessionId: $this->sessionId,
        kind: 'task.completed',
        title: 'Should not appear',
    );

    expect($this->store->countUnread($this->sessionId))->toBe(0);
});

// --- Session resolution ---

test('resolveTargetSession prefers parentSessionId', function () {
    $resolved = NotificationPublisher::resolveTargetSession(
        sessionId: 'execution-session',
        parentSessionId: 'parent-session',
        workScopeSessionId: 'work-scope-session',
    );

    expect($resolved)->toBe('parent-session');
});

test('resolveTargetSession falls back to workScopeSessionId', function () {
    $resolved = NotificationPublisher::resolveTargetSession(
        sessionId: 'execution-session',
        parentSessionId: null,
        workScopeSessionId: 'work-scope-session',
    );

    expect($resolved)->toBe('work-scope-session');
});

test('resolveTargetSession falls back to sessionId', function () {
    $resolved = NotificationPublisher::resolveTargetSession(
        sessionId: 'execution-session',
        parentSessionId: null,
        workScopeSessionId: null,
    );

    expect($resolved)->toBe('execution-session');
});

// --- Fingerprint helpers ---

test('taskFingerprint builds deterministic string', function () {
    $fp = NotificationPublisher::taskFingerprint('task-abc', 'completed');
    expect($fp)->toBe('task:task-abc:completed');
});

test('loopFingerprint builds deterministic string', function () {
    $fp = NotificationPublisher::loopFingerprint('loop-1', 2, 3, 'approved');
    expect($fp)->toBe('loop:loop-1:2:s3:approved');
});

// --- Fingerprint deduplication via publisher ---

test('publish with fingerprint deduplicates', function () {
    $this->publisher->info(
        sessionId: $this->sessionId,
        kind: 'task.completed',
        title: 'First',
        fingerprint: 'fp:test',
    );

    $this->publisher->info(
        sessionId: $this->sessionId,
        kind: 'task.completed',
        title: 'Duplicate',
        fingerprint: 'fp:test',
    );

    $unread = $this->store->getUnreadInformational($this->sessionId);
    expect($unread)->toHaveCount(1);
    expect($unread[0]['title'])->toBe('First');
});

// --- existsByFingerprint ---

test('existsByFingerprint delegates to store', function () {
    expect($this->publisher->existsByFingerprint($this->sessionId, 'fp:check'))->toBeFalse();

    $this->publisher->info(
        sessionId: $this->sessionId,
        kind: 'task.completed',
        title: 'Has fingerprint',
        fingerprint: 'fp:check',
    );

    expect($this->publisher->existsByFingerprint($this->sessionId, 'fp:check'))->toBeTrue();
});

// --- Source metadata ---

test('publishes notification with source metadata', function () {
    $this->publisher->info(
        sessionId: $this->sessionId,
        kind: 'task.completed',
        title: 'With source',
        sourceType: 'background_task',
        sourceId: 'task-xyz',
    );

    $unread = $this->store->getUnreadInformational($this->sessionId);
    expect($unread[0]['source_type'])->toBe('background_task');
    expect($unread[0]['source_id'])->toBe('task-xyz');
});
