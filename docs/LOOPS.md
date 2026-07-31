---
title: Loops
description: "Hands-off multi-role iteration cycles: built-in and custom definitions, termination conditions, session context propagation, monitoring, agent tools, REPL commands, and API endpoints"
---

# Loops

Fully automated, multi-role iteration cycles that run hands-off until a termination condition is met.

> Loop orchestration uses `LoopExecutor` as the shared engine. The API is a full control surface — create, update, delete, pause, resume, stop, retry, skip-stage, and definition CRUD — and it also advances loop stages asynchronously via `LoopManager` on a 5-second ReactPHP timer. Only the conversational entry points are REPL-side.

## Overview

A loop strings together existing agent roles in sequence — each role processes the output of the previous one — and repeats until the work is approved, the goal is judged met, or the iteration limit is reached. Loops are completely autonomous: no human approval, no manual iteration.

The most common pattern is **generator-evaluator**: a plan agent designs, a coder implements, a reviewer approves or rejects, and the cycle repeats until the reviewer says "APPROVED".

## Built-in Definitions

| Definition | Roles | Termination | Max Rounds | Description |
| --- | --- | --- | --- | --- |
| `harness` | plan → coder → reviewer | `evaluation_bound` | 5 | Generator-evaluator pattern inspired by Anthropic's Harness |
| `research` | explorer → coder → reviewer | `evaluation_bound` | 3 | Research-driven investigation and synthesis |
| `diverge-converge` | muse → philosopher → plan → coder → reviewer | `evaluation_bound` | 3 | Creative divergence before convergent implementation |
| `goal-driven` | plan → coder | `goal_bound` | 10 | LLM-evaluated goal completion without a reviewer role |
| `reflection` | explorer → philosopher → identity-curator → muse | `iteration_bound` | 1 | Periodic self-examination: review recent work, reflect on patterns, record observations |

View available definitions with `loop_definitions` or `GET /api/v1/loops/definitions`.

Definitions are validated at discovery time, before template substitution, so a
templated `{{...}}` value must still satisfy its termination type's type rules
when read literally. Use a literal number where a numeric value is required, and
pass `max_iterations` to `loop_start` (or `POST /api/v1/loops`) to override it
per run.

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

### Headless Start

A loop can also start with no conversation. Post just a definition and goal to the API:

```
POST /api/v1/loops
{ "definition": "harness", "goal": "Build a Redis caching layer for the API" }
```

With no `session_id`, Coqui auto-provisions a hidden, loop-owned work-scope session whose active project is the loop's project. This restores the same project propagation and cross-stage artifact scoping that chat-started loops get (see [Session Context Propagation](#session-context-propagation)). Headless loops record `metadata.origin: "headless"` (conversation-started loops record `"conversation"`), surfaced as a derived `headless` flag on the list endpoint (`GET /api/v1/loops`) and as `origin` on the single-loop read endpoints — no endpoint returns both. See [docs/API.md](API.md).

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
  - StageGateEvaluator judges the gate stage → StageVerdict
    → Approved: loop completes
    → Rejected: mark needs_rework, rework_attempts++
      → under max_rework_attempts: advanceIteration() → Iteration 2
      → at max_rework_attempts: loop → blocked (awaits operator retry)
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
| `evaluation_bound` | `StageGateEvaluator` returns an approving verdict for the gate stage | Reviewer's work passes the structured gate |
| `iteration_bound` | N iterations completed | `value: 5` |
| `goal_bound` | An LLM judges the goal achieved from the last stage output | `value: { goal_prompt, max_iterations }` |

A loop can always be stopped explicitly with `loop_control(action: "stop", id: ...)` regardless of its termination type.

