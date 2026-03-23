## Todos

Todos are session-scoped task items for tracking progress through multi-step work. Use todos to break plans into actionable checklists, track implementation progress, and discover new work during execution.

### Tools

- `todo_add` — create a new todo item, optionally linked to an artifact
- `todo_update` — update a todo's title, priority, notes, or status
- `todo_complete` — mark a todo as completed with optional notes
- `todo_list` — list todos with filters (artifact, status, priority). Returns formatted checklist
- `todo_get` — get full details of a specific todo including subtasks
- `todo_delete` — remove a todo and its subtasks
- `todo_bulk_add` — create multiple todos in one call (max 25). Takes a JSON array of items. Use when creating 5+ todos at once (e.g. from a plan)
- `todo_bulk_update` — update multiple todos in one call (max 25). Takes a JSON array of updates. Use when batch-updating status or priority

### When to Create Todos

1. **Planning phase.** When creating a plan artifact, create a linked todo for each implementation step. This creates a traceable checklist the coder agent follows. **Note:** When a plan artifact is staged to `final`, todos are auto-generated from its content — you usually don't need to create them manually.
2. **Discovered work.** When working on a task and discovering additional steps, add new todos to track them rather than losing context.
3. **Multi-step tasks.** Any task requiring 3+ distinct steps should use todos for tracking. Mark each in_progress before starting, then complete when done.
4. **Review feedback.** When the reviewer identifies issues, create todos for each corrective action.

### Status Transitions

- **pending** → **in_progress**: Mark when you begin working on a task
- **in_progress** → **completed**: Mark when the task is finished
- **pending/in_progress** → **cancelled**: Mark when the task is no longer needed

### Artifact Linking

Link todos to plan artifacts with `artifact_id` to create plan→execution traceability:
- Plan agent creates plan artifact + linked todos for each step
- Coder reads the plan, lists linked todos, works through them sequentially
- Progress is visible via `todo_list(artifact_id: "<id>")`

### Subtasks

Use `parent_id` to create subtasks when a top-level todo needs to be broken down further. Keep to single-level nesting — avoid deeply nested hierarchies.

### Best Practices

1. **Keep titles concise.** Use action-oriented titles under 200 characters (e.g. "Create TodoStore class" not "We need to create a new storage class for todo persistence").
2. **Set priority for important items.** Use `high` for blockers, `medium` for standard work, `low` for nice-to-haves.
3. **Complete todos individually.** Mark each todo complete as you finish it — don't batch completions. This gives real-time progress visibility.
4. **Add notes on completion.** When completing a todo, include brief notes about what was done or any follow-ups needed.
5. **Don't over-create.** Only create todos for actionable work items. Don't create a todo for reading a file or thinking about a problem.
6. **Use bulk operations for efficiency.** When creating 5+ todos at once (e.g. from a plan checklist), use `todo_bulk_add` instead of multiple `todo_add` calls. Similarly, use `todo_bulk_update` to batch status changes.

### After Summarization

When the conversation has been summarized, some older context may be compressed. If you notice missing context:
1. Call `todo_list` to see current progress and active tasks
2. Call `artifact_list` to see session artifacts and their stages
3. Use `todo_get` or `artifact_get` for details on specific items
4. Resume work from where the todo list indicates you left off
