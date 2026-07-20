<?php

declare(strict_types=1);

namespace Tests\Unit\Repl\Handler;

use CoquiBot\Coqui\Repl\Handler\AuditHandler;
use CoquiBot\Coqui\Storage\SessionStorage;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

covers(AuditHandler::class);

function replAuditIo(): array
{
    $output = new BufferedOutput();

    return [new SymfonyStyle(new ArrayInput([]), $output), $output];
}

test('bare /audit on an empty log renders the empty-state message', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, '', '');

        expect($output->fetch())->toContain('No audit entries');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit renders a table with tool and action columns', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $storage->logAudit($sessionId, 'exec', ['command' => 'echo hello'], 'auto_approved');

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, '', $sessionId);

        $rendered = $output->fetch();

        expect($rendered)->toContain('exec');
        expect($rendered)->toContain('auto_approved');
        expect($rendered)->toContain('Tool');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit tool <name> filters by tool', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $storage->logAudit($sessionId, 'exec', ['command' => 'findme-exec'], 'auto_approved');
        $storage->logAudit($sessionId, 'write_file', ['path' => 'findme-write'], 'auto_approved');

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'tool write_file', $sessionId);

        $rendered = $output->fetch();

        expect($rendered)->toContain('write_file');
        expect($rendered)->not->toContain('exec ');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit action <name> filters by action', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        $storage->logAudit($sessionId, 'exec', ['command' => 'one'], 'auto_approved');
        $storage->logAudit($sessionId, 'exec', ['command' => 'two'], 'blocked');

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'action blocked', $sessionId);

        expect($output->fetch())->toContain('blocked');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit limit <n> bounds the rendered rows', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 5; $i++) {
            $storage->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'limit 2', $sessionId);

        expect($output->fetch())->toContain('showing 2 of 5');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});

test('/audit reports an unusable filter rather than silently listing everything', function (): void {
    $dbPath = tempnam(sys_get_temp_dir(), 'audit-repl-') . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        [$io, $output] = replAuditIo();
        (new AuditHandler($storage))->handle($io, 'tool', '');

        expect($output->fetch())->toContain('Usage');
    } finally {
        $storage = null;
        cleanupSqliteTestDb($dbPath);
    }
});
