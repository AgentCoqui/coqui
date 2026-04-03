<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\WatchJobInterface;
use CoquiBot\Coqui\Contract\WatchJobResult;

/**
 * Generic polling-based workspace watcher.
 *
 * Holds registered WatchJobInterface instances and scans them on each tick.
 * Designed to be driven by a ReactPHP periodic timer in ApiCommand.
 *
 * Jobs are responsible for their own state tracking (mtime maps, hashes, etc.).
 * The watcher simply iterates and aggregates results.
 */
final class WorkspaceWatcher
{
    /** @var array<string, WatchJobInterface> Keyed by job name */
    private array $jobs = [];

    /** @var array<string, WatchJobResult> Last result per job name */
    private array $lastResults = [];

    public function register(WatchJobInterface $job): void
    {
        $this->jobs[$job->name()] = $job;
    }

    /**
     * Run all registered watch jobs and return aggregated results.
     *
     * Called by ReactPHP timer on each tick.
     *
     * @return array<string, WatchJobResult> Results keyed by job name
     */
    public function tick(): array
    {
        $results = [];

        foreach ($this->jobs as $name => $job) {
            try {
                $result = $job->scan();
            } catch (\Throwable $e) {
                $result = new WatchJobResult(errors: [$e->getMessage()]);
            }

            $results[$name] = $result;
            $this->lastResults[$name] = $result;
        }

        return $results;
    }

    /**
     * Run initial sync for all jobs (alias for tick on first boot).
     */
    public function initialSync(): array
    {
        return $this->tick();
    }

    /**
     * @return array<string, WatchJobResult>
     */
    public function getLastResults(): array
    {
        return $this->lastResults;
    }

    /**
     * @return list<string>
     */
    public function getJobNames(): array
    {
        return array_keys($this->jobs);
    }

    public function hasJobs(): bool
    {
        return $this->jobs !== [];
    }
}
