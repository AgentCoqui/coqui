# Loops

Fully automated, multi-role iteration cycles that run hands-off until a termination condition is met.

> Loop orchestration uses `LoopExecutor` as the shared engine. Loop creation and control remain REPL-first or tool-driven, while the API exposes read-only inspection and advances loop stages asynchronously via `LoopManager` on a 5-second ReactPHP timer.

## Overview

A loop strings together existing agent roles in sequence — each role processes the output of the previous one — and repeats until the work is approved, the goal is judged met, or the iteration limit is reached. Loops are completely autonomous: no human approval, no manual iteration.

The most common pattern is **generator-evaluator**: a plan agent designs, a coder implements, a reviewer approves or rejects, and the cycle repeats until the reviewer says "APPROVED".

## Built-in Definitions

| Definition | Roles | Termination | Max Rounds | Description |
| --- | --- | --- | --- | --- |
| `harness` | plan → coder → reviewer | `evaluation_bound` | 5 | Generator-evaluator pattern inspired by Anthropic's Harness |
| `research` | explorer → coder → reviewer | `evaluation_bound` | 3 | Research-driven investigation and synthesis |
| `goal-driven` | plan → coder | `goal_bound` | 10 | LLM-evaluated goal completion without a reviewer role |

View available definitions with `loop_definitions` or `GET /api/v1/loops/definitions`.

## Starting a Loop

Before a loop is created, Coqui now validates that the API worker is not just reachable, but also dispatch-ready for the same workspace context. If the API server is pointed at a different workspace database, or its loop/task managers are not ticking, loop creation fails fast instead of leaving a loop stuck with pending stages.

### Basic

```
loop_start(definition: "harness", goal: "Build a Redis caching layer for the API")
```

### With Parameters

The `research` definition derives its subject from the goal. Optional parameters can tune the deliverable:

```
loop_start(
    definition: "research",
    goal: "Deep investigation of WebSocket libraries",
  parameters: '{"output_format": "comparison matrix"}'
)
```

### With Iteration Limit

```
loop_start(definition: "harness", goal: "...", max_iterations: 3)
```

## How a Loop Executes

```
loop_start(definition: "harness", goal: "Build caching layer")
  ↓
LoopExecutor:
  1. Validates parameters, resolves or auto-creates a project
  2. Creates loop record → first iteration → stage records
  ↓
Iteration 1:
  Stage 0: plan (role: plan)
    - Receives: goal, acceptance criteria
    - Produces: implementation plan
  Stage 1: implement (role: coder)
    - Receives: goal + plan output from stage 0
    - Produces: code changes
  Stage 2: evaluate (role: reviewer)
    - Receives: goal + plan + implementation output
    - Produces: "NEEDS CHANGES — validation missing" or "APPROVED"
  ↓
LoopExecutor: evaluateIteration()
  - Last stage output contains "APPROVED"?
    → No: advanceIteration() → Iteration 2
    → Yes: loop completes
```

### Prompt Composition

Each stage agent receives a structured prompt with:

1. **Goal** — the user's original goal
2. **Iteration number** — `Iteration 2/5`
3. **Previous stages this cycle** — full output from earlier stages in the same iteration
4. **Previous iteration outcomes** — one-line summaries from all prior iterations
5. **Acceptance criteria** — what "approved" means (from termination condition)
6. **Template parameters** — resolved `{{variable}}` values
7. **Role-specific task** — the stage's prompt instruction

This gives each agent full context of where the loop is, what happened before, and what they need to do.

## Termination Conditions

| Type | Trigger | Example |
| --- | --- | --- |
| `evaluation_bound` | Last stage output contains an approval keyword | Reviewer responds "APPROVED" |
| `iteration_bound` | N iterations completed | `value: 5` |
| `goal_bound` | An LLM judges the goal achieved from the last stage output | `value: { goal_prompt, max_iterations }` |

A loop can always be stopped explicitly with `loop_control(action: "stop", id: ...)` regardless of its termination type.

