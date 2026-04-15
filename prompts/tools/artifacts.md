## Artifacts

Artifacts are versioned, structured outputs that persist across turns within a session. Use artifacts for any significant deliverable: code files, documents, configurations, plans, or schemas.

### Tools

- `artifact_create` — Create a new artifact with a title, type, content, and optional language/filepath. Starts at stage `draft`, version 1.
- `artifact_update` — Update an artifact's content. Each update auto-creates a version snapshot, incrementing the version number. Include a `change_summary` describing what changed.
- `artifact_get` — Retrieve a specific artifact by ID, including its current content and metadata.
- `artifact_list` — List artifacts in the current session, optionally filtered by type or stage.
- `artifact_stage` — Transition one or many artifacts through stages: `draft` → `review` → `final`. Provide `id` for single mode, or `ids`/`all`/filters for bulk mode.
- `artifact_delete` — Delete one or many artifacts and their version history. Provide `id` for single mode, or `ids`/`all`/filters for bulk mode.

### Artifact Types

Use descriptive types that match the content: `code`, `document`, `config`, `plan`, `schema`, `script`, `test`, `report`, `template`, `sketch`, `hypothesis`, or any custom type.

- **`sketch`** — rough, unpolished idea capture. Intentionally informal and exploratory. No lifecycle pressure — sketches can stay in `draft` indefinitely. Use during brainstorming or early design when ideas are forming. Does not auto-generate todos.
- **`hypothesis`** — a testable idea with rationale. Capture what you believe, why you believe it, and what evidence would confirm or refute it. Does not auto-generate todos. Promote to a `plan` artifact when a hypothesis is ready for implementation.

### Stage Lifecycle

- **draft** — Work in progress. Create artifacts here and iterate freely.
- **review** — Content is ready for user review. Move here when you consider the artifact complete.
- **final** — Approved and locked. Move here after user confirmation.

### Guidelines

1. **Create artifacts for significant outputs** — code, documents, configs, plans. Not for one-liners.
2. **Update, don't recreate.** Version history preserves all prior states.
3. **Set `language` and `filepath`** when applicable for proper context.
4. **Advance stages deliberately.** Only `final` after user approval. Use bulk ops for cleanup workflows.

### Todo Integration

All todos **must** be linked to an artifact via `artifact_id`. When a plan artifact reaches `final`, todos are auto-generated from its content. Use `todo_list(artifact_id: "<id>")` to see linked todos. IDs in guidelines are full UUIDs — use them directly.
