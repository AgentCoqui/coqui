# Artifacts

Structured outputs that flow through a draft-review-final lifecycle, backed by files on disk.

## Overview

Artifacts are session-scoped documents — plans, code snippets, configs, data — that persist across agent turns within a session. For filesystem-backed types (`plan`, `document`, `code`, `config`), the canonical content lives as a **file** under the project's `artifacts/` directory; the database holds a lightweight index (title, type, stage, path, hash) and the file is the source of truth, so content history comes from the user's own version control. DB-only types (`loop_output`, `data`, `other`) store their content inline in the database. Artifacts support stage transitions through a draft-review-final lifecycle and optional project linking for cross-session persistence.

## Creating Artifacts

```
artifact_create(
    title: "Authentication Implementation Plan",
    content: "## Overview\n...",
    type: "plan",
    project_id: "abc123"
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

Artifacts start at stage `draft` and version `1`.

## Updating

Each call to `artifact_update` rewrites the artifact's content and bumps a monotonic `version` counter (shown in listings). For filesystem-backed artifacts the content is written to the canonical file — full per-version snapshots are **not** retained; recover prior states from your version control (e.g. `git`) for files on disk.

```
artifact_update(id: "art123", content: "...", change_summary: "Added error handling section")
```

The optional `change_summary` parameter documents what changed for the human in the loop.

## Stage Lifecycle

```
draft → review → final
```

| Stage | Purpose |
| --- | --- |
| `draft` | Work in progress — content is being developed |
| `review` | Submitted for evaluation — user or reviewer assesses |
| `final` | Approved and locked |

Transition via `artifact_stage(id, stage)`. Stages can move in any direction (e.g., `final` back to `draft` for revisions).

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
| `artifact_update` | yes | Update content (bumps the version counter) |
| `artifact_get` | yes | Retrieve an artifact's current content and metadata |
| `artifact_list` | yes | List session artifacts with filters |
| `artifact_stage` | yes | Transition one or many artifacts (single id or bulk ids/filters) |
| `artifact_delete` | no | Delete one or many artifacts (single id or bulk ids/filters) |

## API Endpoints

All routes under `/api/v1/sessions/{id}/artifacts`:

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/artifacts` | List with optional `?type=` and `?stage=` filters |
| `POST` | `/artifacts` | Create artifact |
| `GET` | `/artifacts/{artifactId}` | Get artifact |
| `PATCH` | `/artifacts/{artifactId}` | Update content, title, or stage |
| `DELETE` | `/artifacts/{artifactId}` | Delete artifact |

## Dynamic Guidelines

`ArtifactToolkit` injects a summary of active artifacts into the system prompt on every iteration. This keeps the agent aware of existing artifacts without explicit lookups.

## Typical Workflow

1. **Plan role** creates a plan artifact:
   ```
   artifact_create(type: "plan", title: "Cache Layer Design", project_id: "...")
   ```
2. **User reviews**, plan agent iterates on content via `artifact_update`
3. **Move to review**: `artifact_stage(id, "review")`
4. **User approves**, plan agent finalizes: `artifact_stage(id, "final")`
5. **Coder role** reads the finalized plan artifact and implements each step
6. **Loop stages** (if using a harness loop) create `loop_output` artifacts after each stage

## Bulk Management

Artifacts can be managed in bulk for cleanup and organization workflows using the unified `artifact_stage` and `artifact_delete` tools:

```
artifact_stage(ids: '["art1", "art2"]', stage: "review")
artifact_delete(stage: "draft")
artifact_delete(project_id: "proj123")
artifact_delete(all: true)
```

- Use explicit `ids` when you already know the target artifacts
- Use filters when cleaning up by stage, type, or project context
- Use `all: true` only when you want to wipe the full session artifact set

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [PROJECTS.md](PROJECTS.md) — Projects as working scopes
- [LOOPS.md](LOOPS.md) — Automated workflows that produce artifacts
