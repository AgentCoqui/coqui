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
    /** @var resource */
    private readonly mixed $stdin;
    private readonly bool $isTty;

    /**
     * @param resource|null $stdin  Injectable stdin resource — defaults to STDIN. Used in tests.
     * @param bool|null     $isTty  Override TTY detection for non-TTY test streams.
     */
    public function __construct(mixed $stdin = null, ?bool $isTty = null)
    {
        $this->stdin = $stdin ?? STDIN;
        $this->isTty = $isTty ?? (is_resource($this->stdin) && stream_isatty($this->stdin));
    }

    /**
     * Returns true when STDIN is an interactive TTY.
     *
     * Used to gate all stty operations — piped input, Docker without PTY, and CI
     * environments will return false, causing ESC detection to degrade gracefully.
     */
    public function isInteractiveTty(): bool
    {
        return $this->isTty;
    }

    /**
     * Save the current terminal state via `stty -g` so it can be restored later.
     *
     * Returns null when not a TTY or when stty is unavailable.
     */
    public function saveState(): ?string
    {
        if (!$this->isInteractiveTty() || !$this->targetsProcessStdin()) {
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

        if ($this->targetsProcessStdin()) {
            shell_exec('stty -icanon -echo min 0 time 0 2>/dev/null');
        }

        // Also set PHP's stream layer to non-blocking so fread() returns immediately
        // when no bytes are available, regardless of the OS-level stty settings.
        stream_set_blocking($this->stdin, false);
    }

    /**
     * Restore the terminal to a previously saved state.
     *
     * Called after each agent turn and from the shutdown function if the process
     * crashes while in raw mode.
     */
    public function restoreState(?string $state): void
    {
        if (!$this->isInteractiveTty()) {
            return;
        }

        if ($state !== null && $state !== '' && $this->targetsProcessStdin()) {
            shell_exec('stty ' . escapeshellarg($state) . ' 2>/dev/null');
        }

        // Restore PHP stream to blocking so readline's stream_select loop works correctly.
        stream_set_blocking($this->stdin, true);
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

        $read = [$this->stdin];
        $write = $except = [];
        while (@stream_select($read, $write, $except, 0, 0) > 0) {
            $chunk = @fread($this->stdin, 128);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $read = [$this->stdin];
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
        $shutdownState = new class () {
            public ?string $stty = null;
        };
        $shouldRestore = $this->isInteractiveTty() && $this->targetsProcessStdin();

        register_shutdown_function(static function () use ($shutdownState, $shouldRestore): void {
            if ($shouldRestore && $shutdownState->stty !== null && $shutdownState->stty !== '') {
                shell_exec('stty ' . escapeshellarg($shutdownState->stty) . ' 2>/dev/null');
            }
        });

        return static function (?string $state) use ($shutdownState): void {
            $shutdownState->stty = $state;
        };
    }

    private function targetsProcessStdin(): bool
    {
        return defined('STDIN') && $this->stdin === STDIN;
    }
}
