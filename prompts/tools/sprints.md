## Projects & Sprints

Projects organize long-running work across multiple sessions. Sprints divide project work into ordered units with structured lifecycle management and review gates.

### Project Tools

- `project_create` — create a project with a unique slug for cross-session reference
- `project_list` — list projects with sprint counts and completion progress
- `project_get` — get project details including sprint roster with progress
- `project_update` — update title, description, or status (active/completed/archived)

### Sprint Tools

- `sprint_create` — create a sprint within a project; starts as "planned"
- `sprint_list` — list sprints for a project with progress percentages
- `sprint_get` — full sprint details with progress, review notes, acceptance criteria
- `sprint_transition` — move sprint through lifecycle states
- `sprint_update` — update sprint metadata (title, criteria, contract artifact link)

### Sprint Lifecycle

```
planned → in_progress → review → complete
                          ↓
                       rejected → in_progress (loop)
```

**Transitions:**
- `planned → in_progress`: Work begins. The coder agent creates todos and starts implementing.
- `in_progress → review`: All implementation todos complete. Coder transitions and spawns reviewer.
- `review → complete`: Reviewer confirms acceptance criteria are met.
- `review → rejected`: Reviewer finds issues. Provide notes explaining what needs to change.
- `rejected → in_progress`: Coder reads reviewer notes and addresses feedback.

### Review Rounds

Each `review → rejected` transition increments the review round counter. When `max_review_rounds` is reached, the sprint blocks and requires user intervention. Default is 3 rounds, max 5.

### Linking Artifacts and Todos to Sprints

- Pass `sprint_id` when creating artifacts (`artifact_create`) or todos (`todo_add`, `todo_bulk_add`)
- Pass `project_id` when creating artifacts to mark them as persistent (survives session deletion)
- Use `sprint_get` to see todo progress for a sprint
- All IDs (project, sprint, artifact, todo) shown in tool outputs and guidelines are full UUIDs — use them directly in subsequent tool calls

### Workflow Pattern

1. **Plan agent** creates project + sprint, writes a plan artifact linked to the sprint
2. **Plan stages artifact to final** → todos auto-generated and linked to sprint
3. **Coder agent** reads plan, works through sprint todos, transitions to review
4. **Reviewer agent** checks acceptance criteria, either completes or rejects the sprint
5. **On rejection**, coder reads reviewer notes, addresses feedback, transitions back to review

### Session Continuity

Sprints track `last_session_id`. When resuming work in a new session:
1. Use `sprint_list` or `sprint_get` to find active sprints
2. The sprint's linked todos and artifacts provide full context
3. Use `sprint_transition` to continue the lifecycle

### Best Practices

1. **One active sprint per project.** Use `sprint_list(status: "in_progress")` to check before starting.
2. **Link everything.** Artifacts and todos should reference the sprint for traceability.
3. **Write acceptance criteria upfront.** They guide the reviewer and prevent subjective rejections.
4. **Include reviewer notes on rejection.** Specific, actionable feedback helps the coder iterate faster.
5. **Use contract artifacts.** Link the plan artifact to the sprint via `contract_artifact_id` for reference.
