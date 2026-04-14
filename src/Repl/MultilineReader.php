<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Exception\ShutdownRequestedException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Raw-mode multiline input reader for the REPL.
 *
 * When multiline mode is active the reader:
 * - treats single Enter as a newline inside the prompt body,
 * - submits the prompt on **double Enter** (two consecutive Enter presses
 *   on an empty trailing line),
 * - auto-detects **bracketed paste** sequences and buffers them verbatim
 *   (newlines inside a paste bracket never count toward the double-Enter
 *   sentinel),
 * - preserves embedded blank lines,
 * - supports Ctrl+C (shutdown), standalone ESC (cancel), and Ctrl+D (submit).
 *
 * The reader does NOT replace readline for single-line mode. It is only
 * used when multiline compose mode is explicitly active.
 */
final class MultilineReader
{
    private const int READ_POLL_USEC = 100_000; // 100 ms
    private const int ESC_SEQUENCE_GRACE_USEC = 100_000; // 100 ms

    /** Bracketed paste start/end markers (DEC private mode 2004). */
    private const string BRACKETED_PASTE_START = "\x1B[200~";
    private const string BRACKETED_PASTE_END = "\x1B[201~";

    /** The ANSI sequence to enable bracketed paste mode on the terminal. */
    private const string ENABLE_BRACKETED_PASTE = "\x1B[?2004h";
    private const string DISABLE_BRACKETED_PASTE = "\x1B[?2004l";

    /** Continuation prompt shown for lines after the first. */
    private const string CONTINUATION_PROMPT = ' . ';

    /** @var resource */
    private readonly mixed $stdin;

    public function __construct(
        private readonly OutputInterface $output,
        private readonly TerminalStateManager $terminalState,
        mixed $stdin = null,
    ) {
        $this->stdin = $stdin ?? STDIN;
    }