For `evaluation_bound`, the executor scans the last stage's output for approval signals (`approved`, `lgtm`, `looks good`, `accepted`, `passes all criteria`) while cross-checking against rejection signals to avoid false positives. For `goal_bound`, the executor asks the utility LLM whether the goal is met; if no utility model is configured, the loop runs to its `max_iterations` limit.

## Loop Lifecycle

| Status | Description |
| --- | --- |
| `running` | Stages are being executed |
| `paused` | Suspended — can be resumed |
| `completed` | Termination condition met |
| `failed` | Stage error or unrecoverable failure |
| `cancelled` | Stopped by user or agent |

### Dispatch Diagnostics

Newly created loops store dispatch metadata in the loop record. While the first stage is waiting to be queued, the dispatch status remains `pending`. After the API loop manager creates the background task, the dispatch status becomes `dispatched`. If dispatch fails, the loop metadata records the last dispatch error instead of silently appearing healthy.

## Session Context Propagation

When a loop starts, the orchestrator's session ID is stored in the loop record. As stages execute, this session ID flows through to each stage agent's toolkits:

```
OrchestratorAgent sessionId
  → LoopToolkit(sessionId)
    → LoopExecutor.startLoop(sessionId)
      → loops.session_id
        → LoopStageResult.sessionId
          → LoopManager.advanceLoop()
            → ArtifactToolkit
```

This means stage agents can read and create artifacts — all within the parent session's context. After each successful stage, `LoopManager` creates a `loop_output` artifact with the stage's result.

## Stage Agent Capabilities

Stage agents receive toolkits based on their role's access level:

| Access Level | Capabilities |
| --- | --- |
| `full` (e.g., coder) | Read/write files, full shell, web access, memory, auto-discovered toolkits |
| `readonly-shell` (e.g., explorer) | Read-only files, restricted shell (grep/find/cat/etc.) |
| `readonly` (e.g., plan, reviewer) | Read-only files only |
| `minimal` | No toolkits |

All access levels receive: `ArtifactToolkit` and `SkillToolkit` (except minimal).

### Excluded from Stage Agents

Stage agents intentionally do **not** receive:

- `LoopToolkit` — prevents infinite loop recursion
- `ScheduleToolkit` — prevents unbounded schedule creation
- `WebhookToolkit` — prevents uncontrolled webhook registration

This is an architectural constraint, not configuration — these toolkits are only constructed by `OrchestratorAgent` and never passed to loop stages.

## Custom Loop Definitions

Create JSON files in `workspace/loops/`:

```json
{
    "name": "docs-review",
    "description": "Documentation writer-reviewer cycle",
    "roles": [
        {
            "name": "write",
            "role": "coder",
            "prompt": "Write or update documentation based on the goal and any previous feedback.",
            "order": 0
        },
        {
            "name": "review",
            "role": "reviewer",
            "prompt": "Review the documentation for completeness, accuracy, and clarity. Respond APPROVED if ready, or provide specific feedback.",
            "order": 1
        }
    ],
    "termination_condition": {
        "type": "evaluation_bound",
        "value": {
            "criteria": "Documentation is complete, accurate, and well-organized",
            "max_review_rounds": 3
        }
    }
}
```

### Parameterized Definitions

Add a `parameters` array for template variable support:

```json
{
    "name": "investigate",
    "parameters": [
    {"name": "subject", "description": "Subject to investigate", "required": true},
        {"name": "depth", "description": "How deep to go", "required": false, "default": "moderate"}
    ],
    "roles": [
        {
            "role": "explorer",
      "prompt": "Investigate {{subject}} at {{depth}} depth..."
        }
    ]
}
```

Call with: `loop_start(definition: "investigate", goal: "...", parameters: '{"subject": "caching strategies"}')`.

Use the loop goal for the main subject matter. Parameters should refine behavior or supply additional structured inputs rather than act as loop identity.

## Monitoring and Control

### Check Status

```
loop_status(id: "loop123")
```

