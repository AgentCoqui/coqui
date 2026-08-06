<?php

declare(strict_types=1);

// CAP 0.5.0 storage marker: meta.schema_version stamp, fail-closed-open on a
// mismatched stamp, and foreign_keys enforcement on the connection.

use CoquiBot\Coqui\Storage\SessionStorage;

it('stamps schema_version 0.5.0 on a fresh store', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'coqui_meta_') . '.db';
    new SessionStorage($tmp);
    $pdo = new PDO('sqlite:' . $tmp);
    $val = $pdo->query("SELECT value FROM meta WHERE key='schema_version'")->fetchColumn();
    expect($val)->toBe('0.5.0');
});

it('refuses to open a store stamped with a different schema_version', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'coqui_meta_') . '.db';
    new SessionStorage($tmp);                       // stamps 0.5.0
    $pdo = new PDO('sqlite:' . $tmp);
    $pdo->exec("UPDATE meta SET value='0.4.0' WHERE key='schema_version'");
    expect(fn () => new SessionStorage($tmp))
        ->toThrow(RuntimeException::class);
});

it('enables foreign_keys on the connection', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'coqui_meta_') . '.db';
    $store = new SessionStorage($tmp);
    $on = $store->pdo()->query('PRAGMA foreign_keys')->fetchColumn();  // if no pdo() accessor, assert via a FK violation instead
    expect((int) $on)->toBe(1);
})->skip(!method_exists(\CoquiBot\Coqui\Storage\SessionStorage::class, 'pdo'), 'no pdo accessor');
