<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CoquiBot\Coqui\Repl\NotificationPresenter;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-notif-inject-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store = new NotificationStore($this->storage->getPdo());
    $this->presenter = new NotificationPresenter();
});

afterEach(function () {
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

// --- snapshotAndClear integration with formatForPromptInjection ---

test('snapshotAndClear returns unread informational notifications', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Build finished',
        message: 'All tests passed.',
        priority: 'normal',
    );

    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'loop.iteration',
        title: 'Loop iteration 3 complete',
        priority: 'normal',
    );

    $snapshot = $this->store->snapshotAndClear($this->sessionId, 10);

    expect($snapshot)->toHaveCount(2);
    expect($snapshot[0]['title'])->toBe('Build finished');
    expect($snapshot[1]['title'])->toBe('Loop iteration 3 complete');
});

test('snapshotAndClear marks notifications as read', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Build finished',
        priority: 'normal',
    );

    // First snapshot captures the notification
    $first = $this->store->snapshotAndClear($this->sessionId, 10);
    expect($first)->toHaveCount(1);

    // Second snapshot returns empty — already marked read
    $second = $this->store->snapshotAndClear($this->sessionId, 10);
    expect($second)->toHaveCount(0);
});

test('snapshotAndClear respects limit', function () {
    for ($i = 1; $i <= 5; $i++) {
        $this->store->create(
            sessionId: $this->sessionId,
            class: 'informational',
            kind: 'task.completed',
            title: "Task {$i}",
            priority: 'normal',
        );
    }

    $snapshot = $this->store->snapshotAndClear($this->sessionId, 3);
    expect($snapshot)->toHaveCount(3);

    // The remaining 2 are still unread
    $remaining = $this->store->snapshotAndClear($this->sessionId, 10);
    expect($remaining)->toHaveCount(2);
});

test('snapshotAndClear ignores actionable notifications', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Deploy failed',
        priority: 'high',
    );

    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Build OK',
        priority: 'normal',
    );

    $snapshot = $this->store->snapshotAndClear($this->sessionId, 10);

    // Only the informational notification is returned
    expect($snapshot)->toHaveCount(1);
    expect($snapshot[0]['title'])->toBe('Build OK');
});

test('snapshotAndClear ignores other sessions', function () {
    $otherSession = $this->storage->createSession('orchestrator', 'test/model');

    $this->store->create(
        sessionId: $otherSession,
        class: 'informational',
        kind: 'task.completed',
        title: 'Other session task',
        priority: 'normal',
    );

    $snapshot = $this->store->snapshotAndClear($this->sessionId, 10);
    expect($snapshot)->toHaveCount(0);
});

// --- formatForPromptInjection integration ---

test('formatForPromptInjection returns PENDING NOTIFICATIONS header', function () {
    $notifications = [
        [
            'kind' => 'task.completed',
            'title' => 'Build finished',
            'message' => 'All tests passed.',
            'priority' => 'normal',
            'created_at' => '2025-01-15T10:00:00Z',
        ],
    ];

    $content = $this->presenter->formatForPromptInjection($notifications);

    expect($content)->toContain('[PENDING NOTIFICATIONS]');
    expect($content)->toContain('Build finished');
    expect($content)->toContain('All tests passed.');
    expect($content)->toContain('task.completed');
});

test('formatForPromptInjection returns empty string for no notifications', function () {
    expect($this->presenter->formatForPromptInjection([]))->toBe('');
});

test('formatForPromptInjection includes priority for urgent/high', function () {
    $notifications = [
        [
            'kind' => 'task.failed',
            'title' => 'Deploy crashed',
            'message' => '',
            'priority' => 'urgent',
            'created_at' => '2025-01-15T10:00:00Z',
        ],
    ];

    $content = $this->presenter->formatForPromptInjection($notifications);

    expect($content)->toContain('priority: urgent');
});

