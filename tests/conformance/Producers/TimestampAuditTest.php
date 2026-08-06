<?php

declare(strict_types=1);

use CoquiBot\Coqui\Export\AuditRecordProducer;
use CoquiBot\Coqui\Export\JobEventProducer;
use CoquiBot\Coqui\Export\JobProducer;
use CoquiBot\Coqui\Export\MessageProducer;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * CORE-3 / CORE-59: every non-null timestamp a producer emits is RFC-3339 UTC (Z);
 * every nullable timestamp is null or Z — never a non-Z offset. This has teeth
 * because the underlying columns are persisted by `date('c')` (a `+00:00` offset),
 * so a producer that failed to normalize would emit an offset and fail here.
 */

const TS_Z = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?Z$/';

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-ts-audit-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-3: the underlying columns are stored as a non-Z offset (proves the audit has teeth)', function () {
    $jobId = $this->storage->createTask($this->sessionId, 'Work.');
    $stmt = $this->storage->pdo()->prepare('SELECT created_at FROM background_tasks WHERE id = ?');
    $stmt->execute([$jobId]);
    $raw = (string) $stmt->fetchColumn();

    // date('c') persists a +00:00 offset, NOT a Z — the producer must rewrite it.
    expect($raw)->not->toMatch(TS_Z);
    expect($raw)->toContain('+00:00');
})->group('conformance');

it('CORE-3/CORE-59: producer timestamps are Z; nullable timestamps are null or Z', function () {
    // A running job: created_at + started_at are set; completed_at + cancelled_at null.
    $jobId = $this->storage->createTask($this->sessionId, 'Work.', title: 'T');
    $this->storage->updateTaskStatus($jobId, 'running');
    $job = JobProducer::toWire($this->storage->getTask($jobId));

    $this->storage->appendTaskEvent($jobId, 'started', ['iteration' => 1]);
    $event = JobEventProducer::toWire($this->storage->getTaskEvents($jobId)[0] + ['job_id' => $jobId]);

    $auditId = $this->storage->logAudit($this->sessionId, 'shell_exec', ['command' => 'ls'], 'approved');
    $auditRow = array_values(array_filter($this->storage->getAuditLog($this->sessionId), static fn(array $r): bool => $r['id'] === $auditId))[0];
    $audit = AuditRecordProducer::toWire($auditRow);

    $messageId = $this->storage->addMessage($this->sessionId, 'assistant', 'hi', null, null, null, 'Caelum', 'orchestrator');
    $messageRow = array_values(array_filter($this->storage->getMessages($this->sessionId), static fn(array $r): bool => $r['id'] === $messageId))[0]
        + ['session_id' => $this->sessionId];
    $message = MessageProducer::toWire($messageRow);

    // Each producer's timestamp fields: [required Z fields], [nullable Z-or-null fields].
    $cases = [
        'job' => [$job, ['created_at'], ['started_at', 'completed_at', 'cancelled_at']],
        'job_event' => [$event, ['created_at'], []],
        'audit_record' => [$audit, ['created_at'], []],
        'message' => [$message, ['created_at'], []],
    ];

    $offsetPattern = '/[+-]\d{2}:?\d{2}$/';

    foreach ($cases as $label => [$wire, $required, $nullable]) {
        foreach ($required as $field) {
            expect($wire[$field])->toMatch(TS_Z, "{$label}.{$field} must be Z");
            expect($wire[$field])->not->toMatch($offsetPattern, "{$label}.{$field} must not carry a non-Z offset");
        }
        foreach ($nullable as $field) {
            if ($wire[$field] === null) {
                continue;
            }
            expect($wire[$field])->toMatch(TS_Z, "{$label}.{$field} must be null or Z");
            expect($wire[$field])->not->toMatch($offsetPattern, "{$label}.{$field} must not carry a non-Z offset");
        }
    }

    // Concrete nullability: a running job has not completed or cancelled.
    expect($job['completed_at'])->toBeNull();
    expect($job['cancelled_at'])->toBeNull();
    expect($job['started_at'])->toMatch(TS_Z);
})->group('conformance');
