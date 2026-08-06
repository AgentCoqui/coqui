<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Exception\RequestBodyException;
use CoquiBot\Coqui\Import\ImportMode;
use CoquiBot\Coqui\Import\ImportService;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;
use CoquiBot\Coqui\Tests\Conformance\Support\ExportEnvelopeFixture;

/**
 * CORE-56 (part 1): the in-process ImportService replays an export envelope's
 * DB-backed collections in FK order under preserve mode, keeping original ids.
 */

/**
 * Bring every DB-backed table into existence on a fresh store, then hand back the
 * ImportService bound to its connection.
 */
function freshImportTarget(string $dbPath, string $workspace): array
{
    $storage = new SessionStorage($dbPath);
    $pdo = $storage->pdo();
    new LoopStore($pdo);
    new ScheduleStore($pdo);
    new SkillLifecycleStore($pdo);
    new ArtifactStore($pdo, new ArtifactFileService($workspace));

    return [$storage, new ImportService($pdo, new ConformanceValidator())];
}

it('CORE-56: a preserve import reconstructs the DB-backed export graph with ids preserved', function () {
    $srcDb = sys_get_temp_dir() . '/coqui-import-src-' . bin2hex(random_bytes(8)) . '.db';
    $dstDb = sys_get_temp_dir() . '/coqui-import-dst-' . bin2hex(random_bytes(8)) . '.db';
    $srcWs = sys_get_temp_dir() . '/coqui-import-src-ws-' . bin2hex(random_bytes(6));
    $dstWs = sys_get_temp_dir() . '/coqui-import-dst-ws-' . bin2hex(random_bytes(6));
    mkdir($srcWs, 0775, true);
    mkdir($dstWs, 0775, true);

    try {
        // ── source: seed one row per DB-backed collection, then export it ────
        $source = new SessionStorage($srcDb);
        new LoopStore($source->pdo());
        new ScheduleStore($source->pdo());
        new SkillLifecycleStore($source->pdo());
        ExportEnvelopeFixture::seed($source, $srcWs);
        $envelope = ExportEnvelopeFixture::assemble($source);

        // Sanity: the source export carries every DB-backed collection.
        expect($envelope)->toHaveKeys([
            'personas', 'sessions', 'session_members', 'turns', 'content', 'messages',
            'skills', 'loops', 'loop_iterations', 'loop_stages', 'child_runs',
            'questions', 'artifacts', 'scheduled_tasks',
        ]);

        // ── import into a fresh store, then re-export it ─────────────────────
        [$target, $import] = freshImportTarget($dstDb, $dstWs);
        $result = $import->import($envelope, ImportMode::Preserve);

        $reassembled = ExportEnvelopeFixture::assemble($target);

        // The graph roundtrips byte-for-byte (ids preserved, references intact).
        expect(ExportEnvelopeFixture::canonical($reassembled))
            ->toBe(ExportEnvelopeFixture::canonical($envelope));

        // Per-collection insert accounting (session_members is the owner-only join,
        // which is carried by sessions.persona_id, so it contributes no extra rows).
        expect($result->inserted('personas'))->toBe(1);
        expect($result->inserted('sessions'))->toBe(1);
        expect($result->inserted('turns'))->toBe(1);
        expect($result->inserted('content'))->toBe(1);
        expect($result->inserted('messages'))->toBe(1);
        expect($result->inserted('loops'))->toBe(1);
        expect($result->inserted('loop_iterations'))->toBe(1);
        expect($result->inserted('loop_stages'))->toBe(1);
        expect($result->inserted('child_runs'))->toBe(1);
        expect($result->inserted('questions'))->toBe(1);
        expect($result->inserted('artifacts'))->toBe(1);
        expect($result->inserted('scheduled_tasks'))->toBe(1);
        expect($result->inserted('session_members'))->toBe(0);
    } finally {
        cleanupSqliteTestDb($srcDb);
        cleanupSqliteTestDb($dstDb);
        cleanupTestTree($srcWs);
        cleanupTestTree($dstWs);
    }
});

