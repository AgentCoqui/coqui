# Loop Live View: Rich Poll Observability

**Date:** 2026-07-10
**Status:** Approved (design)
**Program:** Loops-API maturation — **Spec A of 3** (A: Live View · B: Headless start · C: Custom definitions via API). This spec covers **A only**; B and C get their own brainstorm → spec → plan cycles.
**Frame:** loops > profiles > prompt-budgeting; API-first (the UI is a consumer app). Successor to the Phase-1 thinning batch. Additive (build-new), not thinning.

## Purpose

Surface rich, live data for an in-flight loop so a UI can fully understand what it is doing — **the agent working, the model used, and the loop's consumption budget** — through a single poll-friendly API endpoint. The underlying data already exists across `loops`/`loop_iterations`/`loop_stages`, `background_tasks`, `turns`, and `task_events`; what is missing is aggregation, a current-activity projection, and exposure. This spec adds a read-model and one endpoint. It does not change how loops execute.

## Non-Goals

- **No streaming.** SSE/WebSocket push is a documented follow-on, not built here. Everything is poll-based.
- **No budget caps or enforcement.** Consumption is *surfaced*, not limited. Loops keep only their existing `max_iterations` + `deadline` guards.
- **No cost/pricing.** There is no model price table in the codebase; budget is token/iteration/time based, never `$`.
- **No UI.** This delivers the API/data contract only.
- **No headless-start or custom-definition work.** Those are Specs B and C.
- **No change to loop execution** (`LoopManager`/`LoopExecutor`/`TaskRunCommand` untouched except read queries).

## Architectural Grounding (verified)

Data already recorded per loop run:

