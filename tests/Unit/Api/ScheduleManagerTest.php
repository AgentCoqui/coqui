<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ScheduleManager;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-schedule-manager-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->scheduleStore = new ScheduleStore($this->storage->getPdo());
    $this->manager = new ScheduleManager($this->storage, $this->scheduleStore);
});

afterEach(function () {
    cleanupSqliteTestDb($this->dbPath);
});

test('tick creates scheduled task session with persisted persona metadata', function () {
    $scheduleId = $this->scheduleStore->create(
        name: 'caelum-daily',
        scheduleExpression: '@once',
        action: ['kind' => 'turn', 'prompt' => 'Check project continuity'],
        role: 'orchestrator',
        metadata: json_encode(['persona' => 'caelum'], JSON_UNESCAPED_SLASHES),
    );
    $this->scheduleStore->forceNextRun($scheduleId, gmdate('Y-m-d\TH:i:s\Z', time() - 120));

    $this->manager->tick();

    $task = $this->storage->getTaskByScheduleId($scheduleId);
    $session = $task !== null ? $this->storage->getSession((string) $task['session_id']) : null;

    expect($task)->not->toBeNull();
    expect($session)->not->toBeNull();
    expect($session['persona_id'])->toBe('caelum');
});

test('tick dispatches a turn schedule but skips a loop schedule', function () {
    $turnId = $this->scheduleStore->create(
        name: 'turn-schedule',
        scheduleExpression: '@once',
        action: ['kind' => 'turn', 'prompt' => 'Do the turn work'],
        role: 'orchestrator',
    );
    $loopId = $this->scheduleStore->create(
        name: 'loop-schedule',
        scheduleExpression: '@once',
        action: ['kind' => 'loop', 'definition_name' => 'research'],
        role: 'orchestrator',
    );

    $past = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
    $this->scheduleStore->forceNextRun($turnId, $past);
    $this->scheduleStore->forceNextRun($loopId, $past);

    $this->manager->tick();

    // The turn schedule dispatched a background task.
    expect($this->storage->getTaskByScheduleId($turnId))->not->toBeNull();

    // The loop schedule did NOT — no empty-prompt turn is fired.
    expect($this->storage->getTaskByScheduleId($loopId))->toBeNull();
});