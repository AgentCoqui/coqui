# REPL vs API Behavioral Divergences

The REPL is Coqui's primary interface. The API server exists as a background-task executor and monitoring surface. This document describes where the two execution paths intentionally diverge in behavior.

## Agent Turns

| Concern | REPL | API |
|---------|------|-----|
| Execution model | Synchronous, in-process via `AgentTurnExecutor` | Child process per turn via `AgentTurnManager` (`bin/coqui turn:run`) |
| Observability | Live `TerminalObserver` streaming to stdout | Events persisted to SQLite, polled by SSE clients |
| Cancellation | 2-stage Ctrl+C: cooperative cancel → force kill | SIGTERM to child process |
| Concurrency | One turn at a time (blocking readline) | One active turn per session; multiple sessions concurrent |

## Background Tasks

| Concern | REPL | API |
|---------|------|-----|
| Task creation | Agent calls `start_background_task` → record written to SQLite | Same (agent tool or `POST /api/v1/tasks`) |
| Task execution | **Not executed by REPL** — tasks remain pending until the API server picks them up | `BackgroundTaskManager` spawns `bin/coqui task:run` child processes on 1-second tick |
| Monitoring | Agent polls with `task_status` tool; user checks `/tasks` REPL command | Same tools, plus `GET /api/v1/tasks/{id}/events` SSE stream |
| Concurrency | N/A (REPL doesn't execute tasks) | Configurable via `api.tasks.maxConcurrent` (default 3) |

Background tasks **require the API server to be running** for execution. The REPL can create and monitor tasks, but only the API server spawns the child processes that execute them.

## Loops

| Concern | REPL | API |
|---------|------|-----|
| Orchestrator | `LoopRunner` — synchronous, blocking | `LoopManager` — async 5-second ReactPHP timer (removed in v0.x) |
| Session model | **Parent session shared** across all stages — artifacts, todos, and sprints flow continuously | **New session per stage** — each stage runs as an independent background task |
| Stage execution | Child agent runs in-process (same as `SpawnAgentTool`) | Stage spawned as background task via `BackgroundTaskManager` |
| Artifact continuity | Stage outputs stored as `loop_output` artifacts in the parent session | Stage outputs stored in per-stage sessions; linked via loop store only |
| Cancellation | Ctrl+C cancels the current stage agent | `POST /api/v1/loops/{id}/stop` sets status; manager stops on next tick |

Loop execution is **REPL-only**. The API provides read-only inspection of loop status, definitions, and iteration history but does not orchestrate loop execution.

## Schedules

| Concern | REPL | API |
|---------|------|-----|
| Schedule creation | Agent calls `schedule_create` tool; `/schedules` REPL command for management | Same tools, plus REST endpoints |
| Schedule evaluation | **Not evaluated by REPL** — cron expressions are not checked during REPL idle | `ScheduleManager` evaluates cron on a 60-second ReactPHP timer |
| Task spawning | N/A | Ready schedules create background tasks automatically |

Schedules **require the API server to be running** for automatic execution. The REPL can create and manage schedule records, but cron evaluation and task spawning only happen in the API server's event loop.

## Webhooks

| Concern | REPL | API |
|---------|------|-----|
| Subscription management | Agent calls `webhook_create`/`webhook_list`/`webhook_delete` tools | Same tools, plus REST endpoints |
| Incoming delivery | **Not supported** — REPL has no HTTP listener | `POST /api/v1/webhooks/incoming/{name}` verifies signature and creates background task |

Webhooks **require the API server to be running** to receive incoming deliveries. The REPL can manage webhook subscriptions but cannot process incoming webhook events.

## Session Context

| Concern | REPL | API |
|---------|------|-----|
| Active project | Injected into system prompt; displayed in the REPL user label before input | Not injected — API turns don't carry project context |
| Terminal rendering | Rich terminal output with colors, progress bars, file-change summaries | No terminal rendering — events are structured JSON |
| Edit history | Tracked per-turn with file-change summary after each turn | Not tracked — API turns don't have `EditHistory` |

## Summary

The REPL provides the full-featured, interactive experience. The API provides:

1. **Background task execution** — the only way to run tasks in separate processes
2. **Schedule evaluation** — cron-driven task spawning
3. **Webhook reception** — incoming event processing
4. **Monitoring** — read-only inspection of sessions, tasks, artifacts, todos, loops, schedules, and webhooks

When in doubt, use the REPL. The API is designed to complement it, not replace it.
