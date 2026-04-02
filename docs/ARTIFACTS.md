# Artifacts

Versioned, structured outputs that flow through a draft-review-final lifecycle.

## Overview

Artifacts are session-scoped documents — plans, code snippets, configs, data — that persist across agent turns within a session. They support versioning (every update snapshots the previous content), stage transitions that trigger downstream automation, and optional project/sprint linking for cross-session persistence.

The most important artifact workflow: a **plan** artifact finalized with `artifact_stage("final")` auto-generates linked [todos](TODOS.md).

## Creating Artifacts

```
artifact_create(
    title: "Authentication Implementation Plan",
    content: "## Overview\n...",
    type: "plan",
    project_id: "abc123",
    sprint_id: "sprint456"
)
```

| Parameter | Required | Default | Description |
| --- | --- | --- | --- |
| `title` | yes | — | Descriptive name |
| `content` | yes | — | Markdown or code content |
| `type` | no | `code` | `code`, `document`, `config`, `plan`, `data`, `other` |
| `language` | no | — | Programming language (for code artifacts) |
| `filepath` | no | — | Associated file path |
| `project_id` | no | — | Link to a project |
| `sprint_id` | no | — | Link to a sprint |

Artifacts start at stage `draft` and version `1`.

## Versioning

Every call to `artifact_update` bumps the version number and snapshots the previous content in the `artifact_versions` table. You can retrieve any past version:

```
artifact_get(id: "art123", version: 2)
```

Or list all versions through the API: `GET /api/v1/sessions/{id}/artifacts/{artifactId}/versions`.

The `change_summary` parameter on `artifact_update` records what changed:

```
artifact_update(id: "art123", content: "...", change_summary: "Added error handling section")
```

## Stage Lifecycle

```
draft → review → final
```

| Stage | Purpose |
| --- | --- |
| `draft` | Work in progress — content is being developed |
| `review` | Submitted for evaluation — user or reviewer assesses |
| `final` | Approved and locked — triggers downstream automation |

Transition via `artifact_stage(id, stage)`. Stages can move in any direction (e.g., `final` back to `draft` for revisions).

### Finalizing a Plan

When `artifact_stage(id, stage: "final")` is called on a `type: "plan"` artifact, it triggers `PlanTodoGenerator`:

1. The plan content is sent to the utility model (a cheap/fast LLM).
2. The model extracts actionable implementation steps as structured JSON.
3. Up to 25 todos are created via `TodoStore::bulkCreate()`, linked to the artifact and its sprint.
4. The `artifact_stage` response includes `todos_generated` count.

This is best-effort — the stage transition always succeeds even if todo generation fails. If zero todos are generated, the response includes a hint suggesting manual `todo_add` or `todo_bulk_add`.

## Persistence

By default, artifacts are **session-scoped** — they're deleted when the session is deleted.

When an artifact has a `project_id` set, it gains cross-session visibility. Persistent artifacts (`persistent: 1`) survive session cleanup.

| Scenario | Artifact Survives? |
| --- | --- |
| Session deleted, no `project_id` | No |
| Session deleted, `project_id` set, `persistent: 1` | Yes |
| Project deleted | Artifact remains (no cascade) |

## Cross-Agent Sharing

Artifacts are shared between agents through session ID propagation:

- **`spawn_agent`** — child agents receive `ArtifactToolkit` with the parent's session ID, so all artifacts are visible.
- **`loop_start`** — loop stage agents receive the orchestrator's session ID, giving them access to all session artifacts. After each stage, LoopRunner creates a `loop_output` artifact with the stage result.
- **Background tasks** — tasks get their own session but can reference parent artifacts through the task prompt.

All agent types (regardless of access level) receive `ArtifactToolkit`. The `artifact_delete` tool is withheld from read-only roles.

## Agent Tools

| Tool | Read-Only | Description |
| --- | --- | --- |
| `artifact_create` | yes | Create a new artifact |
| `artifact_update` | yes | Update content (bumps version) |
| `artifact_get` | yes | Retrieve artifact or specific version |
| `artifact_list` | yes | List session artifacts with filters |
| `artifact_stage` | yes | Transition artifact stage |
| `artifact_delete` | no | Delete artifact and all versions |

## API Endpoints

All routes under `/api/v1/sessions/{id}/artifacts`:

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/artifacts` | List with optional `?type=` and `?stage=` filters |
| `POST` | `/artifacts` | Create artifact |
| `GET` | `/artifacts/{artifactId}` | Get artifact |
| `PATCH` | `/artifacts/{artifactId}` | Update content, title, or stage |
| `DELETE` | `/artifacts/{artifactId}` | Delete artifact |
| `GET` | `/artifacts/{artifactId}/versions` | Version history |

## Dynamic Guidelines

`ArtifactToolkit` injects a summary of active artifacts into the system prompt on every iteration. When a `TodoStore` is available, plan artifacts show todo progress (e.g., `todos: 3/5`). This keeps the agent aware of existing artifacts without explicit lookups.

## Typical Workflow

1. **Plan role** creates a plan artifact:
   ```
   artifact_create(type: "plan", title: "Cache Layer Design", project_id: "...", sprint_id: "...")
   ```
2. **User reviews**, plan agent iterates on content via `artifact_update`
3. **Move to review**: `artifact_stage(id, "review")`
4. **User approves**, plan agent finalizes: `artifact_stage(id, "final")`
5. **Todos auto-generated** — linked to the artifact and sprint
6. **Coder role** reads todos via `todo_list(artifact_id: "...")` and implements each step
7. **Loop stages** (if using a harness loop) create `loop_output` artifacts after each stage

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [PROJECTS.md](PROJECTS.md) — Projects and sprints
- [TODOS.md](TODOS.md) — Task tracking and auto-generation from plans
- [LOOPS.md](LOOPS.md) — Automated workflows that produce artifacts
