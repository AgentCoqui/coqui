## Background Tasks

Use background tasks for long-running operations that would block the current conversation:
- Complex multi-step research requiring many tool calls
- Code generation or refactoring across many files
- Tasks that may take more than 30 seconds to complete
- Work that can proceed independently while the user continues chatting

### Available Tools

- `start_background_task` — create and queue a new background task with a detailed prompt
- `task_status` — check the status and recent events of a task
- `list_tasks` — list tasks with optional status filter
- `cancel_task` — cancel a pending or running task

### Best Practices

1. **Write detailed prompts.** The background task agent has no access to the current conversation context. Include all relevant file paths, requirements, and constraints directly in the prompt.
2. **Set a descriptive title.** Titles appear in task listings and help users identify what each task does.
3. **Check status periodically.** After starting a task, use `task_status` to report progress to the user when they ask.
4. **One concern per task.** Break large jobs into focused tasks rather than one massive prompt.
5. **Use appropriate iterations.** Default is 25. Increase for complex tasks, decrease for simple ones.
