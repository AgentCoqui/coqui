<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Manages background task child processes.
 *
 * Each background task runs as a separate PHP process via `bin/coqui task:run`.
 * This keeps the API server's ReactPHP event loop fully responsive during
 * long-running agent executions.
 *
 * The manager is ticked periodically by a ReactPHP timer. On each tick it:
 * 1. Checks running processes for termination
 * 2. Starts pending tasks up to the concurrency limit
 */
final class BackgroundTaskManager
{
    private const EVENT_RETENTION_DAYS = 7;
    private const CLEANUP_INTERVAL_TICKS = 300;

    /** @var array<string, resource> Process handles keyed by task ID */
    private array $processes = [];

    /** @var array<string, array{0: resource, 1: resource, 2: resource}> Pipe handles per task */
    private array $pipes = [];

    private int $tickCount = 0;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $coquiBinPath,
        private readonly string $configPath,
        private readonly string $workDir,
        private readonly int $maxConcurrent = 1,
        private readonly bool $unsafeMode = false,
    ) {}

    /**
     * Periodic tick — check running processes, handle cancel requests, and start pending tasks.
     *
     * Called by the ReactPHP event loop timer every ~1 second.
     */
    public function tick(): void
    {
        $this->reapFinishedProcesses();
        $this->processCancelRequests();
        $this->startPendingTasks();

        // Lazy cleanup of old task events (~every 5 minutes)
        $this->tickCount++;
        if ($this->tickCount >= self::CLEANUP_INTERVAL_TICKS) {
            $this->tickCount = 0;
            $this->storage->purgeOldTaskEvents(self::EVENT_RETENTION_DAYS);
        }
    }

    /**
     * Start a specific task immediately (if under the concurrency limit).
     *
     * Returns true if the process was started, false if queued.
     */
    public function start(string $taskId): bool
    {
        if (count($this->processes) >= $this->maxConcurrent) {
            return false;
        }

        return $this->spawnProcess($taskId);
    }

    /**
     * Cancel a task — send SIGTERM to running process or mark pending as cancelled.
     */
    public function cancel(string $taskId): bool
    {
        $task = $this->storage->getTask($taskId);

        if ($task === null) {
            return false;
        }

        if ($task['status'] === 'pending') {
            $this->storage->updateTaskStatus($taskId, 'cancelled');
            $this->storage->appendTaskEvent($taskId, 'cancelled', [
                'message' => 'Cancelled while pending',
            ]);

            return true;
        }

        if ($task['status'] === 'running' && isset($this->processes[$taskId])) {
            $process = $this->processes[$taskId];
            $status = proc_get_status($process);

            if ($status['running'] && $status['pid'] > 0) {
                // Send SIGTERM to the process group
                posix_kill($status['pid'], SIGTERM);
            }

            return true;
        }

        return false;
    }

    /**
     * Gracefully shut down all running task processes.
     *
     * Sends SIGTERM to each running task and closes all process handles.
     * Called during API server shutdown.
     */
    public function shutdown(): void
    {
        foreach (array_keys($this->processes) as $taskId) {
            $process = $this->processes[$taskId];
            $status = proc_get_status($process);

            if ($status['running'] && $status['pid'] > 0 && function_exists('posix_kill')) {
                posix_kill($status['pid'], SIGTERM);
            }

            $this->closeProcess($taskId);
        }
    }

    /**
     * Number of currently running task processes.
     */
    public function activeCount(): int
    {
        return count($this->processes);
    }

    /**
     * Check if a specific task has an active process.
     */
    public function isRunning(string $taskId): bool
    {
        return isset($this->processes[$taskId]);
    }

    /**
     * Number of pending tasks in the queue.
     */
    public function pendingCount(): int
    {
        return count($this->storage->getPendingTasks());
    }

    /**
     * Check for finished processes and update their task status.
     */
    private function reapFinishedProcesses(): void
    {
        foreach ($this->processes as $taskId => $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                continue;
            }

            // Process has terminated — clean up
            $exitCode = $status['exitcode'];

            // Read any stderr output for diagnostics
            $stderr = '';
            if (isset($this->pipes[$taskId][2])) {
                $stderr = stream_get_contents($this->pipes[$taskId][2]) ?: '';
            }

            $this->closeProcess($taskId);

            // The TaskRunCommand updates the task status itself via SQLite.
            // Only handle cases where it crashed before updating (abnormal exit).
            $task = $this->storage->getTask($taskId);

            if ($task !== null && $task['status'] === 'running') {
                // Process died without updating status — mark as failed
                $error = $stderr !== '' ? mb_substr($stderr, 0, 1000) : 'Process exited unexpectedly';
                $this->storage->updateTaskStatus($taskId, 'failed', [
                    'error' => sprintf('Exit code %d: %s', $exitCode, $error),
                ]);
                $this->storage->appendTaskEvent($taskId, 'failed', [
                    'error' => sprintf('Process exited with code %d', $exitCode),
                    'stderr' => mb_substr($stderr, 0, 500),
                ]);
            }
        }
    }

    /**
     * Start pending tasks up to the concurrency limit.
     */
    private function startPendingTasks(): void
    {
        $available = $this->maxConcurrent - count($this->processes);

        if ($available <= 0) {
            return;
        }

        $pendingTasks = $this->storage->getPendingTasks($available);

        foreach ($pendingTasks as $task) {
            $this->spawnProcess($task['id']);
        }
    }

    /**
     * Spawn a child process for a task.
     */
    private function spawnProcess(string $taskId): bool
    {
        $cmd = [
            PHP_BINARY,
            $this->coquiBinPath,
            'task:run',
            $taskId,
            '--config', $this->configPath,
            '--workdir', $this->workDir,
        ];

        if ($this->unsafeMode) {
            $cmd[] = '--unsafe';
        }

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $this->workDir,
            null, // inherit environment
        );

        if (!is_resource($process)) {
            $this->storage->updateTaskStatus($taskId, 'failed', [
                'error' => 'Failed to spawn task process',
            ]);

            return false;
        }

        // Close stdin — the task process doesn't read from it
        fclose($pipes[0]);

        // Set stdout and stderr to non-blocking so tick() doesn't hang
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->processes[$taskId] = $process;
        $this->pipes[$taskId] = $pipes;

        // Update task with the PID
        $status = proc_get_status($process);
        if ($status['pid'] > 0) {
            $this->storage->updateTaskStatus($taskId, 'running', ['pid' => $status['pid']]);
        }

        return true;
    }

    /**
     * Close process handles and pipes.
     */
    private function closeProcess(string $taskId): void
    {
        if (isset($this->pipes[$taskId])) {
            foreach ($this->pipes[$taskId] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            unset($this->pipes[$taskId]);
        }

        if (isset($this->processes[$taskId])) {
            proc_close($this->processes[$taskId]);
            unset($this->processes[$taskId]);
        }
    }

    /**
     * Handle tasks marked as 'cancelling' by the toolkit or REPL.
     *
     * Sends SIGTERM to the child process so it can shut down cooperatively.
     * If the process isn't tracked (orphan cancel request), mark as cancelled directly.
     */
    private function processCancelRequests(): void
    {
        $tasks = $this->storage->getTasksByStatus('cancelling');

        foreach ($tasks as $task) {
            $taskId = $task['id'];

            if (isset($this->processes[$taskId])) {
                $process = $this->processes[$taskId];
                $status = proc_get_status($process);

                if ($status['running'] && $status['pid'] > 0) {
                    posix_kill($status['pid'], SIGTERM);
                }
                // Process will be reaped on next tick — TaskRunCommand sets final status
            } else {
                // No tracked process — cancel directly
                $this->storage->updateTaskStatus($taskId, 'cancelled');
                $this->storage->appendTaskEvent($taskId, 'cancelled', [
                    'message' => 'Cancelled (no active process found)',
                ]);
            }
        }
    }
}
