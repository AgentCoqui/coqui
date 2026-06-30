## Loop Workflows

Use loops to run multi-role automated iteration cycles that execute without human intervention:
- Code generation → review → revision cycles (harness pattern)
- Research → implementation → validation pipelines
- Any multi-step workflow that benefits from iterative refinement

### Available Tools

- `loop_start` — start a new loop from a named definition with a goal
- `loop_list` — list running, paused, completed, or all loop instances
- `loop_status` — get detailed status including current iteration and stage results
- `loop_control` — control a running or paused loop: `action: "pause"` pauses after the current stage, `action: "resume"` resumes a paused loop, `action: "stop"` cancels a loop; pass `id: "all"` for batch operations
- `loop_definitions` — list available loop definitions with role sequences and termination conditions

### How Loops Work

A loop definition specifies a sequence of roles (e.g., plan → coder → reviewer) and a termination condition. Each iteration runs all roles in sequence. After each iteration, the termination condition is evaluated — if not met, a new iteration begins with results from the previous cycle.

Termination types: `evaluation_bound` (an evaluator approves the work against criteria), `iteration_bound` (a fixed iteration count), `goal_bound` (an LLM judges whether the goal is achieved).

### Template Parameters

Definitions support `{{placeholder}}` parameters substituted at start time. Use the goal for the loop's main subject matter; parameters should tune behavior or structured outputs. Use `loop_definitions` to see available parameters. Pass via `loop_start(parameters: {"max_review_rounds": "3"})`.

### Built-in Definitions

| Definition | Roles | Termination | Description |
| --- | --- | --- | --- |
| `harness` | plan → coder → reviewer | evaluation_bound (5 rounds) | Generator-evaluator pattern for iterative code quality |
| `research` | explorer → coder → reviewer | evaluation_bound (3 rounds) | Research-driven implementation with review |
| `goal-driven` | plan → coder | goal_bound (10 iterations) | LLM-evaluated goal completion without a reviewer role |
| `diverge-converge` | muse → philosopher → plan → coder → reviewer | evaluation_bound (3 rounds) | Creative pipeline: brainstorm, synthesize themes, then plan and implement |
| `reflection` | explorer → philosopher → identity-curator → muse | iteration_bound (1) | Periodic self-examination: review recent work, reflect on patterns, update developmental observations |

### Best Practices

1. **Write clear goals.** Be specific about what "done" looks like — the goal is passed to every role in every iteration.
2. **Monitor active loops.** Use `loop_status` to check progress. Use `loop_control(action: "pause")` before making mid-loop adjustments.
3. **Use appropriate definitions.** Choose the one that fits your workflow. Create custom definitions in `workspace/loops/` for specialized workflows.
4. **Use `id: "all"` for batch operations.** `loop_control(action: "stop", id: "all")` cancels all active loops.
