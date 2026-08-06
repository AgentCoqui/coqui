# Core Conformance Checklist (spec 0.3, extended for 0.4 binding-interop MUSTs)

Each row is a testable assertion. "Vector/Check" points to the artifact that proves it.

| # | MUST | Source | Vector / Check |
|---|------|--------|----------------|
| CORE-1 | A persona's `allowed_roles` includes `orchestrator`. | Foundation §4.3 | invalid/persona.no-orchestrator.json rejected |
| CORE-2 | Enums are closed; out-of-set values are rejected. | Foundation §7 | invalid/session.bad-status.json rejected |
| CORE-3 | Timestamps are RFC-3339 UTC (Z). | Foundation §7 | invalid/turn.bad-timestamp.json rejected |
| CORE-4 | Error payloads carry a code from the closed catalog | Api.md §2 | invalid/error.bad-code.json rejected |
| CORE-5 | SSE frames carry a resumable id; reconnect replays after it | events.yaml, Api.md §4 | valid/sse-turn-frame.json |
| CORE-6 | The loop live snapshot is fully typed | schema/loop-live.json | valid/loop-live.json |
| CORE-7 | Verdict is typed; approval requires both flags + no Critical/Important | schema/verdict.json | valid+invalid verdict vectors |
| CORE-8 | termination_condition.value shape matches its type | schema/loop-definition.json | loopdef.* vectors |
| CORE-9 | PATCH bodies are typed + reject unknown fields | persona-patch/memory-patch | vectors |
| CORE-10 | Mutable Core objects carry version; stale writes 409 | Foundation §7 | (behavioral — checklist assertion) |
| CORE-11 | Instances expose a typed model catalog (id, context_window, tokenizer_hint) | schema/model.json | model vectors |
| CORE-12 | Budget tiering + pinned security normative; shed order is SHOULD + inspectable | Budgeting.md §3 | (doc assertion) |
| CORE-13 | Internal collections (jobs/job_events/audit_records) are typed for export validation | schema/*.json | vectors |
| CORE-14 | Export envelope types every Core+internal collection; import is fail-closed + FK-consistent | schema/export.json, Data.md §7 | roundtrip.mjs |
| CORE-15 | session.model nullable; null = inherit makes precedence computable | Personas §5 | session.inherits-model.json |
| CORE-16 | Circuit-breaker + dispatch state are persisted fields | schema/loop.json | valid/loop.json |
| CORE-17 | Deleting a session cascade-stops any non-terminal loop using it | Data.md §3 | (behavioral assertion) |
| CORE-18 | List operations paginate + declare a default sort | openapi.yaml | (lint) |
| CORE-19 | Session carries an opaque `workspace`; agents/loop-stages/child-runs are rooted there and inherit it | Foundation §4.4 | valid/session.workspace.json + (behavioral) |
| CORE-20 | Loop definitions carry no `on_question`; loops never block on a question | Loops.md §8 | invalid/loopdef.on-question.json rejected |
| CORE-21 | Loop stages thread prior-stage output + inherit the session workspace | Loops.md §3/§7 | (behavioral assertion) |
| CORE-22 | `artifact_required` is profile-gated; a def requiring it on a no-`artifacts` instance is rejected 422 at loop creation | Loops.md §4 | (behavioral assertion) |
| CORE-23 | A stage whose role/definition is undefined at dispatch resolves `blocked` + Critical | Loops.md §4 | (behavioral assertion) |
| CORE-24 | The Question object is typed; status is a closed set | schema/question.json | valid+invalid question vectors |
| CORE-25 | The Artifact object is typed; `session_id` is required | schema/artifact.json | valid+invalid artifact vectors |
| CORE-26 | Skills carry a typed `origin` (closed `kind`); imported/script skills are untrusted-by-default | schema/skill.json, Capabilities §5 | valid+invalid skill vectors |
| CORE-27 | Skills declare `execution.kind` (instruction vs script) + `requires`; discovery exposes it | schema/skill.json | valid/skill.imported-script.json |
| CORE-28 | ChildRun is a typed first-class object; status is a closed set; no nesting | schema/child-run.json, Foundation §4.6a | valid+invalid child-run vectors |
| CORE-29 | Spawn is a gated Core op (full-access, top-level only); child runs stream + export | openapi.yaml, Api.md §5a | (lint + roundtrip) |
| CORE-30 | Extension is a declared gradient; host toolkits are declared in InstanceInfo; profiles are a closed set | Capabilities §1, schema/instance-info.json | valid/instance-info.host-toolkits.json + invalid/instance-info.bad-profile.json |
| CORE-31 | The `mcp` profile pins the integration contract (namespacing/gating/budget/trust/transports); transports are a closed set | Mcp.md, schema/instance-info.json | valid/instance-info.mcp.json + invalid/instance-info.mcp-bad-transport.json |
| CORE-32 | `vision` (image understanding) is an access-gated built-in; generation is extension-only | Capabilities §2 | (doc assertion) |
| CORE-33 | The ScheduledTask object is typed; status/action.kind are closed sets | schema/scheduled-task.json | valid+invalid scheduled-task vectors |
| CORE-34 | Turn carries `actor_persona_id`; group-session turns require it (422 if absent) | schema/turn.json, Api §4 | valid/turn.group.json + (behavioral) |
| CORE-35 | InstanceInfo MAY carry per-profile versions (semver); `docs` content is impl-defined | schema/instance-info.json, Capabilities §2 | valid/instance-info.profile-versions.json + invalid/instance-info.bad-profile-version.json |

## 0.4 binding-interop MUSTs (CORE-36…CORE-59)

Added in phase 0.4. Each row is proven by a real vector under `conformance/vectors/**` or by a `test`-chain validator (`validate:leniency`, `validate:errors`, `validate:profile-ops`, `validate:operations`, `validate:roundtrip`) or an `openapi.yaml`/doc assertion checked by `validate:openapi`.

| # | MUST | Source | Vector / Check |
|---|------|--------|----------------|
| CORE-36 | Responses/events are wire-tolerant: consumers MUST NOT reject unknown fields/enums | Api.md, Bindings.md | validate:leniency + lenient/persona.future-field.json |
| CORE-37 | Create bodies are authoring-shaped; server-owned fields (id/version/timestamps) are rejected 422 | schema/persona.create.json, schema/memory.create.json | valid/persona.create.json + valid/memory.create.json + invalid/persona.create.server-field.json |
| CORE-38 | Role/loop-definition PUT distinguishes create (If-None-Match:*) from update (If-Match:v); persisted rows require `version` | schema/role.put.json, schema/loop-definition.put.json | valid/role.put.json + valid/loop-definition.put.json + invalid/role.no-version.json |
| CORE-39 | `InstanceInfo.profiles` is an open string set; discovery MUST NOT reject an unknown profile | schema/instance-info.json, Capabilities | valid/instance-info.future-profile.json |
| CORE-40 | Every operation's documented error codes come from the closed catalog via reusable responses; coverage is complete | schema/error.json, Api.md | validate:errors (error-coverage.json) + valid/error.content-not-found.json |
| CORE-41 | SSE error events carry a code from the closed catalog | schema/sse-error.json | valid/sse-error.json + invalid/sse-error.bad-code.json |
| CORE-42 | Content is a typed object addressed by an opaque ref; `sha256` identity is required | schema/content.json, Data.md §7 | valid/content.json + invalid/content.no-sha.json |
| CORE-43 | Messages carry typed `attachments[]` of `{content_ref, mime_type}` | schema/message.json | valid/message.with-attachments.json |
| CORE-44 | Content ops (`putContent`/`getContent`) are bound (multipart/binary upload + Range download) | openapi.yaml `/content`, `/content/{content_ref}` | validate:openapi (putContent/getContent operations) |
| CORE-45 | Export types a `content` collection; import round-trips it (preserve+remap) | schema/export.json, Data.md §7 | validate:roundtrip + valid/export.roundtrip.json (content[]) |
| CORE-46 | Discovery `InstanceInfo` types `auth`/`limits`/`api`/`builtin_toolkits`; auth scheme is a closed set | schema/instance-info.json, Capabilities | valid/instance-info.full.json + invalid/instance-info.bad-auth-scheme.json |
| CORE-47 | `x-profile` operations map cleanly across both bindings (HTTP + in_process) | operations.yaml, openapi.yaml | validate:profile-ops |
| CORE-48 | `ask_user` answer is a Core path (`submitTurnAnswer`); SSE question frames carry `question_id` | openapi.yaml, schema/sse-question.json | valid/sse-question.json + invalid/sse-question.no-question-id.json |
| CORE-49 | Question format is rich (multi-select) with a typed option shape | schema/question.json, Questions.md | valid/question.multi-select.json + invalid/question.malformed-option.json |
| CORE-50 | `scheduled_task.action` is a discriminated union keyed by `kind`; a loop action requires a definition | schema/scheduled-task.json, Schedules.md | valid/scheduled-task.turn.json + invalid/scheduled-task.loop-no-definition.json |
| CORE-51 | SSE frames are typed per channel; unknown event shapes are rejected | schema/sse-turn-event.json, schema/sse-loop-event.json, schema/sse-childrun-event.json | valid/sse-turn-event.token.json + valid/sse-turn-event.done.json + invalid/sse-turn-event.unknown-shape.json |
| CORE-52 | SSE frame `id` is a string cursor; a numeric id is rejected | schema/sse-frame.json | valid/sse-turn-frame.json + invalid/sse-frame.numeric-id.json |
| CORE-53 | Creators accept an `Idempotency-Key` request header for dedup | openapi.yaml `#/components/parameters/IdempotencyKey` | validate:openapi (IdempotencyKey parameter on creators) |
| CORE-54 | Sessions are authorable via PATCH (clear model→null, set workspace); empty patch is rejected | schema/session-patch.json | valid/session-patch.clear-model.json + valid/session-patch.workspace.json + invalid/session-patch.empty.json |
| CORE-55 | Budget observability is typed (`GET /sessions/{id}/budget` breakdown) | schema/budget-breakdown.json, Budgeting.md | valid/budget-breakdown.json |
| CORE-56 | Import supports `mode=preserve|remap`; remap atomically rewrites every FK | schema/export.json, Data.md §7 | validate:roundtrip (preserve + remap) |
| CORE-57 | In-process binding is normatively specified; thrown errors are typed with a catalog code | Bindings.md, schema/error-thrown.json | valid/error-thrown.json + invalid/error-thrown.bad-code.json |
| CORE-58 | Single-vs-list response cardinality agrees across in_process, operations.yaml, and openapi | operations.yaml, openapi.yaml | validate:operations (cross-catalog cardinality) |
| CORE-59 | Nullable timestamps are RFC-3339 UTC (Z); a non-Z offset is rejected per object family | common.json#/$defs/NullableTimestamp | invalid/turn.completed-offset.json + invalid/loop.last-activity-offset.json + invalid/loop-iteration.started-offset.json + invalid/loop-stage.completed-offset.json + invalid/job.started-offset.json |
