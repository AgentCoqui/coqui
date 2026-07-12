# Background Tasks

Background tasks are the execution substrate that runs a single unit of agent work in an isolated child process while the main conversation stays responsive. They are **not** something the orchestrator agent creates directly — the LLM has no background-task tools. Instead, background tasks are the shared engine that **loops, schedules, and the `/tasks` HTTP API** all run on.

> **Agent-driven async work goes through loops.** To run work asynchronously alongside the conversation, the agent starts a goal-driven loop: `loop_start(definition: "goal-driven", goal: "…")`. Each loop iteration is executed as a background task on this substrate. See [LOOPS.md](LOOPS.md).

> **Requires the API server**: Task execution is handled by the API server's `BackgroundTaskManager`. The REPL can monitor tasks, but only the API server spawns the child processes that execute them. Start Coqui with `coqui` for the full launcher-managed app, or use `coqui --api-only` when you only need the API runtime.

## How It Works

When a background task is started, Coqui:

1. Creates a dedicated session for the task (isolated from the main conversation)
2. Spawns a separate PHP process (`bin/coqui task:run`) with its own agent instance
3. The agent runs independently with full access to all tools and toolkits
4. Events (iterations, tool calls, results) are logged to SQLite in real time
5. When the task finishes, its result and status are persisted

Because each task runs in its own process, the API server's event loop stays fully responsive. Loops advance, schedules fire, and users keep chatting without any blocking.

### Task Lifecycle

```
pending  -->  running  -->  completed
                       -->  failed
         -->  cancelled
              running  -->  cancelling  -->  cancelled
```

- **Pending** — queued, waiting for a slot (concurrency limit)
- **Running** — a child process is actively executing the agent
- **Cancelling** — cancel requested; the manager will send SIGTERM on its next tick
- **Completed** — finished successfully with a result
- **Failed** — encountered an error (agent error, process crash, etc.)
- **Cancelled** — stopped by user or request (cooperative, finishes current iteration)

Pending tasks can be cancelled immediately (status goes straight to `cancelled`). Running tasks transition through `cancelling` first — the `BackgroundTaskManager` detects this on its next tick and sends SIGTERM to the child process, which then finishes its current iteration and exits.

### Concurrency

By default, up to 32 background tasks run concurrently. Additional tasks are queued and start automatically when a slot opens. Configure the concurrency limit in `openclaw.json`:

```json
{
    "api": {
        "tasks": {
            "maxConcurrent": 3
        }
    }
}
```

### Crash Recovery

If the API server restarts while tasks are running, orphaned tasks (status `running` with no live process) are automatically marked as `failed` with an explanatory message. Pending tasks remain in the queue and will be picked up by the task manager after restart.

## What Produces Background Tasks

There is no agent-facing tool for creating raw background tasks. Task records are produced by:

- **Loops** — each loop stage/iteration is scheduled as a background task by `LoopManager`. This is how an agent runs ad-hoc async work: `loop_start(definition: "goal-driven", goal: "…")`.
- **Schedules** — a cron-style schedule tick creates a task for its next run (`ScheduleManager`).
- **The HTTP API** — external integrations create tasks via `POST /api/v1/tasks`.

In every case the API server is the sole executor: the ReactPHP event loop manages child processes and SSE event streams. Producers write task records; the API server consumes and executes them.

## Enabling Background Tasks

The substrate is **automatically available** when running the API server. No additional configuration is required.

```bash
coqui
```

### Requirements

- The API server must be running (`coqui` or `coqui --api-only`)
- `pcntl` extension (for signal handling in child processes) — included in most PHP CLI installs
- `posix` extension (for process management) — included in most PHP CLI installs

## Monitoring Background Tasks

### Via REPL Slash Commands

The REPL provides operator commands for inspecting and cancelling tasks (loops and schedules all surface here):

| Command | Description |
|---------|-------------|
| `/tasks [status]` | List tasks, optionally filtered by status |
| `/task <id>` | Show detailed status, events, and result for a task |
| `/task-cancel <id>` | Cancel a pending or running task |

Task IDs support prefix matching — you only need to type enough characters to uniquely identify a task.

### Via the HTTP API

The API provides full programmatic control over background tasks.

#### Create a Task

```bash
curl -X POST http://localhost:3300/api/v1/tasks \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "Analyze the project structure and create a summary document",
    "title": "Project Analysis",
    "role": "orchestrator",
    "max_iterations": 48
  }'
```

**Response:**

```json
{
    "id": "a1b2c3d4e5f6...",
    "session_id": "f6e5d4c3b2a1...",
    "status": "running",
    "prompt": "Analyze the project structure...",
    "role": "orchestrator",
    "title": "Project Analysis",
    "created_at": "2026-02-18T10:30:00-05:00"
}
```

#### List Tasks

```bash
# All tasks
curl http://localhost:3300/api/v1/tasks \
  -H "Authorization: Bearer $API_KEY"

# Filter by status
curl "http://localhost:3300/api/v1/tasks?status=running" \
  -H "Authorization: Bearer $API_KEY"
```

#### Get Task Details

```bash
curl http://localhost:3300/api/v1/tasks/{id} \
  -H "Authorization: Bearer $API_KEY"
```

#### Stream Task Events (SSE)

