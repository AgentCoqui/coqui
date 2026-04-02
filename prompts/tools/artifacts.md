## Artifacts

Artifacts are versioned, structured outputs that persist across turns within a session. Use artifacts for any significant deliverable: code files, documents, configurations, plans, or schemas.

### Tools

- `artifact_create` — Create a new artifact with a title, type, content, and optional language/filepath. Starts at stage `draft`, version 1.
- `artifact_update` — Update an artifact's content. Each update auto-creates a version snapshot, incrementing the version number. Include a `change_summary` describing what changed.
- `artifact_get` — Retrieve a specific artifact by ID, including its current content and metadata.
- `artifact_list` — List artifacts in the current session, optionally filtered by type or stage.
- `artifact_stage` — Transition an artifact through stages: `draft` → `review` → `final`. Stage transitions are one-directional.

### Artifact Types

Use descriptive types that match the content: `code`, `document`, `config`, `plan`, `schema`, `script`, `test`, `report`, `template`, or any custom type.

### Stage Lifecycle

- **draft** — Work in progress. Create artifacts here and iterate freely.
- **review** — Content is ready for user review. Move here when you consider the artifact complete.
- **final** — Approved and locked. Move here after user confirmation.

### Guidelines

1. **Create artifacts for significant outputs.** Don't create artifacts for trivial one-liners — use them for code, documents, configs, and plans that the user will want to reference or iterate on.
2. **Update, don't recreate.** When iterating on content, update the existing artifact rather than creating a new one. The version history preserves all prior states.
3. **Use meaningful titles.** Titles should describe the artifact concisely (e.g., "User Authentication Service", "Database Migration Plan").
4. **Set language for code.** When creating code artifacts, set the `language` parameter (e.g., `php`, `python`, `sql`) for proper syntax context.
5. **Set filepath when applicable.** If the artifact corresponds to a file, set `filepath` so the user knows where it belongs.
6. **Advance stages deliberately.** Move to `review` when the artifact is ready for the user to evaluate. Only move to `final` when the user has approved it.

### Integration with Todos

Plan artifacts and todos work together for structured implementation. All todos **must** be linked to an artifact:
- Create a plan artifact first, then link todos via `artifact_id`
- When a plan artifact is staged to `final`, todos are auto-generated from its content
- Use `todo_list(artifact_id: "<id>")` to see todos linked to a specific plan
- Artifact guidelines show linked todo progress for plan artifacts (e.g. "todos: 3/5")
- IDs shown in guidelines and tool outputs are full UUIDs — use them directly in tool calls
