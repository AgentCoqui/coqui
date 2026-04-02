<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\ProcessSpawner;

/**
 * Manages child processes for interactive API agent turns.
 *
 * Each agent turn runs as a separate PHP process via `bin/coqui turn:run`.
 * This keeps the API server's ReactPHP event loop fully responsive —
 * other HTTP requests (health checks, session listing, SSE polling) are
 * never blocked by LLM provider calls.
 *
 * Events are persisted to the `task_events` table by the child process
 * (via BackgroundTaskObserver) and polled by the parent via SSE timers.
 *
 * The manager is ticked periodically by a ReactPHP timer. On each tick it:
 * 1. Checks running processes for termination
 * 2. Cleans up finished processes
 */
final class AgentTurnManager
{
    private const GRACEFUL_SHUTDOWN_TIMEOUT_MS = 3000;

    /** @var array<string, resource> Process handles keyed by turn process ID */
    private array $processes = [];

    /** @var array<string, array<int, resource>> Pipe handles per turn process */
    private array $pipes = [];

    /** @var array<string, int> PID keyed by turn process ID */
    private array $pids = [];

    /** @var array<string, string> Map session ID → turn process ID for active turns */
    private array $sessionTurns = [];

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $coquiBinPath,
        private readonly string $configPath,
        private readonly string $workDir,
        private readonly string $workspacePath = '',
        private readonly bool $unsafeMode = false,
    ) {}

    /**
     * Start an agent turn in a child process.
     *
     * Creates a turn process record, spawns `bin/coqui turn:run <id>`,
     * and returns the turn process ID for SSE event polling.
     *
     * @param string[]|null $filePaths
     */
    public function start(string $sessionId, string $prompt, ?array $filePaths = null): ?string
    {
        // Create the turn process record
        $turnProcessId = $this->storage->createTurnProcess($sessionId, $prompt, $filePaths);

        if (!$this->spawnProcess($turnProcessId)) {
            return null;
        }

        $this->sessionTurns[$sessionId] = $turnProcessId;

        return $turnProcessId;
    }

    /**
     * Periodic tick — check running processes for termination.
     *
     * Called by the ReactPHP event loop timer.
     */
    public function tick(): void
    {
        $this->reapFinishedProcesses();
    }

    /**
     * Cancel an active turn for a session — send SIGTERM to the process group.
     */
    public function cancel(string $sessionId): bool
    {
        $turnProcessId = $this->sessionTurns[$sessionId] ?? null;

        if ($turnProcessId === null) {
            return false;
        }

        if (!isset($this->processes[$turnProcessId])) {
            return false;
        }

        $pid = $this->pids[$turnProcessId] ?? 0;

        if ($pid > 0) {
            ProcessSpawner::killProcessGroup($pid, SIGTERM);
        } else {
            proc_terminate($this->processes[$turnProcessId]);
        }

        return true;
    }

    /**
     * Gracefully shut down all running turn processes.
     *
     * Sends SIGTERM to each process group, waits up to 3s, then escalates
     * to SIGKILL for any that refuse to exit. Called during API server shutdown.
     */
    public function shutdown(): void
    {
        foreach (array_keys($this->processes) as $turnProcessId) {
            $process = $this->processes[$turnProcessId];
            $pid = $this->pids[$turnProcessId] ?? 0;

            ProcessSpawner::terminateGracefully($process, $pid, self::GRACEFUL_SHUTDOWN_TIMEOUT_MS);
            $this->closeProcess($turnProcessId);
        }

        $this->sessionTurns = [];
    }

    /**
     * Number of currently running turn processes.
     */
    public function activeCount(): int
    {
        return count($this->processes);
    }

    /**
     * Check if a session has an active turn process.
     */
    public function isActive(string $sessionId): bool
    {
        return isset($this->sessionTurns[$sessionId]);
    }

    /**
     * Check for finished processes, update status, and clean up.
     *
     * Uses conditional UPDATE to avoid overwriting status the child already committed.
     */
    private function reapFinishedProcesses(): void
    {
        foreach ($this->processes as $turnProcessId => $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                continue;
            }

            $exitCode = $status['exitcode'];

            // Read stderr for diagnostics
            $stderr = '';
            if (isset($this->pipes[$turnProcessId][2])) {
                $stderr = stream_get_contents($this->pipes[$turnProcessId][2]) ?: '';
            }

            $this->closeProcess($turnProcessId);

            // Remove session mapping
            $sessionId = array_search($turnProcessId, $this->sessionTurns, true);
            if (is_string($sessionId)) {
                unset($this->sessionTurns[$sessionId]);
            }

            // Conditional update — only set failed if child hasn't already written final status
            $error = $stderr !== '' ? mb_substr($stderr, 0, 1000) : 'Process exited unexpectedly';
            $updated = $this->storage->updateTurnProcessStatusConditional($turnProcessId, 'failed', 'running', [
                'error' => sprintf('Exit code %d: %s', $exitCode, $error),
            ]);

            if ($updated) {
                $this->storage->appendTaskEvent($turnProcessId, 'error', [
                    'message' => sprintf('Process exited with code %d', $exitCode),
                ]);
                $this->storage->appendTaskEvent($turnProcessId, 'complete', [
                    'error' => 'Process exited unexpectedly',
                    'content' => '',
                ]);
            }
        }
    }

    /**
     * Spawn a child process for a turn using process group isolation.
     */
    private function spawnProcess(string $turnProcessId): bool
    {
        $cmd = [
            PHP_BINARY,
            $this->coquiBinPath,
            'turn:run',
            $turnProcessId,
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
            $this->storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                'error' => 'Failed to spawn turn process',
            ]);
            $this->storage->appendTaskEvent($turnProcessId, 'error', [
                'message' => 'Failed to spawn turn process',
            ]);
            $this->storage->appendTaskEvent($turnProcessId, 'complete', [
                'error' => 'Failed to spawn turn process',
                'content' => '',
            ]);

            return false;
        }

        $this->processes[$turnProcessId] = $result['process'];
        $this->pipes[$turnProcessId] = $result['pipes'];

        // Track PID for process group kills
        $pid = ProcessSpawner::getPid($result['process']);
        if ($pid > 0) {
            $this->pids[$turnProcessId] = $pid;
            $this->storage->updateTurnProcessStatus($turnProcessId, 'running', [
                'pid' => $pid,
            ]);
        }

        return true;
    }

    /**
     * Close process handles and pipes.
     */
    private function closeProcess(string $turnProcessId): void
    {
        if (isset($this->pipes[$turnProcessId])) {
            foreach ($this->pipes[$turnProcessId] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            unset($this->pipes[$turnProcessId]);
        }

        if (isset($this->processes[$turnProcessId])) {
            proc_close($this->processes[$turnProcessId]);
            unset($this->processes[$turnProcessId]);
        }

        unset($this->pids[$turnProcessId]);
    }
}
