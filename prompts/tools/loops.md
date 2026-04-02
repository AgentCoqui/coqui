## Loop Workflows

Use loops to run multi-role automated iteration cycles that execute without human intervention:
- Code generation → review → revision cycles (harness pattern)
- Research → implementation → validation pipelines
- Any multi-step workflow that benefits from iterative refinement

### Available Tools

- `loop_start` — start a new loop from a named definition with a goal
- `loop_list` — list running, paused, completed, or all loop instances
- `loop_status` — get detailed status including current iteration and stage results
- `loop_pause` — pause a running loop after the current stage completes
- `loop_resume` — resume a paused loop
- `loop_stop` — cancel a running or paused loop
- `loop_definitions` — list available loop definitions with role sequences and termination conditions

### How Loops Work

1. A loop definition specifies a sequence of **roles** (e.g., plan → coder → reviewer) and a **termination condition**.
2. Each **iteration** runs all roles in sequence. Each role executes as a child agent with its own prompt and context.
3. After each iteration, the **termination condition** is evaluated:
   - **evaluation_bound** — the last stage's output is checked for approval/rejection signals
   - **iteration_bound** — stops after a fixed number of iterations
   - **time_bound** — stops after a deadline
4. If the condition is not met, a new iteration begins with the results from the previous cycle.

### Built-in Definitions

| Definition | Roles | Termination | Description |
| --- | --- | --- | --- |
| `harness` | plan → coder → reviewer | evaluation_bound (5 rounds) | Generator-evaluator pattern for iterative code quality |
| `research` | explorer → coder → reviewer | evaluation_bound (3 rounds) | Research-driven implementation with review |

### Best Practices

1. **Write clear goals.** The goal is passed to every role in every iteration. Be specific about what "done" looks like.
2. **Monitor active loops.** Use `loop_status` to check progress, especially for evaluation-bound loops that may run many iterations.
3. **Pause before modifying.** If you need to adjust something mid-loop, pause first, then resume after changes.
4. **Use appropriate definitions.** Choose the definition that matches your workflow. Create custom definitions in `workspace/loops/` for specialized workflows.
5. **Custom definitions.** Create a JSON file in `workspace/loops/` following the schema of built-in definitions. The loop will be auto-discovered.
