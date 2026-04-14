<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Repl;

use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Exception\ShutdownRequestedException;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Minimal interactive prompt layer with immediate ESC cancel support.
 *
 * Falls back to SymfonyStyle's built-in helpers when no interactive TTY is
 * available. In an interactive TTY, raw mode is used so standalone ESC aborts
 * instantly without waiting for Enter while ANSI sequences such as arrow keys
 * are ignored.
 */
final class InterruptiblePrompt
{
    private const int READ_POLL_USEC = 100000;
    private const int ESC_SEQUENCE_GRACE_USEC = 100000;

    /** @var resource */
    private readonly mixed $stdin;

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly ?TerminalStateManager $terminalState = null,
        mixed $stdin = null,
    ) {
        $this->stdin = $stdin ?? STDIN;
    }

    public function ask(string $question, ?string $default = null): ?string
    {
        if (!$this->isInteractiveTty()) {
            $answer = $this->io->ask($question, $default);

            return is_string($answer) ? $answer : $default;
        }

        $label = $default !== null && $default !== ''
            ? sprintf('%s [%s]', $question, $default)
            : $question;

        $answer = $this->readLine($label . ': ');

        if ($answer === '' && $default !== null) {
            return $default;
        }

        return $answer;
    }

    public function askHidden(string $question): ?string
    {
        if (!$this->isInteractiveTty()) {
            $answer = $this->io->askHidden($question);

            return is_string($answer) ? $answer : null;
        }

        return $this->readLine($question . ': ', hidden: true);
    }

    public function confirm(string $question, bool $default = false): bool
    {
        if (!$this->isInteractiveTty()) {
            return $this->io->confirm($question, $default);
        }

        $suffix = $default ? ' [Y/n]' : ' [y/N]';

        while (true) {
            $answer = trim($this->readLine($question . $suffix . ': '));

            if ($answer === '') {
                return $default;
            }

            $normalized = strtolower($answer);
            if (in_array($normalized, ['y', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['n', 'no'], true)) {
                return false;
            }

            $this->io->warning('Please answer yes or no.');
        }
    }

    /**
     * @param array<int|string, string> $choices
     */
    public function choice(string $question, array $choices, ?string $default = null): string
    {
        if ($choices === []) {
            throw new \InvalidArgumentException('choice() requires at least one option.');
        }

        if (!$this->isInteractiveTty()) {
            $selected = $this->io->choice($question, array_values($choices), $default);

            return is_string($selected) ? $selected : (string) reset($choices);
        }

        $normalizedChoices = array_values($choices);

        while (true) {
            $this->io->writeln($question);
            foreach ($normalizedChoices as $index => $choice) {
                $marker = $choice === $default ? ' <fg=gray>(default)</>' : '';
                $this->io->writeln(sprintf('  <fg=cyan>%d</>) %s%s', $index + 1, $choice, $marker));
            }

            $prompt = 'Select option';
            if ($default !== null && $default !== '') {
                $prompt .= sprintf(' [%s]', $default);
            }

            $answer = trim($this->readLine($prompt . ': '));

            if ($answer === '' && $default !== null) {
                return $default;
            }

            if (ctype_digit($answer)) {
                $position = (int) $answer - 1;
                if (isset($normalizedChoices[$position])) {
                    return $normalizedChoices[$position];
                }
            }

            foreach ($normalizedChoices as $choice) {
                if ($choice === $answer) {
                    return $choice;
                }
            }

            $this->io->warning('Invalid selection. Choose a number or exact option text.');
        }
    }

    private function isInteractiveTty(): bool
    {
        return $this->terminalState()->isInteractiveTty();
    }

    private function terminalState(): TerminalStateManager
    {
        return $this->terminalState ?? new TerminalStateManager();
    }

    private function readLine(string $prompt, bool $hidden = false): string
    {
        $terminalState = $this->terminalState();
        $stdin = $this->stdin;
        $savedState = $terminalState->saveState();
        $previousAsyncSignals = null;
        $previousHandler = null;
        $sigintCount = 0;

        if (function_exists('pcntl_async_signals')) {
            $previousAsyncSignals = pcntl_async_signals(true);
        }

        if (function_exists('pcntl_signal')) {
            $previousHandler = function_exists('pcntl_signal_get_handler')
                ? pcntl_signal_get_handler(SIGINT)
                : null;

            pcntl_signal(SIGINT, static function () use (&$sigintCount): void {
                $sigintCount++;
                if ($sigintCount === 1) {
                    throw ShutdownRequestedException::bySignal();
                }

                if (function_exists('pcntl_signal')) {
                    pcntl_signal(SIGINT, SIG_DFL);
                }

                if (function_exists('posix_kill')) {
                    posix_kill(posix_getpid(), SIGINT);
                }

                throw ShutdownRequestedException::bySignal();
            });
        }

        $terminalState->enterRawMode();
        $this->io->write($prompt);

        $buffer = '';
        $pendingEscape = '';
        $pendingEscapeStartedAt = null;

        try {
            while (true) {
                $read = [$stdin];
                $write = $except = [];
                $ready = @stream_select($read, $write, $except, 0, self::READ_POLL_USEC);

                if ($ready === false || $ready === 0) {
                    if ($this->shouldCancelPendingEscape($pendingEscape, $pendingEscapeStartedAt)) {
                        throw InteractionCancelledException::byEsc();
                    }

                    continue;
                }

                $chunk = @fread($stdin, 32);
                if ($chunk === false || $chunk === '') {
                    if ($this->shouldCancelPendingEscape($pendingEscape, $pendingEscapeStartedAt)) {
                        throw InteractionCancelledException::byEsc();
                    }

                    if (feof($stdin)) {
                        if ($this->shouldCancelPendingEscape($pendingEscape, $pendingEscapeStartedAt, eofReached: true)) {
                            throw InteractionCancelledException::byEsc();
                        }

                        return $buffer;
                    }

                    continue;
                }

                foreach (str_split($chunk) as $char) {
                    if ($pendingEscape !== '') {
                        $this->consumePendingEscapeByte($char, $pendingEscape, $pendingEscapeStartedAt);
                        continue;
                    }

                    if ($char === "\x1B") {
                        $pendingEscape = "\x1B";
                        $pendingEscapeStartedAt = microtime(true);
                        continue;
                    }

                    if ($char === "\x03") {
                        throw ShutdownRequestedException::bySignal();
                    }

                    if ($char === "\x04") {
                        return $buffer;
                    }

                    if ($char === "\r" || $char === "\n") {
                        $this->io->newLine();

                        return $buffer;
                    }

                    if ($char === "\x7F" || $char === "\x08") {
                        if ($buffer === '') {
                            continue;
                        }

                        $buffer = substr($buffer, 0, -1);
                        $this->io->write("\x08 \x08");
                        continue;
                    }

                    if (!$this->isPrintable($char)) {
                        continue;
                    }

                    $buffer .= $char;
                    $this->io->write($hidden ? '*' : $char);
                }
            }
        } catch (InteractionCancelledException) {
            $this->io->newLine();
            throw InteractionCancelledException::byEsc();
        } catch (ShutdownRequestedException) {
            $this->io->newLine();
            throw ShutdownRequestedException::bySignal();
        } finally {
            $terminalState->drainStdin();
            $terminalState->restoreState($savedState);

            if (function_exists('pcntl_signal')) {
                if ($previousHandler !== null) {
                    pcntl_signal(SIGINT, $previousHandler);
                } else {
                    pcntl_signal(SIGINT, SIG_DFL);
                }
            }

            if ($previousAsyncSignals !== null && function_exists('pcntl_async_signals')) {
                pcntl_async_signals($previousAsyncSignals);
            }
        }
    }

    private function isPrintable(string $char): bool
    {
        $ord = ord($char);

        return $ord >= 32 && $ord !== 127;
    }

    private function consumePendingEscapeByte(string $char, string &$pendingEscape, ?float &$pendingEscapeStartedAt): void
    {
        $pendingEscape .= $char;

        if ($pendingEscape === "\x1B[" || $pendingEscape === "\x1BO") {
            return;
        }

        if ($this->isAnsiSequencePrefix($pendingEscape)) {
            if ($this->isAnsiSequenceComplete($pendingEscape)) {
                $pendingEscape = '';
                $pendingEscapeStartedAt = null;
            }

            return;
        }

        $pendingEscape = '';
        $pendingEscapeStartedAt = null;
    }

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