# Phase 5 Status Report — §C new ops + profiles to CAP 0.5.0

**Phase:** 5 of 7 (CAP 0.5.0 conformance migration)
**Branch:** `feat/cap-0.5-conformance` (worktree `coqui-cap-migration`, unpushed)
**Range:** `db120b9..ac4944e` (17 commits: 1 plan doc + 12 tasks + 3 per-task fixes + 1 whole-branch fix)
**Status:** ✅ COMPLETE — whole-branch review returned *mergeable*; its single Important (I-1) fixed. No Critical, no open Important.

## Goal (met)

Close the remaining §C conformance rows — child-run operations, the questions Core answer path, the scheduled-task action union, vision built-in declaration, import roundtrip, typed thrown errors, and cross-binding operation cardinality — turning the **last 11 gate rows** green. The conformance checklist now has **zero `->todo()` rows**.

## Acceptance evidence

- **11 CORE rows flipped to real `it(...)->group('conformance')` assertions with teeth** (todos **11 → 0**): CORE-**2, 12, 29, 32, 47, 48, 49, 50, 56, 57, 58**. `grep '->todo()' CoreChecklistTest.php` → none.
- **Green throughout:** `composer test` = **2700 passed** / 1 skipped (Ollama integration) / 1 warning (pre-existing untouched `CatastrophicBlacklistTest`); `composer analyse` clean (415 files). Green after every task — never committed red. (The nondeterministic `ProcessSpawnerTest::isProcessAlive` environmental flake, an untouched subsystem, was disregarded per the never-red gate.)
- **Safety preserved:** `src/Config/CatastrophicBlacklist.php` + its test **byte-unchanged across all 17 commits** (`git log db120b9..ac4944e -- '*CatastrophicBlacklist*'` empty). Vendored `tests/conformance/spec/**` untouched (pinned `5dffc63`).
- **Closed error catalog:** `ApiErrorCode` unchanged at the 23-code `error.json` set; no off-catalog code emitted; 422 is always `validation_error` + status override.

## Adjudications honored (settled with the user before execution)

1. **CORE-29 child-run = sync-execute-then-report.** `spawnChildRun` runs the child synchronously via `ChildAgent`, records `running`→`completed`/`failed`, returns 202 with the resource; `streamChildRunEvents` replays `started`→`done` deterministically. No async job runtime.
2. **CORE-47 + CORE-58 = coqui-internal self-consistency, NOT tri-catalog parity.** `operations.yaml`/`openapi.yaml` were not vendored or added; a coqui `OperationCatalog` (derived from the route table + the vendored `error-coverage.json`) proves binding-agnostic single-implementation + list/single cardinality. Documented as self-consistency in every `it()` description, code comment, and docblock. Stale `x-persona` wording corrected to `x-profile`.
3. **CORE-56 = in-process `ImportService`.** No HTTP export/import routes; preserve|remap proven by a store-level roundtrip.

## Tasks (each: fresh Opus 4.8 implementer + Opus 4.8 spec+quality reviewer; review clean)

| # | Scope | CORE | Commit(s) |
|---|---|---|---|
| 1 | `RequestBodyException::toThrownError()` typed thrown-error payload | 57 | `2160bfd` |
| 2 | Pin `session.kind` to closed set (status already boolean-derived) | 2 | `166ea68` |
| 3 | Budget shed-order + pinned-tier inspectable (test-only; producer correct) | 12 | `8cc3658` |
| 4 | Declare `vision` built-in in InstanceInfo; generation extension-only | 32 | `c98d9b6` |
| 5 | CAP question wire shape — typed `{value,label?}`, multi-select (test-only) | 49 | `3957495` |
| 6 | `submitTurnAnswer` Core path + question SSE frames w/ `question_id` | 48 | `59c4a0c`+`3dd5fca` |
| 7 | `scheduled_task.action` union + `persona_id` + dialect | 50 | `4a0f755`+`74ed4ac` |
| 8 | `spawnChildRun` (202, gated) + `getChildRun` | 29 | `72451aa`+`2dd40ad` |
| 9 | `streamChildRunEvents` SSE + CORE-29 flip | 29 | `c047751` |
| 10 | `ImportService` preserve mode + roundtrip | 56 | `ed352a9` |
| 11 | Remap FK rewrite + CORE-56 flip | 56 | `4377839` |
| 12 | `OperationCatalog` — x-profile binding + cardinality self-consistency | 47, 58 | `ea34b90` |
| WB | Whole-branch fix I-1: `/D`-anchor `definition_name` slug | 50 | `ac4944e` |