it('CORE-56: preserve mode rejects a primary-key collision with `conflict`', function () {
    $srcDb = sys_get_temp_dir() . '/coqui-import-src-' . bin2hex(random_bytes(8)) . '.db';
    $dstDb = sys_get_temp_dir() . '/coqui-import-dst-' . bin2hex(random_bytes(8)) . '.db';
    $srcWs = sys_get_temp_dir() . '/coqui-import-src-ws-' . bin2hex(random_bytes(6));
    $dstWs = sys_get_temp_dir() . '/coqui-import-dst-ws-' . bin2hex(random_bytes(6));
    mkdir($srcWs, 0775, true);
    mkdir($dstWs, 0775, true);

    try {
        $source = new SessionStorage($srcDb);
        new LoopStore($source->pdo());
        new ScheduleStore($source->pdo());
        new SkillLifecycleStore($source->pdo());
        ExportEnvelopeFixture::seed($source, $srcWs);
        $envelope = ExportEnvelopeFixture::assemble($source);

        [, $import] = freshImportTarget($dstDb, $dstWs);
        $import->import($envelope, ImportMode::Preserve);

        // Re-importing the SAME ids must collide, not silently duplicate.
        try {
            $import->import($envelope, ImportMode::Preserve);
            throw new RuntimeException('expected a conflict rejection');
        } catch (RequestBodyException $e) {
            expect($e->errorCode)->toBe(ApiErrorCode::CONFLICT);
            expect($e->status)->toBe(409);
        }

        // The failed second import rolled back — the store still has exactly one persona.
        $target = new SessionStorage($dstDb);
        $count = (int) $target->pdo()->query('SELECT COUNT(*) FROM personas')->fetchColumn();
        expect($count)->toBe(1);
    } finally {
        cleanupSqliteTestDb($srcDb);
        cleanupSqliteTestDb($dstDb);
        cleanupTestTree($srcWs);
        cleanupTestTree($dstWs);
    }
});

it('CORE-56: preserve rollback is atomic across collections — an EARLIER insert is undone when a LATER collection collides', function () {
    // The Task-10 collision test re-imports the same envelope, so `personas` (the
    // FIRST insert) collides immediately — nothing earlier exists to roll back. This
    // test gives atomicity real teeth: it pre-seeds ONLY a colliding session in the
    // target (personas stays empty), so `personas` inserts NEW and `sessions` — a
    // LATER collection — collides. A genuine transaction must undo the new persona.
    $srcDb = sys_get_temp_dir() . '/coqui-import-src-' . bin2hex(random_bytes(8)) . '.db';
    $dstDb = sys_get_temp_dir() . '/coqui-import-dst-' . bin2hex(random_bytes(8)) . '.db';
    $srcWs = sys_get_temp_dir() . '/coqui-import-src-ws-' . bin2hex(random_bytes(6));
    $dstWs = sys_get_temp_dir() . '/coqui-import-dst-ws-' . bin2hex(random_bytes(6));
    mkdir($srcWs, 0775, true);
    mkdir($dstWs, 0775, true);

    try {
        $source = new SessionStorage($srcDb);
        new LoopStore($source->pdo());
        new ScheduleStore($source->pdo());
        new SkillLifecycleStore($source->pdo());
        ExportEnvelopeFixture::seed($source, $srcWs);
        $envelope = ExportEnvelopeFixture::assemble($source);

        [$target, $import] = freshImportTarget($dstDb, $dstWs);

        // Pre-seed ONLY a session whose id collides with the envelope's session.
        // personas is left empty on purpose, so it will insert cleanly before the
        // later sessions insert hits the collision.
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $target->pdo()
            ->prepare('INSERT INTO sessions (id, model_role, created_at, updated_at) VALUES (:id, :role, :c, :u)')
            ->execute([':id' => $envelope['sessions'][0]['id'], ':role' => 'orchestrator', ':c' => $now, ':u' => $now]);

        expect((int) $target->pdo()->query('SELECT COUNT(*) FROM personas')->fetchColumn())->toBe(0);

        try {
            $import->import($envelope, ImportMode::Preserve);
            throw new RuntimeException('expected a conflict rejection on the later sessions insert');
        } catch (RequestBodyException $e) {
            expect($e->errorCode)->toBe(ApiErrorCode::CONFLICT);
        }

        // Atomicity: the persona inserted BEFORE the collision was rolled back. The
        // target still holds only the one pre-seeded session and zero personas.
        expect((int) $target->pdo()->query('SELECT COUNT(*) FROM personas')->fetchColumn())->toBe(0);
        expect((int) $target->pdo()->query('SELECT COUNT(*) FROM sessions')->fetchColumn())->toBe(1);
    } finally {
        cleanupSqliteTestDb($srcDb);
        cleanupSqliteTestDb($dstDb);
        cleanupTestTree($srcWs);
        cleanupTestTree($dstWs);
    }
});

