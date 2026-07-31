<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-loop-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    // Persona-bound work-scope session; the loop inherits its persona_id.
    $this->sessionId = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
    $this->store = new LoopStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-16: a loop persists circuit-breaker + dispatch diagnostics as real columns and produces a schema-valid Loop', function () {
    $id = $this->store->createLoop(
        definitionName: 'code-review-loop',
        goal: 'Harden the conformance vector suite until review is clean.',
        configuration: ['name' => 'code-review-loop', 'max_rework_attempts' => 3],
        sessionId: $this->sessionId,
        personaId: '01J000000000000000000PERSONA',
        maxIterations: 5,
    );

    // Drive the circuit-breaker + dispatch state through the real columns.
    $this->store->setReworkAttempts($id, 2);
    $this->store->setDispatchState($id, 'dispatched');

    $wire = LoopStore::toWire($this->store->getLoop($id));

    $v = new ConformanceValidator();
    expect($v->isValid('loop.json', $wire))->toBeTrue($v->errorText('loop.json', $wire));

    // Circuit-breaker + dispatch state are persisted, typed fields (not blob keys).
    expect($wire['rework_attempts'])->toBe(2);
    expect($wire['dispatch_state'])->toBe('dispatched');
    expect($wire['dispatch_state'])->toBeIn(['pending', 'dispatched']);
    expect($wire['last_dispatch_error'])->toBeNull();

    // origin is a closed set; created_at maps from the started_at column, Z-suffixed.
    expect($wire['origin'])->toBe('conversation');
    expect($wire['origin'])->toBeIn(['conversation', 'headless']);
    expect($wire['persona_id'])->toBe('01J000000000000000000PERSONA');
    expect($wire['created_at'])->toMatch('/Z$/');
    expect($wire['configuration'])->toBeObject();

    // The dropped Project column leaves no trace on the wire.
    expect(array_key_exists('project_id', $wire))->toBeFalse();
})->group('conformance');

it('CORE-16: a failed dispatch is captured in last_dispatch_error while dispatch_state stays a closed-set value', function () {
    $id = $this->store->createLoop(
        definitionName: 'code-review-loop',
        goal: 'Ship it.',
        configuration: ['name' => 'code-review-loop'],
        sessionId: $this->sessionId,
        personaId: '01J000000000000000000PERSONA',
        origin: 'headless',
    );

    $this->store->setDispatchState($id, 'pending', 'stage task creation failed: provider timeout');

    $wire = LoopStore::toWire($this->store->getLoop($id));

    $v = new ConformanceValidator();
    expect($v->isValid('loop.json', $wire))->toBeTrue($v->errorText('loop.json', $wire));
    expect($wire['dispatch_state'])->toBe('pending');
    expect($wire['last_dispatch_error'])->toBe('stage task creation failed: provider timeout');
    expect($wire['origin'])->toBe('headless');
})->group('conformance');
