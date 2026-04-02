<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Utility\ScheduleValidator;

/**
 * Agent-facing tools for creating and managing scheduled tasks.
 *
 * Enables the agent to schedule its own future work using cron expressions.
 * Schedules are persisted in SQLite and evaluated by the ScheduleManager
 * running inside the API server's event loop.
 *
 * Key capability: the agent can create cron-style schedules that automatically
 * spawn background tasks at specified intervals — enabling autonomous,
 * recurring operation without human intervention.
 */
final readonly class ScheduleToolkit implements ToolkitInterface
{
    public function __construct(
        private ScheduleStore $scheduleStore,
    ) {}

    public function tools(): array
    {
        return [
            $this->createScheduleTool(),
            $this->listSchedulesTool(),
            $this->getScheduleTool(),
            $this->updateScheduleTool(),
            $this->deleteScheduleTool(),
            $this->triggerScheduleTool(),
            $this->enableScheduleTool(),
            $this->disableScheduleTool(),
        ];
    }

    public function guidelines(): string
    {
        $stats = $this->scheduleStore->getStats();
        $activeCount = $stats['enabled'];
        $totalRuns = $stats['total_runs'];

        $statusLine = $activeCount > 0
            ? "Active schedules: {$activeCount} ({$totalRuns} total runs)"
            : 'No active schedules';

        return <<<GUIDELINES
        ## Scheduled Tasks

        You can create recurring or one-shot scheduled tasks using cron expressions.
        Schedules are evaluated every 60 seconds by the API server.

        **Status:** {$statusLine}

        ### Cron Expression Format
        Standard 5-field cron: `minute hour day month weekday`
        - `*/5 * * * *` — every 5 minutes
        - `0 9 * * 1-5` — weekdays at 9:00 AM
        - `0 0 * * *` — daily at midnight
        - `0 */6 * * *` — every 6 hours
        - `@once` — run once immediately (auto-disables after execution)

        ### Best Practices
        - Give schedules descriptive names for easy identification
        - Use `@once` for single deferred tasks
        - Set appropriate `max_iterations` based on task complexity
        - Monitor schedule execution via `schedule_get` to check success/failure counts
        - Schedules auto-disable after consecutive failures (default: 3)
        - All times are UTC

        ### Self-Scheduling
        You can schedule your own future work. For example:
        - Schedule a daily code review: `schedule_create(name: "daily-review", cron: "0 9 * * 1-5", prompt: "Review recent changes...")`
        - Schedule a one-time follow-up: `schedule_create(name: "followup-123", cron: "@once", prompt: "Check if deployment succeeded...")`
        GUIDELINES;
    }

    private function createScheduleTool(): Tool
    {
        return new Tool(
            name: 'schedule_create',
            description: 'Create a scheduled task that runs automatically at specified intervals. Use cron expressions (e.g. "*/30 * * * *" for every 30 min) or "@once" for a single deferred execution.',
            parameters: [
                new StringParameter(
                    name: 'name',
                    description: 'Unique name for this schedule (lowercase, hyphens allowed)',
                    required: true,
                ),
                new StringParameter(
                    name: 'cron',
                    description: 'Cron expression (e.g. "0 */6 * * *") or "@once" for single execution',
                    required: true,
                ),
                new StringParameter(
                    name: 'prompt',
                    description: 'The prompt/instructions to execute when the schedule fires',
                    required: true,
                ),
                new StringParameter(
                    name: 'role',
                    description: 'Role for the spawned agent (default: orchestrator)',
                    required: false,
                ),
                new NumberParameter(
                    name: 'max_iterations',
                    description: 'Max agent iterations per run (1-' . CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS . ', default: 48)',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS,
                ),
                new StringParameter(
                    name: 'timezone',
                    description: 'Timezone for cron evaluation (default: UTC)',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeCreate($args),
        );
    }

    private function listSchedulesTool(): Tool
    {
        return new Tool(
            name: 'schedule_list',
            description: 'List all scheduled tasks with their status, next run time, and execution history.',
            parameters: [
                new BoolParameter(
                    name: 'enabled_only',
                    description: 'If true, only show enabled schedules',
                    required: false,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeList($args),
        );
    }

    private function getScheduleTool(): Tool
    {
        return new Tool(
            name: 'schedule_get',
            description: 'Get detailed information about a specific schedule including execution history.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Schedule ID or name',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeGet($args),
        );
    }

    private function updateScheduleTool(): Tool
    {
        return new Tool(
            name: 'schedule_update',
            description: 'Update a schedule\'s cron expression, prompt, enabled state, or other properties.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Schedule ID or name',
                    required: true,
                ),
                new StringParameter(
                    name: 'cron',
                    description: 'New cron expression',
                    required: false,
                ),
                new StringParameter(
                    name: 'prompt',
                    description: 'New prompt text',
                    required: false,
                ),
                new BoolParameter(
                    name: 'enabled',
                    description: 'Enable or disable the schedule',
                    required: false,
                ),
                new StringParameter(
                    name: 'role',
                    description: 'New role for the task agent',
                    required: false,
                ),
                new NumberParameter(
                    name: 'max_iterations',
                    description: 'New max iterations (1-' . CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS . ')',
                    required: false,
                    integer: true,
                    minimum: 1,
                    maximum: CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeUpdate($args),
        );
    }

    private function deleteScheduleTool(): Tool
    {
        return new Tool(
            name: 'schedule_delete',
            description: 'Delete a scheduled task permanently. Pass "all" as the ID to delete all schedules.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Schedule ID or name, or "all" to delete all schedules',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeDelete($args),
        );
    }

    private function triggerScheduleTool(): Tool
    {
        return new Tool(
            name: 'schedule_trigger',
            description: 'Immediately trigger a schedule, creating a background task without waiting for the next cron tick. Pass "all" as the ID to trigger all enabled schedules.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Schedule ID or name, or "all" to trigger all enabled schedules',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeTrigger($args),
        );
    }

    private function enableScheduleTool(): Tool
    {
        return new Tool(
            name: 'schedule_enable',
            description: 'Enable a disabled schedule so it resumes automatic execution. Also resets the failure counter. Pass "all" as the ID to enable all disabled schedules.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Schedule ID or name, or "all" to enable all disabled schedules',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeEnable($args),
        );
    }

    private function disableScheduleTool(): Tool
    {
        return new Tool(
            name: 'schedule_disable',
            description: 'Disable a schedule to stop automatic execution. The schedule is preserved and can be re-enabled later. Pass "all" as the ID to disable all enabled schedules.',
            parameters: [
                new StringParameter(
                    name: 'id',
                    description: 'Schedule ID or name, or "all" to disable all enabled schedules',
                    required: true,
                ),
            ],
            callback: fn(array $args): ToolResult => $this->executeDisable($args),
        );
    }

    // =========================================================================
    // Tool Implementations
    // =========================================================================

    /**
     * @param array<string, mixed> $args
     */
    private function executeCreate(array $args): ToolResult
    {
        $name = trim((string) ($args['name'] ?? ''));
        $cron = trim((string) ($args['cron'] ?? ''));
        $prompt = trim((string) ($args['prompt'] ?? ''));

        if ($name === '' || $cron === '' || $prompt === '') {
            return ToolResult::error('name, cron, and prompt are required');
        }

        if (($error = ScheduleValidator::validateName($name)) !== null) {
            return ToolResult::error($error);
        }

        // Check for duplicate name
        if ($this->scheduleStore->getByName($name) !== null) {
            return ToolResult::error("Schedule '{$name}' already exists. Use schedule_update to modify it.");
        }

        // Validate cron expression
        if (($error = ScheduleValidator::validateExpression($cron)) !== null) {
            return ToolResult::error($error);
        }

        if (($error = ScheduleValidator::validatePromptLength($prompt)) !== null) {
            return ToolResult::error($error);
        }

        $timezone = trim((string) ($args['timezone'] ?? 'UTC'));
        if (($error = ScheduleValidator::validateTimezone($timezone)) !== null) {
            return ToolResult::error($error);
        }

        $role = (string) ($args['role'] ?? 'orchestrator');
        $maxIterations = ScheduleValidator::normalizeMaxIterations((int) ($args['max_iterations'] ?? 48));
        $isOneShot = ($cron === '@once');

        $id = $this->scheduleStore->create(
            name: $name,
            scheduleExpression: $isOneShot ? '@once' : $cron,
            prompt: $prompt,
            role: $role,
            maxIterations: $maxIterations,
            timezone: $timezone,
        );

        $schedule = $this->scheduleStore->get($id);

        return ToolResult::success((string) json_encode([
            'id' => $id,
            'name' => $name,
            'cron' => $cron,
            'one_shot' => $isOneShot,
            'next_run' => $schedule['next_run_at'] ?? null,
            'message' => $isOneShot
                ? "One-shot schedule '{$name}' created. Will execute on next scheduler tick."
                : "Schedule '{$name}' created. Next run: " . ($schedule['next_run_at'] ?? 'pending'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeList(array $args): ToolResult
    {
        $enabledOnly = (bool) ($args['enabled_only'] ?? false);
        $schedules = $this->scheduleStore->list(
            enabled: $enabledOnly ? true : null,
        );

        if ($schedules === []) {
            return ToolResult::success('No scheduled tasks found.');
        }

        $lines = [];
        foreach ($schedules as $s) {
            $status = ((int) $s['enabled']) ? '✓' : '✗';
            $nextRun = $s['next_run_at'] ?? 'N/A';
            $runs = (int) ($s['run_count'] ?? 0);
            $failures = (int) ($s['failure_count'] ?? 0);

            $line = sprintf(
                '%s [%s] %s — cron: %s | next: %s | runs: %d | failures: %d',
                $status,
                $s['id'],
                $s['name'],
                $s['schedule_expression'],
                $nextRun,
                $runs,
                $failures,
            );
            $lines[] = $line;
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeGet(array $args): ToolResult
    {
        $schedule = $this->resolveSchedule((string) ($args['id'] ?? ''));
        if ($schedule === null) {
            return ToolResult::error('Schedule not found');
        }

        return ToolResult::success((string) json_encode($schedule, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeUpdate(array $args): ToolResult
    {
        $schedule = $this->resolveSchedule((string) ($args['id'] ?? ''));
        if ($schedule === null) {
            return ToolResult::error('Schedule not found');
        }

        $id = (string) $schedule['id'];

        // Validate cron if provided
        $cron = isset($args['cron']) ? trim((string) $args['cron']) : null;
        if ($cron !== null && ($error = ScheduleValidator::validateExpression($cron)) !== null) {
            return ToolResult::error($error);
        }

        // Validate prompt length if provided
        $prompt = isset($args['prompt']) ? trim((string) $args['prompt']) : null;
        if ($prompt !== null && ($error = ScheduleValidator::validatePromptLength($prompt)) !== null) {
            return ToolResult::error($error);
        }

        // Validate timezone if provided
        $timezone = isset($args['timezone']) ? trim((string) $args['timezone']) : null;
        if ($timezone !== null && ($error = ScheduleValidator::validateTimezone($timezone)) !== null) {
            return ToolResult::error($error);
        }

        $maxIterations = isset($args['max_iterations'])
            ? ScheduleValidator::normalizeMaxIterations((int) $args['max_iterations'])
            : null;

        $this->scheduleStore->update(
            id: $id,
            scheduleExpression: $cron,
            prompt: isset($args['prompt']) ? trim((string) $args['prompt']) : null,
            role: $args['role'] ?? null,
            maxIterations: $maxIterations,
            enabled: isset($args['enabled']) ? (bool) $args['enabled'] : null,
        );

        $updated = $this->scheduleStore->get($id);
        if ($updated === null) {
            return ToolResult::error('Schedule not found after update');
        }

        return ToolResult::success((string) json_encode([
            'message' => "Schedule '{$updated['name']}' updated",
            'schedule' => $updated,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeDelete(array $args): ToolResult
    {
        $id = trim((string) ($args['id'] ?? ''));

        if (strtolower($id) === 'all') {
            $count = $this->scheduleStore->deleteAll();
            return ToolResult::success("Deleted all schedules ({$count} total).");
        }

        $schedule = $this->resolveSchedule($id);
        if ($schedule === null) {
            return ToolResult::error('Schedule not found');
        }

        $name = (string) $schedule['name'];
        $this->scheduleStore->delete((string) $schedule['id']);

        return ToolResult::success("Schedule '{$name}' deleted.");
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeTrigger(array $args): ToolResult
    {
        $id = trim((string) ($args['id'] ?? ''));

        if (strtolower($id) === 'all') {
            return $this->executeTriggerAll();
        }

        $schedule = $this->resolveSchedule($id);
        if ($schedule === null) {
            return ToolResult::error('Schedule not found');
        }

        $scheduleId = (string) $schedule['id'];
        $name = (string) $schedule['name'];

        // Fallback: force next_run_at to now so the scheduler picks it up on next tick
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->scheduleStore->update($scheduleId, enabled: true);
        $this->scheduleStore->forceNextRun($scheduleId, $now);

        return ToolResult::success((string) json_encode([
            'message' => "Schedule '{$name}' will be triggered on next scheduler tick (within 60 seconds)",
            'schedule_id' => $scheduleId,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Trigger all enabled schedules.
     */
    private function executeTriggerAll(): ToolResult
    {
        $enabled = $this->scheduleStore->list(enabled: true);
        if ($enabled === []) {
            return ToolResult::success('No enabled schedules to trigger.');
        }

        // Fallback: force next_run_at to now
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $count = 0;
        foreach ($enabled as $s) {
            $this->scheduleStore->forceNextRun((string) $s['id'], $now);
            $count++;
        }

        return ToolResult::success((string) json_encode([
            'message' => "{$count} schedule(s) will be triggered on next scheduler tick (within 60 seconds)",
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeEnable(array $args): ToolResult
    {
        $id = trim((string) ($args['id'] ?? ''));

        if (strtolower($id) === 'all') {
            $count = $this->scheduleStore->enableAll();
            return ToolResult::success("Enabled {$count} schedule(s). Failure counters reset.");
        }

        $schedule = $this->resolveSchedule($id);
        if ($schedule === null) {
            return ToolResult::error('Schedule not found');
        }

        $name = (string) $schedule['name'];
        $this->scheduleStore->enable((string) $schedule['id']);

        return ToolResult::success("Schedule '{$name}' enabled. Failure counter reset.");
    }

    /**
     * @param array<string, mixed> $args
     */
    private function executeDisable(array $args): ToolResult
    {
        $id = trim((string) ($args['id'] ?? ''));

        if (strtolower($id) === 'all') {
            $count = $this->scheduleStore->disableAll();
            return ToolResult::success("Disabled {$count} schedule(s).");
        }

        $schedule = $this->resolveSchedule($id);
        if ($schedule === null) {
            return ToolResult::error('Schedule not found');
        }

        $name = (string) $schedule['name'];
        $this->scheduleStore->disable((string) $schedule['id']);

        return ToolResult::success("Schedule '{$name}' disabled.");
    }

    /**
     * Resolve a schedule by ID or name.
     *
     * @return array<string, mixed>|null
     */
    private function resolveSchedule(string $idOrName): ?array
    {
        if ($idOrName === '') {
            return null;
        }

        // Try by ID first
        $schedule = $this->scheduleStore->get($idOrName);
        if ($schedule !== null) {
            return $schedule;
        }

        // Fall back to name lookup
        return $this->scheduleStore->getByName($idOrName);
    }
}