    /**
     * Read a multiline prompt from the terminal.
     *
     * @param string $initialPrompt The first-line prompt symbol (e.g. " › ").
     *
     * @return string The complete multiline input (may contain newlines).
     *
     * @throws ShutdownRequestedException on Ctrl+C
     * @throws InteractionCancelledException on standalone ESC
     */
    public function read(string $initialPrompt = ' › '): string
    {
        $savedState = $this->terminalState->saveState();
        $previousAsyncSignals = null;

        if (function_exists('pcntl_async_signals')) {
            $previousAsyncSignals = pcntl_async_signals(true);
        }

        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, static function (): void {
                throw ShutdownRequestedException::bySignal();
            });
        }

        $this->terminalState->enterRawMode();

        // Enable bracketed paste so pasted multi-line content is framed.
        $this->output->write(self::ENABLE_BRACKETED_PASTE);
        $this->output->write($initialPrompt);

        $lines = [''];
        $currentLineIndex = 0;
        $lastCharWasNewline = false;
        $insideBracketedPaste = false;

        $pendingEscape = '';
        $pendingEscapeStartedAt = null;

        try {
            while (true) {
                $read = [$this->stdin];
                $write = $except = [];
                $ready = @stream_select($read, $write, $except, 0, self::READ_POLL_USEC);

                if ($ready === false || $ready === 0) {
                    if ($this->shouldCancelPendingEscape($pendingEscape, $pendingEscapeStartedAt)) {
                        throw InteractionCancelledException::byEsc();
                    }
                    continue;
                }

                $chunk = @fread($this->stdin, 256);
                if ($chunk === false || $chunk === '') {
                    if ($this->shouldCancelPendingEscape($pendingEscape, $pendingEscapeStartedAt, eofReached: true)) {
                        throw InteractionCancelledException::byEsc();
                    }
                    if (feof($this->stdin)) {
                        // Ctrl+D / EOF → submit what we have
                        break;
                    }
                    continue;
                }

                foreach (str_split($chunk) as $char) {
                    // --- Escape sequence handling (reuses InterruptiblePrompt pattern) ---
                    if ($pendingEscape !== '') {
                        $pendingEscape .= $char;

                        // Still building a recognised ANSI prefix — keep buffering
                        if ($pendingEscape === "\x1B[" || $pendingEscape === "\x1BO") {
                            continue;
                        }

                        // Check for bracketed paste start/end inside escape buffer
                        if (str_starts_with(self::BRACKETED_PASTE_START, $pendingEscape)) {
                            if ($pendingEscape === self::BRACKETED_PASTE_START) {
                                $insideBracketedPaste = true;
                                $pendingEscape = '';
                                $pendingEscapeStartedAt = null;
                            }
                            continue;
                        }

                        if (str_starts_with(self::BRACKETED_PASTE_END, $pendingEscape)) {
                            if ($pendingEscape === self::BRACKETED_PASTE_END) {
                                $insideBracketedPaste = false;
                                $pendingEscape = '';
                                $pendingEscapeStartedAt = null;
                            }
                            continue;
                        }

                        // Known complete ANSI sequence (arrows, function keys) — discard
                        if ($this->isCompleteAnsiSequence($pendingEscape)) {
                            $pendingEscape = '';
                            $pendingEscapeStartedAt = null;
                            continue;
                        }

                        // Unknown sequence still growing — keep buffering if plausible
                        if ($this->isAnsiSequencePrefix($pendingEscape)) {
                            continue;
                        }

                        // Not a recognised sequence — discard the buffered bytes
                        $pendingEscape = '';
                        $pendingEscapeStartedAt = null;
                        continue;
                    }

                    if ($char === "\x1B") {
                        $pendingEscape = "\x1B";
                        $pendingEscapeStartedAt = microtime(true);
                        continue;
                    }

                    // --- Control characters ---
                    if ($char === "\x03") { // Ctrl+C
                        throw ShutdownRequestedException::bySignal();
                    }

                    if ($char === "\x04") { // Ctrl+D — submit
                        break 2;
                    }

                    // --- Enter / newline ---
                    if ($char === "\r" || $char === "\n") {
                        // Inside bracketed paste, newlines are just content
                        if ($insideBracketedPaste) {
                            $this->output->write("\n" . self::CONTINUATION_PROMPT);
                            $currentLineIndex++;
                            $lines[$currentLineIndex] = '';
                            $lastCharWasNewline = false; // don't trigger double-Enter inside paste
                            continue;
                        }

                        if ($lastCharWasNewline && $lines[$currentLineIndex] === '') {
                            // Double Enter on an empty line → submit
                            $this->output->writeln('');
                            break 2;
                        }

                        $lastCharWasNewline = true;
                        $this->output->write("\n" . self::CONTINUATION_PROMPT);
                        $currentLineIndex++;
                        $lines[$currentLineIndex] = '';
                        continue;
                    }

                    // Any non-newline character resets the double-Enter sentinel
                    $lastCharWasNewline = false;

                    // --- Backspace ---
                    if ($char === "\x7F" || $char === "\x08") {
                        if ($lines[$currentLineIndex] !== '') {
                            $lines[$currentLineIndex] = mb_substr($lines[$currentLineIndex], 0, -1);
                            $this->output->write("\x08 \x08");
                        }
                        continue;
                    }

                    // --- Printable character ---
                    if (ord($char) >= 32) {
                        $lines[$currentLineIndex] .= $char;
                        $this->output->write($char);
                    }
                }
            }

            return $this->buildResult($lines);
        } catch (InteractionCancelledException) {
            $this->output->writeln('');
            throw InteractionCancelledException::byEsc();
        } catch (ShutdownRequestedException) {
            $this->output->writeln('');
            throw ShutdownRequestedException::bySignal();
        } finally {
            $this->output->write(self::DISABLE_BRACKETED_PASTE);
            $this->terminalState->drainStdin();
            $this->terminalState->restoreState($savedState);

            if ($previousAsyncSignals !== null && function_exists('pcntl_async_signals')) {
                pcntl_async_signals($previousAsyncSignals);
            }
        }
    }

    /**
     * Assemble the final prompt string from collected lines.
     *
     * Trims the trailing empty line that triggered double-Enter submission
     * but preserves all other blank lines.
     */
    /** @param array<int, string> $lines */
    private function buildResult(array $lines): string
    {
        // Remove the final empty line that served as the submit sentinel
        while ($lines !== [] && $lines[array_key_last($lines)] === '') {
            array_pop($lines);
        }

        return implode("\n", $lines);
    }

    // --- Escape sequence helpers (mirrors InterruptiblePrompt) ---

    private function shouldCancelPendingEscape(string &$pendingEscape, ?float &$pendingEscapeStartedAt, bool $eofReached = false): bool
    {
        if ($pendingEscapeStartedAt === null) {
            return false;
        }

        $elapsedUsec = (int) ((microtime(true) - $pendingEscapeStartedAt) * 1_000_000);
        if (!$eofReached && $elapsedUsec < self::ESC_SEQUENCE_GRACE_USEC) {
            return false;
        }

        $isStandaloneEscape = $pendingEscape === "\x1B";
        $pendingEscape = '';
        $pendingEscapeStartedAt = null;

        return $isStandaloneEscape;
    }

    private function isAnsiSequencePrefix(string $buffer): bool
    {
        return str_starts_with($buffer, "\x1B[") || str_starts_with($buffer, "\x1BO");
    }

    private function isCompleteAnsiSequence(string $buffer): bool
    {
        if (!$this->isAnsiSequencePrefix($buffer)) {
            return false;
        }

        $len = strlen($buffer);
        if ($len < 3) {
            return false;
        }

        $lastByte = ord($buffer[$len - 1]);

        // CSI sequences end with a byte in the range 0x40–0x7E
        return $lastByte >= 0x40 && $lastByte <= 0x7E;
    }
}
