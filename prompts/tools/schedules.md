## Scheduled Tasks

Use schedules to automate recurring or deferred work without human intervention:
- Daily code reviews or health checks
- Periodic data processing or report generation
- One-shot deferred follow-ups after deployments or long-running tasks
- Recurring maintenance tasks (cleanup, backups, monitoring)

### Available Tools

- `schedule_create` — create a new schedule with a cron expression and prompt
- `schedule_list` — list all schedules with status, next run, and execution history
- `schedule_get` — get detailed information about a specific schedule
- `schedule_update` — modify a schedule's cron, prompt, role, or other properties
- `schedule_delete` — permanently delete a schedule
- `schedule_trigger` — immediately execute a schedule without waiting for the next tick
- `schedule_enable` — re-enable a disabled schedule and reset its failure counter
- `schedule_disable` — pause a schedule without deleting it

### Cron Expression Format

Standard 5-field cron: `minute hour day month weekday`

| Expression      | Meaning               |
| --------------- | --------------------- |
| `*/5 * * * *`   | Every 5 minutes       |
| `0 9 * * 1-5`   | Weekdays at 9:00 AM  |
| `0 0 * * *`     | Daily at midnight     |
| `0 */6 * * *`   | Every 6 hours         |
| `@once`         | Run once, then auto-disable |

All times are evaluated in the schedule's configured timezone (default: UTC).

### Best Practices

1. **Write detailed prompts.** The scheduled task runs as a background agent with no conversation context. Include all file paths, goals, and constraints in the prompt.
2. **Use descriptive names.** Names appear in listings and logs — make them meaningful (e.g. `daily-test-runner`, `hourly-metrics-check`).
3. **Monitor execution.** Use `schedule_get` to check `run_count`, `failure_count`, and `last_status`. Investigate failures before they trigger the circuit breaker.
4. **Circuit breaker.** Schedules auto-disable after 3 consecutive failures (configurable via `max_failures`). Use `schedule_enable` to re-enable after fixing the issue.
5. **Use `@once` for deferred work.** When you need to follow up on something later, create a one-shot schedule instead of asking the user to remind you.
6. **Set appropriate iterations.** Match `max_iterations` to task complexity. Simple checks need 5-10; complex work may need 48.
