## Loop Workflows

Use loops to run multi-role automated iteration cycles that execute without human intervention:
- Code generation → review → revision cycles (harness pattern)
- Research → implementation → validation pipelines
- Any multi-step workflow that benefits from iterative refinement

### Available Tools

- `loop_start` — start a new loop from a named definition with a goal
- `loop_list` — list running, paused, completed, or all loop instances
- `loop_status` — get detailed status including current iteration and stage results
- `loop_pause` — pause a running loop after the current stage completes; pass `id: "all"` to pause every running loop
- `loop_resume` — resume a paused loop; pass `id: "all"` to resume every paused loop
- `loop_stop` — cancel a running or paused loop; pass `id: "all"` to cancel every active loop
- `loop_definitions` — list available loop definitions with role sequences and termination conditions

### How Loops Work

A loop definition specifies a sequence of roles (e.g., plan → coder → reviewer) and a termination condition. Each iteration runs all roles in sequence. After each iteration, the termination condition is evaluated — if not met, a new iteration begins with results from the previous cycle.

Termination types: `evaluation_bound` (approval signals), `iteration_bound` (fixed count), `time_bound` (deadline), `goal_bound` (LLM evaluator), `tool_bound` (metric threshold).

### Template Parameters

Definitions support `{{placeholder}}` parameters substituted at start time. Use the goal for the loop's main subject matter; parameters should tune behavior or structured outputs. Use `loop_definitions` to see available parameters. Pass via `loop_start(parameters: {"max_review_rounds": "3"})`.

### Built-in Definitions

| Definition | Roles | Termination | Description |
| --- | --- | --- | --- |
| `harness` | plan → coder → reviewer | evaluation_bound (5 rounds) | Generator-evaluator pattern for iterative code quality |
| `research` | explorer → coder → reviewer | evaluation_bound (3 rounds) | Research-driven implementation with review |
| `goal-driven` | plan → coder | goal_bound (10 iterations) | LLM-evaluated goal completion without a reviewer role |

### Best Practices

1. **Write clear goals.** Be specific about what "done" looks like — the goal is passed to every role in every iteration.
2. **Monitor active loops.** Use `loop_status` to check progress. Use `loop_pause` before making mid-loop adjustments.
3. **Use appropriate definitions.** Choose the one that fits your workflow. Create custom definitions in `workspace/loops/` for specialized workflows.
4. **Use `id: "all"` for batch operations.** `loop_stop(id: "all")` cancels all active loops.

### Artifact Contract Enforcement

Loop stages can declare `requires_artifact_from` to enforce inter-stage artifact dependencies. Built-in definitions use this: coder requires plan output, reviewer requires coder output. Missing artifacts auto-fail the stage.
