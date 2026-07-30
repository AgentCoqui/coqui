<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-scheduled-task-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->store = new ScheduleStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-33: builds a persona-bound turn schedule and produces a schema-valid ScheduledTask', function () {
    $id = $this->store->create(
        name: 'daily-review',
        scheduleExpression: '0 9 * * 1-5',
        prompt: 'Review recent changes.',
        personaId: '01J000000000000000000PERSONA',
    );

    $wire = ScheduleStore::toWire($this->store->get($id));

    $v = new ConformanceValidator();
    expect($v->isValid('scheduled-task.json', $wire))->toBeTrue($v->errorText('scheduled-task.json', $wire));

    // action is a typed object discriminated by kind; coqui schedules are turn-kind.
    expect($wire['action'])->toBeObject();
    expect($wire['action']->kind)->toBe('turn');
    expect($wire['action']->kind)->toBeIn(['turn', 'loop']);
    expect($wire['action']->prompt)->toBe('Review recent changes.');

    // status is a closed set derived from the enabled flag.
    expect($wire['status'])->toBe('enabled');
    expect($wire['status'])->toBeIn(['enabled', 'disabled']);

    // persona_id is carried; cron is the schedule expression; created_at is Z-suffixed.
    expect($wire['persona_id'])->toBe('01J000000000000000000PERSONA');
    expect($wire['cron'])->toBe('0 9 * * 1-5');
    expect($wire['created_at'])->toMatch('/Z$/');
})->group('conformance');

it('CORE-33: a disabled schedule serializes status=disabled and stays schema-valid', function () {
    $id = $this->store->create(
        name: 'weekly-retro',
        scheduleExpression: '0 9 * * 1',
        prompt: 'Summarize the week.',
        personaId: '01J000000000000000000PERSONA',
    );
    $this->store->disable($id);

    $wire = ScheduleStore::toWire($this->store->get($id));

    $v = new ConformanceValidator();
    expect($v->isValid('scheduled-task.json', $wire))->toBeTrue($v->errorText('scheduled-task.json', $wire));
    expect($wire['status'])->toBe('disabled');
})->group('conformance');
