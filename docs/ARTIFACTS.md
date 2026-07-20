---
title: Artifacts
description: "File-backed durable deliverables: when to create one, updating, ownership-based retention, memory-on-promotion, cross-agent sharing, agent tools, and API endpoints"
---

# Artifacts

Durable deliverables saved as plain files on disk, with a lightweight database index.

## Overview

Artifacts are the outputs an agent produces that are worth keeping — plans, documents, code, configs. Every artifact's content is a **file on disk** under `artifacts/<type>/<slug>-<shortid>.<ext>`; the database row is a pure index (`id, session_id, project_id, created_by, title, type, language, path, content_hash, version, metadata, timestamps`). The file is the source of truth, so content history comes from the user's own version control (e.g. `git`). There is no hybrid file/DB engine, and no draft→review→final stage machine: nothing reads or writes a stage. The `stage`, `persistent`, `storage_mode`, and `canonical_path` columns are still declared — SQLite cannot cheaply drop columns — but are dormant and never read by current code.

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

`artifact_create` accepts exactly those four types. A fifth type, `loop_output`, is created by the loop engine after each stage and can be *filtered* on via `artifact_list`, but it cannot be created directly.

The tool returns the artifact `id` and its file `path`. Project linking is inherited from the active project of the session; there is no per-call `project_id`/`filepath`/`stage` matrix.

Path convention: `artifacts/<type>/<slug>-<shortid>.<ext>`, where the extension derives from the type (`plan`/`document` → `.md`; `code` → the language's extension or `.txt`; `config` → `.json`/`.yaml`/`.txt`). The path is stable for the life of the artifact.

## Updating

`artifact_update(id, content)` is a **full rewrite** — it overwrites the same file (reusing the path) and bumps a monotonic `version` counter shown in listings. The version counter is only a "times updated" signal; per-version snapshots are **not** retained. Optionally rename via `title`.

```
artifact_update(id: "art123", content: "...")
```

## Retention (ownership-based)

- **Project-linked artifacts persist.** An artifact created while a project is active is linked to that project. Deleting a session that still holds project-linked artifacts is **refused** with a `CONFLICT` — detach them from the project first.
- **Session-only artifacts are cleaned up when their session is deleted** — their files and index rows are removed.

There is no boot-time cleanup sweep and no `final`-deletes-on-restart footgun.

| Scenario | Artifact survives? |
| --- | --- |
| Session deleted, no project link | No |
| Session deleted, project-linked | N/A — the session deletion is refused (`CONFLICT`) while such artifacts exist |
| Project deleted | Artifact remains (no cascade) |

### Memory-on-promotion

The pinned index (below) ages out. So when an artifact is a **canonical, reusable deliverable**, the agent records a durable **memory pointer** to it via the existing `memory_save` tool — a high-signal, profile-scoped fact naming the artifact's `path` + subject (e.g. "`artifacts/plan/pricing-a1b2.md` is the canonical pricing model"). There is **no** `pinned` column and **no** artifact-schema change: the memory *is* the promotion.

- **Gate** (both must hold): the artifact is canonical/reusable (a plan/design/config the work depends on, or one the user asked to keep) **and** a durability signal holds — it was edited more than once (`version` > 1) **or** the user said keep/remember. Drafts, one-off answers, sketches, and superseded versions are not remembered.
- **Supersession**: before writing a new pointer for a subject that already has one, the agent calls `memory_forget` (semantic match on the subject) so the KB doesn't accrete stale pointers.
- **Separation of concerns**: artifacts are work products, memory holds facts, skills hold procedures. The pointer is a fact *about* a work product — the artifact body is never copied into memory.
- **Scope**: the pointer is per-profile (the active profile's judgment); the artifact stays shared. The agent-facing convention lives in `prompts/tools/artifacts.md`.

## Provenance (`created_by`)

Each artifact records a display-only `created_by` label — the active profile/persona if present, else the role/agent identity (for `loop_output`, the loop/stage identity). It is shown in listings and the pinned index so an agent can judge relevance, but it is **never** used to filter: artifacts are shared, not profile-scoped. (Only *memories* are profile-scoped.)

## Cross-agent sharing

Artifacts are shared between agents through session-ID propagation:

- **`spawn_agent`** — child agents receive `ArtifactToolkit` scoped to the parent's session, so all session artifacts are visible. Non-full-access children get read-only access (no delete) but can still create/update — so they also receive `MemoryToolkit` (memory-on-promotion: any artifact-creating child can record and supersede a memory pointer).
- **`loop_start`** — loop stage agents share the work-scope session. After each stage the loop engine creates a `loop_output` artifact (a file) with the stage result; the reviewer reads it via `artifact_get(id)`.
- **Background tasks** — tasks get their own session but can reference parent artifacts through the task prompt.

## Agent tools

| Tool | Read-only roles | Description |
| --- | --- | --- |
| `artifact_create` | yes | Create a new artifact (writes a file, returns id + path) |
| `artifact_update` | yes | Full-rewrite content (bumps the version counter) |
| `artifact_get` | yes | Retrieve content (read from the file) and metadata |
| `artifact_list` | yes | List artifacts with `type`/`project_id`/`created_after` filters (its `type` filter also accepts `loop_output`) |
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

Rather than pruning artifact guidance under budget pressure, the when-to-use guidance and a small **recent-artifacts index** are injected as a *pinned* prompt section (the same priority as core memories), so they survive context compaction. The index lists pointers only — `- **title** (id) [type] path — by <created_by>` — capped at ~10 most-recently-updated within scope. Scope is session→project: with no project loaded it lists the session's own artifacts; when a project is active the scope **widens** to that project's most-recently-updated artifacts across all sessions, since artifacts are shared rather than session-private. It never inlines content; the agent reads or greps the file by path.

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [PROJECTS.md](PROJECTS.md) — Projects as working scopes
- [LOOPS.md](LOOPS.md) — Automated workflows that produce artifacts
