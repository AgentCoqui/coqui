<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\ScheduleToolkit;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-schedule-toolkit-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->store = new ScheduleStore($this->storage->getPdo());
});

afterEach(function () {
    cleanupSqliteTestDb($this->dbPath);
});

test('schedule_create persists active profile in schedule metadata', function () {
    $toolkit = new ScheduleToolkit($this->store, 'caelum');
    $tool = toolFromToolkit($toolkit, 'schedule_create');

    $result = $tool->execute([
        'name' => 'caelum-daily',
        'cron' => '@once',
        'prompt' => 'Check project continuity',
    ]);

    $data = json_decode($result->content, true);
    $schedule = $this->store->get((string) $data['id']);
    $metadata = json_decode((string) ($schedule['metadata'] ?? '{}'), true);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($metadata['profile'] ?? null)->toBe('caelum');
});