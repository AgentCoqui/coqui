<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Shared utility for spawning child processes with process group management.
 *
 * On Unix systems, commands are wrapped with setsid (Linux) or a Perl-based
 * fallback (macOS) so the child becomes a session leader in its own process
 * group. This ensures that killProcessGroup() terminates ALL descendants —
 * not just the immediate child, but also any shell commands, curl processes,
 * or other subprocesses it may have spawned.
 */
final class ProcessSpawner
{
    private const int GRACEFUL_POLL_INTERVAL_US = 100_000; // 100ms

    private static ?bool $hasSetsid = null;

    private static ?bool $hasPerl = null;

    /**
     * Spawn a child process with process group isolation.
     *
     * The child becomes a session leader so all its descendants can be killed
     * as a group via killProcessGroup().
     *
     * @param list<string> $cmd Command to execute (e.g. [PHP_BINARY, 'bin/coqui', 'task:run', $id])
     * @param string $workDir Working directory for the child process
     * @return array{process: resource, pipes: array<int, resource>}|null Null on failure
     */
    public static function spawn(array $cmd, string $workDir): ?array
    {
        $cmd = self::wrapWithSetsid($cmd);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open(
            $cmd,
            $descriptors,
            $pipes,
            $workDir,
            null, // inherit environment
        );

        if (!is_resource($process)) {
            return null;
        }

        // Close stdin — child processes don't read from it
        fclose($pipes[0]);
        unset($pipes[0]);

        // Set stdout and stderr to non-blocking so tick() doesn't hang
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * Get the PID of a spawned process.
     *
     * When setsid wraps the command, proc_get_status returns the setsid wrapper PID.
     * The actual child (the PHP process) has a different PID that becomes the
     * session leader. We store the PID from proc_get_status, which is the
     * process group leader we can target with negative-PID kills.
     *
     * @param resource $process
     */
    public static function getPid($process): int
    {
        $status = proc_get_status($process);

        return $status['pid'] > 0 ? $status['pid'] : 0;
    }

    /**
     * Send a signal to the entire process group led by $pid.
     *
     * Uses negative PID to target the group. Falls back to single-process
     * signal when posix_kill is unavailable (Windows).
     */
    public static function killProcessGroup(int $pid, int $signal = SIGTERM): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            // Negative PID = send signal to entire process group
            $groupKilled = posix_kill(-$pid, $signal);

            if (!$groupKilled) {
                // Fallback: try single PID (group may not exist if setsid wasn't used)
                return posix_kill($pid, $signal);
            }

            return true;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return self::windowsTaskKill($pid, $signal === SIGKILL);
        }

        return false;
    }

    /**
     * Terminate a process gracefully: SIGTERM → wait → SIGKILL.
     *
     * Sends SIGTERM to the process group, waits up to $timeoutMs for exit,
     * then escalates to SIGKILL if the process is still running.
     *
     * @param resource $process proc_open resource
     */
    public static function terminateGracefully($process, int $pid, int $timeoutMs = 3000): void
    {
        if ($pid <= 0) {
            return;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            return;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            self::windowsTaskKill($pid, false);

            $elapsedUs = 0;
            $timeoutUs = $timeoutMs * 1000;

            while ($elapsedUs < $timeoutUs) {
                usleep(self::GRACEFUL_POLL_INTERVAL_US);
                $elapsedUs += self::GRACEFUL_POLL_INTERVAL_US;

                if (!self::isProcessAlive($pid)) {
                    return;
                }
            }

            self::windowsTaskKill($pid, true);
            return;
        }

        // Phase 1: SIGTERM to the process group
        self::killProcessGroup($pid, SIGTERM);

        // Phase 2: Poll for exit
        $elapsedUs = 0;
        $timeoutUs = $timeoutMs * 1000;

        while ($elapsedUs < $timeoutUs) {
            usleep(self::GRACEFUL_POLL_INTERVAL_US);
            $elapsedUs += self::GRACEFUL_POLL_INTERVAL_US;

            $status = proc_get_status($process);
            if (!$status['running']) {
                return; // Exited cleanly
            }
        }

        // Phase 3: Force kill the entire process group
        self::killProcessGroup($pid, SIGKILL);
    }

    /**
     * Check if a PID is still alive.
     *
     * Uses signal 0 (no actual signal sent) to test process existence.
     */
    public static function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $output = self::runCommand([
                self::windowsPowerShellBinary(),
                '-NoProfile',
                '-Command',
                sprintf('Get-Process -Id %d -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Id', $pid),
            ]);

            return trim($output ?? '') === (string) $pid;
        }

        return false;
    }

    /**
     * Wrap a command array with setsid so the child becomes a session leader.
     *
     * - Linux: uses `setsid` binary
     * - macOS: uses Perl POSIX::setsid() fallback
     * - Windows/unsupported: returns command unchanged
     *
     * @param list<string> $cmd
     * @return list<string>
     */
    private static function wrapWithSetsid(array $cmd): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return $cmd;
        }

        // Linux: use setsid binary
        if (self::hasSetsid()) {
            return ['setsid', '--fork', ...$cmd];
        }

        // macOS / other Unix: Perl-based setsid fallback
        if (self::hasPerl()) {
            return ['perl', '-e', 'use POSIX qw(setsid); setsid() or die; exec(@ARGV)', '--', ...$cmd];
        }

        // No setsid available — fall back to bare command
        return $cmd;
    }

    private static function hasSetsid(): bool
    {
        if (self::$hasSetsid === null) {
            // Check that setsid exists and supports --fork (Linux setsid from util-linux)
            exec('setsid --fork true 2>/dev/null', $output, $exitCode);
            self::$hasSetsid = $exitCode === 0;
        }

        return self::$hasSetsid;
    }

    private static function hasPerl(): bool
    {
        if (self::$hasPerl === null) {
            exec('perl -e "use POSIX qw(setsid)" 2>/dev/null', $output, $exitCode);
            self::$hasPerl = $exitCode === 0;
        }

        return self::$hasPerl;
    }

    private static function windowsTaskKill(int $pid, bool $force): bool
    {
        $command = ['taskkill'];
        if ($force) {
            $command[] = '/F';
        }
        array_push($command, '/PID', (string) $pid, '/T');

        $exitCode = self::runCommandExitCode($command);

        return $exitCode === 0 || !self::isProcessAlive($pid);
    }

    private static function windowsPowerShellBinary(): string
    {
        return 'powershell';
    }

    /**
     * @param list<string> $command
     */
    private static function runCommand(array $command): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, null);
        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout === false ? null : $stdout;
    }

    /**
     * @param list<string> $command
     */
    private static function runCommandExitCode(array $command): int
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, null, null);
        if (!is_resource($process)) {
            return 1;
        }

        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    }
}
