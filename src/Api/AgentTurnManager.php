<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\AgentTurnResult;
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
 * Events are persisted to the `turn_events` table by the child process
 * (via TurnProcessObserver) and polled by the parent via SSE timers.
 *
 * The manager is ticked periodically by a ReactPHP timer. On each tick it:
 * 1. Checks running processes for termination
 * 2. Cleans up finished processes
 */
final class AgentTurnManager
{
    private const GRACEFUL_SHUTDOWN_TIMEOUT_MS = 3000;

    /**
     * How long to keep waiting for a detached child to record its own PID
     * before treating a wrapper exit + missing PID as a failed spawn. Bounds
     * the boot window so a slow-booting turn is not mistaken for a crash.
     */
    private const float CHILD_LIVENESS_GRACE_SECONDS = 10.0;

    /** @var array<string, resource> Process handles keyed by turn process ID */
    private array $processes = [];

    /** @var array<string, array<int, resource>> Pipe handles per turn process */
    private array $pipes = [];

    /** @var array<string, int> PID keyed by turn process ID */
    private array $pids = [];

    /** @var array<string, string> Map session ID → turn process ID for active turns */
    private array $sessionTurns = [];

    /** @var array<string, float> Wall-clock time each wrapper was first observed exited */
    private array $wrapperExitedAt = [];

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $coquiBinPath,
        private readonly string $configPath,
        private readonly string $workDir,
        private readonly string $workspacePath = '',
        private readonly bool $unsafeMode = false,
        private readonly float $childLivenessGraceSeconds = self::CHILD_LIVENESS_GRACE_SECONDS,
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
     * The tracked handle is the `setsid --fork` wrapper (see {@see ProcessSpawner}),
     * which detaches the real `turn:run` child into its own session and then exits
     * almost immediately. `proc_get_status` therefore reports the WRAPPER's exit,
     * never the real child's — so a clean wrapper exit tells us nothing about
     * whether the turn actually finished. We consult the DB status and the child's
     * own recorded PID before concluding anything, and only synthesize a failure
     * when the real child is genuinely gone without having recorded a terminal
     * status.
     */
    private function reapFinishedProcesses(): void
    {
        foreach ($this->processes as $turnProcessId => $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                continue;
            }

            $turnProcess = $this->storage->getTurnProcess($turnProcessId);
            $dbStatus = is_array($turnProcess) ? (string) ($turnProcess['status'] ?? '') : '';

            // The child records its own terminal status (TurnRunCommand). If it
            // already did, the turn is genuinely done — just release the handle.
            if ($dbStatus === 'completed' || $dbStatus === 'failed') {
                $this->releaseProcess($turnProcessId);
                continue;
            }

            // Not terminal yet. Is the real child still alive? The child records
            // its own PID (getmypid), which differs from the wrapper PID we
            // captured at spawn.
            $wrapperPid = $this->pids[$turnProcessId] ?? 0;
            $childPid = is_array($turnProcess) ? (int) ($turnProcess['pid'] ?? 0) : 0;
            $childPidRecorded = $childPid > 0 && $childPid !== $wrapperPid;

            if ($childPidRecorded && ProcessSpawner::isProcessAlive($childPid)) {
                // Real turn:run child is still executing — check again next tick.
                continue;
            }

            if (!$childPidRecorded) {
                // The child has not recorded its own PID yet (still booting, or it
                // died before it could). Give it a bounded grace period so a slow
                // boot is not mistaken for a crash.
                $firstSeen = $this->wrapperExitedAt[$turnProcessId] ??= microtime(true);
                if ((microtime(true) - $firstSeen) < $this->childLivenessGraceSeconds) {
                    continue;
                }
            }

            // The real child is gone (confirmed dead, or never recorded a PID
            // within the grace window) with no terminal status → a genuine crash.
            $exitCode = $status['exitcode'];

            $stderr = '';
            if (isset($this->pipes[$turnProcessId][2])) {
                $stderr = stream_get_contents($this->pipes[$turnProcessId][2]) ?: '';
            }

            $this->releaseProcess($turnProcessId);

            // Conditional update — only set failed if child hasn't already written final status
            $error = $stderr !== '' ? mb_substr($stderr, 0, 1000) : 'Process exited unexpectedly';
            $updated = $this->storage->updateTurnProcessStatusConditional($turnProcessId, 'failed', 'running', [
                'error' => sprintf('Exit code %d: %s', $exitCode, $error),
            ]);

            if ($updated) {
                $this->storage->appendTurnEvent($turnProcessId, 'error', [
                    'message' => sprintf('Process exited with code %d', $exitCode),
                ]);
                $this->storage->appendTurnEvent(
                    $turnProcessId,
                    'complete',
                    AgentTurnResult::fromError('Process exited unexpectedly')->toArray(),
                );
            }
        }
    }

    /**
     * Release all tracking for a turn process: close the handle/pipes, drop the
     * session mapping, and clear the wrapper-exit timestamp.
     */
    private function releaseProcess(string $turnProcessId): void
    {
        $this->closeProcess($turnProcessId);

        unset($this->wrapperExitedAt[$turnProcessId]);

        $sessionId = array_search($turnProcessId, $this->sessionTurns, true);
        if (is_string($sessionId)) {
            unset($this->sessionTurns[$sessionId]);
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
            $this->storage->appendTurnEvent($turnProcessId, 'error', [
                'message' => 'Failed to spawn turn process',
            ]);
            $this->storage->appendTurnEvent(
                $turnProcessId,
                'complete',
                AgentTurnResult::fromError('Failed to spawn turn process')->toArray(),
            );

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
