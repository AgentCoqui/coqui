<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-skill-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->skills = new SkillLifecycleStore($this->storage->pdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-26/27: catalogs a builtin instruction skill and produces a schema-valid Skill', function () {
    $wire = $this->skills->upsertSkill(
        name: 'summarize',
        description: 'Condense long documents into a brief.',
        status: 'available',
        origin: ['kind' => 'builtin'],
        execution: ['kind' => 'instruction', 'requires' => []],
    );

    $v = new ConformanceValidator();
    expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));

    // CORE-26: origin is a typed object with a closed-set kind.
    expect($wire['origin'])->toBeObject();
    expect($wire['origin']->kind)->toBe('builtin');
    expect($wire['origin']->kind)->toBeIn(['builtin', 'local', 'imported']);

    // CORE-27: execution declares kind (instruction vs script) + requires (default []).
    expect($wire['execution'])->toBeObject();
    expect($wire['execution']->kind)->toBe('instruction');
    expect($wire['execution']->kind)->toBeIn(['instruction', 'script']);
    expect($wire['execution']->requires)->toBe([]);
})->group('conformance');

it('CORE-27: a script skill carries its required capabilities', function () {
    $wire = $this->skills->upsertSkill(
        name: 'deploy_site',
        description: 'Build and push the static site.',
        status: 'available',
        origin: ['kind' => 'imported', 'publisher' => 'acme'],
        execution: ['kind' => 'script', 'requires' => ['shell', 'network']],
    );

    $v = new ConformanceValidator();
    expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));
    expect($wire['execution']->kind)->toBe('script');
    expect($wire['execution']->requires)->toBe(['shell', 'network']);
    expect($wire['origin']->kind)->toBe('imported');
    expect($wire['origin']->publisher)->toBe('acme');
})->group('conformance');

it('CORE-26: a disabled imported skill is still a valid Skill', function () {
    $wire = $this->skills->upsertSkill(
        name: 'risky-import',
        description: 'An imported, untrusted-by-default skill.',
        status: 'disabled',
        origin: ['kind' => 'imported'],
        execution: ['kind' => 'script', 'requires' => ['shell']],
    );

    $v = new ConformanceValidator();
    expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));
    expect($wire['status'])->toBe('disabled');
})->group('conformance');

it('CORE-27: execution.requires defaults to [] when omitted; empty object columns emit as objects', function () {
    $wire = $this->skills->upsertSkill(
        name: 'note',
        description: 'A minimal instruction-only skill.',
        status: 'available',
        origin: ['kind' => 'local'],
        execution: ['kind' => 'instruction'],
    );

    $v = new ConformanceValidator();
    expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));
    expect($wire['execution']->requires)->toBe([]);
    // metadata/source default to null; timestamps are Z-suffixed.
    expect($wire['metadata'])->toBeNull();
    expect($wire['source'])->toBeNull();
    expect($wire['created_at'])->toMatch('/Z$/');
})->group('conformance');

it('CORE-26/27: a persisted row round-trips through the skills table', function () {
    $wire = $this->skills->upsertSkill(
        name: 'roundtrip',
        description: 'Proves the catalog row projects back to the same wire.',
        status: 'available',
        origin: ['kind' => 'builtin'],
        execution: ['kind' => 'instruction', 'requires' => []],
        metadata: ['category' => 'writing'],
        source: 'builtin://roundtrip',
    );

    $stmt = $this->storage->pdo()->prepare('SELECT * FROM skills WHERE name = :name');
    $stmt->execute(['name' => 'roundtrip']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    expect($row)->not->toBeFalse();
    $reWire = SkillLifecycleStore::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('skill.json', $reWire))->toBeTrue($v->errorText('skill.json', $reWire));
    expect($reWire)->toEqual($wire);
})->group('conformance');
