<?php

declare(strict_types=1);

use CoquiBot\Coqui\Export\AuditRecordProducer;
use CoquiBot\Coqui\Export\JobEventProducer;
use CoquiBot\Coqui\Export\JobProducer;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

/**
 * CORE-13: the internal (diagnostics-only) collections — jobs (background_tasks),
 * job_events (task_events), audit_records (audit_log) — are typed and produce
 * schema-valid instances for export validation.
 */

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-internal-producer-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

it('CORE-13: a background_tasks row produces a schema-valid job.json', function () {
    $taskId = $this->storage->createTask(
        sessionId: $this->sessionId,
        prompt: 'Run the export validation over the internal collections.',
        role: 'orchestrator',
        title: 'Harden the export validator',
        maxIterations: 25,
    );
    $this->storage->updateTaskStatus($taskId, 'running');

    $wire = JobProducer::toWire($this->storage->getTask($taskId));

    $v = new ConformanceValidator();
    expect($v->isValid('job.json', $wire))->toBeTrue($v->errorText('job.json', $wire));

    expect($wire['id'])->toBe($taskId);
    expect($wire['session_id'])->toBe($this->sessionId);
    expect($wire['status'])->toBeIn(['pending', 'running', 'completed', 'failed', 'cancelled']);
    // date('c')-persisted timestamps are normalized to Z (never a +00:00 offset).
    expect($wire['created_at'])->toMatch('/Z$/');
    expect($wire['started_at'])->toMatch('/Z$/');
    expect($wire['metadata'])->toBeNull();
})->group('conformance');

it('CORE-13: a task_events row produces a schema-valid job-event.json with an INTEGER id', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'Do the work.');
    $this->storage->appendTaskEvent($taskId, 'iteration_started', ['iteration' => 1]);

    $events = $this->storage->getTaskEvents($taskId);
    // getTaskEvents omits task_id; supply the job reference the schema requires.
    $row = $events[0] + ['job_id' => $taskId];
    $wire = JobEventProducer::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('job-event.json', $wire))->toBeTrue($v->errorText('job-event.json', $wire));

    // id is an autoincrement INTEGER (not an opaque Id string).
    expect($wire['id'])->toBeInt();
    expect($wire['job_id'])->toBe($taskId);
    expect($wire['event_type'])->toBe('iteration_started');
    expect($wire['data'])->toBeObject();
    expect($wire['data']->iteration)->toBe(1);
    expect($wire['created_at'])->toMatch('/Z$/');
})->group('conformance');

it('CORE-13: an audit_log row produces a schema-valid audit-record.json with object arguments', function () {
    $auditId = $this->storage->logAudit(
        $this->sessionId,
        'shell_exec',
        ['command' => 'rm -rf ./build'],
        'approved',
        'operator confirmed',
    );

    $rows = $this->storage->getAuditLog($this->sessionId);
    $row = array_values(array_filter($rows, static fn(array $r): bool => $r['id'] === $auditId))[0];
    $wire = AuditRecordProducer::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('audit-record.json', $wire))->toBeTrue($v->errorText('audit-record.json', $wire));

    expect($wire['id'])->toBe($auditId);
    expect($wire['tool_name'])->toBe('shell_exec');
    expect($wire['arguments'])->toBeObject();
    expect($wire['action'])->toBe('approved');
    // The operational turn_id column is dropped (additionalProperties:false).
    expect($wire)->not->toHaveKey('turn_id');
    expect($wire['created_at'])->toMatch('/Z$/');
})->group('conformance');

it('CORE-13: a null-session audit record keeps session_id nullable', function () {
    $auditId = $this->storage->logAudit(null, 'web_fetch', ['url' => 'https://example.test'], 'denied');

    $rows = $this->storage->getAuditLog();
    $row = array_values(array_filter($rows, static fn(array $r): bool => $r['id'] === $auditId))[0];
    $wire = AuditRecordProducer::toWire($row);

    $v = new ConformanceValidator();
    expect($v->isValid('audit-record.json', $wire))->toBeTrue($v->errorText('audit-record.json', $wire));
    expect($wire['session_id'])->toBeNull();
    expect($wire['reason'])->toBeNull();
})->group('conformance');
