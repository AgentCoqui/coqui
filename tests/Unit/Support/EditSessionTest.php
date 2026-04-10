<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\EditSession;

// ---------------------------------------------------------------
// Session lifecycle
// ---------------------------------------------------------------

test('new session is active with no pending edits', function () {
    $session = new EditSession();

    expect($session->isActive())->toBeTrue();
    expect($session->isCommitted())->toBeFalse();
    expect($session->isRolledBack())->toBeFalse();
    expect($session->isExpired())->toBeFalse();
    expect($session->status())->toBe('active');
    expect($session->pendingCount())->toBe(0);
    expect($session->pendingEdits())->toBe([]);
    expect($session->id)->toHaveLength(32); // 16 random bytes = 32 hex chars
});

test('addEdit queues edits and tracks count', function () {
    $session = new EditSession();

    $session->addEdit('/tmp/test.txt', 'original', 'modified', 'replace_in_file');
    expect($session->pendingCount())->toBe(1);

    $session->addEdit('/tmp/other.txt', 'old', 'new', 'insert_after', ['key' => 'value']);
    expect($session->pendingCount())->toBe(2);

    $edits = $session->pendingEdits();
    expect($edits)->toHaveCount(2);
    expect($edits[0]['path'])->toBe('/tmp/test.txt');
    expect($edits[0]['operation'])->toBe('replace_in_file');
    expect($edits[1]['path'])->toBe('/tmp/other.txt');
    expect($edits[1]['metadata'])->toBe(['key' => 'value']);
});

// ---------------------------------------------------------------
// Commit
// ---------------------------------------------------------------

test('commit returns edits and transitions to committed', function () {
    $session = new EditSession();
    $session->addEdit('/tmp/a.txt', 'orig', 'mod', 'op1');
    $session->addEdit('/tmp/b.txt', 'orig2', 'mod2', 'op2');

    $edits = $session->commit();

    expect($edits)->toHaveCount(2);
    expect($edits[0]['path'])->toBe('/tmp/a.txt');
    expect($edits[0]['original'])->toBe('orig');
    expect($edits[0]['modified'])->toBe('mod');
    expect($edits[0]['operation'])->toBe('op1');
    expect($edits[1]['path'])->toBe('/tmp/b.txt');

    expect($session->isCommitted())->toBeTrue();
    expect($session->isActive())->toBeFalse();
    expect($session->status())->toBe('committed');
});

test('cannot addEdit after commit', function () {
    $session = new EditSession();
    $session->commit();

    $session->addEdit('/tmp/a.txt', 'a', 'b', 'op');
})->throws(RuntimeException::class, 'already committed');

test('cannot commit twice', function () {
    $session = new EditSession();
    $session->commit();

    $session->commit();
})->throws(RuntimeException::class, 'already committed');

// ---------------------------------------------------------------
// Rollback
// ---------------------------------------------------------------

test('rollback discards edits and transitions to rolled_back', function () {
    $session = new EditSession();
    $session->addEdit('/tmp/a.txt', 'a', 'b', 'op');

    $session->rollback();

    expect($session->isRolledBack())->toBeTrue();
    expect($session->isActive())->toBeFalse();
    expect($session->status())->toBe('rolled_back');
    expect($session->pendingCount())->toBe(0);
});

test('cannot addEdit after rollback', function () {
    $session = new EditSession();
    $session->rollback();

    $session->addEdit('/tmp/a.txt', 'a', 'b', 'op');
})->throws(RuntimeException::class, 'rolled back');

// ---------------------------------------------------------------
// Expiry
// ---------------------------------------------------------------

test('session with zero timeout expires immediately', function () {
    $session = new EditSession(timeoutSeconds: 0);

    // With 0 timeout, expiresAt = createdAt, and microtime(true) > expiresAt
    // might be a race; use a tiny sleep to ensure
    usleep(1000); // 1ms

    expect($session->isExpired())->toBeTrue();
    expect($session->isActive())->toBeFalse();
    expect($session->status())->toBe('expired');
});

test('cannot addEdit to expired session', function () {
    $session = new EditSession(timeoutSeconds: 0);
    usleep(1000);

    $session->addEdit('/tmp/a.txt', 'a', 'b', 'op');
})->throws(RuntimeException::class, 'expired');

test('session with long timeout does not expire', function () {
    $session = new EditSession(timeoutSeconds: 3600);

    expect($session->isExpired())->toBeFalse();
    expect($session->isActive())->toBeTrue();
});

// ---------------------------------------------------------------
// Validation (concurrent edit detection)
// ---------------------------------------------------------------

test('validate returns empty for unmodified files', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'es_');
    file_put_contents($tmpFile, 'original content');

    $session = new EditSession();
    $session->addEdit($tmpFile, 'original content', 'new content', 'op');

    $conflicts = $session->validate();

    expect($conflicts)->toBe([]);

    unlink($tmpFile);
});

test('validate detects modified files', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'es_');
    file_put_contents($tmpFile, 'original content');

    $session = new EditSession();
    $session->addEdit($tmpFile, 'original content', 'new content', 'op');

    // Simulate external modification
    file_put_contents($tmpFile, 'someone else changed this');

    $conflicts = $session->validate();

    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0])->toBe($tmpFile);

    unlink($tmpFile);
});

test('validate detects deleted files', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'es_');
    file_put_contents($tmpFile, 'original');

    $session = new EditSession();
    $session->addEdit($tmpFile, 'original', 'modified', 'op');

    unlink($tmpFile);

    $conflicts = $session->validate();

    expect($conflicts)->toHaveCount(1);
    expect($conflicts[0])->toBe($tmpFile);
});

test('validate deduplicates conflicting paths', function () {
    $tmpFile = tempnam(sys_get_temp_dir(), 'es_');
    file_put_contents($tmpFile, 'original');

    $session = new EditSession();
    $session->addEdit($tmpFile, 'original', 'mod1', 'op1');
    $session->addEdit($tmpFile, 'original', 'mod2', 'op2');

    // Modify the file
    file_put_contents($tmpFile, 'changed');

    $conflicts = $session->validate();

    // Same path should only appear once
    expect($conflicts)->toHaveCount(1);

    unlink($tmpFile);
});
