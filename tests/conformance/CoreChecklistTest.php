<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tests\Conformance;

use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
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
    'CORE-15: session.model nullable; null = inherit makes precedence computable',
    'CORE-16: circuit-breaker + dispatch state are persisted fields',
    'CORE-17: deleting a session cascade-stops any non-terminal loop using it',
    'CORE-18: list operations paginate + declare a default sort',
    'CORE-19: session carries an opaque workspace; agents/loop-stages/child-runs are rooted there and inherit it',
    'CORE-20: loop definitions carry no on_question; loops never block on a question',
    'CORE-21: loop stages thread prior-stage output + inherit the session workspace',
    'CORE-22: artifact_required is persona-gated; a def requiring it on a no-artifacts instance is rejected 422 at loop creation',
    'CORE-23: a stage whose role/definition is undefined at dispatch resolves blocked + Critical',
    'CORE-24: the Question object is typed; status is a closed set',
    'CORE-25: the Artifact object is typed; session_id is required',
    'CORE-26: skills carry a typed origin (closed kind); imported/script skills are untrusted-by-default',
    'CORE-27: skills declare execution.kind (instruction vs script) + requires; discovery exposes it',
    'CORE-28: ChildRun is a typed first-class object; status is a closed set; no nesting',
    'CORE-29: spawn is a gated Core op (full-access, top-level only); child runs stream + export',
    'CORE-30: extension is a declared gradient; host toolkits are declared in InstanceInfo; personas are a closed set',
    'CORE-31: the mcp persona pins the integration contract (namespacing/gating/budget/trust/transports); transports are a closed set',
    'CORE-32: vision (image understanding) is an access-gated built-in; generation is extension-only',
    'CORE-33: the ScheduledTask object is typed; status/action.kind are closed sets',
    'CORE-34: turn carries actor_persona_id; group-session turns require it (422 if absent)',
    'CORE-35: InstanceInfo MAY carry per-persona versions (semver); docs content is impl-defined',

    // 0.4 binding-interop MUSTs (CORE-36..CORE-59).
    'CORE-36: responses/events are wire-tolerant: consumers MUST NOT reject unknown fields/enums',
    'CORE-37: create bodies are authoring-shaped; server-owned fields (id/version/timestamps) are rejected 422',
    'CORE-38: role/loop-definition PUT distinguishes create (If-None-Match:*) from update (If-Match:v); persisted rows require version',
    'CORE-39: InstanceInfo.personas is an open string set; discovery MUST NOT reject an unknown persona',
    'CORE-40: every operation\'s documented error codes come from the closed catalog via reusable responses; coverage is complete',
    'CORE-41: SSE error events carry a code from the closed catalog',
    'CORE-42: content is a typed object addressed by an opaque ref; sha256 identity is required',
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
