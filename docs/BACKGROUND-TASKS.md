# Background Tasks

Background tasks let Coqui run long-running agent work in a separate process while the main conversation continues. Instead of blocking the chat while the agent researches, codes, or processes data, you can kick off a background task and check in on its progress whenever you want.

## How It Works

When a background task is started, Coqui:

1. Creates a dedicated session for the task (isolated from the main conversation)
2. Spawns a separate PHP process (`bin/coqui task:run`) with its own agent instance
3. The agent runs independently with full access to all tools and toolkits
4. Events (iterations, tool calls, results) are logged to SQLite in real time
5. When the task finishes, its result and status are persisted

Because each task runs in its own process, the API server's event loop stays fully responsive. Users can continue chatting, start additional tasks, or monitor running tasks without any blocking.

### Task Lifecycle

```
pending  -->  running  -->  completed
                       -->  failed
                       -->  cancelled
```

- **Pending** — queued, waiting for a slot (concurrency limit)
- **Running** — a child process is actively executing the agent
- **Completed** — finished successfully with a result
- **Failed** — encountered an error (agent error, process crash, etc.)
- **Cancelled** — stopped by user or agent request (cooperative, finishes current iteration)

### Concurrency

By default, one background task runs at a time. Additional tasks are queued and start automatically when a slot opens. Configure the concurrency limit in `openclaw.json`:

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

## Enabling Background Tasks

Background tasks are **automatically available** when running the API server. No additional configuration is required.

```bash
php bin/coqui api
```

The feature is API-only. It is not available in the terminal REPL because it requires the ReactPHP event loop to manage child processes and serve SSE event streams.

### Requirements

- The API server must be running (`php bin/coqui api`)
- `pcntl` extension (for signal handling in child processes) — included in most PHP CLI installs
- `posix` extension (for process management) — included in most PHP CLI installs

## Using Background Tasks

### Via the LLM Agent

When connected through the API, the orchestrator agent has access to four background task tools. You can ask it naturally:

> "Start a background task to refactor the authentication module"

The agent will use `start_background_task` to create the task, and you can ask it to check progress:

> "How's the refactoring task going?"

The agent calls `task_status` and reports back with the current state and recent activity.

#### Available Agent Tools

| Tool | Description |
|------|-------------|
| `start_background_task` | Create and queue a new task with a prompt, title, and optional role/iteration limit |
| `task_status` | Check the status, result, and recent events of a specific task |
| `list_tasks` | List tasks with optional status filter |
| `cancel_task` | Cancel a pending or running task |

> These tools are only available to agents running in API mode. They are intentionally excluded from background task agents themselves to prevent recursive task spawning.

### Via the HTTP API

The API provides full programmatic control over background tasks.

#### Create a Task

```bash
curl -X POST http://localhost:8080/api/tasks \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "prompt": "Analyze the project structure and create a summary document",
    "title": "Project Analysis",
    "role": "orchestrator",
    "max_iterations": 25
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
curl http://localhost:8080/api/tasks \
  -H "Authorization: Bearer $API_KEY"

# Filter by status
curl "http://localhost:8080/api/tasks?status=running" \
  -H "Authorization: Bearer $API_KEY"
```

#### Get Task Details

```bash
curl http://localhost:8080/api/tasks/{id} \
  -H "Authorization: Bearer $API_KEY"
```

#### Stream Task Events (SSE)

Monitor a task in real time using Server-Sent Events:

```bash
curl -N http://localhost:8080/api/tasks/{id}/events \
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
curl -N "http://localhost:8080/api/tasks/{id}/events?since_id=42" \
  -H "Authorization: Bearer $API_KEY"
```

#### Inject Input into a Running Task

Send additional instructions to a running task. The input is consumed by the agent at the start of its next iteration:

```bash
curl -X POST http://localhost:8080/api/tasks/{id}/input \
  -H "Authorization: Bearer $API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"content": "Focus on the database layer first"}'
```

#### Cancel a Task

```bash
curl -X POST http://localhost:8080/api/tasks/{id}/cancel \
  -H "Authorization: Bearer $API_KEY"
```

Cancellation is cooperative. The agent finishes its current iteration before stopping. For pending tasks, cancellation is immediate.

## API Reference

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/api/tasks` | Create a new background task |
| `GET` | `/api/tasks` | List tasks (optional `?status=` filter) |
| `GET` | `/api/tasks/{id}` | Get task details |
| `GET` | `/api/tasks/{id}/events` | SSE event stream (optional `?since_id=`) |
| `POST` | `/api/tasks/{id}/input` | Inject input into a running task |
| `POST` | `/api/tasks/{id}/cancel` | Cancel a task |

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
| `status` | string | `pending`, `running`, `completed`, `failed`, `cancelled` |
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
  |     |-- start pending tasks (up to maxConcurrent)
  |
  |-- proc_open("php bin/coqui task:run {taskId}")
        |-- TaskRunCommand boots full agent stack
        |-- Registers SIGTERM handler for cancellation
        |-- AgentRunner.runForTask() with:
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

### Key Components

| Component | Purpose |
|-----------|---------|
| `BackgroundTaskManager` | Manages child processes, ticked by ReactPHP timer |
| `TaskRunCommand` | Console command that runs a single task in a child process |
| `BackgroundTaskObserver` | Persists agent events to `task_events` table |
| `DatabasePendingInputProvider` | Reads injected input from `task_inputs` table |
| `ProcessCancellationToken` | Converts SIGTERM to cooperative cancellation |
| `BackgroundTaskToolkit` | LLM-facing tools for the orchestrator agent |
| `TaskHandler` | HTTP API handler for all task endpoints |
