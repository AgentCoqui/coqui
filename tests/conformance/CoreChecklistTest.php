<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

use CoquiBot\Coqui\Api\Handler\SessionHandler;
use CoquiBot\Coqui\Api\Handler\TurnHandler;
use CoquiBot\Coqui\Content\ContentStore;
use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Tests\Conformance\Support\ConformanceValidator;

// CAP 0.5.0 Core conformance scoreboard (conformance/checklist.md, CORE-1..CORE-59).
// Each row starts as a todo and is replaced by a real assertion in the phase that
// implements it. Todos do not fail the suite; they surface remaining work.

it('CORE-1: persona allowed_roles includes orchestrator', function () {
    $wire = PersonaSnapshotStore::toWire([
        'id' => '01J000000000000000000PERSONA',
        'name' => 'Caelum',
        'avatar' => json_encode(['tint' => '#2b3a52']),
        'model' => 'anthropic/claude-sonnet-4',
        'allowed_roles' => json_encode(['orchestrator']),
        'soul' => 'You are Caelum, a warm, precise research companion.',
        'backstory' => null,
        'context' => null,
        'preferences' => null,
        'version' => 1,
        'created_at' => '2026-07-28T00:00:00Z',
        'updated_at' => '2026-07-28T00:00:00Z',
    ]);
    $v = new ConformanceValidator();
    expect($v->isValid('persona.json', $wire))->toBeTrue($v->errorText('persona.json', $wire));
    expect($wire['allowed_roles'])->toContain('orchestrator');
})->group('conformance');