Three tasks required a fix cycle before their review passed: **T6** (submitTurnAnswer was dead in production — `questions.turn_id` persisted null; fixed by decoupling the FK-safe audit link from the question turn id), **T7** (ScheduleManager mis-dispatched loop schedules as empty-prompt turns + non-slug `definition_name` accepted), and the whole-branch **I-1** (trailing-newline slug).

## Cross-task coherence (whole-branch review confirmed)

- **Shared primitives, single definition each:** `childRunToWire` (sole child-run producer, used by spawn/get/list/stream/export); `QuestionPersistence::toWire`/`wireOptions`/`wireSuggested` (reused by `MessageHandler::projectQuestionData` for question SSE frames); `QuestionHandler::recordAnswer` (single write path behind both `/questions/{id}/answer` and `submitTurnAnswer`); `ImportIdMap` (unifies preserve + remap — preserve is the identity remap, no dual path). No divergent copies.
- **No orphans / clean deletions:** `logChildRun` renamed to `createChildRun`+`finalizeChildRun` with every caller migrated (zero dangling refs); `ImportMode::Remap` stub throw removed when filled. No dead code from any deletion.
- **Seams intact:** T8 child-run write path ↔ T9 deterministic stream; T10 `ImportService` seam ↔ T11 remap; T5 wire shape ↔ T6 frames — each earlier task's contract is consumed, not contradicted, by its successor. CORE-48 part-(c) drives the real `SuspendingQuestionResponder::ask()` write path through `submitTurnAnswer` keyed only on the turn id, closing the T5→T6 + `questions.turn_id`-persistence seam with teeth.
- **Gate integrity — no overstatement:** CORE-56 explicitly scopes "every FK" to *imported* collections and disclaims `memories.persona_id`; CORE-47/58 explicitly state coqui-internal self-consistency, never tri-catalog parity. Verified in row bodies, comments, and docblocks.
- **Recreate-from-empty schema:** `scheduled_tasks` gained `action_kind`/`definition_name` (and `prompt` relaxed to nullable) with no ALTER chain; `child_runs`/`content`/`questions` were already CAP-realigned in Phase 2.

## Carry-forwards (Phase 6 / product follow-ups)

- **Toolkit-parity (CORE-29):** the HTTP-spawned child is text-only (no toolkit graph), unlike the in-agent `spawn_agent`. Documented in `docs/API.md`; product may later grant the HTTP child the access-level toolkit graph for parity.
- **`ImportService` production wiring (CORE-56):** the service is currently constructed only in tests (its `EnvelopeItemValidator` port has only the test `ConformanceValidator` as implementer, and no HTTP route builds it) — consistent with the in-process adjudication. A production import path needs a concrete validator + entry point. `memories` is out-of-import (deferred to the Memory reshape); restored `content` rows are metadata-only (`bytes=''`, sha256 preserved) — no code path treats them as byte-resolvable.
- **InstanceInfo `skills` profile maps to zero operations (CORE-47/58):** a real coqui-internal inconsistency (advertised profile, no HTTP handler) — disclosed and asserted, an InstanceInfo-owner follow-up. `OperationCatalog` is hand-maintained (a new profiled route won't auto-fail unless added).
- **App-facing wire deltas (Phase 6, Flutter):** `GET /sessions/{id}/child-runs` now emits the strict `childRunToWire` shape; new endpoints (`POST/GET /sessions/{id}/child-runs`, `/child-runs/{id}/events`, `POST /sessions/{id}/turns/{turnId}/answer`) and the reshaped schedule request/response.
- **Minor tidy:** the schedule `enable`/`disable`/`trigger`/`runs` endpoints still return raw `{schedule: row}` (additive-live, not `toWire`); the CORE-56 conformance FK check is membership-only (unit test pins precise targets); the child-run stream's reconnect cursor is accepted-but-ignored (deterministic replay) and the terminal `done` waits ~1s for the first timer tick; the pre-existing non-`/D` slug regexes in `LoopDefinition`/`LoopDiscovery` (not feeding a CAP producer) were left as-is.

## Next

Phase 6 — gate green + Flutter delta: close any remaining CORE rows (the §C set is now complete), prove all vectors produce/consume and roundtrip preserve+remap, then the Flutter **wire-boundary-only** delta on its own worktree off app `main` (never `feat/discord-redesign`): `/profiles→/personas`, changed JSON keys, name→`persona_id` addressing, plus the new Phase-5 endpoints and reshaped responses above. Per user cadence, `/compact` before starting Phase 6.