- **`turns`** (one execution session per stage) records `model`, `prompt_tokens`, `completion_tokens`, `total_tokens`, `iterations`, `duration_ms`, `tools_used`, `created_at`, `completed_at`. `AgentRunner` captures provider-reported usage.
- **`task_events`** (`task_id`, `event_type`, `data` JSON, `created_at`) logs activity including `tool_call` and lifecycle (`failed`, `cancelled`). `background_tasks.last_heartbeat_at` gives liveness.
- **Linkage:** `loops → loop_iterations → loop_stages(task_id) → background_tasks(session_id) → turns`. Confirmed: **each stage runs in its own fresh hidden session** (`LoopManager` creates it with `parent_session_id` = the loop's work-scope session). So per-stage model + token attribution is a clean per-session lookup, and per-loop rollup is a straight sum across stage sessions.

What is missing: no aggregate read-model, no per-loop token rollup, no current-activity projection, no live exposure (`metrics` only returns status/role *counts* + timings).

## Design

### Components

1. **`LoopLiveViewBuilder`** — new, `src/Api/LoopLiveViewBuilder.php`. Pure read aggregator. Constructor injects `LoopStore` and `SessionStorage` (and `ProjectStore` if a project name is surfaced). One public method:
   - `build(string $loopId): ?LoopLiveSnapshot` — returns the snapshot, or `null` if the loop does not exist.
   No side effects; fully unit-testable against fixture rows.

2. **Contracts** (`src/Contract/`, `final readonly`, each with `toArray()`):
   - **`LoopLiveSnapshot`** — the top-level view (fields below).
   - **`LoopLiveStage`** — one stage entry in the stages list.
   - **`LoopLiveEvent`** — one entry in the recent-event feed.

3. **`LoopHandler::live()`** — new handler method registering **`GET /api/v1/loops/{id}/live`**. Returns `200` with `snapshot->toArray()`, or `404` (`ApiErrorCode::NOT_FOUND`) when the builder returns `null`. Existing `get`/`history`/`metrics`/`list` endpoints are unchanged.

4. **Supporting read queries** (added to `LoopStore` and/or `SessionStorage`, kept focused):
   - Per stage session: **sum** its turns' `prompt_tokens`/`completion_tokens`/`total_tokens`/`duration_ms`, collect `tools_used`, and take the **latest** turn's `model`. (A stage session usually has one turn but may have several; summing keeps per-stage totals consistent with the loop rollup.)
   - The loop-level token rollup is then the sum of the per-stage sums (equivalently, all turns across all stage sessions).
   - Fetch the most recent N `task_events` across a set of task ids (the loop's stage tasks), newest first.

### Snapshot shape (`LoopLiveSnapshot`)

```
{
  "loop": {
    "id", "definition_name", "goal", "status",
    "project_id", "work_scope_session_id",
    "started_at", "last_activity_at", "completed_at", "deadline"
  },
  "position": {
    "current_iteration", "max_iterations",
    "current_stage_index", "current_stage_role", "stages_per_iteration"
  },
  "current_stage": null | {            // the running stage, if any
    "stage_id", "iteration_number", "stage_index", "role",
    "model", "status", "task_id", "session_id",
    "started_at", "last_heartbeat_at",
    "latest_activity": null | { "type", "summary", "timestamp" }
  },
  "budget": {                          // consumption only
    "tokens": { "prompt", "completion", "total" },
    "iterations": { "used", "max" },
    "time": { "elapsed_seconds", "deadline", "remaining_seconds": null|int }
  },
  "stages": [ LoopLiveStage, ... ],    // ordered by iteration then stage_index
  "recent_events": [ LoopLiveEvent, ... ]  // newest-first, capped (default 50)
}
```

**`LoopLiveStage`:** `iteration_number, stage_index, role, model, status, tokens {prompt, completion, total}, duration_ms, tools_used (list), result_summary, started_at, completed_at, task_id`.

**`LoopLiveEvent`:** `timestamp, stage_id (nullable), role (nullable), type, summary`. `type` is the source `event_type` (e.g. `tool_call`, `failed`, `cancelled`) plus derived lifecycle markers (`stage_started`, `stage_completed`) inferred from stage timestamps. `summary` is a short human string (e.g. the tool name for `tool_call`).

### Data flow

`build(loopId)`:
1. `LoopStore::getLoop` → loop meta + position; return `null` if absent.
2. `LoopStore::listIterations` + `listStages` → the full stage set; collect each stage's `task_id`.
3. For each stage: `task_id → background_tasks.session_id`; **sum** that session's turns → per-stage tokens + `duration_ms` + `tools_used`, and take the **latest** turn's `model`; `background_tasks` → `status`, `last_heartbeat_at`.
4. **Budget rollup:** sum per-stage tokens; `iterations.used = loops.current_iteration`, `max = loops.max_iterations`; `time.elapsed = now − started_at` (or `completed_at − started_at` if finished), `remaining = deadline − now` when a deadline is set.
5. **Current stage:** the stage whose `status = 'running'` (there is at most one advancing stage; if several, the highest iteration/stage). Attach its latest `task_event` as `latest_activity`.
6. **Recent events:** newest N `task_events` across the loop's stage `task_id`s, mapped to `LoopLiveEvent` and interleaved with derived stage lifecycle markers.

The "now" timestamp must be injected (constructor clock or parameter) so the builder is deterministically testable.

### Error handling

- Loop not found → builder returns `null` → handler `404`.
- Running stage with no turn yet → `tokens` 0 and `model` `null` until the first turn lands.
- Missing `last_heartbeat_at` → `null` (not an error).
- A stage whose task/session/turn row is missing contributes zeros and is still listed — **one dataless stage never fails the whole snapshot.**
- Empty loop (no iterations yet) → `stages: []`, `recent_events: []`, `current_stage: null`, zeroed budget.

### Testing

- **`LoopLiveViewBuilder` unit tests** (fixtures inserted via `LoopStore` + `SessionStorage`): assert token rollup sums, per-stage `model`/token attribution, current-stage detection (running stage), event ordering (newest-first) and cap, deadline/remaining math (with an injected clock), and the graceful-null paths (no turns yet, missing heartbeat, missing rows).
- **`LoopHandler::live` handler test:** `200` snapshot shape for a seeded loop; `404` for an unknown id. Mirrors existing `LoopHandlerTest` patterns.
- Full suite + PHPStan level 8 green.

### Docs

- `docs/API.md` — document `GET /api/v1/loops/{id}/live` with the response shape.
- `docs/LOOPS.md` — note the live view as the way to observe a running loop.
- `config/source.json` — add `LoopLiveViewBuilder`, `LoopLiveSnapshot`, `LoopLiveStage`, `LoopLiveEvent`.

## Streaming Follow-On (documented, not built)

The same event source powers a future push path: a cursor-based `GET /api/v1/loops/{id}/events?since=<event_id>` for incremental polling, then an SSE `GET /api/v1/loops/{id}/stream` that replays and tails `task_events`. No schema changes needed — `task_events.id` is the cursor. Deferred to a later spec.

## Successors

- **Spec B — Headless loop start:** `POST /loops` already accepts no `session_id` and `LoopExecutor::startLoop` auto-creates a project; B hardens the no-conversation path (clean auto-provisioned work-scope session, first-class ownership, docs).
- **Spec C — Custom loop definitions via API:** CRUD that validates via `LoopDefinition::fromArray()` and persists JSON to `workspace/loops/` (already scanned by `LoopDiscovery`); a `GET .../definitions` list already exists.
