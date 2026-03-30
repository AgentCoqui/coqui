<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Support\ProcessSpawner;

/**
 * Manages background task child processes.
 *
 * Each background task runs as a separate PHP process via `bin/coqui task:run`.
 * This keeps the API server's ReactPHP event loop fully responsive during
 * long-running agent executions.
 *
 * The manager is ticked periodically by a ReactPHP timer. On each tick it:
 * 1. Checks running processes for termination (reap)
 * 2. Handles cancel requests
 * 3. Detects stale/timed-out processes
 * 4. Starts pending tasks up to the concurrency limit
 */
final class BackgroundTaskManager
{
    private const EVENT_RETENTION_DAYS = 7;
    private const CLEANUP_INTERVAL_TICKS = 300;
    private const STALE_CHECK_INTERVAL_TICKS = 60;
    private const GRACEFUL_SHUTDOWN_TIMEOUT_MS = 3000;

    /** @var array<string, resource> Process handles keyed by task ID */
    private array $processes = [];

    /** @var array<string, array<int, resource>> Pipe handles per task */
    private array $pipes = [];

    /** @var array<string, int> PID keyed by task ID (for process group kills) */
    private array $pids = [];

    private int $tickCount = 0;

    private int $staleCheckTickCount = 0;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $coquiBinPath,
        private readonly string $configPath,
        private readonly string $workDir,
        private readonly string $workspacePath = '',
        private readonly int $maxConcurrent = CoquiDefaults::MAX_CONCURRENT_TASKS,
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

        // Stale heartbeat + max execution time checks (~every 60 seconds)
        $this->staleCheckTickCount++;
        if ($this->staleCheckTickCount >= self::STALE_CHECK_INTERVAL_TICKS) {
            $this->staleCheckTickCount = 0;
            $this->killStaleTasks();
            $this->killTimedOutTasks();
        }

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
            $pid = $this->pids[$taskId] ?? 0;

            if ($pid > 0) {
                ProcessSpawner::killProcessGroup($pid, SIGTERM);
            } else {
                proc_terminate($this->processes[$taskId]);
            }

            return true;
        }

        return false;
    }

    /**
     * Gracefully shut down all running task processes.
     *
     * Sends SIGTERM to each process group, waits up to 3s, then escalates
     * to SIGKILL for any that refuse to exit. Called during API server shutdown.
     */
    public function shutdown(): void
    {
        foreach (array_keys($this->processes) as $taskId) {
            $process = $this->processes[$taskId];
            $pid = $this->pids[$taskId] ?? 0;

            ProcessSpawner::terminateGracefully($process, $pid, self::GRACEFUL_SHUTDOWN_TIMEOUT_MS);
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
     *
     * Uses conditional UPDATE (WHERE status = 'running') to avoid a race condition
     * where the child process has already committed its final status.
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
            // Use conditional update to avoid overwriting a status the child already committed.
            $error = $stderr !== '' ? mb_substr($stderr, 0, 1000) : 'Process exited unexpectedly';
            $updated = $this->storage->updateTaskStatusConditional(
                $taskId,
                'failed',
                'running',
                ['error' => sprintf('Exit code %d: %s', $exitCode, $error)],
            );

            if ($updated) {
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
     * Spawn a child process for a task using process group isolation.
     */
    private function spawnProcess(string $taskId): bool
    {
        $cmd = [
            PHP_BINARY,
            $this->coquiBinPath,
            'task:run',
            $taskId,
            '--workdir', $this->workDir,
        ];

        if ($this->workspacePath !== '') {
            $cmd[] = '--workspace';
            $cmd[] = $this->workspacePath;
        }

        if ($this->configPath !== '') {
            $cmd[] = '--config';
            $cmd[] = $this->configPath;
        }

        if ($this->unsafeMode) {
            $cmd[] = '--unsafe';
        }

        $result = ProcessSpawner::spawn($cmd, $this->workDir);

        if ($result === null) {
            $this->storage->updateTaskStatus($taskId, 'failed', [
                'error' => 'Failed to spawn task process',
            ]);

            return false;
        }

        $this->processes[$taskId] = $result['process'];
        $this->pipes[$taskId] = $result['pipes'];

        // Track PID for process group kills
        $pid = ProcessSpawner::getPid($result['process']);
        if ($pid > 0) {
            $this->pids[$taskId] = $pid;
            $this->storage->updateTaskStatus($taskId, 'running', ['pid' => $pid]);
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

        unset($this->pids[$taskId]);
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
                $pid = $this->pids[$taskId] ?? 0;

                if ($pid > 0) {
                    ProcessSpawner::killProcessGroup($pid, SIGTERM);
                } else {
                    proc_terminate($this->processes[$taskId]);
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

    /**
     * Kill tasks whose heartbeat has gone stale (no heartbeat for 5+ minutes).
     */
    private function killStaleTasks(): void
    {
        $staleTasks = $this->storage->getStaleRunningTasks();

        foreach ($staleTasks as $task) {
            $taskId = $task['id'];
            $pid = $this->pids[$taskId] ?? ((int) ($task['pid'] ?? 0));

            if ($pid > 0) {
                ProcessSpawner::killProcessGroup($pid, SIGTERM);
            }

            if (isset($this->processes[$taskId])) {
                $this->closeProcess($taskId);
            }

            $this->storage->updateTaskStatusConditional($taskId, 'failed', 'running', [
                'error' => 'Process stale — no heartbeat for 5 minutes',
            ]);
            $this->storage->appendTaskEvent($taskId, 'failed', [
                'error' => 'Killed: no heartbeat for 5 minutes',
            ]);
        }
    }

    /**
     * Kill tasks that have exceeded their max execution time.
     */
    private function killTimedOutTasks(): void
    {
        $timedOutTasks = $this->storage->getTimedOutRunningTasks();

        foreach ($timedOutTasks as $task) {
            $taskId = $task['id'];
            $maxSeconds = (int) ($task['max_execution_seconds'] ?? CoquiDefaults::BACKGROUND_TASK_MAX_EXECUTION_SECONDS);
            $pid = $this->pids[$taskId] ?? ((int) ($task['pid'] ?? 0));

            if ($pid > 0) {
                ProcessSpawner::killProcessGroup($pid, SIGTERM);
            }

            if (isset($this->processes[$taskId])) {
                $this->closeProcess($taskId);
            }

            $this->storage->updateTaskStatusConditional($taskId, 'failed', 'running', [
                'error' => sprintf('Exceeded maximum execution time (%d seconds)', $maxSeconds),
            ]);
            $this->storage->appendTaskEvent($taskId, 'failed', [
                'error' => sprintf('Killed: exceeded max execution time (%ds)', $maxSeconds),
            ]);
        }
    }
}
