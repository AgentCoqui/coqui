<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-turn-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-34: produces a schema-valid Turn carrying actor_persona_id', function () {
    $sessionId = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');

    // A group-style turn produced by a named member persona.
    $turnId = $this->storage->createTurn(
        $sessionId,
        'Summarize the quarterly report.',
        'anthropic/claude-sonnet-4',
        null,
        'caelum',
    );
    $this->storage->completeTurn(
        $turnId,
        'Here is the summary.',
        120,
        45,
        165,
        2,
        1500,
        json_encode(['read_file', 'grep']),
        0,
    );

    $row = $this->storage->getTurn($turnId);
    $wire = TurnHandler::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('turn.json', $wire))->toBeTrue($v->errorText('turn.json', $wire));
    expect(array_key_exists('actor_persona_id', $wire))->toBeTrue();
    expect($wire['actor_persona_id'])->toBe('caelum');
    expect($wire['status'])->toBeIn(['running', 'completed', 'failed', 'cancelled']);
    expect($wire['status'])->toBe('completed');
    expect($wire['tools_used'])->toBe(['read_file', 'grep']);
    expect($wire['session_id'])->toBe($sessionId);
    expect($wire['turn_number'])->toBe(1);
})->group('conformance');

it('CORE-34: a solo turn omits model when null and carries a null actor_persona_id', function () {
    $sessionId = $this->storage->createSession('orchestrator', null, 'caelum');

    // Solo turn: no acting-member persona, no rooted model.
    $turnId = $this->storage->createTurn($sessionId, 'What time is it?');

    $row = $this->storage->getTurn($turnId);
    $wire = TurnHandler::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('turn.json', $wire))->toBeTrue($v->errorText('turn.json', $wire));
    // actor_persona_id is oneOf[Id,null]: present-and-null is schema-valid in a solo session.
    expect(array_key_exists('actor_persona_id', $wire))->toBeTrue();
    expect($wire['actor_persona_id'])->toBeNull();
    // model is a non-null ModelId string and is NOT required → omit the key when null.
    expect(array_key_exists('model', $wire))->toBeFalse();
    // A freshly created, not-yet-completed turn is 'running'.
    expect($wire['status'])->toBe('running');
})->group('conformance');
