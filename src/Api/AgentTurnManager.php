<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\SessionStorage;

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
    /** @var array<string, resource> Process handles keyed by turn process ID */
    private array $processes = [];

    /** @var array<string, array<int, resource>> Pipe handles per turn process */
    private array $pipes = [];

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
     * Cancel an active turn for a session — send SIGTERM to the child process.
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

        $process = $this->processes[$turnProcessId];
        $status = proc_get_status($process);

        if ($status['running'] && $status['pid'] > 0 && function_exists('posix_kill')) {
            posix_kill($status['pid'], SIGTERM);
        }

        return true;
    }

    /**
     * Gracefully shut down all running turn processes.
     *
     * Called during API server shutdown.
     */
    public function shutdown(): void
    {
        foreach (array_keys($this->processes) as $turnProcessId) {
            $process = $this->processes[$turnProcessId];
            $status = proc_get_status($process);

            if ($status['running'] && $status['pid'] > 0 && function_exists('posix_kill')) {
                posix_kill($status['pid'], SIGTERM);
            }

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

            // Check if the child process updated status itself
            $turnProcess = $this->storage->getTurnProcess($turnProcessId);

            if ($turnProcess !== null && $turnProcess['status'] === 'running') {
                // Process died without updating status — mark as failed
                $error = $stderr !== '' ? mb_substr($stderr, 0, 1000) : 'Process exited unexpectedly';
                $this->storage->updateTurnProcessStatus($turnProcessId, 'failed', [
                    'error' => sprintf('Exit code %d: %s', $exitCode, $error),
                ]);
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
     * Spawn a child process for a turn.
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

        // Close stdin — the turn process doesn't read from it
        fclose($pipes[0]);

        // Set stdout and stderr to non-blocking so tick() doesn't hang
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $this->processes[$turnProcessId] = $process;
        $this->pipes[$turnProcessId] = $pipes;

        // Update turn with the PID
        $status = proc_get_status($process);
        if ($status['pid'] > 0) {
            $this->storage->updateTurnProcessStatus($turnProcessId, 'running', [
                'pid' => $status['pid'],
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
    }
}
