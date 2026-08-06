<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ObjectVersionStore;
use CoquiBot\Coqui\Storage\SessionStorage;

function makeObjectVersionStore(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-objver-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return [new ObjectVersionStore($storage->getPdo()), $dbPath];
}

test('current returns 0 for an object with no version row', function () {
    [$store, $dbPath] = makeObjectVersionStore();

    try {
        expect($store->current('persona', 'ghost'))->toBe(0);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('create seeds version 1 and a second create throws', function () {
    [$store, $dbPath] = makeObjectVersionStore();

    try {
        expect($store->create('persona', 'nova'))->toBe(1);
        expect($store->current('persona', 'nova'))->toBe(1);
        expect(fn () => $store->create('persona', 'nova'))->toThrow(RuntimeException::class);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('bump advances the version monotonically', function () {
    [$store, $dbPath] = makeObjectVersionStore();

    try {
        $store->create('persona', 'nova');
        expect($store->bump('persona', 'nova'))->toBe(2);
        expect($store->bump('persona', 'nova'))->toBe(3);
        expect($store->current('persona', 'nova'))->toBe(3);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('bump on an absent row treats it as an implicit version 1 and advances to 2', function () {
    [$store, $dbPath] = makeObjectVersionStore();

    try {
        // A pre-existing file-authored persona has no version row yet (implicit v1).
        expect($store->bump('persona', 'caelum'))->toBe(2);
        expect($store->current('persona', 'caelum'))->toBe(2);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('delete removes the version row so current falls back to 0', function () {
    [$store, $dbPath] = makeObjectVersionStore();

    try {
        $store->create('persona', 'nova');
        $store->delete('persona', 'nova');
        expect($store->current('persona', 'nova'))->toBe(0);
        // Independent object types keep separate counters.
        $store->create('role', 'nova');
        expect($store->current('role', 'nova'))->toBe(1);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