Returns: current iteration/stage, status, elapsed time, and stage results.

### Observing a Running Loop

For a live, poll-friendly view of an in-flight loop, use
`GET /api/v1/loops/{id}/live`. It returns the loop's current stage and model, its
consumption budget (tokens, iterations, elapsed/remaining time), a per-stage
breakdown, and a newest-first feed of recent activity — everything a dashboard or
status poller needs in one call. It is a pure read-model over existing loop, task,
turn, and event data. See [API.md](API.md) for the full response shape.

### Pause and Resume

```
loop_control(action: "pause", id: "loop123")    # Pauses after current stage completes
loop_control(action: "resume", id: "loop123")   # Continues from where it stopped
loop_control(action: "pause", id: "all")        # Pauses every running loop
loop_control(action: "resume", id: "all")       # Resumes every paused loop
```

### Stop

```
loop_control(action: "stop", id: "loop123")     # Cancels the loop
loop_control(action: "stop", id: "all")         # Cancels every active loop
```

## Agent Tools

| Tool | Description |
| --- | --- |
| `loop_start` | Start a loop from a definition with a goal |
| `loop_list` | List loops with optional status filter |
| `loop_status` | Get detailed status of a specific loop |
| `loop_control` | Pause, resume, or stop one loop or all active loops |
| `loop_definitions` | List available loop definitions |

## API Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/api/v1/loops` | List loops |
| `GET` | `/api/v1/loops/active/count` | Count running loops |
| `POST` | `/api/v1/loops` | Create/start a loop |
| `GET` | `/api/v1/loops/definitions` | List definitions |
| `GET` | `/api/v1/loops/{id}` | Get loop details |
| `GET` | `/api/v1/loops/{id}/history` | Get full loop iteration history |
| `GET` | `/api/v1/loops/{id}/metrics` | Get aggregate loop metrics |
| `GET` | `/api/v1/loops/{id}/live` | Get rich live snapshot (current stage, model, budget, events) |
| `PATCH` | `/api/v1/loops/{id}` | Update editable loop fields |
| `DELETE` | `/api/v1/loops/{id}` | Delete a loop |
| `POST` | `/api/v1/loops/{id}/pause` | Pause loop |
| `POST` | `/api/v1/loops/{id}/resume` | Resume loop |
| `POST` | `/api/v1/loops/{id}/stop` | Stop/cancel loop |
| `GET` | `/api/v1/loops/{id}/iterations` | List iterations |
| `GET` | `/api/v1/loops/{id}/iterations/{iterationId}` | Get iteration with stages |

## REPL Commands

| Command | Description |
| --- | --- |
| `/loops` | List all loops with status and progress |
| `/loops definitions` | Show available definitions |
| `/loops status <id>` | Detailed loop status |
| `/loops pause <id\|all>` | Pause running loop(s) |
| `/loops resume <id\|all>` | Resume paused loop(s) |
| `/loops stop <id\|all>` | Stop/cancel loop(s) |

## Execution Modes

Loops execute differently depending on the interface:

| Mode | Driver | Behavior |
| --- | --- | --- |
| REPL | `LoopExecutor` | Synchronous — spawns `ChildAgent` per stage, attaches observers for live output |
| API | `LoopManager` | Asynchronous — 5-second ReactPHP timer advances one stage per tick |

Both modes use `LoopExecutor` as the shared orchestration engine for state management, prompt composition, and termination evaluation.

## Known Limitations

- **No nested loops** — stage agents cannot start loops inside loops (LoopToolkit is excluded)
- **No background tasks from stages** — stage agents cannot spawn background tasks
- **Stage output truncation** — stage results are truncated to 2,000 characters when stored, which may lose detail for verbose outputs
- **Approval detection** — `evaluation_bound` uses keyword matching, not semantic understanding. Ambiguous responses may be misclassified

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [ARTIFACTS.md](ARTIFACTS.md) — Artifacts created by loop stages
- [PROJECTS.md](PROJECTS.md) — Projects auto-created by loops
