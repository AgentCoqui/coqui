# Artifacts

Durable deliverables saved as plain files on disk, with a lightweight database index.

## Overview

Artifacts are the outputs an agent produces that are worth keeping — plans, documents, code, configs. Every artifact's content is a **file on disk** under `artifacts/<type>/<slug>-<shortid>.<ext>`; the database row is a pure index (`id, session_id, project_id, created_by, title, type, path, content_hash, version, timestamps`). The file is the source of truth, so content history comes from the user's own version control (e.g. `git`). There is no hybrid file/DB engine and no draft→review→final stage machine — those were removed.

Referencing an artifact by its path instead of re-pasting its content reduces context-window pressure, which is the point of the subsystem.

## When to create one

Create an artifact when the output is **substantial** (more than ~15 lines or a complete file/document), **durable** (the user would keep, re-open, share, or iterate on it), and **self-contained** (it stands on its own without the surrounding chat). Do not create one for one-off answers, short snippets, explanations, or commentary about an existing artifact.

## Creating

```
artifact_create(
    title: "Authentication Implementation Plan",
    content: "## Overview\n...",
    type: "plan"
)
```

| Parameter | Required | Default | Description |
| --- | --- | --- | --- |
| `title` | yes | — | Descriptive name (drives the file slug) |
| `content` | yes | — | Markdown or code content |
| `type` | no | `document` | `plan`, `document`, `code`, `config` |
| `language` | no | — | Language hint for `code`/`config` — sets the file extension |

The tool returns the artifact `id` and its file `path`. Project linking is inherited from the active project of the session; there is no per-call `project_id`/`filepath`/`stage` matrix.

Path convention: `artifacts/<type>/<slug>-<shortid>.<ext>`, where the extension derives from the type (`plan`/`document` → `.md`; `code` → the language's extension or `.txt`; `config` → `.json`/`.yaml`/`.txt`). The path is stable for the life of the artifact.

## Updating

`artifact_update(id, content)` is a **full rewrite** — it overwrites the same file (reusing the path) and bumps a monotonic `version` counter shown in listings. The version counter is only a "times updated" signal; per-version snapshots are **not** retained. Optionally rename via `title`.

```
artifact_update(id: "art123", content: "...")
```

## Retention (ownership-based)

- **Project-linked artifacts persist.** An artifact created while a project is active is linked to that project and survives.
- **Session-only artifacts are cleaned up when their session is deleted** — their files and index rows are removed.

There is no boot-time cleanup sweep and no `final`-deletes-on-restart footgun.

| Scenario | Artifact survives? |
| --- | --- |
| Session deleted, no project link | No |
| Session deleted, project-linked | Yes |
| Project deleted | Artifact remains (no cascade) |

## Provenance (`created_by`)

Each artifact records a display-only `created_by` label — the active profile/persona if present, else the role/agent identity (for `loop_output`, the loop/stage identity). It is shown in listings and the pinned index so an agent can judge relevance, but it is **never** used to filter: artifacts are shared, not profile-scoped. (Only *memories* are profile-scoped.)

## Cross-agent sharing

Artifacts are shared between agents through session-ID propagation:

- **`spawn_agent`** — child agents receive `ArtifactToolkit` scoped to the parent's session, so all session artifacts are visible. Non-full-access children get read-only access (no delete).
- **`loop_start`** — loop stage agents share the work-scope session. After each stage the loop engine creates a `loop_output` artifact (a file) with the stage result; the reviewer reads it via `artifact_get(id)`.
- **Background tasks** — tasks get their own session but can reference parent artifacts through the task prompt.

## Agent tools

| Tool | Read-only roles | Description |
| --- | --- | --- |
| `artifact_create` | yes | Create a new artifact (writes a file, returns id + path) |
| `artifact_update` | yes | Full-rewrite content (bumps the version counter) |
| `artifact_get` | yes | Retrieve content (read from the file) and metadata |
| `artifact_list` | yes | List artifacts with `type`/`project_id`/`created_after` filters |
| `artifact_delete` | no | Delete an artifact and its file (single `id`) |

There are no stage or bulk-management tools.

## API endpoints

All routes under `/api/v1/sessions/{id}/artifacts`:

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/artifacts` | List with an optional `?type=` filter |
| `POST` | `/artifacts` | Create artifact |
| `GET` | `/artifacts/{artifactId}` | Get artifact |
| `PATCH` | `/artifacts/{artifactId}` | Update content, title, metadata, or project link |
| `DELETE` | `/artifacts/{artifactId}` | Delete artifact |

## Pinned recent-artifacts index

Rather than pruning artifact guidance under budget pressure, the when-to-use guidance and a small **recent-artifacts index** are injected as a *pinned* prompt section (the same priority as core memories), so they survive context compaction. The index lists pointers only — `- **title** (id) [type] path — by <created_by>` — capped at ~10 most-recently-updated within scope (narrowed to the active project when one is loaded). It never inlines content; the agent reads or greps the file by path.

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [PROJECTS.md](PROJECTS.md) — Projects as working scopes
- [LOOPS.md](LOOPS.md) — Automated workflows that produce artifacts
