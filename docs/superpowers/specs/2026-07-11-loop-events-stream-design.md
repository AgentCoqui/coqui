# Loop Events Stream — SSE Live Transparency Design

**Status:** Approved (brainstormed 2026-07-11)
**Successor to:** the loops-API program (`GET /loops/{id}/live` poll snapshot, PR #149; event-ordering fix PR #150).

## Problem

A running loop executes as a hidden sequence of stage tasks while the user keeps chatting. The `GET /loops/{id}/live` poll endpoint exposes a rich snapshot, but a UI that wants to "watch it work" has to poll on a fixed interval — either too slow (stale) or too fast (wasteful). We want the server to *push* when something actually changes, over infrastructure Coqui already has.

## Grounding: Coqui already speaks SSE

The HTTP API is ReactPHP (`react/http`). Two SSE consumers already exist, both on the same pattern (`React\Stream\ThroughStream` + `event: connected` handshake + `id:/event:/data:` frames + terminal event + `stream->on('close')` cleanup + `Content-Type: text/event-stream`):

- **Turn streaming** — `POST /sessions/{id}/messages` (SSE default, `?stream=false` for JSON).
- **Task event streaming** — `GET /tasks/{id}/events?since_id=N` (`TaskHandler::events`): 1s `addPeriodicTimer` polling `getTaskEvents`, `done` on terminal status.

There is **no** WebSocket dependency anywhere. Loops run *on* tasks (`loop → loop_iterations → loop_stages(task_id) → task_events`), so loop transparency is an extension of the task-events pattern, not new infrastructure.

## Decision: SSE, thin-push, typed nudges

Evaluated SSE vs WebSocket vs enhanced polling. **SSE wins** for this use case: it is already the house pattern, rides plain HTTP on the existing event loop, resumes via `Last-Event-ID`, and is unidirectional server→client — exactly the transparency need. Loop *control* (pause/resume/stop/skip) and task input are already discrete REST calls, so WebSocket's only real advantage (high-frequency bidirectional messaging) buys nothing here while adding a dependency and a parallel server stack.

**Thin-push (nudge + refetch), not fat-push.** The stream carries lightweight *signals*; the client re-fetches `GET /loops/{id}/live` for the rich snapshot. This keeps `LoopLiveViewBuilder` the single source of truth — no snapshot-serialization duplicated into the stream, no parity to maintain.

## Endpoint

`GET /api/v1/loops/{id}/events` → `Content-Type: text/event-stream`.

Typed event vocabulary. Every payload is a **thin pointer** (a reason + a cursor) — never snapshot data; the client resolves detail (role, model, budget) by re-fetching `/live`.

| Event | Fires when | Payload |
|---|---|---|
| `connected` | on open | `{"loop_id": "<id>"}` |
| `stage_changed` | `current_iteration`, `current_stage`, or lifecycle `status` moved (incl. `running`↔`paused`); also the initial position on connect | `{"iteration": <int>, "stage_index": <int>, "status": "<status>"}` |
| `activity` | new `task_events` appeared with position unchanged — **coalesced to one per tick** | `{"cursor": <max_event_id>}` |
| `done` | status became terminal (`completed`/`failed`/`cancelled`) | `{"status": "<status>"}` — stream then closes |

`role`/`model`/budget are deliberately *out* of the payload — the client reads them from `/live`. Loop terminal statuses are `completed`, `failed`, `cancelled`; `paused` is non-terminal (the loop can resume), so a pause emits `stage_changed` and keeps the stream open.

Client contract: on `connected`/`stage_changed`/`activity`, (re-)fetch `GET /loops/{id}/live`; treat `stage_changed` as a transition worth highlighting and throttle `activity` refetches; stop on `done`.

## Architecture

The change-detection logic is a **pure, fully-tested unit**; only the ReactPHP timer glue is untestable (as with the existing task-events endpoint, which has no tests because its logic lives inside the timer closure). We do not repeat that mistake.

- **`LoopStreamState`** (`final readonly` value object): `status`, `currentIteration`, `currentStage`, `latestActivityId` — the minimal snapshot a tick observes.
- **`LoopStreamEvent`** (`final readonly` VO): `type` (string), `data` (array).
- **`LoopStreamTracker::diff(?LoopStreamState $prev, LoopStreamState $now): ?LoopStreamEvent`** — pure. Precedence `done` > `stage_changed` > `activity`; returns `null` when nothing moved. `diff(null, now)` produces the initial emit (`stage_changed`, or `done` if already terminal), unifying connect and tick logic.
- **`SseStream`** — a small helper wrapping `ThroughStream`: a pure static `format(type, data, ?id): string` (tested), instance writers (`connected`, `event`, `done`), `onClose`, and `response()` returning the `text/event-stream` `Response` with the standard headers. The new endpoint uses it. The two existing SSE endpoints are **left untouched** in this spec (migrating them is a noted follow-up, not scope here).
- **`LoopStore::latestActivityId(string $loopId): ?int`** — one aggregate query `MAX(task_events.id)` joining `loop_iterations → loop_stages → task_events` (same SQLite PDO). `null` when the loop has produced no events yet.
- **`LoopHandler::events(ServerRequestInterface, string $id): Response`** + route `GET /loops/{id}/events`, registered before `/loops/{id}` (beside `/live`).

## Data flow

```
open
 └─ loop unknown? → 404 JSON (no stream opened)
 └─ connected{loop_id}
 └─ initial: emit diff(null, readState())        // stage_changed, or done if already terminal
 └─ if not closed: addPeriodicTimer(POLL_INTERVAL):
      state = readState()                          // getLoop + latestActivityId (2 cheap queries)
      event = diff(prev, state); prev = state
      event → write frame (id = state.latestActivityId); if done → close + cancel timer
 └─ stream.on('close') → cancel timer             // client disconnect
```

`readState()` reads `getLoop($id)` (→ `status`, `current_iteration`, `current_stage`) and `latestActivityId($id)`. If the loop row vanishes mid-stream (deleted), treat as terminal `cancelled` → `done` + close.

## Error handling & resume

- Unknown loop → `404` JSON via `Router::errorResponse(ApiErrorCode::NOT_FOUND, ...)` **before** any stream is opened.
- Already-terminal loop at open → `connected` then `done` then close (client fetches `/live` once for the final state).
- Client disconnect → `stream.on('close')` cancels the timer (mirrors `TaskHandler::events`).
- Per-tick exceptions → best-effort `end()` + cancel timer (mirrors the existing endpoint).
- Reconnect is treated as a fresh re-sync (`connected` + initial `stage_changed`) — idempotent because the client always refetches `/live`. Each frame still carries an SSE `id:` (the activity cursor) so browser `Last-Event-ID` works.

## Testing

- `LoopStreamTracker::diff` — full unit coverage: `diff(null, running)`→`stage_changed`; `diff(null, completed)`→`done`; iteration/stage advance→`stage_changed`; `running`→`paused`→`stage_changed`; activity-id change→`activity`; no change→`null`; already-terminal `prev`→`null`.
- `SseStream::format` — exact frame-string assertions (with and without `id:`).
- `LoopStore::latestActivityId` — store test over seeded iterations/stages/`task_events`; `null` when none.
- `LoopHandler::events` — `404` for unknown loop; `200` + `Content-Type: text/event-stream` for a known loop. The live timer loop stays thin and uncovered (like the existing endpoint), but all logic lives in the tested units above.

## Out of scope (future specs)

WebSockets; notifications/escalation streaming; a multiplexed global `GET /events` dashboard fan-in; migrating the turn/task SSE endpoints onto `SseStream`; backpressure/fan-out tuning for many concurrent viewers; snapshot deltas in-band (fat-push).
