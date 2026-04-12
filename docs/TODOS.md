# Todos

Session-scoped task tracking with auto-generation from plan artifacts.

## Overview

Todos provide structured task management within a session. They support subtask hierarchies, priority levels, artifact linking, sprint scoping, and bulk operations. The system integrates with [artifacts](ARTIFACTS.md) — finalizing a plan artifact auto-generates linked todos — and with [sprints](PROJECTS.md#sprints) for project-level tracking.

Agents see a live progress summary in their system prompt on every iteration, keeping them oriented without explicit lookups.

## Creating Todos

### Single Todo

```
todo_add(
    title: "Implement email validation",
    priority: "high",
    artifact_id: "plan123",
    sprint_id: "sprint456",
    notes: "Use filter_var with FILTER_VALIDATE_EMAIL"
)
```

### Bulk Create (up to 25)

```
todo_add(
    artifact_id: "plan123",
    items: '[
        {"title": "Create User model", "priority": "high"},
        {"title": "Add login endpoint", "priority": "high"},
        {"title": "Write integration tests", "priority": "medium"}
    ]'
)
```

### Auto-Generated from Plans

When `artifact_stage(id, stage: "final")` is called on a `type: "plan"` artifact, `PlanTodoGenerator` extracts implementation steps and creates linked todos automatically. See [Artifacts: Finalizing a Plan](ARTIFACTS.md#finalizing-a-plan).

## Todo Schema

| Field | Type | Description |
| --- | --- | --- |
| `id` | Text | Random hex ID |
| `session_id` | Text | Scoped to session (cascade delete) |
| `artifact_id` | Text | Optional link to source artifact (set null on deletion) |
| `parent_id` | Text | Optional subtask hierarchy (cascade delete) |
| `title` | Text | Task description |
| `status` | Enum | `pending`, `in_progress`, `completed`, `cancelled` |
| `priority` | Enum | `high`, `medium`, `low` |
| `created_by` | Text | Role that created the todo |
| `completed_by` | Text | Role that completed it |
| `notes` | Text | Additional context |
| `sprint_id` | Text | Optional sprint link |
| `sort_order` | Integer | Display ordering |

## Status Lifecycle

```
pending → in_progress → completed
    ↓         ↓
 cancelled  cancelled
```

Update via `todo_update(id, status: "in_progress")` or `todo_complete(id)`.

## Subtasks

Todos can have parent-child relationships via `parent_id`. When a parent is deleted, all subtasks cascade-delete with it.

```
todo_add(title: "Validate email format", parent_id: "parent123")
todo_add(title: "Check for duplicates", parent_id: "parent123")
```

## Bulk Operations

`todo_add(items: ...)` and `todo_update(updates: ...)` accept up to 25 items per call. `todo_complete(ids: ...)` and `todo_delete(ids: ...)` also support batch execution for up to 25 IDs. Operations are transaction-wrapped for atomicity where the store supports it.

```
todo_update(updates: '[
    {"id": "todo1", "status": "completed"},
    {"id": "todo2", "status": "in_progress"},
    {"id": "todo3", "status": "cancelled"}
]')
```

Use bulk operations when modifying 5+ todos at once to reduce tool call overhead.

## Role-Based Permissions

| Access Level | Available Tools |
| --- | --- |
| `full` | `todo_list`, `todo_get`, `todo_add`, `todo_update`, `todo_complete`, `todo_delete` |
| `readonly`, `readonly-shell` | `todo_list`, `todo_get`, `todo_add`, `todo_update` |
| `minimal` | `todo_list`, `todo_get` |

Read-only roles can create and update todos (important for plan agents) but cannot delete or complete them.

## Dynamic Guidelines

`TodoToolkit` injects a progress summary into the system prompt:

```
## TODO PROGRESS
████████░░ 80% (8/10)

### Active
- [IN PROGRESS] Implement login endpoint → artifact: Auth Plan

### Pending
- [PENDING] Write integration tests
- [PENDING] Add rate limiting
```

Todos linked to artifacts show `→ artifact: {title}` for traceability. A recovery hint reminds agents to check `todo_list` and `artifact_list` after conversation summarization.

## Cross-Agent Sharing

Todos are shared between agents through session ID propagation — the same mechanism used by [artifacts](ARTIFACTS.md#cross-agent-sharing).

- **`spawn_agent`** — child agents receive `TodoToolkit` with the parent's session ID
- **`loop_start`** — loop stage agents share the orchestrator's session todos
- **Background tasks** — get their own session with independent todos

## Agent Tools

| Tool | Description |
| --- | --- |
| `todo_add` | Create one todo or batch-create up to 25 todos via `items` |
| `todo_update` | Update one todo or batch-update up to 25 todos via `updates` |
| `todo_complete` | Mark one, many, or all todos as completed |
| `todo_list` | List todos with optional `artifact_id` or `status` filter |
| `todo_get` | Get a todo with its subtasks |
| `todo_delete` | Delete one, many, or scoped todos (cascades to subtasks) |

## API Endpoints

All routes under `/api/v1/sessions/{id}/todos`:

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/todos` | List todos with filters |
| `POST` | `/todos` | Create todo |
| `POST` | `/todos/bulk` | Bulk create (max 25) |
| `GET` | `/todos/stats` | Session todo statistics |
| `GET` | `/todos/{todoId}` | Get todo with subtasks |
| `PATCH` | `/todos/{todoId}` | Update todo |
| `PATCH` | `/todos/bulk` | Bulk update (max 25) |
| `POST` | `/todos/{todoId}/complete` | Mark complete |
| `DELETE` | `/todos/{todoId}` | Delete todo |

## REPL Command

```
/todos              # Show all session todos with progress bar
/todos pending      # Filter by status
/todos completed    # Filter by status
```

## Session Cleanup

Use the agent tools when you need to reset progress without enumerating IDs first:

```
todo_complete(all: true)
todo_delete(scope: "completed")
todo_delete(scope: "all")
```

- `scope: "completed"` removes completed and cancelled todos only
- `scope: "all"` deletes every todo in the current session
- For explicit ID batches, use `todo_complete(ids: '["todo1", "todo2"]')` and `todo_delete(ids: '["todo1", "todo2"]')`

## Cleanup

- **Session deletion** — all session-scoped todos cascade-delete
- **Boot cleanup** — `TodoStore::cleanupOrphaned()` removes todos from deleted sessions; `cleanupStale()` removes old completed/cancelled todos from inactive sessions
- **Artifact deletion** — todos linked to a deleted artifact have their `artifact_id` set to null (not deleted)

## Typical Workflow

1. **Plan agent** creates a plan artifact and finalizes it
2. **Todos auto-generated** from the plan, linked to the artifact and sprint
3. **Coder agent** reads todos:
   ```
   todo_list(artifact_id: "plan123")
   ```
4. **Works through each todo**, updating status as they go:
   ```
   todo_update(id: "todo1", status: "in_progress")
   # ... implements ...
   todo_complete(id: "todo1")
   ```
5. **Progress bar updates** in the system prompt on every iteration
6. **Reviewer** checks completed todos against actual implementation

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [ARTIFACTS.md](ARTIFACTS.md) — Plan artifacts that generate todos
- [PROJECTS.md](PROJECTS.md) — Sprint-level tracking
- [LOOPS.md](LOOPS.md) — Automated loops that work through todos
