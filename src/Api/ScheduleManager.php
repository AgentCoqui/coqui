<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Evaluates due schedules and creates background tasks for execution.
 *
 * Ticked every 60 seconds by a ReactPHP periodic timer in ApiCommand.
 * On each tick:
 *   1. Queries ScheduleStore for enabled schedules past their next_run_at
 *   2. Creates a session + background task for each due schedule
 *   3. Records the task ID on the schedule via markExecuted()
 *
 * A separate reconciliation timer (every 10s) checks recently completed
 * tasks to update schedule success/failure counters and circuit breaker state.
 */
final class ScheduleManager
{
    /**
     * Minimum seconds between consecutive runs of the same schedule.
     * Prevents duplicate enqueue if a tick fires before markExecuted() commits.
     */
    private const MIN_INTERVAL_SECONDS = 60;

    /** @var array<string, true> In-flight task IDs to prevent double-enqueue within a tick cycle */
    private array $inFlight = [];

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly ScheduleStore $scheduleStore,
    ) {}

    /**
     * Evaluate due schedules and create background tasks.
     *
     * Called by ReactPHP timer every 60 seconds.
     */
    public function tick(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $readySchedules = $this->scheduleStore->getReadySchedules($now);

        foreach ($readySchedules as $schedule) {
            $this->executeSchedule($schedule, $now);
        }
    }

    /**
     * Check schedule-linked tasks that have completed and update counters.
     *
     * Iterates schedules whose last_status is still NULL (not yet reconciled),
     * looks up the task by last_task_id, and updates success/failure counters.
     *
     * Called by ReactPHP timer every 10 seconds.
     */
    public function reconcile(): void
    {
        $pendingReconciliation = $this->scheduleStore->getSchedulesPendingReconciliation();

        foreach ($pendingReconciliation as $schedule) {
            $taskId = $schedule['last_task_id'] ?? null;
            if ($taskId === null || $taskId === '') {
                continue;
            }

            $task = $this->storage->getTask($taskId);
            if ($task === null) {
                continue;
            }

            $taskStatus = (string) $task['status'];

            // Only reconcile terminal statuses
            if ($taskStatus === 'completed') {
                $this->scheduleStore->markSuccess((string) $schedule['id']);
            } elseif ($taskStatus === 'failed' || $taskStatus === 'cancelled') {
                $this->scheduleStore->markFailed((string) $schedule['id']);
            }
            // 'pending' and 'running' — not yet terminal, skip
        }

        // Clean up in-flight tracking
        $this->inFlight = [];
    }

    /**
     * Create a background task for a due schedule.
     *
     * @param array<string, mixed> $schedule
     */
    private function executeSchedule(array $schedule, \DateTimeImmutable $now): void
    {
        $scheduleId = (string) $schedule['id'];

        // Guard: only turn-kind schedules dispatch a background task here.
        // Loop-kind schedules persist through the public API but their dispatch
        // is deferred to the future loops profile; running one as a turn would
        // fire an empty-prompt turn every tick, so skip it entirely.
        if ((string) ($schedule['action_kind'] ?? 'turn') !== 'turn') {
            return;
        }

        // Guard: duplicate enqueue protection
        if (isset($this->inFlight[$scheduleId])) {
            return;
        }

        // Guard: enforce minimum interval between runs
        $lastRunAt = $schedule['last_run_at'] ?? null;
        if ($lastRunAt !== null && $lastRunAt !== '') {
            $lastRun = new \DateTimeImmutable($lastRunAt, new \DateTimeZone('UTC'));
            $elapsed = $now->getTimestamp() - $lastRun->getTimestamp();
            if ($elapsed < self::MIN_INTERVAL_SECONDS) {
                return;
            }
        }

        $role = (string) ($schedule['role'] ?? SystemRole::Orchestrator->value);
        $prompt = (string) ($schedule['prompt'] ?? '');
        $maxIterations = (int) ($schedule['max_iterations'] ?? 48);
        $scheduleName = (string) ($schedule['name'] ?? 'schedule');
        $activePersona = $this->extractSchedulePersona($schedule);

        // Create a session for this scheduled task
        $sessionId = $this->storage->createSession(
            modelRole: $role,
            model: '',
            persona: $activePersona,
            visibility: 'hidden',
        );

        // Create the background task linked to the schedule
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $prompt,
            role: $role,
            title: sprintf('Schedule: %s', $scheduleName),
            maxIterations: min($maxIterations, 100),
            scheduleId: $scheduleId,
        );

        // Mark the schedule as executed — updates next_run_at and handles @once
        $this->scheduleStore->markExecuted($scheduleId, $taskId);

        // Track in-flight to prevent double-enqueue
        $this->inFlight[$scheduleId] = true;
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function extractSchedulePersona(array $schedule): ?string
    {
        $metadata = $schedule['metadata'] ?? null;
        if (!is_string($metadata) || trim($metadata) === '') {
            return null;
        }

        try {
            $decoded = json_decode($metadata, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $persona = $decoded['persona'] ?? null;

        return is_string($persona) && $persona !== '' ? $persona : null;
    }
}
