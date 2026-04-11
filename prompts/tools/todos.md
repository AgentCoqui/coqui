## Todos

Todos are session-scoped task items for tracking multi-step work. Link every todo to an artifact via `artifact_id`.

### Tools

- `todo_add` — create one or many todos linked to an artifact (pass `items` JSON array for bulk, max 25)
- `todo_update` — update title, priority, notes, or status for one or many (pass `updates` JSON array for bulk, max 25)
- `todo_complete` — mark todos completed: single `id`, multiple `ids`, or `all: true`
- `todo_list` — list todos with filters (artifact, status, priority)
- `todo_get` — get full details including subtasks
- `todo_delete` — remove todos: single `id`, multiple `ids`, or `scope: "all"` / `scope: "completed"`

### When to Create Todos

1. **Planning phase.** Create linked todos for each implementation step. When a plan artifact reaches `final`, todos are auto-generated — manual creation is usually unnecessary.
2. **Discovered work.** Add new todos for steps discovered during execution.
3. **Multi-step tasks.** Any task with 3+ steps should use todos. Mark `in_progress` before starting, `completed` when done.

### Best Practices

1. **Keep titles concise and action-oriented** — under 200 characters.
2. **Complete todos individually** as you finish them for real-time progress visibility.
3. **Use bulk operations** when creating/updating 3+ todos at once.
4. **Don't over-create.** Only for actionable work items, not for reading files or thinking.
5. **Add notes on completion** about what was done or follow-ups needed.

### After Summarization

If context was compressed, call `todo_list` and `artifact_list` to recover progress state, then resume from where the todos indicate you left off.
