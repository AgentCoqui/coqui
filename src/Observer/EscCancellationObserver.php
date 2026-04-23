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
 * read on STDIN. If a standalone ESC byte (0x1B) is confirmed instead of the start
 * of an ANSI escape sequence, sets the cancellation token so the agent stops
 * cooperatively after its current operation.
 *
 * Requires the terminal to be in raw mode (min 0 time 0) so ESC is delivered
 * immediately without waiting for Enter. RunCommand manages this via stty before
 * and after each agent turn. ANSI sequences such as arrow keys are consumed and
 * ignored so they do not cancel the request or leak into the next readline prompt.
 *
 * Falls back to a no-op if STDIN is not a TTY (piped input, Docker, CI).
 */
final class EscCancellationObserver implements SplObserver
{
    private const int ESC_SEQUENCE_GRACE_USEC = 100000;

    private readonly bool $isTty;
    /** @var resource */
    private readonly mixed $stdin;
    private bool $messageShown = false;
    private string $pendingEscape = '';
    private ?float $pendingEscapeStartedAt = null;

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
        $this->resetPendingEscape();
    }

    /**
     * Begin a fresh REPL turn.
     *
     * Resets per-turn state, drains any stale pending input from the prior turn,
     * and enables ESC polling for the new token.
     */
    public function beginTurn(ProcessCancellationToken $token): void
    {
        $this->token = $token;
        $this->messageShown = false;
        $this->resetPendingEscape();
        $this->drainPendingInput();
        $this->inner->setActorContext(null, null);
        $this->active = true;
    }

    /**
     * End the current REPL turn.
     *
     * Disables polling first so queued timer callbacks become no-ops, then
     * drains any leftover bytes to prevent false cancellation on the next turn.
     */
    public function endTurn(): void
    {
        $this->active = false;
        $this->messageShown = false;
        $this->resetPendingEscape();
        $this->drainPendingInput();
        $this->inner->setActorContext(null, null);
    }

    public function setActorContext(?string $actorName, ?string $actorRole): void
    {
        $this->inner->setActorContext($actorName, $actorRole);
    }

    public function handleEvent(string $event, mixed $data): void
    {
        $this->poll();
        $this->inner->handleEvent($event, $data);
    }

    public function poll(): void
    {
        if (!$this->active || !$this->isTty || $this->token->isCancelled()) {
            return;
        }

        $this->consumeAvailableInput();
        $this->cancelPendingEscapeIfConfirmed();
    }

    public function update(SplSubject $subject): void
    {
        $this->poll();

        $this->inner->update($subject);
    }

    private function drainPendingInput(): void
    {
        if (!$this->isTty) {
            return;
        }

        $this->resetPendingEscape();

        if ($this->isSelectableStream()) {
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

            return;
        }

        while (true) {
            $chunk = @fread($this->stdin, 128);
            if ($chunk === false || $chunk === '') {
                return;
            }
        }
    }

    private function isSelectableStream(): bool
    {
        /** @var false|array<string, mixed> $meta */
        $meta = @stream_get_meta_data($this->stdin);

        return is_array($meta) && empty($meta['seekable']);
    }

    private function consumeAvailableInput(): void
    {
        while ($this->isInputAvailable()) {
            $chunk = @fread($this->stdin, 32);
            if ($chunk === false || $chunk === '') {
                return;
            }

            $this->consumeChunk($chunk);

            if ($this->token->isCancelled()) {
                return;
            }
        }
    }

    private function consumeChunk(string $chunk): void
    {
        foreach (str_split($chunk) as $byte) {
            if ($this->pendingEscape !== '') {
                $this->consumePendingEscapeByte($byte);
                continue;
            }

            if ($byte === "\x1B") {
                $this->pendingEscape = "\x1B";
                $this->pendingEscapeStartedAt = microtime(true);
            }
        }
    }

    private function consumePendingEscapeByte(string $byte): void
    {
        $this->pendingEscape .= $byte;

        if ($this->pendingEscape === "\x1B[" || $this->pendingEscape === "\x1BO") {
            return;
        }

        if ($this->isAnsiSequencePrefix($this->pendingEscape)) {
            if ($this->isAnsiSequenceComplete($this->pendingEscape)) {
                $this->resetPendingEscape();
            }

            return;
        }

        $this->resetPendingEscape();
    }

    private function cancelPendingEscapeIfConfirmed(): void
    {
        if ($this->pendingEscapeStartedAt === null) {
            return;
        }

        $elapsedUsec = (int) ((microtime(true) - $this->pendingEscapeStartedAt) * 1_000_000);
        if ($elapsedUsec < self::ESC_SEQUENCE_GRACE_USEC) {
            return;
        }

        if ($this->pendingEscape === "\x1B") {
            $this->resetPendingEscape();
            $this->token->cancel();
            $this->showCancellationMessage();
            return;
        }

        $this->resetPendingEscape();
    }

    private function showCancellationMessage(): void
    {
        if ($this->messageShown) {
            return;
        }

        $this->messageShown = true;
        $this->output->write("\r\033[K");
        $this->output->writeln(
            "\n<fg=yellow>⚑ Cancellation requested — returning to the REPL...</>",
        );
    }

    private function resetPendingEscape(): void
    {
        $this->pendingEscape = '';
        $this->pendingEscapeStartedAt = null;
    }

    private function isInputAvailable(): bool
    {
        if ($this->isSelectableStream()) {
            $read = [$this->stdin];
            $write = $except = [];

            return @stream_select($read, $write, $except, 0, 0) > 0;
        }

        return true;
    }

    private function isAnsiSequencePrefix(string $bytes): bool
    {
        return str_starts_with($bytes, "\x1B[") || str_starts_with($bytes, "\x1BO");
    }

    private function isAnsiSequenceComplete(string $bytes): bool
    {
        $lastByte = ord($bytes[strlen($bytes) - 1]);

        return $lastByte >= 64 && $lastByte <= 126;
    }
}
