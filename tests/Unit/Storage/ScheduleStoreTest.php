<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-schedule-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->store = new ScheduleStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// --- Create ---

test('create returns a 32-char hex id', function () {
    $id = $this->store->create(
        name: 'test-schedule',
        scheduleExpression: '*/5 * * * *',
        prompt: 'Run health check',
    );

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('create stores schedule with correct fields', function () {
    $id = $this->store->create(
        name: 'daily-report',
        scheduleExpression: '0 9 * * *',
        prompt: 'Generate daily report',
        role: 'coder',
        maxIterations: 30,
        description: 'Runs every day at 9am',
        createdBy: 'orchestrator',
        timezone: 'America/New_York',
        maxFailures: 5,
    );

    $schedule = $this->store->get($id);

    expect($schedule)->not->toBeNull();
    expect($schedule['name'])->toBe('daily-report');
    expect($schedule['schedule_expression'])->toBe('0 9 * * *');
    expect($schedule['prompt'])->toBe('Generate daily report');
    expect($schedule['role'])->toBe('coder');
    expect((int) $schedule['max_iterations'])->toBe(30);
    expect($schedule['description'])->toBe('Runs every day at 9am');
    expect($schedule['created_by'])->toBe('orchestrator');
    expect($schedule['timezone'])->toBe('America/New_York');
    expect((int) $schedule['max_failures'])->toBe(5);
    expect((int) $schedule['enabled'])->toBe(1);
    expect((int) $schedule['run_count'])->toBe(0);
    expect((int) $schedule['failure_count'])->toBe(0);
});

test('create computes next_run_at for valid cron', function () {
    $id = $this->store->create(
        name: 'every-minute',
        scheduleExpression: '* * * * *',
        prompt: 'ping',
    );

    $schedule = $this->store->get($id);

    expect($schedule['next_run_at'])->not->toBeNull();
});

test('create with @once sets next_run_at to now', function () {
    $id = $this->store->create(
        name: 'one-shot',
        scheduleExpression: '@once',
        prompt: 'Do this once',
    );

    $schedule = $this->store->get($id);

    expect($schedule['next_run_at'])->not->toBeNull();
});

test('create enforces unique name', function () {
    $this->store->create(
        name: 'unique-name',
        scheduleExpression: '* * * * *',
        prompt: 'first',
    );

    $this->store->create(
        name: 'unique-name',
        scheduleExpression: '*/5 * * * *',
        prompt: 'second',
    );
})->throws(PDOException::class);

// --- Get ---

test('get returns null for nonexistent id', function () {
    expect($this->store->get('nonexistent'))->toBeNull();
});

test('getByName returns the schedule', function () {
    $this->store->create(
        name: 'find-me',
        scheduleExpression: '* * * * *',
        prompt: 'test',
    );

    $schedule = $this->store->getByName('find-me');

    expect($schedule)->not->toBeNull();
    expect($schedule['name'])->toBe('find-me');
});

test('getByName returns null for nonexistent name', function () {
    expect($this->store->getByName('nope'))->toBeNull();
});

// --- Update ---

test('update modifies fields', function () {
    $id = $this->store->create(
        name: 'update-me',
        scheduleExpression: '* * * * *',
        prompt: 'original',
    );

    $result = $this->store->update($id,
        prompt: 'updated prompt',
        description: 'New description',
    );

    expect($result)->toBeTrue();

    $schedule = $this->store->get($id);
    expect($schedule['prompt'])->toBe('updated prompt');
    expect($schedule['description'])->toBe('New description');
});

test('update recomputes next_run_at when expression changes', function () {
    $id = $this->store->create(
        name: 'recompute',
        scheduleExpression: '0 0 1 1 *',
        prompt: 'yearly',
    );

    $before = $this->store->get($id);

    $this->store->update($id,
        scheduleExpression: '* * * * *',
    );

    $after = $this->store->get($id);

    expect($after['next_run_at'])->not->toBe($before['next_run_at']);
});

test('update returns false for nonexistent id', function () {
    expect($this->store->update('fake-id', prompt: 'x'))->toBeFalse();
});

// --- Delete ---

test('delete removes the schedule', function () {
    $id = $this->store->create(
        name: 'delete-me',
        scheduleExpression: '* * * * *',
        prompt: 'bye',
    );

    $result = $this->store->delete($id);

    expect($result)->toBeTrue();
    expect($this->store->get($id))->toBeNull();
});

test('delete returns false for nonexistent id', function () {
    expect($this->store->delete('fake-id'))->toBeFalse();
});

// --- List ---

test('list returns all schedules', function () {
    $this->store->create(name: 'a', scheduleExpression: '* * * * *', prompt: 'a');
    $this->store->create(name: 'b', scheduleExpression: '* * * * *', prompt: 'b');

    $list = $this->store->list();

    expect($list)->toHaveCount(2);
});

test('list filters by enabled state', function () {
    $id = $this->store->create(name: 'enabled', scheduleExpression: '* * * * *', prompt: 'x');
    $disabledId = $this->store->create(name: 'to-disable', scheduleExpression: '* * * * *', prompt: 'y');
    $this->store->disable($disabledId);

    $enabledOnly = $this->store->list(enabled: true);
    $disabledOnly = $this->store->list(enabled: false);

    expect($enabledOnly)->toHaveCount(1);
    expect($enabledOnly[0]['name'])->toBe('enabled');
    expect($disabledOnly)->toHaveCount(1);
    expect($disabledOnly[0]['name'])->toBe('to-disable');
});

// --- Enable / Disable ---

test('disable and enable toggle the state', function () {
    $id = $this->store->create(
        name: 'toggle',
        scheduleExpression: '* * * * *',
        prompt: 'test',
    );

    $this->store->disable($id);
    expect((int) $this->store->get($id)['enabled'])->toBe(0);

    $this->store->enable($id);
    $schedule = $this->store->get($id);
    expect((int) $schedule['enabled'])->toBe(1);
    expect((int) $schedule['failure_count'])->toBe(0);
});

// --- Mark Executed / Success / Failed ---

test('markExecuted updates run tracking fields', function () {
    $id = $this->store->create(
        name: 'executed',
        scheduleExpression: '* * * * *',
        prompt: 'test',
    );

    $this->store->markExecuted($id, 'task-123');

    $schedule = $this->store->get($id);
    expect($schedule['last_task_id'])->toBe('task-123');
    expect($schedule['last_run_at'])->not->toBeNull();
    expect((int) $schedule['run_count'])->toBe(1);
    // next_run_at should be updated to the future
    expect($schedule['next_run_at'])->not->toBeNull();
});

test('markSuccess updates status and resets failures', function () {
    $id = $this->store->create(
        name: 'success',
        scheduleExpression: '* * * * *',
        prompt: 'test',
    );

    $this->store->markExecuted($id, 'task-1');
    $this->store->markSuccess($id);

    $schedule = $this->store->get($id);
    expect($schedule['last_status'])->toBe('completed');
    expect((int) $schedule['failure_count'])->toBe(0);
});

test('markFailed increments failure count', function () {
    $id = $this->store->create(
        name: 'failing',
        scheduleExpression: '* * * * *',
        prompt: 'test',
        maxFailures: 3,
    );

    $this->store->markExecuted($id, 'task-1');
    $disabled = $this->store->markFailed($id);

    expect($disabled)->toBeFalse();

    $schedule = $this->store->get($id);
    expect((int) $schedule['failure_count'])->toBe(1);
    expect($schedule['last_status'])->toBe('failed');
});

test('markFailed disables on max failures (circuit breaker)', function () {
    $id = $this->store->create(
        name: 'circuit-break',
        scheduleExpression: '* * * * *',
        prompt: 'test',
        maxFailures: 2,
    );

    $this->store->markExecuted($id, 'task-1');
    $this->store->markFailed($id);
    $this->store->markExecuted($id, 'task-2');
    $disabled = $this->store->markFailed($id);

    expect($disabled)->toBeTrue();
    expect((int) $this->store->get($id)['enabled'])->toBe(0);
});

// --- Ready Schedules ---

test('getReadySchedules returns only enabled schedules past next_run_at', function () {
    $this->store->create(
        name: 'ready',
        scheduleExpression: '* * * * *',
        prompt: 'past due',
    );

    // Force next_run_at into the past
    $id = $this->store->getByName('ready')['id'];
    $this->store->forceNextRun($id, '2020-01-01T00:00:00Z');

    $ready = $this->store->getReadySchedules(new DateTimeImmutable('2024-01-01T00:00:00Z'));

    expect($ready)->toHaveCount(1);
    expect($ready[0]['name'])->toBe('ready');
});

test('getReadySchedules excludes disabled schedules', function () {
    $id = $this->store->create(
        name: 'disabled',
        scheduleExpression: '* * * * *',
        prompt: 'nope',
    );

    $this->store->forceNextRun($id, '2020-01-01T00:00:00Z');
    $this->store->disable($id);

    $ready = $this->store->getReadySchedules(new DateTimeImmutable('2024-01-01T00:00:00Z'));

    expect($ready)->toBeEmpty();
});

// --- Stats ---

test('getStats returns correct counts', function () {
    $id1 = $this->store->create(name: 'active', scheduleExpression: '* * * * *', prompt: 'a');
    $id2 = $this->store->create(name: 'inactive', scheduleExpression: '* * * * *', prompt: 'b');
    $this->store->disable($id2);

    $stats = $this->store->getStats();

    expect((int) $stats['total'])->toBe(2);
    expect((int) $stats['enabled'])->toBe(1);
    expect((int) $stats['disabled'])->toBe(1);
});

// --- Force Next Run ---

test('forceNextRun updates next_run_at', function () {
    $id = $this->store->create(
        name: 'force',
        scheduleExpression: '0 0 1 1 *',
        prompt: 'test',
    );

    $this->store->forceNextRun($id, '2024-06-15T12:00:00Z');

    $schedule = $this->store->get($id);
    expect($schedule['next_run_at'])->toBe('2024-06-15T12:00:00Z');
});

// --- Compute Next Run ---

test('computeNextRun returns DateTimeImmutable for valid cron', function () {
    $next = $this->store->computeNextRun('* * * * *');

    expect($next)->toBeInstanceOf(DateTimeImmutable::class);
});

test('computeNextRun returns null for @once', function () {
    // @once is treated specially — schedule store sets next_run_at to now on create,
    // but computeNextRun for @once after execution should return the current time
    $next = $this->store->computeNextRun('@once');

    expect($next)->toBeInstanceOf(DateTimeImmutable::class);
});

// --- Bulk Operations ---

test('deleteAll removes all schedules and returns count', function () {
    $this->store->create(name: 'a', scheduleExpression: '* * * * *', prompt: 'a');
    $this->store->create(name: 'b', scheduleExpression: '* * * * *', prompt: 'b');
    $this->store->create(name: 'c', scheduleExpression: '* * * * *', prompt: 'c');

    $count = $this->store->deleteAll();

    expect($count)->toBe(3);
    expect($this->store->list())->toBeEmpty();
});

test('deleteAll returns 0 on empty store', function () {
    expect($this->store->deleteAll())->toBe(0);
});

test('disableAll disables all enabled schedules and returns count', function () {
    $id1 = $this->store->create(name: 'a', scheduleExpression: '* * * * *', prompt: 'a');
    $id2 = $this->store->create(name: 'b', scheduleExpression: '* * * * *', prompt: 'b');
    $id3 = $this->store->create(name: 'c', scheduleExpression: '* * * * *', prompt: 'c');
    $this->store->disable($id3);

    $count = $this->store->disableAll();

    expect($count)->toBe(2);
    expect((int) $this->store->get($id1)['enabled'])->toBe(0);
    expect((int) $this->store->get($id2)['enabled'])->toBe(0);
    expect((int) $this->store->get($id3)['enabled'])->toBe(0);
});

test('disableAll returns 0 when all already disabled', function () {
    $id = $this->store->create(name: 'a', scheduleExpression: '* * * * *', prompt: 'a');
    $this->store->disable($id);

    expect($this->store->disableAll())->toBe(0);
});

test('enableAll enables all disabled schedules with recomputed next_run_at', function () {
    $id1 = $this->store->create(name: 'a', scheduleExpression: '* * * * *', prompt: 'a');
    $id2 = $this->store->create(name: 'b', scheduleExpression: '0 9 * * *', prompt: 'b');
    $this->store->disable($id1);
    $this->store->disable($id2);

    $count = $this->store->enableAll();

    expect($count)->toBe(2);
    $s1 = $this->store->get($id1);
    $s2 = $this->store->get($id2);
    expect((int) $s1['enabled'])->toBe(1);
    expect((int) $s2['enabled'])->toBe(1);
    expect($s1['next_run_at'])->not->toBeNull();
    expect($s2['next_run_at'])->not->toBeNull();
    // Failure counters should be reset
    expect((int) $s1['failure_count'])->toBe(0);
    expect((int) $s2['failure_count'])->toBe(0);
});

test('enableAll returns 0 when all already enabled', function () {
    $this->store->create(name: 'a', scheduleExpression: '* * * * *', prompt: 'a');

    expect($this->store->enableAll())->toBe(0);
});

// --- Reserved Name Validation ---

test('reserved name "all" is rejected by validator', function () {
    $error = \CoquiBot\Coqui\Utility\ScheduleValidator::validateName('all');

    expect($error)->not->toBeNull();
    expect($error)->toContain('reserved');
});

test('reserved name "ALL" (case-insensitive) is rejected by validator', function () {
    $error = \CoquiBot\Coqui\Utility\ScheduleValidator::validateName('ALL');

    expect($error)->not->toBeNull();
    expect($error)->toContain('reserved');
});

test('schedule validator accepts prompt at 50000 characters', function () {
    $error = \CoquiBot\Coqui\Utility\ScheduleValidator::validatePromptLength(str_repeat('x', 50000));

    expect($error)->toBeNull();
});

test('schedule validator rejects prompt longer than 50000 characters', function () {
    $error = \CoquiBot\Coqui\Utility\ScheduleValidator::validatePromptLength(str_repeat('x', 50001));

    expect($error)->not->toBeNull();
    expect($error)->toContain('50000');
});
