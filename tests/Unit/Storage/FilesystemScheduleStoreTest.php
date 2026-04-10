<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-fs-schedule-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->store = new ScheduleStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// --- upsertFilesystem ---

test('upsertFilesystem creates a new filesystem schedule', function () {
    $id = $this->store->upsertFilesystem(
        name: 'daily-backup',
        sourcePath: '/workspace/schedules/daily-backup.json',
        scheduleExpression: '0 2 * * *',
        prompt: 'Run daily backup',
        role: 'orchestrator',
        description: 'Nightly backup',
    );

    expect($id)->toBeString()->toHaveLength(32);

    $schedule = $this->store->get($id);
    expect($schedule)->not->toBeNull();
    expect($schedule['name'])->toBe('daily-backup');
    expect($schedule['source'])->toBe('filesystem');
    expect($schedule['source_path'])->toBe('/workspace/schedules/daily-backup.json');
    expect($schedule['schedule_expression'])->toBe('0 2 * * *');
    expect($schedule['prompt'])->toBe('Run daily backup');
    expect($schedule['description'])->toBe('Nightly backup');
});

test('upsertFilesystem updates definition fields on re-sync', function () {
    $id1 = $this->store->upsertFilesystem(
        name: 'my-schedule',
        sourcePath: '/workspace/schedules/my-schedule.json',
        scheduleExpression: '*/5 * * * *',
        prompt: 'Old prompt',
    );

    $id2 = $this->store->upsertFilesystem(
        name: 'my-schedule',
        sourcePath: '/workspace/schedules/my-schedule.json',
        scheduleExpression: '*/10 * * * *',
        prompt: 'New prompt',
    );

    expect($id2)->toBe($id1);

    $schedule = $this->store->get($id1);
    expect($schedule['schedule_expression'])->toBe('*/10 * * * *');
    expect($schedule['prompt'])->toBe('New prompt');
});

test('upsertFilesystem preserves runtime fields on update', function () {
    $id = $this->store->upsertFilesystem(
        name: 'counter-test',
        sourcePath: '/workspace/schedules/counter-test.json',
        scheduleExpression: '* * * * *',
        prompt: 'Test prompt',
    );

    // Simulate runtime state via markExecuted (increments run_count)
    $this->store->markExecuted($id, 'task-1');
    $this->store->markExecuted($id, 'task-2');

    $before = $this->store->get($id);
    expect((int) $before['run_count'])->toBe(2);

    // Re-sync with updated prompt
    $this->store->upsertFilesystem(
        name: 'counter-test',
        sourcePath: '/workspace/schedules/counter-test.json',
        scheduleExpression: '* * * * *',
        prompt: 'Updated prompt',
    );

    $after = $this->store->get($id);
    expect($after['prompt'])->toBe('Updated prompt');
    expect((int) $after['run_count'])->toBe(2); // Preserved
});

// --- deleteRemovedFilesystemSchedules ---

test('deleteRemovedFilesystemSchedules removes orphaned entries', function () {
    $this->store->upsertFilesystem(
        name: 'keep-me',
        sourcePath: '/workspace/schedules/keep-me.json',
        scheduleExpression: '* * * * *',
        prompt: 'Keep this',
    );

    $this->store->upsertFilesystem(
        name: 'remove-me',
        sourcePath: '/workspace/schedules/remove-me.json',
        scheduleExpression: '* * * * *',
        prompt: 'Remove this',
    );

    // Only keep-me.json still exists
    $removed = $this->store->deleteRemovedFilesystemSchedules([
        '/workspace/schedules/keep-me.json',
    ]);

    expect($removed)->toBe(1);
    expect($this->store->getByName('keep-me'))->not->toBeNull();
    expect($this->store->getByName('remove-me'))->toBeNull();
});

test('deleteRemovedFilesystemSchedules does not remove system schedules', function () {
    // Create a system schedule
    $this->store->create(
        name: 'system-schedule',
        scheduleExpression: '* * * * *',
        prompt: 'System prompt',
    );

    // Create a filesystem schedule
    $this->store->upsertFilesystem(
        name: 'fs-schedule',
        sourcePath: '/workspace/schedules/fs-schedule.json',
        scheduleExpression: '* * * * *',
        prompt: 'FS prompt',
    );

    // Empty active paths — should only remove filesystem schedules
    $removed = $this->store->deleteRemovedFilesystemSchedules([]);

    expect($removed)->toBe(1);
    expect($this->store->getByName('system-schedule'))->not->toBeNull();
    expect($this->store->getByName('fs-schedule'))->toBeNull();
});

// --- isFilesystemSchedule ---

test('isFilesystemSchedule returns true for filesystem schedules', function () {
    $id = $this->store->upsertFilesystem(
        name: 'fs-test',
        sourcePath: '/workspace/schedules/fs-test.json',
        scheduleExpression: '* * * * *',
        prompt: 'Test',
    );

    expect($this->store->isFilesystemSchedule($id))->toBeTrue();
});

test('isFilesystemSchedule returns false for system schedules', function () {
    $id = $this->store->create(
        name: 'system-test',
        scheduleExpression: '* * * * *',
        prompt: 'Test',
    );

    expect($this->store->isFilesystemSchedule($id))->toBeFalse();
});

// --- Source-aware bulk operations ---

test('deleteAll only deletes system schedules', function () {
    $this->store->create(
        name: 'system-one',
        scheduleExpression: '* * * * *',
        prompt: 'System',
    );

    $this->store->upsertFilesystem(
        name: 'fs-one',
        sourcePath: '/workspace/schedules/fs-one.json',
        scheduleExpression: '* * * * *',
        prompt: 'Filesystem',
    );

    $deleted = $this->store->deleteAll();

    expect($deleted)->toBe(1);
    expect($this->store->getByName('system-one'))->toBeNull();
    expect($this->store->getByName('fs-one'))->not->toBeNull();
});

test('enableAll only enables system schedules', function () {
    $sysId = $this->store->create(
        name: 'system-disabled',
        scheduleExpression: '* * * * *',
        prompt: 'Test',
    );
    $this->store->disable($sysId);

    $fsId = $this->store->upsertFilesystem(
        name: 'fs-disabled',
        sourcePath: '/workspace/schedules/fs-disabled.json',
        scheduleExpression: '* * * * *',
        prompt: 'Test',
        enabled: false,
    );

    $enabled = $this->store->enableAll();

    expect($enabled)->toBe(1); // Only system schedule
});

test('disableAll only disables system schedules', function () {
    $this->store->create(
        name: 'system-enabled',
        scheduleExpression: '* * * * *',
        prompt: 'Test',
    );

    $this->store->upsertFilesystem(
        name: 'fs-enabled',
        sourcePath: '/workspace/schedules/fs-enabled.json',
        scheduleExpression: '* * * * *',
        prompt: 'Test',
    );

    $disabled = $this->store->disableAll();

    expect($disabled)->toBe(1); // Only system schedule
});
