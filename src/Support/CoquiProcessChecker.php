<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Determines whether a recorded PID still belongs to a live Coqui worker.
 *
 * Used by the storage layer to decide whether an orphaned task, turn, or
 * title-job process can be safely requeued. An optional checker closure
 * lets tests and alternative environments override the OS-level probe.
 */
final class CoquiProcessChecker
{
    /** @var (\Closure(int, string): bool)|null */
    private $override;

    /**
     * @param (\Closure(int, string): bool)|null $override Optional checker used instead of the OS probe.
     */
    public function __construct(?\Closure $override = null)
    {
        $this->override = $override;
    }

    /**
     * Whether $pid is a live process running `bin/coqui <subcommand>`.
     */
    public function isExpectedCoquiProcessAlive(int $pid, string $subcommand): bool
    {
        if ($this->override !== null) {
            return ($this->override)($pid, $subcommand);
        }

        if ($pid <= 0 || !ProcessSpawner::isProcessAlive($pid)) {
            return false;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? $this->windowsProcessCommandLine($pid)
            : shell_exec(sprintf('ps -o command= -p %d 2>/dev/null', $pid));

        if (!is_string($command) || trim($command) === '') {
            return false;
        }

        return str_contains($command, 'bin/coqui')
            && str_contains($command, $subcommand);
    }

    private function windowsProcessCommandLine(int $pid): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([
            'powershell',
            '-NoProfile',
            '-Command',
            sprintf('(Get-CimInstance Win32_Process -Filter "ProcessId = %d" -ErrorAction SilentlyContinue).CommandLine', $pid),
        ], $descriptors, $pipes, null, null);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout === false ? null : trim($stdout);
    }
}