it('CORE-15: session.model is nullable; a stored null passes through as null (inherit)', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core15-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $id = $storage->createSession('orchestrator', null, 'caelum');
        $wire = SessionHandler::toWire($storage->getSession($id));

        $v = new ConformanceValidator();
        expect($v->isValid('session.json', $wire))->toBeTrue($v->errorText('session.json', $wire));
        expect(array_key_exists('model', $wire))->toBeTrue();
        expect($wire['model'])->toBeNull();
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-19: session carries an opaque workspace echoed verbatim; null = no rooted workspace', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core19-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $rooted = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $storage->pdo()
            ->prepare('UPDATE sessions SET workspace = :ws WHERE id = :id')
            ->execute(['ws' => '/srv/agents/ws-42', 'id' => $rooted]);
        $rootedWire = SessionHandler::toWire($storage->getSession($rooted));

        $unrooted = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $unrootedWire = SessionHandler::toWire($storage->getSession($unrooted));

        $v = new ConformanceValidator();
        expect($v->isValid('session.json', $rootedWire))->toBeTrue($v->errorText('session.json', $rootedWire));
        expect($rootedWire['workspace'])->toBe('/srv/agents/ws-42');
        expect($v->isValid('session.json', $unrootedWire))->toBeTrue($v->errorText('session.json', $unrootedWire));
        expect($unrootedWire['workspace'])->toBeNull();
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-34: turn carries actor_persona_id and a closed-set status', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core34-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        // A turn produced by a named member persona.
        $turnId = $storage->createTurn($sessionId, 'Draft the release notes.', 'anthropic/claude-sonnet-4', null, 'caelum');
        $wire = TurnHandler::toWire($storage->getTurn($turnId));

        $v = new ConformanceValidator();
        expect($v->isValid('turn.json', $wire))->toBeTrue($v->errorText('turn.json', $wire));
        expect(array_key_exists('actor_persona_id', $wire))->toBeTrue();
        expect($wire['actor_persona_id'])->toBe('caelum');   // carried, non-null
        expect($wire['status'])->toBeIn(['running', 'completed', 'failed', 'cancelled']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-28: ChildRun is a typed first-class object; status is a closed set; no nesting', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core28-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $storage->logChildRun(
            parentSessionId: $sessionId,
            role: 'coder',
            model: 'anthropic/claude-sonnet-4',
            prompt: 'Implement the fix.',
            status: 'completed',
            result: 'Fixed.',
            promptTokens: 80,
            completionTokens: 20,
            totalTokens: 100,
        );

        $runs = $storage->getChildRuns($sessionId);
        $wire = SessionHandler::childRunToWire($runs[0]);

        $v = new ConformanceValidator();
        expect($v->isValid('child-run.json', $wire))->toBeTrue($v->errorText('child-run.json', $wire));
        expect($wire['parent_session_id'])->toBe($sessionId);   // required, present
        expect($wire['status'])->toBeIn(['pending', 'running', 'completed', 'failed', 'cancelled']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-42: content is a typed object addressed by an opaque ref; sha256 identity is required', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core42-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $content = new ContentStore($storage->pdo());
        $bytes = "hello, content-addressing\n";
        $wire = $content->store($bytes, 'text/markdown');

        $v = new ConformanceValidator();
        expect($v->isValid('content.json', $wire))->toBeTrue($v->errorText('content.json', $wire));
        // sha256 identity is required and is the lowercase-hex digest of the bytes.
        expect($wire['sha256'])->toMatch('/^[0-9a-f]{64}$/');
        expect($wire['sha256'])->toBe(hash('sha256', $bytes));
        expect($wire['size'])->toBe(strlen($bytes));
        // content_ref is the opaque handle; the spec never interprets it.
        expect($wire['content_ref'])->not->toBe('');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-26: skills carry a typed origin (closed kind); imported/script skills are untrusted-by-default', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core26-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $skills = new SkillLifecycleStore($storage->pdo());
        $wire = $skills->upsertSkill(
            name: 'summarize',
            description: 'Condense long documents into a brief.',
            status: 'available',
            origin: ['kind' => 'builtin'],
            execution: ['kind' => 'instruction', 'requires' => []],
        );

        $v = new ConformanceValidator();
        expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));
        // origin is a typed object with a closed-set kind (never a bare array).
        expect($wire['origin'])->toBeObject();
        expect($wire['origin']->kind)->toBeIn(['builtin', 'local', 'imported']);
        expect($wire['origin']->kind)->toBe('builtin');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-27: skills declare execution.kind (instruction vs script) + requires; discovery exposes it', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core27-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $skills = new SkillLifecycleStore($storage->pdo());
        $wire = $skills->upsertSkill(
            name: 'deploy_site',
            description: 'Build and push the static site.',
            status: 'available',
            origin: ['kind' => 'imported'],
            execution: ['kind' => 'script', 'requires' => ['shell']],
        );

        $v = new ConformanceValidator();
        expect($v->isValid('skill.json', $wire))->toBeTrue($v->errorText('skill.json', $wire));
        // execution is a typed object; kind is a closed set; requires is a list.
        expect($wire['execution'])->toBeObject();
        expect($wire['execution']->kind)->toBeIn(['instruction', 'script']);
        expect($wire['execution']->kind)->toBe('script');
        expect($wire['execution']->requires)->toBe(['shell']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-33: the ScheduledTask object is typed; status/action.kind are closed sets', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core33-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $store = new ScheduleStore($storage->getPdo());
        $id = $store->create(
            name: 'daily-review',
            scheduleExpression: '0 9 * * 1-5',
            prompt: 'Review recent changes.',
            personaId: 'caelum',
        );
        $wire = ScheduleStore::toWire($store->get($id));

        $v = new ConformanceValidator();
        expect($v->isValid('scheduled-task.json', $wire))->toBeTrue($v->errorText('scheduled-task.json', $wire));
        // action is a typed object; kind is a closed set (turn|loop); coqui is turn-kind.
        expect($wire['action'])->toBeObject();
        expect($wire['action']->kind)->toBe('turn');
        expect($wire['action']->kind)->toBeIn(['turn', 'loop']);
        // status is a closed set derived from the enabled flag.
        expect($wire['status'])->toBeIn(['enabled', 'disabled']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-16: circuit-breaker + dispatch state are persisted columns, not blob keys', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core16-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $store = new LoopStore($storage->getPdo());
        $id = $store->createLoop(
            definitionName: 'code-review-loop',
            goal: 'Converge on a clean review.',
            configuration: ['name' => 'code-review-loop', 'max_rework_attempts' => 3],
            sessionId: $sessionId,
            personaId: '01J000000000000000000PERSONA',
            maxIterations: 5,
        );

        // The breaker + dispatch diagnostics round-trip through real columns.
        $store->setReworkAttempts($id, 1);
        $store->setDispatchState($id, 'dispatched');

        $wire = LoopStore::toWire($store->getLoop($id));

        $v = new ConformanceValidator();
        expect($v->isValid('loop.json', $wire))->toBeTrue($v->errorText('loop.json', $wire));
        expect($wire['rework_attempts'])->toBe(1);
        expect($wire['dispatch_state'])->toBe('dispatched');
        expect($wire['dispatch_state'])->toBeIn(['pending', 'dispatched']);
        expect($wire['origin'])->toBeIn(['conversation', 'headless']);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
})->group('conformance');

it('CORE-25: the Artifact object is typed; session_id is required', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-core25-' . bin2hex(random_bytes(8)) . '.db';
    $workspace = sys_get_temp_dir() . '/core25-' . bin2hex(random_bytes(6));
    mkdir($workspace, 0775, true);
    $storage = new SessionStorage($dbPath);

    try {
        $sessionId = $storage->createSession('orchestrator', 'anthropic/claude-sonnet-4', 'caelum');
        $store = new ArtifactStore($storage->getPdo(), new ArtifactFileService($workspace));
        $id = $store->create($sessionId, 'Design Doc', "# Title\nbody\n", 'document', createdBy: 'coder');

        $wire = ArtifactStore::toWire($store->get($id, $sessionId));

        $v = new ConformanceValidator();
        expect($v->isValid('artifact.json', $wire))->toBeTrue($v->errorText('artifact.json', $wire));

        // session_id is a required, non-empty owning-session reference.
        expect($wire['session_id'])->toBe($sessionId);
        expect($wire['session_id'])->not->toBe('');
        // Files-only content addressing surfaces as the opaque content_ref.
        expect($wire['content_ref'])->toStartWith('artifacts/document/');
        expect($wire['created_at'])->toMatch('/Z$/');
    } finally {
        cleanupSqliteTestDb($dbPath);
        exec('rm -rf ' . escapeshellarg($workspace));
    }
})->group('conformance');

