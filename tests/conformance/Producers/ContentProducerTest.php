<?php

declare(strict_types=1);

use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-content-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->content = new ContentStore($this->storage->pdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-42: stores bytes content-addressed and produces a schema-valid Content', function () {
    $bytes = "the quick brown fox\n";
    $wire = $this->content->store($bytes, 'text/plain');

    $v = new ConformanceValidator();
    expect($v->isValid('content.json', $wire))->toBeTrue($v->errorText('content.json', $wire));

    // sha256 is the lowercase-hex digest of exactly the stored bytes.
    expect($wire['sha256'])->toMatch('/^[0-9a-f]{64}$/');
    expect($wire['sha256'])->toBe(hash('sha256', $bytes));
    expect($wire['size'])->toBe(strlen($bytes));
    expect($wire['mime_type'])->toBe('text/plain');
    expect($wire['content_ref'])->not->toBe('');
})->group('conformance');

it('CORE-42: empty bytes are addressable (size 0, known empty digest)', function () {
    $wire = $this->content->store('', 'application/octet-stream');

    $v = new ConformanceValidator();
    expect($v->isValid('content.json', $wire))->toBeTrue($v->errorText('content.json', $wire));
    expect($wire['size'])->toBe(0);
    // SHA-256 of the empty string.
    expect($wire['sha256'])->toBe('e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');
})->group('conformance');

it('CORE-42: toWire emits exactly the five schema properties', function () {
    $wire = $this->content->store('payload', 'application/json');

    expect(array_keys($wire))->toEqualCanonicalizing([
        'content_ref', 'mime_type', 'size', 'sha256', 'created_at',
    ]);
})->group('conformance');

it('CORE-42: a stored row round-trips through the persisted table', function () {
    $wire = $this->content->store('roundtrip', 'text/plain');

    $stmt = $this->storage->pdo()->prepare('SELECT * FROM content WHERE content_ref = :ref');
    $stmt->execute(['ref' => $wire['content_ref']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    expect($row)->not->toBeFalse();
    $reWire = ContentStore::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('content.json', $reWire))->toBeTrue($v->errorText('content.json', $reWire));
    expect($reWire)->toEqual($wire);
})->group('conformance');