test('formatForPromptInjection includes review instruction footer', function () {
    $notifications = [
        [
            'kind' => 'task.completed',
            'title' => 'Something',
            'priority' => 'normal',
            'created_at' => '',
        ],
    ];

    $content = $this->presenter->formatForPromptInjection($notifications);

    expect($content)->toContain('background work');
    expect($content)->toContain('incorporate');
});

// --- Ephemeral UserMessage injection ---

test('ephemeral UserMessage is added to conversation from snapshot', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Build finished',
        message: 'Success.',
        priority: 'normal',
    );

    $history = new Conversation();
    $history->add(new UserMessage('Hello'));

    // Simulate what AgentRunner::injectNotificationSnapshot does
    $snapshot = $this->store->snapshotAndClear($this->sessionId, 10);
    $content = $this->presenter->formatForPromptInjection($snapshot);

    expect($content)->not->toBe('');

    $history->add(new UserMessage($content));

    // The conversation should now have 2 user messages
    $userMessages = $history->filter(Role::User);
    expect($userMessages)->toHaveCount(2);

    // The last message should contain the notification content
    $lastMessage = $history->last();
    expect($lastMessage)->toBeInstanceOf(UserMessage::class);
    expect($lastMessage->content())->toContain('[PENDING NOTIFICATIONS]');
    expect($lastMessage->content())->toContain('Build finished');
});

test('no UserMessage added when no notifications exist', function () {
    $history = new Conversation();
    $history->add(new UserMessage('Hello'));

    $snapshot = $this->store->snapshotAndClear($this->sessionId, 10);

    expect($snapshot)->toHaveCount(0);

    $content = $this->presenter->formatForPromptInjection($snapshot);
    expect($content)->toBe('');

    // History should remain unchanged
    expect($history->count())->toBe(1);
});

test('ephemeral UserMessage is not persisted to database', function () {
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Build finished',
        priority: 'normal',
    );

    // Snapshot and format
    $snapshot = $this->store->snapshotAndClear($this->sessionId, 10);
    $content = $this->presenter->formatForPromptInjection($snapshot);

    // Add to in-memory conversation
    $history = new Conversation();
    $history->add(new UserMessage($content));

    // The database should NOT have this message — it was only in-memory
    // Verify by loading conversation from storage (which only has DB messages)
    $dbHistory = $this->storage->loadConversation($this->sessionId);

    // Database history should be empty (no messages added via storage->addMessage)
    expect($dbHistory->count())->toBe(0);
});

// --- Full end-to-end snapshot → format → inject flow ---

test('complete notification injection pipeline', function () {
    // Create several notifications of varying types
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'task.completed',
        title: 'Unit tests passed',
        message: '42/42 tests green.',
        priority: 'normal',
    );

    $this->store->create(
        sessionId: $this->sessionId,
        class: 'informational',
        kind: 'loop.completed',
        title: 'Harness loop finished',
        message: 'All reviewers approved.',
        priority: 'high',
    );

    // Actionable — should NOT appear in snapshot
    $this->store->create(
        sessionId: $this->sessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Deploy needs retry',
        priority: 'urgent',
    );

    // Step 1: Snapshot
    $snapshot = $this->store->snapshotAndClear($this->sessionId, 10);
    expect($snapshot)->toHaveCount(2);

    // Step 2: Format
    $content = $this->presenter->formatForPromptInjection($snapshot);
    expect($content)->toContain('[PENDING NOTIFICATIONS]');
    expect($content)->toContain('Unit tests passed');
    expect($content)->toContain('Harness loop finished');
    expect($content)->not->toContain('Deploy needs retry');

    // Step 3: Inject into conversation
    $history = new Conversation();
    $history->add(new UserMessage('Continue working on the feature.'));
    $history->add(new UserMessage($content));

    // The agent would see 2 user messages
    expect($history->count())->toBe(2);

    // Step 4: Verify atomicity — second snapshot is empty
    $secondSnapshot = $this->store->snapshotAndClear($this->sessionId, 10);
    expect($secondSnapshot)->toHaveCount(0);
});
