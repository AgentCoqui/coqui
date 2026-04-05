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
| Concurrency | N/A (REPL doesn't execute tasks) | Configurable via `api.tasks.maxConcurrent` (default 32) |

Background tasks **require the API server to be running** for execution. The REPL can create and monitor tasks, but only the API server spawns the child processes that execute them.

## Loops

| Concern | REPL | API |
|---------|------|-----|
| Loop creation | Agent calls `loop_start` tool; `/loops start` REPL command | Same tools, plus `POST /api/v1/loops` |
| Stage advancement | **Not executed by REPL** — loop records are created but stages remain pending until the API server picks them up | `LoopManager` advances stages on a 5-second ReactPHP timer, creating background tasks via `BackgroundTaskManager` |
| Session model | N/A (REPL doesn't execute stages) | **New session per stage** — each stage runs as an independent background task; artifacts shared via work-scope session |
| Artifact continuity | N/A | Stage outputs stored as `loop_output` artifacts in the work-scope session |
| Monitoring | Agent polls with `loop_status` tool; user checks `/loops` REPL command | Same tools, plus `GET /api/v1/loops/{id}` |
| Cancellation | `/loops stop <id>` sets status; manager stops on next tick | `POST /api/v1/loops/{id}/stop` sets status; manager stops on next tick |

Loop stage advancement **requires the API server to be running**. The REPL can create loops (writing records to SQLite) and monitor their progress, but only the API server's `LoopManager` creates background tasks for each stage and advances the loop state machine.

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
2. **Loop stage advancement** — the only way loop stages progress (via `LoopManager`)
3. **Schedule evaluation** — cron-driven task spawning
4. **Webhook reception** — incoming event processing
5. **Monitoring** — read-only inspection of sessions, tasks, artifacts, todos, loops, schedules, and webhooks

When in doubt, use the REPL. The API is designed to complement it, not replace it.
