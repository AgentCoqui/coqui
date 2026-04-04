# Projects and Sprints

Persistent, cross-session containers for organizing development work.

## Overview

**Projects** group related work under a slug and optional directory. **Sprints** break a project into ordered, deliverable units — each with acceptance criteria and a review workflow that prevents premature completion.

Projects and sprints persist across sessions. When a project is **active**, its context is injected into the system prompt so all agents know what they're working on.

## Creating a Project

### Via Agent Tool

```
project_create(title: "Auth System", slug: "auth", description: "User authentication and authorization")
```

This creates a project record and an auto-generated workspace directory at `workspace/projects/auth-{hash}/`.

### Via REPL

Projects are created through agent tools only — there is no `/projects create` REPL command. Use the agent or the plan role.

## Project Lifecycle

| Status | Description |
| --- | --- |
| `active` | Work in progress |
| `completed` | All sprints done |
| `archived` | Retained for reference |

Update via `project_update(id, status: "completed")`.

## Active Project Context

Set the active project with `/projects auth` or `project_switch(slug: "auth")`. Clear with `/projects clear` or `project_switch(slug: "clear")`.

When active, the system prompt gains an `# ACTIVE PROJECT` section containing:

- Project name, slug, status, and description
- Workspace directory path
- Sprint roster with progress bars and todo counts

This gives agents ambient awareness without requiring explicit lookups. The REPL prompt also changes to show the project slug: `[auth] ›`.

Active project state is per-session and restores automatically when resuming a session.

## Sprints

Sprints are ordered work units within a project. Each sprint has a title, acceptance criteria, and a review workflow.

### Creating Sprints

```
sprint_create(
    project_id: "abc123",
    title: "MVP Login",
    acceptance_criteria: '["Users can register", "Users can log in", "Session persists across page loads"]'
)
```

Acceptance criteria are stored as a JSON array of testable statements. The reviewer uses these to evaluate whether the sprint passes.

### Sprint Lifecycle

```
planned → in_progress → review → complete
                           ↓
                       rejected → in_progress → review → ...
```

| Status | Description |
| --- | --- |
| `planned` | Queued, not yet started |
| `in_progress` | Active development |
| `review` | Implementation submitted for evaluation |
| `complete` | Acceptance criteria met (terminal) |
| `rejected` | Review failed, needs rework |

Transition via `sprint_transition(id, status: "review")`. Invalid transitions (e.g., `planned` → `complete`) are rejected.

### Review Rounds

Each sprint tracks `review_round` / `max_review_rounds` (default 3, hard cap 5). When a reviewer rejects a sprint, the round counter increments. Exceeding the max throws an error — the sprint has had too many review cycles and needs intervention.

Reviewers should provide `reviewer_notes` when rejecting:

```
sprint_transition(id: "...", status: "rejected", notes: "Login form doesn't validate email format")
```

### Linking Sprints to Artifacts and Todos

Artifacts and todos can reference a sprint via their `sprint_id` field:

```
artifact_create(type: "plan", project_id: "...", sprint_id: "...", title: "Login Implementation Plan")
todo_add(title: "Implement email validation", sprint_id: "...")
```

This creates traceability: project → sprint → artifact → todos.

## Agent Tools

| Tool | Description |
| --- | --- |
| `project_create` | Create a project with title, slug, description |
| `project_list` | List projects with optional status filter |
| `project_get` | Get project details with sprint roster |
| `project_update` | Update title, description, or status |
| `project_delete` | Delete one project or all projects; clears active-project session references |
| `project_switch` | Switch or clear the active project |
| `sprint_create` | Create a sprint within a project |
| `sprint_list` | List sprints with progress stats |
| `sprint_get` | Full sprint details with review history |
| `sprint_transition` | Move sprint through its lifecycle |
| `sprint_update` | Update sprint metadata |
| `sprint_delete` | Delete one sprint or bulk-delete sprints globally or by project |

`project_switch` is only available when the agent has a session context (orchestrator and background tasks).

## REPL Commands

| Command | Description |
| --- | --- |
| `/projects` | List all projects (active project marked with `●`) |
| `/projects active` | Filter to active projects |
| `/projects completed` | Filter to completed projects |
| `/projects <slug>` | Set as active project |
| `/projects clear` | Unset active project |
| `/sprints` | Show sprints from all active projects |
| `/sprints <slug>` | Show sprints for a specific project |

## Cleanup

Projects and sprints can now be removed directly from the agent tool surface:

```
project_delete(id: "auth")
project_delete(id: "all")
project_delete(id: "auth", delete_directory: true)

sprint_delete(id: "sprint123")
sprint_delete(id: "all", project_id: "auth")
```

- `project_delete` always removes the database record and clears any session `active_project_id` references pointing at that project
- `delete_directory: true` additionally removes `workspace/projects/<directory>` for the project
- Project directory deletion is optional and intended for full cleanup workflows
- Deleting a project or sprint does not automatically delete related artifacts or todos; clean those up with `artifact_bulk_delete` and `todo_clear` / `todo_bulk_delete`

## Recommended Workflow

For substantial tasks (multi-file features, new systems):

1. **Create a project** — `project_create(title, slug)`
2. **Switch to it** — `/projects <slug>` (injects context into prompts)
3. **Create a plan artifact** — `artifact_create(type: "plan", project_id: "...")` with the plan role
4. **Decompose into sprints** — `sprint_create(project_id, title, acceptance_criteria)` for each deliverable unit
5. **Link artifacts** — set both `project_id` and `sprint_id` when creating implementation artifacts
6. **Finalize the plan** — `artifact_stage("final")` auto-generates todos from the plan
7. **Implement** — switch to coder role, work through todos, mark them complete
8. **Review** — `sprint_transition(id, "review")`, reviewer checks acceptance criteria
9. **Iterate or complete** — rejected sprints go back to `in_progress`; passing sprints move to `complete`

Keep sprints small (3–8 todos each) with clear, testable acceptance criteria. One concern per sprint.

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [ARTIFACTS.md](ARTIFACTS.md) — Versioned artifacts and plan handoff
- [TODOS.md](TODOS.md) — Task tracking and auto-generation
