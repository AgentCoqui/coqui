<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;

/**
 * The hybrid file/DB engine has been removed — every store is now
 * unconditionally file-backed. This is the regression guard for that.
 */
it('every store is file-capable — create always writes a file', function (): void {
    $ws = sys_get_temp_dir() . '/ash-' . bin2hex(random_bytes(6));
    mkdir($ws, 0775, true);

    try {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE sessions (id TEXT PRIMARY KEY)');

        $store = new ArtifactStore($pdo, new ArtifactFileService($ws));
        $id = $store->create('s1', 'X', 'body', 'code', language: 'php');
        $path = $store->get($id)['path'];

        expect($path)->toEndWith('.php')
            ->and(file_exists($ws . '/' . $path))->toBeTrue();
    } finally {
        exec('rm -rf ' . escapeshellarg($ws));
    }
});
