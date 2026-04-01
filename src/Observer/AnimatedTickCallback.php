<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Animated spinner tick callback for the REPL.
 *
 * Called at strategic points in the agent run loop (between stream chunks,
 * between tool calls, after provider calls). Updates the terminal status
 * line with a spinner animation and polls STDIN for ESC keypresses.
 *
 * Throttled to ≥50ms between redraws to avoid flicker.
 */
final class AnimatedTickCallback implements TickCallbackInterface
{
    private const array FRAMES = ['⠋', '⠙', '⠹', '⠸', '⠼', '⠴', '⠦', '⠧', '⠇', '⠏'];

    /** Minimum interval between redraws in seconds. */
    private const float THROTTLE_SECONDS = 0.05;

    private int $frameIndex = 0;
    private float $lastDrawTime = 0.0;
    private string $context = '';
    private bool $active = false;
    private bool $statusLineVisible = false;

    public function __construct(
        private readonly OutputInterface $output,
        private ?ProcessCancellationToken $cancellationToken = null,
        /** @var resource|null */
        private readonly mixed $stdin = null,
    ) {}

    /**
     * Start the animation with optional context text.
     */
    public function start(string $context = ''): void
    {
        $this->context = $context;
        $this->active = true;
        $this->frameIndex = 0;
        $this->lastDrawTime = 0.0;
        $this->draw();
    }

    /**
     * Stop the animation and clear the status line.
     */
    public function stop(): void
    {
        $this->active = false;
        $this->clearStatusLine();
    }

    /**
     * Temporarily suspend the animation without resetting state.
     *
     * Used by TerminalObserver when streaming text begins — all tick()
     * calls from the periodic timer and AbstractAgent become no-ops
     * until resume() is called.
     */
    public function suspend(): void
    {
        $this->active = false;
        $this->clearStatusLine();
    }

    /**
     * Resume a suspended animation.
     *
     * Re-enables tick() so the next call (from the periodic timer or
     * an explicit showStatusLine) will redraw the spinner.
     */
    public function resume(): void
    {
        $this->active = true;
    }

    /**
     * Update the context text (e.g. "Working on exec...").
     */
    public function setContext(string $context): void
    {
        $this->context = $context;
    }

    public function setCancellationToken(ProcessCancellationToken $token): void
    {
        $this->cancellationToken = $token;
    }

    #[\Override]
    public function tick(): void
    {
        if (!$this->active) {
            return;
        }

        $this->pollEsc();

        $now = microtime(true);
        if (($now - $this->lastDrawTime) < self::THROTTLE_SECONDS) {
            return;
        }

        $this->draw();
        $this->lastDrawTime = $now;
    }

    /**
     * Draw the current spinner frame on the status line.
     */
    private function draw(): void
    {
        $frame = self::FRAMES[$this->frameIndex % count(self::FRAMES)];
        $this->frameIndex++;

        $label = $this->context !== '' ? "Working on {$this->context}" : 'Working';
        $this->output->write("\r\033[K  <fg=cyan>{$frame}</> <fg=gray>{$label}...</> <fg=#666666>(press ESC to cancel)</>");
        $this->statusLineVisible = true;
    }

    /**
     * Clear the status line if visible.
     */
    public function clearStatusLine(): void
    {
        if (!$this->statusLineVisible) {
            return;
        }
        $this->output->write("\r\033[K");
        $this->statusLineVisible = false;
    }

    /**
     * Non-blocking poll for ESC keypress on STDIN.
     */
    private function pollEsc(): void
    {
        if ($this->cancellationToken === null || $this->cancellationToken->isCancelled()) {
            return;
        }

        $stdin = $this->stdin ?? \STDIN;
        if (!is_resource($stdin) || !stream_isatty($stdin)) {
            return;
        }

        $read = [$stdin];
        $write = $except = [];
        if (@stream_select($read, $write, $except, 0, 0) > 0) {
            $char = fread($stdin, 1);
            if ($char === "\x1b") {
                $this->cancellationToken->cancel();
                $this->clearStatusLine();
                $this->output->writeln('  <fg=yellow>⚠ Cancellation requested (ESC) — finishing current operation...</>');
            }
        }
    }
}
