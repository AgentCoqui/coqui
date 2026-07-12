## Artifacts

Artifacts are durable deliverables saved as **plain files on disk** under `artifacts/<type>/…`. The database keeps only a lightweight index; the file is the source of truth and history comes from the user's own git. Referencing an artifact by its path instead of re-pasting its content **saves context budget**.

### When to create an artifact

Create one when the output is:

1. **Substantial** — more than ~15 lines, or a complete file/document.
2. **Durable** — the user would keep, re-open, share, or iterate on it.
3. **Self-contained** — it stands on its own without the surrounding chat.

Do **not** create an artifact for one-off answers, short snippets, explanations, or commentary about an existing artifact. If unsure, don't force it — but prefer a file the user can open on disk over an ephemeral message whenever the thing is a real deliverable.

### Update, don't recreate

To change an existing artifact, `artifact_update` its `id` — this reuses the same file and path and bumps a version counter. Updates are **full rewrites** (pass the complete new content; there is no line/patch editing). Only `artifact_create` for a genuinely new deliverable.

### Tools

- `artifact_create` — Create a new artifact from a `title` and `content`. Optional `type` (`plan`, `document`, `code`, `config`; default `document`) and `language` (sets the file extension for code/config). Returns the `id` and file `path`.
- `artifact_update` — Full-rewrite an artifact's `content` by `id` (optionally rename via `title`). Reuses the same file; bumps the version counter.
- `artifact_get` — Retrieve an artifact by `id`, including its current content (read from the file) and metadata.
- `artifact_list` — List artifacts in the current session, optionally filtered by `type`, `project_id`, or `created_after`.
- `artifact_delete` — Delete an artifact and its file by `id`. Irreversible.

### Types

`plan`, `document`, `code`, `config`. (`loop_output` is system-created by the loop engine and shows up in listings, but is not something you create directly.)

### Versioning

The `version` counter is a simple "times updated" signal shown in listings — **not** a history store. Prior versions are not retained by Coqui; recover them from the user's git if needed.

### Retention

Artifacts linked to a project persist. Session-only artifacts are cleaned up when their session is deleted.