it('CORE-56: remap mints fresh ids and rewrites FKs into them; the id-map exposes the rewrite', function () {
    $srcDb = sys_get_temp_dir() . '/coqui-import-src-' . bin2hex(random_bytes(8)) . '.db';
    $dstDb = sys_get_temp_dir() . '/coqui-import-dst-' . bin2hex(random_bytes(8)) . '.db';
    $srcWs = sys_get_temp_dir() . '/coqui-import-src-ws-' . bin2hex(random_bytes(6));
    $dstWs = sys_get_temp_dir() . '/coqui-import-dst-ws-' . bin2hex(random_bytes(6));
    mkdir($srcWs, 0775, true);
    mkdir($dstWs, 0775, true);

    try {
        $source = new SessionStorage($srcDb);
        new LoopStore($source->pdo());
        new ScheduleStore($source->pdo());
        new SkillLifecycleStore($source->pdo());
        ExportEnvelopeFixture::seed($source, $srcWs);
        ExportEnvelopeFixture::seedGroupMember($source);
        $envelope = ExportEnvelopeFixture::assemble($source);

        [$target, $import] = freshImportTarget($dstDb, $dstWs);
        $result = $import->import($envelope, ImportMode::Remap);

        // The id-map carries a fresh id for every id-keyed collection PK.
        $sessionMap = $result->idMap['sessions'];
        $personaMap = $result->idMap['personas'];
        foreach ($sessionMap as $old => $new) {
            expect($new)->not->toBe($old);
        }

        // The rewrite is applied in the store: the turn's session_id column equals its
        // OWN session's NEW id (not the old one), proving the FK followed the PK.
        $oldTurnSessionId = $envelope['turns'][0]['session_id'];
        $turnRow = $target->pdo()->query('SELECT session_id, actor_persona_id FROM turns LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        expect($turnRow['session_id'])->toBe($sessionMap[$oldTurnSessionId]);
        expect(in_array($turnRow['session_id'], array_values($sessionMap), true))->toBeTrue();
        expect($turnRow['actor_persona_id'])->toBe($personaMap[ExportEnvelopeFixture::PERSONA_ID]);

        // The non-owner group member join was rewritten to the new session + persona.
        $memberRow = $target->pdo()
            ->query('SELECT session_id, persona_id FROM session_group_members LIMIT 1')
            ->fetch(PDO::FETCH_ASSOC);
        expect($memberRow)->not->toBeFalse();
        expect(in_array($memberRow['session_id'], array_values($sessionMap), true))->toBeTrue();
        expect(in_array($memberRow['persona_id'], array_values($personaMap), true))->toBeTrue();

        // The message attachment's content_ref is content-addressed and preserved —
        // it still resolves to the (un-remapped) content row.
        $refRow = $target->pdo()->query('SELECT ma.content_ref FROM message_attachments ma LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $contentRefs = $target->pdo()->query('SELECT content_ref FROM content')->fetchAll(PDO::FETCH_COLUMN);
        expect(in_array($refRow['content_ref'], $contentRefs, true))->toBeTrue();
    } finally {
        cleanupSqliteTestDb($srcDb);
        cleanupSqliteTestDb($dstDb);
        cleanupTestTree($srcWs);
        cleanupTestTree($dstWs);
    }
});

it('CORE-56: a malformed collection item is rejected before anything is written', function () {
    $dstDb = sys_get_temp_dir() . '/coqui-import-dst-' . bin2hex(random_bytes(8)) . '.db';
    $dstWs = sys_get_temp_dir() . '/coqui-import-dst-ws-' . bin2hex(random_bytes(6));
    mkdir($dstWs, 0775, true);

    try {
        [$target, $import] = freshImportTarget($dstDb, $dstWs);

        // A persona missing every required field fails persona.json.
        $envelope = [
            'protocol_version' => '0.5.0',
            'exported_at' => '2026-07-28T00:00:03Z',
            'personas' => [['id' => 'persona_broken']],
        ];

        try {
            $import->import($envelope, ImportMode::Preserve);
            throw new RuntimeException('expected a validation rejection');
        } catch (RequestBodyException $e) {
            expect($e->errorCode)->toBe(ApiErrorCode::VALIDATION_ERROR);
        }

        // Fail-closed: nothing was persisted.
        $count = (int) $target->pdo()->query('SELECT COUNT(*) FROM personas')->fetchColumn();
        expect($count)->toBe(0);
    } finally {
        cleanupSqliteTestDb($dstDb);
        cleanupTestTree($dstWs);
    }
});