For `evaluation_bound`, the loop's gate stage is judged by `StageGateEvaluator` — a single-shot utility-LLM call that returns a structured verdict (`requirements_met` + `quality_pass` + severity-tagged findings). Approval requires both verdicts true and no Critical/Important findings. When no utility model is configured (or its output is unparseable), the evaluator falls back to keyword matching against the last stage's output — approval signals (`approved`, `approve`, `lgtm`, `looks good`, `accepted`, `passes all criteria`) cross-checked against rejection signals (`rejected`, `needs changes`, `needs_changes`, `needs work`, `not approved`, `revisions needed`). For `goal_bound`, the executor asks the utility LLM whether the goal is met; if no utility model is configured, the loop runs to its `max_iterations` limit. See [Stage verdicts and the gate](#stage-verdicts-and-the-gate).

### Stage verdicts and the gate

Every completed stage resolves to a machine-readable `StageStatus`:

| Status | Meaning |
| --- | --- |
| `done` | Stage succeeded; the loop advances |
| `done_with_concerns` | Gate evaluation did not approve — surfaced, not halting. Produced only by the gate; a producer stage that merely accrues a soft Minor finding stays `done` |
| `blocked` | Stage cannot proceed; the loop halts into escalation |
| `needs_context` | Stage needs more input; the loop halts into escalation |

Stages fall into two kinds:

- **Gate stages** — the last role of an `evaluation_bound` loop, or any role flagged `gate: true`. A gate stage is judged by `StageGateEvaluator`, which returns a `StageVerdict` (`requirements_met` + `quality_pass` + a list of severity-tagged findings). Approval requires both booleans true with no Critical/Important findings; anything else is a rejection.
- **Producer (non-gate) stages** — self-signal with no LLM call. The default status is `done`. A producer can halt the loop by emitting a line-leading sentinel — `STATUS: BLOCKED` or `STATUS: NEEDS_CONTEXT` (case-insensitive, within the first few lines). Mid-sentence prose that merely contains the phrase does not match.

Gate verdicts are persisted on the stage row (`loop_stages.verdict`) so re-evaluation across reconcile ticks is idempotent.

### Circuit-breaker and the `blocked` state

An `evaluation_bound` loop that keeps failing its gate does not spin forever. Each rejected round marks the iteration `needs_rework` and increments a `rework_attempts` counter in the loop metadata. Once `rework_attempts` reaches `max_rework_attempts` (default `3`, overridable with a top-level `max_rework_attempts` field in the definition), the loop transitions to **`blocked`** and records an `escalation` metadata entry (reason, attempt count, findings).

`blocked` is a *retryable* stop, not a terminal one — it is distinct from `completed (limit reached)`, which is a clean stop after the iteration limit. Reaching `blocked` fires an **actionable** notification (`loop.blocked`) so an operator (or the API automation runner) knows the loop is waiting for input.

A producer stage that self-signals `blocked`/`needs_context`, or fails a hard `artifact_required` check, also escalates the loop to `blocked` before the next stage dispatches.

### Artifact-required and memory-required stages

Two per-role flags add stage-level guarantees:

- `artifact_required: true` — a **hard** gate. If the stage produces no durable artifact, it resolves to `blocked` (a Critical finding) and the loop escalates.
- `memory_required: true` — a **soft** check. If the stage records no memory pointer, it accrues a single Minor finding; the stage status stays `done` and the loop is never blocked.

### Unblocking

A `blocked` loop is revived by reopening its latest iteration, clearing the circuit-breaker (`rework_attempts` → 0), and injecting any operator note as an `## Operator Guidance` prompt section on the next round.

- **Agent / chat:** `loop_control(action: "retry", id: "loop123", note: "…")`. The `note` is optional. Retry applies to `blocked`, `paused`, or `cancelled` loops.
- **API:** `POST /api/v1/loops/{id}/iterations/{iterationId}/retry` with an optional `{"note": "…"}` body. Only the latest iteration is retryable, its status must be `failed` or `needs_rework`, and a `running` loop must be paused or stopped first.

## Loop Lifecycle

| Status | Description |
| --- | --- |
| `running` | Stages are being executed |
| `paused` | Suspended — can be resumed |
| `completed` | Termination condition met |
| `blocked` | Circuit-breaker tripped or a stage self-signalled blocked/needs-context — retryable via `loop_control(action: "retry")` or the REST `/retry` endpoint |
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

For headless loops (started with no conversation), `LoopExecutor::startLoop` provisions a hidden loop-owned session up front and sets its active project, so `loops.session_id` is non-null and this same propagation chain applies unchanged.

## Stage Agent Capabilities

Stage agents receive toolkits based on their role's access level:

| Access Level | Capabilities |
| --- | --- |
| `full` (e.g., coder) | Read/write files, full shell, web access, auto-discovered toolkits |
| `readonly-shell` (e.g., explorer) | Read-only files, restricted shell (grep/find/cat/etc.) |
| `readonly` (e.g., plan, reviewer) | Read-only files only |
| `minimal` | No file or shell access |

Access level gates file, shell, web, and auto-discovered toolkits only. Independently of access level — including `minimal` — every stage agent also receives `CoquiDocsToolkit` (unconditional), `MemoryToolkit` (whenever a memory store is configured), `ArtifactToolkit` (storage + session + the `artifacts` persona feature), and the always-loaded `CoquiSkillsTool`. `SkillToolkit` is never constructed for stage agents; it is only wired for `spawn_agent` children.

### Excluded from Stage Agents

Stage agents intentionally do **not** receive:

- `LoopToolkit` — prevents infinite loop recursion
- `ScheduleToolkit` — prevents unbounded schedule creation

Stage agents run through the same `OrchestratorAgent` class as top-level agents; the exclusion comes from a guard inside it. Both toolkits require `access_level === 'full'` **and** `workScopeSessionId === null`, and a stage agent always has a work-scope session set. `LoopToolkit` additionally requires the `loops` persona feature, and both consult the role's toolkit allowlist — so two of the three gates are configuration.

## Custom Loop Definitions

Create JSON files in `workspace/loops/` — either by hand, or over the API with
full CRUD (`GET`/`POST`/`PUT`/`DELETE /api/v1/loops/definitions[/{name}]`; see
[API.md](API.md)). The definition name must match `^[a-z0-9][a-z0-9_-]*$` and
becomes the filename. Built-in definitions are editable and deletable like any
other; a deleted built-in re-seeds from `config/loops/` on the next boot.

Example file:

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

### Non-interactive questions

A loop runs unattended, so an `ask_user` call from a stage cannot open an interactive prompt and **never blocks the loop**. The agent's `suggested` best-guess answer is auto-taken and logged, and the stage continues without interruption. Always provide a good `suggested` default. See [QUESTIONS.md](QUESTIONS.md) for the full structured-questions guide.

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

Returns: `id`, `definition`, `status`, `goal`, `current_iteration`, `max_iterations`, `started_at`, `completed_at`, loop `metadata`, `current_iteration_status`, and a `stages` array. Elapsed time and budget are not included here — they are available from `GET /api/v1/loops/{id}/live`.

### Observing a Running Loop

For a live, poll-friendly view of an in-flight loop, use
`GET /api/v1/loops/{id}/live`. It returns the loop's current stage and model, its
consumption budget (tokens, iterations, elapsed/remaining time), a per-stage
breakdown, and a newest-first feed of recent activity — everything a dashboard or
status poller needs in one call. It is a pure read-model over existing loop, task,
turn, and event data. See [API.md](API.md) for the full response shape.

- `GET /api/v1/loops/{id}/events` streams SSE nudges (`connected`, `stage_changed`, `activity`, `done`) so clients can watch a running loop live and refetch `/loops/{id}/live` on each signal.

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
| `loop_control` | Pause, resume, stop, or retry one loop or all active loops (`retry` reopens a `blocked`/`paused`/`cancelled` loop with an optional guidance `note`) |
| `loop_definitions` | List available loop definitions |

## API Endpoints

| Method | Endpoint | Description |
| --- | --- | --- |
| `GET` | `/api/v1/loops` | List loops |
| `GET` | `/api/v1/loops/active/count` | Count running loops |
| `POST` | `/api/v1/loops` | Create/start a loop |
| `GET` | `/api/v1/loops/definitions` | List definitions (each flagged `builtin`) |
| `GET` | `/api/v1/loops/definitions/{name}` | Get one raw definition |
| `POST` | `/api/v1/loops/definitions` | Create a definition (409 if it exists) |
| `PUT` | `/api/v1/loops/definitions/{name}` | Upsert a definition |
| `DELETE` | `/api/v1/loops/definitions/{name}` | Delete a definition |
| `GET` | `/api/v1/loops/{id}` | Get loop details |
| `GET` | `/api/v1/loops/{id}/history` | Get full loop iteration history |
| `GET` | `/api/v1/loops/{id}/metrics` | Get aggregate loop metrics |
| `GET` | `/api/v1/loops/{id}/live` | Get rich live snapshot (current stage, model, budget, events) |
| `GET` | `/api/v1/loops/{id}/events` | SSE stream of live nudges (`connected`, `stage_changed`, `activity`, `done`) |
| `PATCH` | `/api/v1/loops/{id}` | Update editable loop fields |
| `DELETE` | `/api/v1/loops/{id}` | Delete a loop |
| `POST` | `/api/v1/loops/{id}/pause` | Pause loop |
| `POST` | `/api/v1/loops/{id}/resume` | Resume loop |
| `POST` | `/api/v1/loops/{id}/stop` | Stop/cancel loop |
| `GET` | `/api/v1/loops/{id}/iterations` | List iterations |
| `GET` | `/api/v1/loops/{id}/iterations/{iterationId}` | Get iteration with stages |
| `POST` | `/api/v1/loops/{id}/iterations/{iterationId}/retry` | Retry the latest iteration (optional `note`); unblocks a `blocked` loop |

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

There is **one** execution mode. Whatever creates a loop, its stages always run as API-driven background tasks:

| Surface | Role | Behavior |
| --- | --- | --- |
| REPL / agent tools | Create and control loops | `loop_start` and `/loops` require a reachable API server; they never execute stages themselves |
| API | `LoopManager` | Asynchronous — a 5-second ReactPHP timer advances one stage per tick |

`LoopExecutor` is mode-agnostic and handles state management, prompt composition, and termination evaluation. It does **not** execute agents — each stage is dispatched as a background task, and `ChildAgent` is not used anywhere in the loop path.

## Known Limitations

- **No nested loops** — stage agents cannot start loops inside loops (LoopToolkit is excluded)
- **No background tasks from stages** — stage agents cannot spawn background tasks
- **Stage output truncation** — stage results are truncated to 2,000 characters when stored, which may lose detail for verbose outputs
- **Approval detection** — `evaluation_bound` prefers a structured `StageGateEvaluator` verdict; only when no utility model is configured (or its output is unparseable) does it fall back to keyword matching, where ambiguous responses may be misclassified

## Related

- [DATA_FLOW.md](DATA_FLOW.md) — How all components connect
- [ARTIFACTS.md](ARTIFACTS.md) — Artifacts created by loop stages
- [PROJECTS.md](PROJECTS.md) — Projects auto-created by loops
- [QUESTIONS.md](QUESTIONS.md) — Structured questions and how loops auto-answer them
