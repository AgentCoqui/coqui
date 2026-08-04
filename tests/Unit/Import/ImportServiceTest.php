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