$rows = [
    // Spec 0.3 Core MUSTs (CORE-2..CORE-35).
    'CORE-2: enums are closed; out-of-set values rejected',
    'CORE-3: timestamps are RFC-3339 UTC (Z)',
    'CORE-4: error payloads carry a code from the closed catalog',
    'CORE-5: SSE frames carry a resumable id; reconnect replays after it',
    'CORE-6: the loop live snapshot is fully typed',
    'CORE-7: verdict is typed; approval requires both flags + no Critical/Important',
    'CORE-8: termination_condition.value shape matches its type',
    'CORE-9: PATCH bodies are typed + reject unknown fields',
    'CORE-10: mutable Core objects carry version; stale writes 409',
    'CORE-11: instances expose a typed model catalog (id, context_window, tokenizer_hint)',
    'CORE-12: budget tiering + pinned security normative; shed order is SHOULD + inspectable',
    'CORE-13: internal collections (jobs/job_events/audit_records) are typed for export validation',
    'CORE-14: export envelope types every Core+internal collection; import is fail-closed + FK-consistent',
    'CORE-17: deleting a session cascade-stops any non-terminal loop using it',
    'CORE-18: list operations paginate + declare a default sort',
    'CORE-20: loop definitions carry no on_question; loops never block on a question',
    'CORE-21: loop stages thread prior-stage output + inherit the session workspace',
    'CORE-22: artifact_required is persona-gated; a def requiring it on a no-artifacts instance is rejected 422 at loop creation',
    'CORE-23: a stage whose role/definition is undefined at dispatch resolves blocked + Critical',
    'CORE-24: the Question object is typed; status is a closed set',
    'CORE-29: spawn is a gated Core op (full-access, top-level only); child runs stream + export',
    'CORE-30: extension is a declared gradient; host toolkits are declared in InstanceInfo; personas are a closed set',
    'CORE-31: the mcp persona pins the integration contract (namespacing/gating/budget/trust/transports); transports are a closed set',
    'CORE-32: vision (image understanding) is an access-gated built-in; generation is extension-only',
    'CORE-35: InstanceInfo MAY carry per-persona versions (semver); docs content is impl-defined',

    // 0.4 binding-interop MUSTs (CORE-36..CORE-59).
    'CORE-36: responses/events are wire-tolerant: consumers MUST NOT reject unknown fields/enums',
    'CORE-37: create bodies are authoring-shaped; server-owned fields (id/version/timestamps) are rejected 422',
    'CORE-38: role/loop-definition PUT distinguishes create (If-None-Match:*) from update (If-Match:v); persisted rows require version',
    'CORE-39: InstanceInfo.personas is an open string set; discovery MUST NOT reject an unknown persona',
    'CORE-40: every operation\'s documented error codes come from the closed catalog via reusable responses; coverage is complete',
    'CORE-41: SSE error events carry a code from the closed catalog',
    'CORE-43: messages carry typed attachments[] of {content_ref, mime_type}',
    'CORE-44: content ops (putContent/getContent) are bound (multipart/binary upload + Range download)',
    'CORE-45: export types a content collection; import round-trips it (preserve+remap)',
    'CORE-46: discovery InstanceInfo types auth/limits/api/builtin_toolkits; auth scheme is a closed set',
    'CORE-47: x-persona operations map cleanly across both bindings (HTTP + in_process)',
    'CORE-48: ask_user answer is a Core path (submitTurnAnswer); SSE question frames carry question_id',
    'CORE-49: question format is rich (multi-select) with a typed option shape',
    'CORE-50: scheduled_task.action is a discriminated union keyed by kind; a loop action requires a definition',
    'CORE-51: SSE frames are typed per channel; unknown event shapes are rejected',
    'CORE-52: SSE frame id is a string cursor; a numeric id is rejected',
    'CORE-53: creators accept an Idempotency-Key request header for dedup',
    'CORE-54: sessions are authorable via PATCH (clear model->null, set workspace); empty patch is rejected',
    'CORE-55: budget observability is typed (GET /sessions/{id}/budget breakdown)',
    'CORE-56: import supports mode=preserve|remap; remap atomically rewrites every FK',
    'CORE-57: in-process binding is normatively specified; thrown errors are typed with a catalog code',
    'CORE-58: single-vs-list response cardinality agrees across in_process, operations.yaml, and openapi',
    'CORE-59: nullable timestamps are RFC-3339 UTC (Z); a non-Z offset is rejected per object family',
];

foreach ($rows as $row) {
    test($row)->todo();
}
