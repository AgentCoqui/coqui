<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-artifact-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->workspace = sys_get_temp_dir() . '/ap-' . bin2hex(random_bytes(6));
    mkdir($this->workspace, 0775, true);
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
    $this->store = new ArtifactStore($this->storage->getPdo(), new ArtifactFileService($this->workspace));
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    exec('rm -rf ' . escapeshellarg($this->workspace));
});

it('CORE-25: a files-only artifact serializes to a schema-valid, session-scoped Artifact', function () {
    $id = $this->store->create(
        sessionId: $this->sessionId,
        title: 'Design Doc',
        content: "# Title\nbody\n",
        type: 'document',
        createdBy: 'coder',
        metadata: ['reviewed' => true],
    );

    $wire = ArtifactStore::toWire($this->store->get($id, $this->sessionId));

    $v = new ConformanceValidator();
    expect($v->isValid('artifact.json', $wire))->toBeTrue($v->errorText('artifact.json', $wire));

    // session_id is required and must be the owning session.
    expect($wire['session_id'])->toBe($this->sessionId);
    expect($wire['id'])->toBe($id);

    // coqui `title` maps to the schema `name`; the files-only path is content_ref.
    expect($wire['name'])->toBe('Design Doc');
    expect($wire['type'])->toBe('document');
    expect($wire['content_ref'])->toStartWith('artifacts/document/');

    // created_at is RFC-3339 UTC (Z); metadata is a JSON object, not a bare array.
    expect($wire['created_at'])->toMatch('/Z$/');
    expect($wire['metadata'])->toBeObject();
})->group('conformance');

it('CORE-25: toWire emits exactly the schema properties (additionalProperties:false-clean)', function () {
    $id = $this->store->create($this->sessionId, 'Plan', 'x', 'plan');

    $wire = ArtifactStore::toWire($this->store->get($id, $this->sessionId));

    expect(array_keys($wire))->toEqualCanonicalizing([
        'id', 'session_id', 'name', 'type', 'content_ref', 'metadata', 'created_at',
    ]);
})->group('conformance');

it('CORE-25: absent metadata emits null (never a bare array)', function () {
    $id = $this->store->create($this->sessionId, 'No Meta', 'x', 'document');

    $wire = ArtifactStore::toWire($this->store->get($id, $this->sessionId));

    $v = new ConformanceValidator();
    expect($v->isValid('artifact.json', $wire))->toBeTrue($v->errorText('artifact.json', $wire));
    expect($wire['metadata'])->toBeNull();
})->group('conformance');
