## Todos

Todos are session-scoped task items for tracking progress through multi-step work. Use todos to break plans into actionable checklists, track implementation progress, and discover new work during execution.

### Tools

- `todo_add` — create a new todo item linked to an artifact (artifact_id is required)
- `todo_update` — update a todo's title, priority, notes, or status
- `todo_complete` — mark a todo as completed with optional notes
- `todo_list` — list todos with filters (artifact, status, priority). Returns formatted checklist
- `todo_get` — get full details of a specific todo including subtasks
- `todo_delete` — remove a todo and its subtasks
- `todo_bulk_add` — create multiple todos linked to an artifact in one call (max 25). Takes a JSON array of items. Use when creating 3+ todos at once (e.g. from a plan)
- `todo_bulk_update` — update multiple todos in one call (max 25). Takes a JSON array of updates. Use when batch-updating status or priority
- `todo_bulk_complete` — mark multiple todos as completed in one call (max 25). Use when finishing a batch of tasks
- `todo_bulk_delete` — delete multiple todos in one call (max 25). Use sparingly — prefer cancelling
- `todo_complete_all` — mark every pending or in-progress todo in the current session as completed
- `todo_clear` — delete completed/cancelled todos, or wipe the entire session todo list with `scope: "all"`

### When to Create Todos

1. **Planning phase.** When creating a plan artifact, create a linked todo for each implementation step. Every todo **must** be linked to an artifact via `artifact_id` — create or find a plan artifact first (`artifact_create` or `artifact_list`). **Note:** When a plan artifact is staged to `final`, todos are auto-generated from its content — you usually don't need to create them manually.
2. **Discovered work.** When working on a task and discovering additional steps, add new todos to track them rather than losing context.
3. **Multi-step tasks.** Any task requiring 3+ distinct steps should use todos for tracking. Mark each in_progress before starting, then complete when done.
4. **Review feedback.** When the reviewer identifies issues, create todos for each corrective action.

### Status Transitions

- **pending** → **in_progress**: Mark when you begin working on a task
- **in_progress** → **completed**: Mark when the task is finished
- **pending/in_progress** → **cancelled**: Mark when the task is no longer needed

### Artifact Linking (Required)

Every todo **must** be linked to an artifact via `artifact_id`. This ensures plan→execution traceability:
- Plan agent creates plan artifact + linked todos for each step
- Coder reads the plan, lists linked todos, works through them sequentially  
- Progress is visible via `todo_list(artifact_id: "<id>")`
- If no plan artifact exists yet, create one with `artifact_create(type: "plan", ...)` before adding todos

### Subtasks

Use `parent_id` to create subtasks when a top-level todo needs to be broken down further. Keep to single-level nesting — avoid deeply nested hierarchies.

### Best Practices

1. **Keep titles concise.** Use action-oriented titles under 200 characters (e.g. "Create TodoStore class" not "We need to create a new storage class for todo persistence").
2. **Set priority for important items.** Use `high` for blockers, `medium` for standard work, `low` for nice-to-haves.
3. **Complete todos individually.** Mark each todo complete as you finish it — don't batch completions. This gives real-time progress visibility.
4. **Add notes on completion.** When completing a todo, include brief notes about what was done or any follow-ups needed.
5. **Don't over-create.** Only create todos for actionable work items. Don't create a todo for reading a file or thinking about a problem.
6. **Use bulk operations for efficiency.** When creating 3+ todos at once (e.g. from a plan checklist), use `todo_bulk_add` instead of multiple `todo_add` calls. Use `todo_bulk_update` to batch status changes. Use `todo_bulk_complete` to finish multiple tasks at once.
7. **Use session cleanup deliberately.** `todo_clear(scope: "completed")` is useful after finishing a sprint. `todo_clear(scope: "all")` is for reset/start-fresh workflows and deletes the whole session checklist.

### After Summarization

When the conversation has been summarized, some older context may be compressed. If you notice missing context:
1. Call `todo_list` to see current progress and active tasks
2. Call `artifact_list` to see session artifacts and their stages
3. Use `todo_get` or `artifact_get` for details on specific items
4. Resume work from where the todo list indicates you left off
