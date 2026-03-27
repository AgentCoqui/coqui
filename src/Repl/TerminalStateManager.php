<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

/**
 * Manages terminal state for ESC key detection during agent execution.
 *
 * Handles saving/restoring stty state, switching to raw mode for byte-by-byte
 * ESC delivery, and draining leftover bytes from STDIN after each turn.
 *
 * All operations are no-ops when STDIN is not an interactive TTY (piped input,
 * Docker without PTY, CI environments).
 */
final class TerminalStateManager
{
    /**
     * Returns true when STDIN is an interactive TTY.
     *
     * Used to gate all stty operations — piped input, Docker without PTY, and CI
     * environments will return false, causing ESC detection to degrade gracefully.
     */
    public function isInteractiveTty(): bool
    {
        // stream_isatty() is more reliable than posix_isatty() for PHP stream resources.
        // posix_isatty() can return false on macOS for php://stdin even in a real TTY.
        return stream_isatty(STDIN);
    }

    /**
     * Save the current terminal state via `stty -g` so it can be restored later.
     *
     * Returns null when not a TTY or when stty is unavailable.
     */
    public function saveState(): ?string
    {
        if (!$this->isInteractiveTty()) {
            return null;
        }

        $state = shell_exec('stty -g 2>/dev/null');

        return is_string($state) ? trim($state) : null;
    }

    /**
     * Switch the terminal to raw, non-blocking mode so ESC is delivered
     * byte-by-byte without waiting for Enter.
     *
     * `min 0 time 0` causes `fread()` to return immediately even when no bytes
     * are available, which is required for the non-blocking STDIN check in
     * EscCancellationObserver::update().
     */
    public function enterRawMode(): void
    {
        if (!$this->isInteractiveTty()) {
            return;
        }

        shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');
        // Also set PHP's stream layer to non-blocking so fread() returns immediately
        // when no bytes are available, regardless of the OS-level stty settings.
        stream_set_blocking(STDIN, false);
    }

    /**
     * Restore the terminal to a previously saved state.
     *
     * Called after each agent turn and from the shutdown function if the process
     * crashes while in raw mode.
     */
    public function restoreState(?string $state): void
    {
        if ($state === null || $state === '' || !$this->isInteractiveTty()) {
            return;
        }

        shell_exec('stty ' . escapeshellarg($state) . ' 2>/dev/null');
        // Restore PHP stream to blocking so readline's stream_select loop works correctly.
        stream_set_blocking(STDIN, true);
    }

    /**
     * Drain any pending bytes from STDIN without blocking.
     *
     * Called after each agent turn to discard leftover ESC keypresses or other
     * stray bytes that accumulated during execution. Prevents them from being
     * misread as cancellation signals at the start of the next turn.
     */
    public function drainStdin(): void
    {
        if (!$this->isInteractiveTty()) {
            return;
        }

        $read = [STDIN];
        $write = $except = [];
        while (@stream_select($read, $write, $except, 0, 0) > 0) {
            @fread(STDIN, 128);
            $read = [STDIN];
            $write = $except = [];
        }
    }

    /**
     * Register a shutdown function that restores the terminal state if the
     * process crashes while in raw mode.
     *
     * Returns a callback that should be called with the saved stty state
     * before entering raw mode, and with null after restoring.
     *
     * @return \Closure(?string): void
     */
    public function registerShutdownGuard(): \Closure
    {
        $shutdownStty = null;

        register_shutdown_function(static function () use (&$shutdownStty): void {
            // PHPStan cannot model that $shutdownStty (captured by reference) is
            // mutated later; it treats it as always-null inside the closure.
            // @phpstan-ignore booleanAnd.alwaysFalse, notIdentical.alwaysFalse, notIdentical.alwaysTrue
            if ($shutdownStty !== null && $shutdownStty !== '') {
                shell_exec('stty ' . escapeshellarg($shutdownStty) . ' 2>/dev/null');
            }
        });

        return static function (?string $state) use (&$shutdownStty): void {
            $shutdownStty = $state;
        };
    }
}
