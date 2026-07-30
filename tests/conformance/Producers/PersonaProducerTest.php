<?php

declare(strict_types=1);

use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

it('CORE-1: produces a schema-valid Persona whose allowed_roles includes orchestrator', function () {
    $row = [
        'id' => '01J000000000000000000PERSONA',
        'name' => 'Caelum',
        'avatar' => json_encode(['tint' => '#2b3a52']),
        'model' => 'anthropic/claude-sonnet-4',
        'allowed_roles' => json_encode(['orchestrator']),
        'soul' => 'You are Caelum, a warm, precise research companion.',
        'backstory' => null,
        'context' => null,
        'preferences' => null,
        'version' => 1,
        'created_at' => '2026-07-28T00:00:00Z',
        'updated_at' => '2026-07-28T00:00:00Z',
    ];
    $wire = PersonaSnapshotStore::toWire($row);
    $v = new ConformanceValidator();
    expect($v->isValid('persona.json', $wire))->toBeTrue($v->errorText('persona.json', $wire));
    expect($wire['allowed_roles'])->toContain('orchestrator');
    expect($wire['avatar'])->toBeInstanceOf(stdClass::class);   // empty/object avatar, never []
});
