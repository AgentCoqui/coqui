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

test('schedule_create persists active persona in schedule metadata', function () {
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
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');
    expect($metadata['persona'] ?? null)->toBe('caelum');
});

test('schedule_get returns structured json metadata', function () {
    $toolkit = new ScheduleToolkit($this->store);
    $create = toolFromToolkit($toolkit, 'schedule_create');
    $get = toolFromToolkit($toolkit, 'schedule_get');

    $created = $create->execute([
        'name' => 'nightly-index',
        'cron' => '0 1 * * *',
        'prompt' => 'Rebuild search index',
    ]);

    $id = (string) json_decode($created->content, true)['id'];
    $result = $get->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['id'])->toBe($id);
    expect($data['name'])->toBe('nightly-index');
});

test('schedule_update returns structured json metadata', function () {
    $toolkit = new ScheduleToolkit($this->store);
    $create = toolFromToolkit($toolkit, 'schedule_create');
    $update = toolFromToolkit($toolkit, 'schedule_update');

    $created = $create->execute([
        'name' => 'ops-daily',
        'cron' => '0 9 * * *',
        'prompt' => 'Check system health',
    ]);

    $id = (string) json_decode($created->content, true)['id'];
    $result = $update->execute([
        'id' => $id,
        'enabled' => false,
    ]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['schedule']['id'])->toBe($id);
    expect((int) $data['schedule']['enabled'])->toBe(0);
});

test('schedule_trigger returns structured json metadata', function () {
    $toolkit = new ScheduleToolkit($this->store);
    $create = toolFromToolkit($toolkit, 'schedule_create');
    $trigger = toolFromToolkit($toolkit, 'schedule_trigger');

    $created = $create->execute([
        'name' => 'deploy-watch',
        'cron' => '*/15 * * * *',
        'prompt' => 'Watch deploy status',
    ]);

    $id = (string) json_decode($created->content, true)['id'];
    $result = $trigger->execute(['id' => $id]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['schedule_id'])->toBe($id);
    expect($data['message'])->toContain('next scheduler tick');
});

test('schedule_trigger all returns structured json metadata', function () {
    $toolkit = new ScheduleToolkit($this->store);
    $create = toolFromToolkit($toolkit, 'schedule_create');
    $trigger = toolFromToolkit($toolkit, 'schedule_trigger');

    $create->execute([
        'name' => 'ops-a',
        'cron' => '0 2 * * *',
        'prompt' => 'Check cluster A',
    ]);
    $create->execute([
        'name' => 'ops-b',
        'cron' => '0 3 * * *',
        'prompt' => 'Check cluster B',
    ]);

    $result = $trigger->execute(['id' => 'all']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['message'])->toContain('2 schedule(s)');
});