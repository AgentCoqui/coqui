<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\ProcessSpawner;

/**
 * Manages internal session title worker processes.
 *
 * Title generation is intentionally decoupled from interactive response
 * delivery. The API server periodically reaps finished title workers and
 * starts pending jobs from the session_title_jobs queue.
 */
final class SessionTitleJobManager
{
    private const GRACEFUL_SHUTDOWN_TIMEOUT_MS = 3000;

    /** @var array<string, resource> */
    private array $processes = [];

    /** @var array<string, array<int, resource>> */
    private array $pipes = [];

    /** @var array<string, int> */
    private array $pids = [];

    private ?string $lastTickAt = null;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly string $coquiBinPath,
        private readonly string $configPath,
        private readonly string $workDir,
        private readonly string $workspacePath = '',
        private readonly int $maxConcurrent = 2,
        private readonly bool $unsafeMode = false,
    ) {}

    public function tick(): void
    {
        $this->lastTickAt = gmdate('Y-m-d\TH:i:s\Z');
        $this->reapFinishedProcesses();
        $this->startPendingJobs();
    }

    public function shutdown(): void
    {
        foreach (array_keys($this->processes) as $jobId) {
            $process = $this->processes[$jobId];
            $pid = $this->pids[$jobId] ?? 0;

            ProcessSpawner::terminateGracefully($process, $pid, self::GRACEFUL_SHUTDOWN_TIMEOUT_MS);
            $this->closeProcess($jobId);
        }
    }

    public function activeCount(): int
    {
        return count($this->processes);
    }

    public function pendingCount(): int
    {
        return count($this->storage->getPendingSessionTitleJobs());
    }

    public function lastTickAt(): ?string
    {
        return $this->lastTickAt;
    }

    private function reapFinishedProcesses(): void
    {
        foreach ($this->processes as $jobId => $process) {
            $status = proc_get_status($process);

            if ($status['running']) {
                continue;
            }

            $exitCode = $status['exitcode'];
            $stderr = '';
            if (isset($this->pipes[$jobId][2])) {
                $stderr = stream_get_contents($this->pipes[$jobId][2]) ?: '';
            }

            $this->closeProcess($jobId);

            $error = $stderr !== '' ? mb_substr($stderr, 0, 1000) : 'Process exited unexpectedly';
            $this->storage->updateSessionTitleJobStatusConditional(
                $jobId,
                'failed',
                'running',
                ['error' => sprintf('Exit code %d: %s', $exitCode, $error)],
            );
        }
    }

    private function startPendingJobs(): void
    {
        $available = $this->maxConcurrent - count($this->processes);
        if ($available <= 0) {
            return;
        }

        $jobs = $this->storage->getPendingSessionTitleJobs($available);
        foreach ($jobs as $job) {
            $this->spawnProcess((string) $job['id']);
        }
    }

    private function spawnProcess(string $jobId): bool
    {
        $cmd = [
            PHP_BINARY,
            $this->coquiBinPath,
            'session-title:run',
            $jobId,
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
            $this->storage->updateSessionTitleJobStatus($jobId, 'failed', ['error' => 'Failed to spawn session title worker']);
            return false;
        }

        $this->processes[$jobId] = $result['process'];
        $this->pipes[$jobId] = $result['pipes'];

        $pid = ProcessSpawner::getPid($result['process']);
        $extra = $pid > 0 ? ['pid' => $pid] : [];
        if ($pid > 0) {
            $this->pids[$jobId] = $pid;
        }

        $this->storage->updateSessionTitleJobStatusConditional($jobId, 'running', 'pending', $extra);

        return true;
    }

    private function closeProcess(string $jobId): void
    {
        if (isset($this->pipes[$jobId])) {
            foreach ($this->pipes[$jobId] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            unset($this->pipes[$jobId]);
        }

        if (isset($this->processes[$jobId])) {
            proc_close($this->processes[$jobId]);
            unset($this->processes[$jobId]);
        }

        unset($this->pids[$jobId]);
    }
}