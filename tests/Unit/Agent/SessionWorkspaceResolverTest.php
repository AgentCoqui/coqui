<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\SessionWorkspaceResolver;
use CoquiBot\Coqui\Storage\SessionStorage;

test('resolves a session workspace when the column is set, else the global default', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-wsres-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    try {
        $resolver = new SessionWorkspaceResolver($storage, '/global/ws');

        $rooted = $storage->createSession('orchestrator', null, 'caelum', workspace: '/srv/agents/ws-7');
        expect($resolver->resolve($rooted))->toBe('/srv/agents/ws-7');

        $unrooted = $storage->createSession('orchestrator', null, 'caelum');
        expect($resolver->resolve($unrooted))->toBe('/global/ws');

        // Null session id and unknown session both fall back to the global default.
        expect($resolver->resolve(null))->toBe('/global/ws');
        expect($resolver->resolve('does-not-exist'))->toBe('/global/ws');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
