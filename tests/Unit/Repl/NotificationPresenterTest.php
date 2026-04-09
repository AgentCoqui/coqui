<?php

declare(strict_types=1);

use CoquiBot\Coqui\Repl\NotificationPresenter;

beforeEach(function () {
    $this->presenter = new NotificationPresenter();
});

// --- formatIdleNotifications ---

test('returns empty array for no notifications', function () {
    expect($this->presenter->formatIdleNotifications([]))->toBe([]);
});

test('formats single notification for idle display', function () {
    $notifications = [
        [
            'kind' => 'task.completed',
            'title' => 'Background task finished',
            'priority' => 'normal',
            'created_at' => (new DateTimeImmutable('-5 minutes'))->format('Y-m-d\TH:i:s\Z'),
        ],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);

    expect($lines)->not->toBeEmpty();
    // First and last lines are empty (spacing)
    expect($lines[0])->toBe('');
    expect($lines[count($lines) - 1])->toBe('');
    // Header line
    expect($lines[1])->toContain('Notifications');
    expect($lines[1])->toContain('[1☀︎]:');
    // Notification line contains the title
    expect($lines[2])->toContain('Background task finished');
});

test('formats multiple notifications with correct count', function () {
    $notifications = [
        ['kind' => 'task.completed', 'title' => 'Task 1', 'priority' => 'normal', 'created_at' => ''],
        ['kind' => 'task.failed', 'title' => 'Task 2', 'priority' => 'high', 'created_at' => ''],
        ['kind' => 'loop.completed', 'title' => 'Loop done', 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);

    expect($lines[1])->toContain('[3☀︎]:');
});

test('truncates long titles', function () {
    $longTitle = str_repeat('A', 120);
    $notifications = [
        ['kind' => 'task.completed', 'title' => $longTitle, 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    // The notification line should contain the truncated title with ...
    $notifLine = $lines[2];
    expect($notifLine)->toContain('...');
});

// --- formatBadge ---

test('badge always returns empty since count is in header', function () {
    expect($this->presenter->formatBadge(0))->toBe('');
    expect($this->presenter->formatBadge(5))->toBe('');
});

test('formats actionable summary with pending and active counts', function () {
    $summary = $this->presenter->formatActionableSummary(2, 1);

    expect($summary)->toContain('Automation');
    expect($summary)->toContain('2 pending');
    expect($summary)->toContain('1 active');
});

test('returns empty actionable summary when no actionable notifications are open', function () {
    expect($this->presenter->formatActionableSummary(0, 0))->toBe('');
});

// --- formatTurnAcknowledgment ---

test('returns empty for no notifications', function () {
    expect($this->presenter->formatTurnAcknowledgment([]))->toBe([]);
});

test('formats singular acknowledgment', function () {
    $lines = $this->presenter->formatTurnAcknowledgment([
        ['kind' => 'task.completed', 'title' => 'Done'],
    ]);

    expect($lines)->toHaveCount(1);
    expect($lines[0])->toContain('1 notification');
});

test('formats plural acknowledgment', function () {
    $lines = $this->presenter->formatTurnAcknowledgment([
        ['kind' => 'task.completed', 'title' => 'A'],
        ['kind' => 'task.failed', 'title' => 'B'],
    ]);

    expect($lines[0])->toContain('2 notifications');
});

// --- formatForPromptInjection ---

test('returns empty string for no notifications', function () {
    expect($this->presenter->formatForPromptInjection([]))->toBe('');
});

test('formats prompt injection with header and items', function () {
    $notifications = [
        [
            'kind' => 'task.completed',
            'title' => 'Background task done',
            'message' => 'All steps passed.',
            'priority' => 'normal',
            'created_at' => '2025-01-15T10:00:00Z',
        ],
    ];

    $output = $this->presenter->formatForPromptInjection($notifications);

    expect($output)->toContain('[PENDING NOTIFICATIONS]');
    expect($output)->toContain('1. [completed] Background task done');
    expect($output)->toContain('kind: task.completed');
    expect($output)->toContain('All steps passed.');
    expect($output)->toContain('Time: 2025-01-15T10:00:00Z');
    expect($output)->toContain('background work');
});

test('includes metadata in prompt injection when available', function () {
    $notifications = [
        [
            'kind' => 'task.failed',
            'title' => 'Background task failed',
            'message' => 'Exited with code 1.',
            'priority' => 'high',
            'created_at' => '2025-01-15T10:00:00Z',
            'metadata' => json_encode(['task_id' => 'task-123', 'exit_code' => 1], JSON_THROW_ON_ERROR),
        ],
    ];

    $output = $this->presenter->formatForPromptInjection($notifications);

    expect($output)->toContain('Metadata: {"task_id":"task-123","exit_code":1}');
});

test('includes priority for high/urgent notifications', function () {
    $notifications = [
        ['kind' => 'task.failed', 'title' => 'Critical failure', 'message' => '', 'priority' => 'urgent', 'created_at' => ''],
    ];

    $output = $this->presenter->formatForPromptInjection($notifications);
    expect($output)->toContain('priority: urgent');
});

test('does not include priority for normal notifications', function () {
    $notifications = [
        ['kind' => 'task.completed', 'title' => 'Normal task', 'message' => '', 'priority' => 'normal', 'created_at' => ''],
    ];

    $output = $this->presenter->formatForPromptInjection($notifications);
    expect($output)->not->toContain('priority:');
});

test('truncates long messages in prompt injection', function () {
    $longMessage = str_repeat('X', 200);
    $notifications = [
        ['kind' => 'task.completed', 'title' => 'Task', 'message' => $longMessage, 'priority' => 'normal', 'created_at' => ''],
    ];

    $output = $this->presenter->formatForPromptInjection($notifications);
    expect($output)->toContain('...');
});

// --- Priority colorization ---

test('urgent priority gets red indicator', function () {
    $notifications = [
        ['kind' => 'task.failed', 'title' => 'Urgent', 'priority' => 'urgent', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    $notifLine = $lines[2];
    expect($notifLine)->toContain('<fg=red>');
});

test('high priority gets yellow indicator', function () {
    $notifications = [
        ['kind' => 'task.failed', 'title' => 'High', 'priority' => 'high', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    $notifLine = $lines[2];
    expect($notifLine)->toContain('<fg=yellow>');
});

// --- Kind colorization ---

test('completed task kinds get green color on icon and title', function () {
    $notifications = [
        ['kind' => 'task.completed', 'title' => 'Done', 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    expect($lines[2])->toContain('<fg=green>✔</>');
    expect($lines[2])->toContain('<fg=green>Done</>');
});

test('failed task kinds get red color', function () {
    $notifications = [
        ['kind' => 'task.failed', 'title' => 'Oops', 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    expect($lines[2])->toContain('<fg=red>');
    expect($lines[2])->toContain('✘');
});

test('completed loop kinds get cyan color', function () {
    $notifications = [
        ['kind' => 'loop.completed', 'title' => 'Done', 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    expect($lines[2])->toContain('<fg=cyan>');
    expect($lines[2])->toContain('✔');
});

test('bracketed content in title renders as gray', function () {
    $notifications = [
        ['kind' => 'loop.stage_completed', 'title' => 'Stage completed: explorer [research]', 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    expect($lines[2])->toContain('<fg=gray>[research]</>');
});

test('cancelled kinds get yellow color', function () {
    $notifications = [
        ['kind' => 'task.cancelled', 'title' => 'Stopped', 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    expect($lines[2])->toContain('<fg=yellow>');
    expect($lines[2])->toContain('⏹');
});

// --- Relative time ---

test('formats relative time for recent notifications', function () {
    $recent = (new DateTimeImmutable('-30 seconds'))->format('Y-m-d\TH:i:s\Z');
    $notifications = [
        ['kind' => 'task.completed', 'title' => 'Recent', 'priority' => 'normal', 'created_at' => $recent],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);
    expect($lines[2])->toContain('s ago');
});

test('handles invalid timestamp gracefully', function () {
    $notifications = [
        ['kind' => 'task.completed', 'title' => 'Bad time', 'priority' => 'normal', 'created_at' => 'not-a-date'],
    ];

    // Should not throw — graceful fallback
    $lines = $this->presenter->formatIdleNotifications($notifications);
    expect($lines)->not->toBeEmpty();
});

test('unknown kinds fall back to gray sun icon', function () {
    $notifications = [
        ['kind' => 'custom.event', 'title' => 'Unknown event', 'priority' => 'normal', 'created_at' => ''],
    ];

    $lines = $this->presenter->formatIdleNotifications($notifications);

    expect($lines[2])->toContain('<fg=gray>☀︎</>');
    expect($lines[2])->toContain('<fg=gray>Unknown event</>');
});
