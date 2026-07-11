# Headless Loop Start

**Date:** 2026-07-11
**Status:** Approved (design)
**Program:** Loops-API maturation — **Spec B of 3** (A: Live View · B: Headless start · C: Custom definitions via API). Successor to Spec A (`docs/superpowers/specs/2026-07-10-loop-live-view-design.md`).
**Frame:** loops > profiles > prompt-budgeting; API-first. Additive (build-new), not thinning.

## Purpose

Make it easy and clean to start a loop **without a conversation** (headless), and let the API identify such loops. Starting a loop from the API already partly works — `POST /api/v1/loops` accepts a body with no `session_id`, and `LoopExecutor::startLoop` auto-resolves a project — but a headless loop is stored with `session_id = null`, which skips the work-scope-session machinery that cross-stage artifact sharing depends on. This spec provisions a **loop-owned work-scope session** for headless loops (full parity with conversation-started loops) and records the loop's **origin** so the API can flag and filter headless loops.

## Non-Goals

- **No new `SessionType`.** Headless loops are identified by a loop-level `origin`, not a session type (see Decision below).
- **No new create endpoint.** `POST /api/v1/loops` already accepts `{definition, goal}` with no session.
- **No `LoopManager` change.** Provisioning the work-scope session restores the existing propagation path unchanged.
- **No REPL/agent-tool change.** `loop_start` runs inside a conversation and already has a session.
- **No streaming, no live-view changes** beyond surfacing `origin` (Spec A owns the live view).

## Architectural Grounding (verified)

- `POST /api/v1/loops` treats `session_id` as optional (`LoopHandler::create`). `LoopExecutor::startLoop(?string $sessionId = null, …)` passes it straight to `LoopStore::createLoop(sessionId: $sessionId)`, so a headless loop persists with `loops.session_id = null`.
- `LoopExecutor::resolveProject` **always** returns a project: explicit → session-active → **auto-create** (`Loop: {name}`, slug `loop-{name}-{hex}`). So a headless loop always has a project.
- Cross-stage data sharing between loop stages is **project-scoped**: artifacts carry `project_id`, and `ArtifactToolkit` filters by it ("useful in loop contexts"). `LoopManager` gives each stage its own hidden session and propagates the project to it via the **work-scope session**: `getActiveProjectId(workScopeSessionId) → setActiveProject(stageSession)`. `LoopStageResult.sessionId = loops.session_id`.
- **The gap:** when `loops.session_id` is null, `LoopManager`'s `if ($workScopeSessionId !== null)` propagation is skipped, so stage sessions may not receive the loop's active project — degrading cross-stage artifact scoping. Provisioning a loop-owned work-scope session (with its active project set) restores the path with no `LoopManager` change.
- `SessionType` currently has only `Interactive` and `Group`; no exhaustive `match` on it exists, but `SessionHandler` dispatches via a `sessionTypeRegistry()->handlerFor(SessionType)` registry.

## Decision: identify via `loop.metadata.origin` (Option B)

"Is this loop headless?" is a **loop-level** question and is answered directly by a loop-level field. A `SessionType::Loop` would not answer it without a session→loop join, and would drag in the session registry/storage (a `handlerFor` fallback, a `session_type` backfill) for speculative, session-level value. So headless is recorded as `loops.metadata.origin` and surfaced in the loop API. `SessionType::Loop` remains a clean future follow-up if session-level identification is ever needed.

## Design

### 1. Auto-provision a loop-owned work-scope session (core)

In `LoopExecutor::startLoop`, when the caller passes **no** `sessionId`:

1. Capture `$headless = ($sessionId === null)` before anything else.
2. Resolve the project as today (`resolveProject(...)` with the null session → auto-create).
3. Create a hidden **loop-owned** session: `SessionStorage::createSession(modelRole: 'orchestrator', model: '', visibility: 'hidden')`.
4. Set that session's active project to the resolved project: `SessionStorage::setActiveProject($loopOwnedSessionId, $resolvedProjectId)`.
5. Use `$loopOwnedSessionId` as the `sessionId` passed to `LoopStore::createLoop`.

Conversation-started loops (a `sessionId` **was** provided) are unchanged. `LoopManager` is unchanged — with a non-null `loops.session_id` it propagates the project to stage sessions exactly as before, so cross-stage artifact scoping and the live view's `work_scope_session_id` both work headless.

### 2. Record the loop origin

`LoopStore::createLoop` already receives a `metadata` array (the dispatch-pending marker). Add `origin` to it in `startLoop`:

```
metadata['origin'] = $headless ? 'headless' : 'conversation'
```

### 3. Surface + filter in the loop API

- `GET /api/v1/loops` — for each loop, decode `metadata.origin` and add `headless: bool` (`origin === 'headless'`) to the response. Support a `?headless=true|false` query filter, applied in the handler over the decoded loops and composable with the existing `?status=` filter.
- `GET /api/v1/loops/{id}` — add `origin` (and/or `headless`) to the response.
- **Contingent on Spec A being merged:** if `LoopLiveViewBuilder` exists in the base, add the one `origin` field to its live-view `loop` block (which already carries `work_scope_session_id`). If Spec A has not landed when Spec B is implemented, skip this and leave it as a trivial follow-up — it is not core to B, and B must not depend on unmerged Spec A code.

### 4. Docs

- `docs/LOOPS.md` / `docs/API.md` — document the headless path: `POST /api/v1/loops {definition, goal}` → returns loop id → poll `GET /api/v1/loops/{id}/live`. Note the `headless` flag and `?headless=` filter, and that a hidden loop-owned work-scope session is provisioned automatically.

## Error Handling

- No behavioral change when `session_id` is provided and valid (validated as today via `SessionAccess::requireWritableSession`).
- If session creation or `setActiveProject` fails, `startLoop` throws as it would for any storage error — the loop is not created (fail closed).
- `metadata.origin` absent on older loops → treated as `conversation` (default), so `headless` is `false` for pre-existing loops.
- `?headless=` with a non-boolean value → ignored (no filter), consistent with the lenient `?status=` handling.

## Testing

- `LoopExecutor::startLoop` with `sessionId = null` → `loops.session_id` is a non-null **hidden** session; that session's active project equals the loop's resolved project; `loops.metadata.origin === 'headless'`.
- `startLoop` with a provided `sessionId` → session unchanged (no loop-owned session created); `metadata.origin === 'conversation'`.
- A headless loop's stage, when advanced by `LoopManager`, receives the loop's project (integration-level assertion that stage sessions get the active project — i.e. parity with a conversation loop).
- `GET /api/v1/loops?headless=true` returns only headless loops; `?headless=false` only conversation loops; absent → all. Each listed loop carries `headless`.
- `GET /api/v1/loops/{id}` includes `origin`.

## Successor

- **Spec C — Custom loop definitions via API:** CRUD that validates via `LoopDefinition::fromArray()` and persists JSON to `workspace/loops/` (already scanned by `LoopDiscovery`); a `GET .../definitions` list already exists.
