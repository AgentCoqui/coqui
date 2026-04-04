<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Observer;

use CoquiBot\Coqui\Api\ProcessCancellationToken;
use SplObserver;
use SplSubject;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Decorator over TerminalObserver that detects ESC keypresses during agent execution.
 *
 * On each agent event (streaming chunk, tool call, etc.), performs a non-blocking
 * read on STDIN. If an ESC byte (0x1B) is detected, sets the cancellation token so
 * the agent stops cooperatively after its current operation.
 *
 * Requires the terminal to be in raw mode (min 0 time 0) so ESC is delivered
 * immediately without waiting for Enter. RunCommand manages this via stty before
 * and after each agent turn.
 *
 * Falls back to a no-op if STDIN is not a TTY (piped input, Docker, CI).
 */
final class EscCancellationObserver implements SplObserver
{
    private readonly bool $isTty;
    /** @var resource */
    private readonly mixed $stdin;
    private bool $messageShown = false;

    /** Set to true by RunCommand during agent execution; false otherwise. */
    public bool $active = false;

    /**
     * @param resource|null $stdin  Injectable stdin resource — defaults to STDIN. Used in tests.
     * @param bool|null     $isTty  Override TTY detection — pass true/false in tests to avoid
     *                              calling posix_isatty() on non-TTY streams like php://memory.
     */
    public function __construct(
        private readonly TerminalObserver $inner,
        private ProcessCancellationToken $token,
        private readonly OutputInterface $output,
        mixed $stdin = null,
        ?bool $isTty = null,
    ) {
        $this->stdin = $stdin ?? STDIN;
        // stream_isatty() is preferred over posix_isatty() for PHP stream resources —
        // posix_isatty() can return false on macOS for php://stdin even in a real TTY.
        $this->isTty = $isTty ?? (is_resource($this->stdin) && stream_isatty($this->stdin));
    }

    /**
     * Replace the cancellation token for a new REPL turn.
     *
     * Called by RunCommand before each agent turn so each turn gets a fresh token.
     * Also resets the "message shown" flag so the cancellation hint can appear again.
     */
    public function setToken(ProcessCancellationToken $token): void
    {
        $this->token = $token;
        $this->messageShown = false;
    }

    public function poll(): void
    {
        if (!$this->active || !$this->isTty || $this->token->isCancelled()) {
            return;
        }

        /** @var false|array<string, mixed> $meta */
        $meta = @stream_get_meta_data($this->stdin);
        $isSelectable = is_array($meta) && empty($meta['seekable']);

        if ($isSelectable) {
            $read = [$this->stdin];
            $write = $except = [];
            $available = @stream_select($read, $write, $except, 0, 0);
        } else {
            $available = 1;
        }

        $byte = ($available > 0) ? @fread($this->stdin, 1) : false;

        if ($byte !== "\x1B") {
            return;
        }

        $this->token->cancel();

        if ($this->messageShown) {
            return;
        }

        $this->messageShown = true;
        $this->output->write("\r\033[K");
        $this->output->writeln(
            "\n<fg=yellow>⚑ Cancellation requested — returning to the REPL...</>",
        );
    }

    public function update(SplSubject $subject): void
    {
        $this->poll();

        $this->inner->update($subject);
    }
}
