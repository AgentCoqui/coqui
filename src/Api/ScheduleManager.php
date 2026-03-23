<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Periodically evaluates scheduled tasks and creates background tasks for due schedules.
 *
 * Runs inside the ReactPHP event loop via a 60-second periodic timer.
 * On each tick, queries ScheduleStore for enabled schedules whose next_run_at
 * has passed, creates background tasks via SessionStorage, and updates
 * the schedule records. Enforces a minimum 60-second gap between consecutive
 * runs of the same schedule.
 *
 * Also polls for recently completed schedule-linked tasks to update
 * the schedule's success/failure counters.
 */
final class ScheduleManager
{
    /** Minimum seconds between consecutive runs of the same schedule. */
    private const int MIN_INTERVAL_SECONDS = 60;

    /** @var callable|null Emits events (e.g., schedule.triggered, schedule.disabled) */
    private $onNotify;

    public function __construct(
        private readonly ScheduleStore $scheduleStore,
        private readonly SessionStorage $storage,
    ) {}

    /**
     * Set a callback for emitting events.
     *
     * @param callable(string $event, array<string, mixed> $data): void $callback
     */
    public function setOnNotify(callable $callback): void
    {
        $this->onNotify = $callback;
    }

    /**
     * Periodic tick — evaluate due schedules and create background tasks.
     *
     * Called by a ReactPHP periodic timer every ~60 seconds.
     */
    public function tick(): void
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $this->processReadySchedules($now);
        $this->checkCompletedTasks();
    }

    /**
     * Find and execute all schedules that are due.
     */
    private function processReadySchedules(\DateTimeImmutable $now): void
    {
        $readySchedules = $this->scheduleStore->getReadySchedules($now);

        foreach ($readySchedules as $schedule) {
            try {
                $this->executeSchedule($schedule, $now);
            } catch (\Throwable $e) {
                $scheduleId = (string) $schedule['id'];
                $scheduleName = (string) $schedule['name'];

                // Mark as failed so the circuit breaker can track it
                $this->scheduleStore->markFailed($scheduleId);

                $this->notify('schedule.error', [
                    'schedule_id' => $scheduleId,
                    'schedule_name' => $scheduleName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Create a background task for a due schedule.
     *
     * @param array<string, mixed> $schedule
     */
    private function executeSchedule(array $schedule, \DateTimeImmutable $now): void
    {
        $scheduleId = (string) $schedule['id'];
        $scheduleName = (string) $schedule['name'];

        // Enforce minimum interval between runs
        if ($schedule['last_run_at'] !== null) {
            try {
                $lastRun = new \DateTimeImmutable($schedule['last_run_at']);
                $elapsed = $now->getTimestamp() - $lastRun->getTimestamp();
                if ($elapsed < self::MIN_INTERVAL_SECONDS) {
                    $this->notify('schedule.skipped', [
                        'schedule_id' => $scheduleId,
                        'schedule_name' => $scheduleName,
                        'reason' => sprintf('Minimum interval not elapsed (%ds since last run, requires %ds)', $elapsed, self::MIN_INTERVAL_SECONDS),
                    ]);
                    return;
                }
            } catch (\Throwable) {
                // Invalid timestamp — proceed with execution
            }
        }

        // Create a dedicated session for the scheduled task
        $role = (string) ($schedule['role'] ?? 'orchestrator');
        $sessionId = $this->storage->createSession($role, 'scheduled-task');

        // Create the background task
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: (string) $schedule['prompt'],
            role: $role,
            title: sprintf('[Scheduled] %s', $scheduleName),
            maxIterations: (int) ($schedule['max_iterations'] ?? 48),
        );

        // Update schedule record
        $this->scheduleStore->markExecuted($scheduleId, $taskId);

        $this->notify('schedule.triggered', [
            'schedule_id' => $scheduleId,
            'schedule_name' => $scheduleName,
            'task_id' => $taskId,
        ]);
    }

    /**
     * Check completed tasks linked to schedules and update success/failure counters.
     */
    private function checkCompletedTasks(): void
    {
        // Get all enabled schedules that have a last_task_id but no last_status update yet
        $schedules = $this->scheduleStore->list(enabled: null);

        foreach ($schedules as $schedule) {
            $taskId = $schedule['last_task_id'] ?? null;
            if ($taskId === null) {
                continue;
            }

            // Skip if we've already processed this task's outcome
            if ($schedule['last_status'] !== null) {
                continue;
            }

            $task = $this->storage->getTask($taskId);
            if ($task === null) {
                continue;
            }

            $status = $task['status'];
            $scheduleId = (string) $schedule['id'];

            if ($status === 'completed') {
                $this->scheduleStore->markSuccess($scheduleId);
            } elseif ($status === 'failed') {
                $wasDisabled = $this->scheduleStore->markFailed($scheduleId);
                if ($wasDisabled) {
                    $this->notify('schedule.disabled', [
                        'schedule_id' => $scheduleId,
                        'schedule_name' => (string) $schedule['name'],
                        'reason' => sprintf(
                            'Auto-disabled after %d consecutive failures',
                            (int) $schedule['max_failures'],
                        ),
                    ]);
                }
            } elseif ($status === 'cancelled') {
                // Treat cancellation as neutral — don't increment failure count
                $now = gmdate('Y-m-d\TH:i:s\Z');
                $this->scheduleStore->update($scheduleId, metadata: json_encode([
                    'last_status_note' => 'Task was cancelled',
                ]) ?: null);
            }
            // If still running or pending, skip — check again next tick
        }
    }

    /**
     * Force immediate execution of a schedule (bypass timing).
     */
    public function trigger(string $scheduleId): ?string
    {
        $schedule = $this->scheduleStore->get($scheduleId);
        if ($schedule === null) {
            return null;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        $role = (string) ($schedule['role'] ?? 'orchestrator');
        $sessionId = $this->storage->createSession($role, 'scheduled-task');

        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: (string) $schedule['prompt'],
            role: $role,
            title: sprintf('[Triggered] %s', (string) $schedule['name']),
            maxIterations: (int) ($schedule['max_iterations'] ?? 48),
        );

        $this->scheduleStore->markExecuted($scheduleId, $taskId);

        $this->notify('schedule.triggered', [
            'schedule_id' => $scheduleId,
            'schedule_name' => (string) $schedule['name'],
            'task_id' => $taskId,
            'manual' => true,
        ]);

        return $taskId;
    }

    /**
     * Emit an event notification.
     *
     * @param array<string, mixed> $data
     */
    private function notify(string $event, array $data): void
    {
        if ($this->onNotify !== null) {
            ($this->onNotify)($event, $data);
        }
    }
}