Monitor a task in real time using Server-Sent Events:

```bash
curl -N http://localhost:3300/api/v1/tasks/{id}/events \
  -H "Authorization: Bearer $API_KEY"
```

The stream emits events as the agent works:

```
event: connected
data: {"task_id":"a1b2c3d4..."}

event: iteration
data: {"iteration":1}

event: tool_call
data: {"tool":"read_file","arguments":{"path":"src/main.php"}}

event: tool_result
data: {"tool":"read_file","content":"<?php..."}

event: done
data: {"status":"completed"}
```

The stream closes automatically when the task reaches a terminal state (completed, failed, or cancelled). Use the `since_id` query parameter to resume from a specific event:

```bash
curl -N "http://localhost:3300/api/v1/tasks/{id}/events?since_id=42" \
  -H "Authorization: Bearer $API_KEY"
```

#### Inject Input into a Running Task

Send additional instructions to a running task. The input is consumed by the agent at the start of its next iteration:

```bash
curl -X POST http://localhost:3300/api/v1/tasks/{id}/input \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"content": "Focus on the database layer first"}'
```

#### Cancel a Task

```bash
curl -X POST http://localhost:3300/api/v1/tasks/{id}/cancel \
  -H "Authorization: Bearer $API_KEY"
```

Cancellation is cooperative. The agent finishes its current iteration before stopping. For pending tasks, cancellation is immediate.

## API Reference

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/v1/tasks` | Create a new background task |
| `GET` | `/api/v1/tasks` | List tasks (optional `?status=` filter) |
| `GET` | `/api/v1/tasks/{id}` | Get task details |
| `GET` | `/api/v1/tasks/{id}/events` | SSE event stream (optional `?since_id=`) |
| `POST` | `/api/v1/tasks/{id}/input` | Inject input into a running task |
| `POST` | `/api/v1/tasks/{id}/cancel` | Cancel a task |

### Create Task Request Body

| Field | Type | Required | Default | Description |
|-------|------|----------|---------|-------------|
| `prompt` | string | Yes | | Instructions for the background agent |
| `title` | string | No | | Human-readable task title |
| `role` | string | No | `orchestrator` | Model role for the task agent |
| `parent_session_id` | string | No | | Session that spawned this task |
| `max_iterations` | integer | No | `25` | Maximum agent iterations (1-100) |

### Task Status Response

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Task ID |
| `session_id` | string | Dedicated session for this task |
| `parent_session_id` | string\|null | Session that spawned this task |
| `status` | string | `pending`, `running`, `cancelling`, `completed`, `failed`, `cancelled` |
| `title` | string\|null | Task title |
| `prompt` | string | Original prompt |
| `role` | string | Model role used |
| `pid` | integer\|null | OS process ID (when running) |
| `result` | string\|null | Final result (when completed) |
| `error` | string\|null | Error message (when failed) |
| `created_at` | string | ISO 8601 timestamp |
| `started_at` | string\|null | When the task started running |
| `completed_at` | string\|null | When the task finished |
| `process_alive` | boolean | Whether the process is still active (GET only) |

## Architecture

Background tasks use process-level isolation via `proc_open`. Each task runs as a completely separate PHP process:

```
API Server (ReactPHP event loop)
  |
  |-- BackgroundTaskManager.tick() [every 1s via periodic timer]
  |     |-- reap finished processes
  |     |-- process cancel requests (cancelling -> SIGTERM)
  |     |-- start pending tasks (up to maxConcurrent)
  |     |-- lazy purge of old task events (every ~5 min)
  |
  |-- proc_open("php bin/coqui task:run {taskId}")
        |-- TaskRunCommand boots full agent stack
        |-- Reads role + max_iterations from task record
        |-- Registers SIGTERM handler for cancellation
        |-- AgentRunner.runForTask(role, maxIterations) with:
        |     |-- BackgroundTaskObserver (events -> SQLite)
        |     |-- DatabasePendingInputProvider (input injection)
        |     |-- ProcessCancellationToken (cooperative cancel)
        |-- Updates task status on completion/failure
```

### Why Process Isolation?

- The ReactPHP event loop stays completely unblocked
- A crashing task cannot take down the API server
- Each task gets its own memory space and error handling
- Clean signal-based cancellation via SIGTERM

### Event Retention

Task events (iterations, tool calls, results) are stored in the `task_events` table. To prevent unbounded growth, the `BackgroundTaskManager` periodically purges old events:

- Events for completed, failed, and cancelled tasks older than **7 days** are deleted
- Cleanup runs lazily every ~5 minutes (300 ticks) during normal operation
- Running and pending task events are never purged

### Key Components

| Component | Purpose |
|-----------|---------|
| `BackgroundTaskManager` | Manages child processes, ticked by ReactPHP timer |
| `TaskRunCommand` | Console command that runs a single task in a child process |
| `BackgroundTaskObserver` | Persists agent events to `task_events` table |
| `DatabasePendingInputProvider` | Reads injected input from `task_inputs` table |
| `ProcessCancellationToken` | Converts SIGTERM to cooperative cancellation |
| `LoopManager` / `ScheduleManager` | Produce tasks for loop iterations and scheduled runs |
| `TaskHandler` | HTTP API handler for all task endpoints |
