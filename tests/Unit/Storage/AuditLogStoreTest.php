<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\AuditLogQuery;
use CoquiBot\Coqui\Storage\AuditLogStore;
use CoquiBot\Coqui\Storage\SessionStorage;

covers(AuditLogStore::class);
covers(AuditLogQuery::class);

function auditStoreFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-audit-store-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    return ['dbPath' => $dbPath, 'storage' => $storage, 'store' => new AuditLogStore($storage->getPdo())];
}

test('query returns rows newest first with turn_id and decoded arguments', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $turnId = $f['storage']->createTurn($sessionId, 'do the thing');

        $f['storage']->logAudit($sessionId, 'exec', ['command' => 'echo one'], 'auto_approved', null, $turnId);
        $f['storage']->logAudit($sessionId, 'write_file', ['path' => '/tmp/x'], 'auto_approved');

        $rows = $f['store']->query(new AuditLogQuery());

        expect($rows)->toHaveCount(2);
        expect($rows[0])->toHaveKeys(['id', 'session_id', 'turn_id', 'tool_name', 'action', 'reason', 'arguments', 'created_at']);
        expect($rows[0]['arguments'])->toBeArray();
        expect(array_column($rows, 'turn_id'))->toContain($turnId);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('filters by session, tool, and action', function (): void {
    $f = auditStoreFixture();

    try {
        $a = $f['storage']->createSession('orchestrator', 'test/model');
        $b = $f['storage']->createSession('orchestrator', 'test/model');

        $f['storage']->logAudit($a, 'exec', ['command' => 'one'], 'auto_approved');
        $f['storage']->logAudit($a, 'exec', ['command' => 'two'], 'blocked');
        $f['storage']->logAudit($b, 'write_file', ['path' => '/tmp/y'], 'auto_approved');

        expect($f['store']->query(new AuditLogQuery(sessionId: $a)))->toHaveCount(2);
        expect($f['store']->query(new AuditLogQuery(toolName: 'exec')))->toHaveCount(2);
        expect($f['store']->query(new AuditLogQuery(action: 'blocked')))->toHaveCount(1);
        expect($f['store']->query(new AuditLogQuery(sessionId: $a, action: 'blocked')))->toHaveCount(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('pagination is deterministic across pages with identical timestamps', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 10; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }

        $page1 = $f['store']->query(new AuditLogQuery(limit: 4, offset: 0));
        $page2 = $f['store']->query(new AuditLogQuery(limit: 4, offset: 4));
        $page3 = $f['store']->query(new AuditLogQuery(limit: 4, offset: 8));

        $ids = [...array_column($page1, 'id'), ...array_column($page2, 'id'), ...array_column($page3, 'id')];

        expect($ids)->toHaveCount(10);
        expect(array_unique($ids))->toHaveCount(10);
        expect($f['store']->count(new AuditLogQuery()))->toBe(10);

        // With every created_at identical, `id DESC` is the only tiebreaker that
        // makes ordering deterministic. IdGenerator::hex() is random, so insertion
        // order disagrees with id-descending order and this asserts the tiebreaker
        // is load-bearing: drop `, id DESC` from the store and this fails.
        $expected = $ids;
        rsort($expected);
        expect($ids)->toBe($expected);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('count ignores limit and offset but honours filters', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        for ($i = 0; $i < 5; $i++) {
            $f['storage']->logAudit($sessionId, 'exec', ['n' => $i], 'auto_approved');
        }
        $f['storage']->logAudit($sessionId, 'exec', ['n' => 99], 'blocked');

        expect($f['store']->count(new AuditLogQuery(limit: 2)))->toBe(6);
        expect($f['store']->count(new AuditLogQuery(action: 'blocked')))->toBe(1);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('a row with undecodable arguments falls back rather than throwing', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $id = $f['storage']->logAudit($sessionId, 'exec', ['ok' => true], 'auto_approved');

        $f['storage']->getPdo()
            ->prepare('UPDATE audit_log SET arguments = :a WHERE id = :id')
            ->execute(['a' => 'not json at all', 'id' => $id]);

        $rows = $f['store']->query(new AuditLogQuery());

        expect($rows[0]['arguments'])->toBe(['_raw' => 'not json at all']);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});

test('fromParams clamps limit and offset into range', function (): void {
    expect(AuditLogQuery::fromParams([])->limit)->toBe(100);
    expect(AuditLogQuery::fromParams(['limit' => '9999'])->limit)->toBe(500);
    expect(AuditLogQuery::fromParams(['limit' => '0'])->limit)->toBe(1);
    expect(AuditLogQuery::fromParams(['limit' => '-5'])->limit)->toBe(1);
    expect(AuditLogQuery::fromParams(['offset' => '-3'])->offset)->toBe(0);
    expect(AuditLogQuery::fromParams(['offset' => '25'])->offset)->toBe(25);
});

test('fromParams rejects a malformed timestamp', function (): void {
    expect(fn () => AuditLogQuery::fromParams(['after' => 'not-a-date']))
        ->toThrow(InvalidArgumentException::class);
});

test('fromParams accepts ISO-8601 boundaries and applies inclusive/exclusive semantics', function (): void {
    $f = auditStoreFixture();

    try {
        $sessionId = $f['storage']->createSession('orchestrator', 'test/model');
        $id = $f['storage']->logAudit($sessionId, 'exec', ['n' => 1], 'auto_approved');

        $stmt = $f['storage']->getPdo()->prepare('SELECT created_at FROM audit_log WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $createdAt = (string) $stmt->fetchColumn();

        // Second row, forced to a timestamp clearly before the boundary so it is a
        // genuine negative control: the `after` filter must EXCLUDE it. Without this
        // row a one-row fixture would keep `after` green even if the filter never
        // reached the WHERE clause.
        $earlierId = $f['storage']->logAudit($sessionId, 'exec', ['n' => 0], 'auto_approved');
        $earlierAt = date('c', strtotime($createdAt) - 3600);
        $f['storage']->getPdo()
            ->prepare('UPDATE audit_log SET created_at = :ts WHERE id = :id')
            ->execute(['ts' => $earlierAt, 'id' => $earlierId]);

        // `after` is inclusive of the boundary and excludes the earlier row: only
        // the boundary row matches. Drop the `after` condition from the store's
        // WHERE builder and this returns both rows, failing the count and id checks.
        $afterRows = $f['store']->query(AuditLogQuery::fromParams(['after' => $createdAt]));
        expect($afterRows)->toHaveCount(1);
        expect($afterRows[0]['id'])->toBe($id);

        // `before` is exclusive of the boundary: the boundary row does not match,
        // but the earlier row does — proving the boundary timestamp is excluded.
        $beforeRows = $f['store']->query(AuditLogQuery::fromParams(['before' => $createdAt]));
        expect($beforeRows)->toHaveCount(1);
        expect($beforeRows[0]['id'])->toBe($earlierId);
    } finally {
        $f['storage'] = null;
        cleanupSqliteTestDb($f['dbPath']);
    }
});
