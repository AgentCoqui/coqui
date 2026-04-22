<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

use CoquiBot\Coqui\Repl\TerminalStateManager;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Full-screen TUI event loop that manages a stack of interactive screens.
 *
 * Enters raw terminal mode, renders the active screen, polls STDIN for
 * keyboard input, and calls tick() for periodic data refresh. Screens are
 * composed via push/pop navigation (e.g. dashboard → detail → back).
 *
 * Designed to be reusable: any ScreenInterface implementation can be driven
 * by this runner. After run() returns, the terminal is restored and control
 * returns to the REPL.
 */
final class ScreenRunner
{
    /** Microseconds between STDIN polls. */
    private const int POLL_INTERVAL_USEC = 200_000;

    /** Seconds between tick() calls for data refresh. */
    private const float TICK_INTERVAL_SEC = 1.0;

    /** Maximum bytes to read per keypress (covers multi-byte escape sequences). */
    private const int READ_BUFFER_SIZE = 8;

    public function __construct(
        private readonly TerminalStateManager $terminalState,
        private readonly OutputInterface $output,
    ) {}

    /**
     * Run the TUI event loop with the given initial screen.
     *
     * Blocks until the user exits (ESC from the root screen, or ScreenAction::exit()).
     * Terminal state is always restored on exit, even on crash.
     */
    public function run(ScreenInterface $initial): void
    {
        if (!$this->terminalState->isInteractiveTty()) {
            return;
        }

        $savedState = $this->terminalState->saveState();
        $shutdownGuard = $this->terminalState->registerShutdownGuard();
        $shutdownGuard($savedState);

        /** @var list<ScreenInterface> $stack */
        $stack = [$initial];

        try {
            $this->terminalState->enterRawMode();
            $this->installSignalHandler($savedState);

            $terminal = new Terminal();
            $frameRenderer = new ScreenFrameRenderer($this->output);
            $lastTickAt = microtime(true);
            $needsRender = true;
            $lastWidth = 0;
            $lastHeight = 0;
            $previousFrame = null;

            while ($stack !== []) {
                $screen = $stack[array_key_last($stack)];
                $width = $terminal->getWidth();
                $height = $terminal->getHeight();

                if ($width !== $lastWidth || $height !== $lastHeight) {
                    $lastWidth = $width;
                    $lastHeight = $height;
                    $needsRender = true;
                    $previousFrame = null;
                }

                if ($needsRender) {
                    $frame = $this->renderFrame($screen, $width, $height);

                    if ($previousFrame === null || !$frame->sharesViewport($previousFrame)) {
                        $this->clearScreen();
                        $frameRenderer->renderFull($frame);
                    } else {
                        $frameRenderer->renderDiff($previousFrame, $frame);
                    }

                    $previousFrame = $frame;
                    $needsRender = false;
                }

                // Poll STDIN for keyboard input
                $key = $this->pollKeypress();

                if ($key !== null) {
                    $action = $screen->handleKey($key);

                    if ($action === null) {
                        // null = local state changed, re-render
                        $needsRender = true;
                        continue;
                    }

                    if ($action->isExit()) {
                        break;
                    }

                    if ($action->isPush() && $action->screen !== null) {
                        $stack[] = $action->screen;
                        $needsRender = true;
                        $previousFrame = null;
                        continue;
                    }

                    if ($action->isPop()) {
                        array_pop($stack);
                        $needsRender = true;
                        $previousFrame = null;
                        continue;
                    }

                    // refresh action
                    $needsRender = true;
                    continue;
                }

                // Periodic tick for data refresh
                $now = microtime(true);
                if ($now - $lastTickAt >= self::TICK_INTERVAL_SEC) {
                    $lastTickAt = $now;
                    if ($screen->tick()) {
                        $needsRender = true;
                    }
                }
            }
        } finally {
            $this->clearScreen();
            $this->terminalState->restoreState($savedState);
            $this->terminalState->drainStdin();
            $shutdownGuard(null);
        }
    }

    private function clearScreen(): void
    {
        $this->output->write("\e[2J\e[H");
    }

    private function renderFrame(ScreenInterface $screen, int $width, int $height): ScreenFrame
    {
        $buffer = new BufferedOutput(
            verbosity: $this->output->getVerbosity(),
            decorated: $this->output->isDecorated(),
            formatter: $this->output->getFormatter(),
        );

        $screen->render($buffer, $width, $height);

        return ScreenFrame::fromRenderedOutput($buffer->fetch(), $width, $height);
    }

    /**
     * Poll STDIN for a keypress with a short timeout.
     *
     * Returns null if no input is available within the poll interval.
     */
    private function pollKeypress(): ?KeyEvent
    {
        $read = [STDIN];
        $write = $except = [];

        /** @var int|false $ready */
        $ready = @stream_select($read, $write, $except, 0, self::POLL_INTERVAL_USEC);

        if ($ready === false || $ready === 0) {
            return null;
        }

        $bytes = @fread(STDIN, self::READ_BUFFER_SIZE);
        if ($bytes === false || $bytes === '') {
            return null;
        }

        return KeyEvent::fromBytes($bytes);
    }

    private function installSignalHandler(?string $savedState): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGINT, function () use ($savedState): void {
            $this->clearScreen();
            $this->terminalState->restoreState($savedState);
            // Allow the REPL's own SIGINT handler to take over
        });
    }
}
