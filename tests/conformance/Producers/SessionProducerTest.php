<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-session-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-19: produces a schema-valid Session (bound persona, opaque workspace, derived status/members)', function () {
    $id = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');

    // Opaque, host-defined workspace locator — the spec never interprets it (CORE-19).
    $this->storage->pdo()
        ->prepare('UPDATE sessions SET workspace = :ws WHERE id = :id')
        ->execute(['ws' => '/home/carmelo/Projects/CoquiBot', 'id' => $id]);

    $row = $this->storage->getSession($id);
    $wire = SessionHandler::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('session.json', $wire))->toBeTrue($v->errorText('session.json', $wire));
    expect($wire['members'])->toBeArray()->toContain($wire['persona_id']);
    expect($wire['persona_id'])->toBe('caelum');
    expect($wire['workspace'])->toBe('/home/carmelo/Projects/CoquiBot'); // echoed verbatim
    expect($wire['status'])->toBeIn(['active', 'archived', 'closed']);
    expect($wire['kind'])->toBeIn(['chat', 'loop_workscope']);
    expect($wire['pinned'])->toBeBool();
    expect($wire['version'])->toBeInt()->toBeGreaterThanOrEqual(1);
})->group('conformance');

it('CORE-15: session.model is nullable and passes through as null (null = inherit)', function () {
    // A persona-bound session with no rooted model: null ⇒ inherit per Personas §5.
    $id = $this->storage->createSession('orchestrator', null, 'caelum');

    $row = $this->storage->getSession($id);
    $wire = SessionHandler::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('session.json', $wire))->toBeTrue($v->errorText('session.json', $wire));
    expect(array_key_exists('model', $wire))->toBeTrue();  // present…
    expect($wire['model'])->toBeNull();                    // …and null (not back-filled)
    expect(array_key_exists('workspace', $wire))->toBeTrue();
    expect($wire['workspace'])->toBeNull();                // no rooted workspace ⇒ null
});
