# Loops

Fully automated, multi-role iteration cycles that run hands-off until a termination condition is met.

## Overview

A loop strings together existing agent roles in sequence — each role processes the output of the previous one — and repeats until the work is approved, a limit is reached, or time runs out. Loops are completely autonomous: no human approval, no manual iteration.

The most common pattern is **generator-evaluator**: a plan agent designs, a coder implements, a reviewer approves or rejects, and the cycle repeats until the reviewer says "APPROVED".

## Built-in Definitions

| Definition | Roles | Termination | Max Rounds | Description |
| --- | --- | --- | --- | --- |
| `harness` | plan → coder → reviewer | `evaluation_bound` | 5 | Generator-evaluator pattern inspired by Anthropic's Harness |
| `research` | explorer → coder → reviewer | `evaluation_bound` | 3 | Research-driven investigation and synthesis |

View available definitions with `loop_definitions` or `GET /api/v1/loops/definitions`.

## Starting a Loop

### Basic

```
loop_start(definition: "harness", goal: "Build a Redis caching layer for the API")
```

### With Parameters

The `research` definition supports template parameters that are substituted into role prompts:

```
loop_start(
    definition: "research",
    goal: "Deep investigation of WebSocket libraries",
    parameters: '{"topic": "PHP WebSocket libraries", "output_format": "comparison matrix"}'
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
  1. Validates parameters, auto-creates a project
  2. Creates loop record → first iteration → sprint → stage records
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
| `iteration_bound` | N iterations completed | `max_iterations: 5` |
| `time_bound` | Wall-clock time elapsed | `value: 3600` (1 hour) |
| `manual` | Explicitly stopped by user/agent | `loop_stop(id)` |

For `evaluation_bound`, the executor scans the last stage's output for approval signals (`approved`, `lgtm`, `looks good`, `accepted`, `passes all criteria`) while cross-checking against rejection signals to avoid false positives.

## Loop Lifecycle

| Status | Description |
| --- | --- |
| `running` | Stages are being executed |
| `paused` | Suspended — can be resumed |
| `completed` | Termination condition met |
| `failed` | Stage error or unrecoverable failure |
| `cancelled` | Stopped by user or agent |

## Session Context Propagation

When a loop starts, the orchestrator's session ID is stored in the loop record. As stages execute, this session ID flows through to each stage agent's toolkits:

```
OrchestratorAgent sessionId
  → LoopToolkit(sessionId)
    → LoopExecutor.startLoop(sessionId)
      → loops.session_id
        → LoopStageResult.sessionId
          → LoopRunner.buildToolkits(sessionId)
            → ArtifactToolkit, TodoToolkit, SprintToolkit
```

This means stage agents can read and create artifacts, track todos, and update sprint progress — all within the parent session's context. After each successful stage, LoopRunner creates a `loop_output` artifact with the stage's result.

## Stage Agent Capabilities

Stage agents receive toolkits based on their role's access level:

| Access Level | Capabilities |
| --- | --- |
| `full` (e.g., coder) | Read/write files, full shell, web access, memory, auto-discovered toolkits |
| `readonly-shell` (e.g., explorer) | Read-only files, restricted shell (grep/find/cat/etc.) |
| `readonly` (e.g., plan, reviewer) | Read-only files only |
| `minimal` | No toolkits |

All access levels receive: `ArtifactToolkit`, `TodoToolkit`, `SprintToolkit`, and `SkillToolkit` (except minimal).

### Excluded from Stage Agents

Stage agents intentionally do **not** receive:

- `LoopToolkit` — prevents infinite loop recursion
- `BackgroundTaskToolkit` — prevents uncontrolled process spawning
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
        {"name": "topic", "description": "Subject to investigate", "required": true},
        {"name": "depth", "description": "How deep to go", "required": false, "default": "moderate"}
    ],
    "roles": [
        {
            "role": "explorer",
            "prompt": "Investigate {{topic}} at {{depth}} depth..."
        }
    ]
}
```

Call with: `loop_start(definition: "investigate", goal: "...", parameters: '{"topic": "caching strategies"}')`.

## Monitoring and Control

### Check Status

```
loop_status(id: "loop123")
```

Returns: current iteration/stage, status, elapsed time, and stage results.

### Pause and Resume

```
loop_pause(id: "loop123")    # Pauses after current stage completes
loop_resume(id: "loop123")   # Continues from where it stopped
```

### Stop

```
loop_stop(id: "loop123")     # Cancels the loop
```

## Agent Tools

| Tool | Description |
| --- | --- |
| `loop_start` | Start a loop from a definition with a goal |
| `loop_list` | List loops with optional status filter |
| `loop_status` | Get detailed status of a specific loop |
| `loop_pause` | Pause a running loop |
| `loop_resume` | Resume a paused loop |
| `loop_stop` | Stop/cancel a loop |
| `loop_definitions` | List available loop definitions |

## API Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/api/v1/loops` | List loops |
| `POST` | `/api/v1/loops` | Create/start a loop |
| `GET` | `/api/v1/loops/definitions` | List definitions |
| `GET` | `/api/v1/loops/{id}` | Get loop details |
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
| REPL | `LoopRunner` | Synchronous — spawns `ChildAgent` per stage, attaches observers for live output |
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
- [TODOS.md](TODOS.md) — Todos that loop stages can track
- [PROJECTS.md](PROJECTS.md) — Projects auto-created by loops
