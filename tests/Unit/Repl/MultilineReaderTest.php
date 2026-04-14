<?php

declare(strict_types=1);

use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Exception\ShutdownRequestedException;
use CoquiBot\Coqui\Repl\MultilineReader;
use CoquiBot\Coqui\Repl\TerminalStateManager;
use Symfony\Component\Console\Output\BufferedOutput;

covers(MultilineReader::class);

/**
 * @return array{0: resource, 1: resource}
 */
function makeMultilineSocketPair(): array
{
    return createNonBlockingStreamPair();
}

/**
 * @return array{0: MultilineReader, 1: BufferedOutput}
 */
function makeMultilineReader(mixed $stdin): array
{
    $output = new BufferedOutput();
    $terminalState = new TerminalStateManager($stdin, isTty: true);

    return [new MultilineReader($output, $terminalState, $stdin), $output];
}

/**
 * @param resource $writer
 */
function writeAndCloseMultilineInput(mixed $writer, string $bytes): void
{
    fwrite($writer, $bytes);
    fclose($writer);
}

describe('MultilineReader', function (): void {
    test('single line submitted via double Enter', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // "hello" + Enter + Enter (double-enter on empty line submits)
        writeAndCloseMultilineInput($writer, "hello\n\n");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe('hello');
        } finally {
            fclose($reader);
        }
    });

    test('multiple lines preserved with embedded newlines', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // line1 + Enter + line2 + Enter + Enter (submit)
        writeAndCloseMultilineInput($writer, "line one\nline two\n\n");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe("line one\nline two");
        } finally {
            fclose($reader);
        }
    });

    test('blank lines in non-paste mode trigger double-Enter submit', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // Consecutive newlines in non-paste mode trigger submit — only first line captured.
        // To preserve blank lines, use bracketed paste or Ctrl+D submit.
        writeAndCloseMultilineInput($writer, "first\n\nsecond\x04");
        [$multiline] = makeMultilineReader($reader);

        try {
            // Double-Enter after "first" submits before "second" is read
            expect($multiline->read())->toBe('first');
        } finally {
            fclose($reader);
        }
    });

    test('blank lines preserved via bracketed paste', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // Bracketed paste protects embedded blank lines from triggering submit
        $paste = "\x1B[200~first\n\nsecond\x1B[201~\n\n";
        writeAndCloseMultilineInput($writer, $paste);
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe("first\n\nsecond");
        } finally {
            fclose($reader);
        }
    });

    test('ctrl+D submits without double Enter', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        writeAndCloseMultilineInput($writer, "just one line\x04");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe('just one line');
        } finally {
            fclose($reader);
        }
    });

    test('EOF on closed stream submits accumulated content', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        writeAndCloseMultilineInput($writer, "eof content");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe('eof content');
        } finally {
            fclose($reader);
        }
    });

    test('standalone escape cancels multiline input', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        writeAndCloseMultilineInput($writer, "\x1B");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect(fn() => $multiline->read())->toThrow(InteractionCancelledException::class);
        } finally {
            fclose($reader);
        }
    });

    test('ctrl+C throws shutdown exception', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        writeAndCloseMultilineInput($writer, "\x03");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect(fn() => $multiline->read())->toThrow(ShutdownRequestedException::class);
        } finally {
            fclose($reader);
        }
    });

    test('arrow key sequences are ignored', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // Up arrow + "hello" + double Enter
        writeAndCloseMultilineInput($writer, "\x1B[Ahello\n\n");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe('hello');
        } finally {
            fclose($reader);
        }
    });

    test('backspace removes last character', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // "helloo" + backspace + double Enter
        writeAndCloseMultilineInput($writer, "helloo\x7F\n\n");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe('hello');
        } finally {
            fclose($reader);
        }
    });

    test('backspace on empty line is noop', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        writeAndCloseMultilineInput($writer, "\x7Fhello\n\n");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe('hello');
        } finally {
            fclose($reader);
        }
    });

    test('bracketed paste preserves newlines without triggering submit', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // Bracketed paste start + content with newlines + bracketed paste end + double Enter to submit
        $paste = "\x1B[200~first\nsecond\nthird\x1B[201~\n\n";
        writeAndCloseMultilineInput($writer, $paste);
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe("first\nsecond\nthird");
        } finally {
            fclose($reader);
        }
    });

    test('bracketed paste blank lines do not trigger double-Enter submit', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // Paste content that has consecutive empty lines, then submit normally after paste ends
        $paste = "\x1B[200~line1\n\n\nline4\x1B[201~\n\n";
        writeAndCloseMultilineInput($writer, $paste);
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe("line1\n\n\nline4");
        } finally {
            fclose($reader);
        }
    });

    test('trailing empty lines stripped from result', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // Content + several trailing Enters
        writeAndCloseMultilineInput($writer, "content\n\n");
        [$multiline] = makeMultilineReader($reader);

        try {
            // The double-Enter submits, and the sentinel empty lines are stripped
            expect($multiline->read())->toBe('content');
        } finally {
            fclose($reader);
        }
    });

    test('continuation prompt shown for each new line', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        writeAndCloseMultilineInput($writer, "a\nb\n\n");
        [$multiline, $output] = makeMultilineReader($reader);

        try {
            $multiline->read(' › ');
            $rendered = $output->fetch();
            // Output should contain the initial prompt and continuation prompts
            expect($rendered)->toContain(' › ');
            expect($rendered)->toContain(' . ');
        } finally {
            fclose($reader);
        }
    });

    test('only printable characters are accepted', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        // Bell character (0x07) and null byte (0x00) should be ignored
        writeAndCloseMultilineInput($writer, "he\x07l\x00lo\n\n");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe('hello');
        } finally {
            fclose($reader);
        }
    });

    test('carriage return treated same as line feed', function (): void {
        [$reader, $writer] = makeMultilineSocketPair();
        writeAndCloseMultilineInput($writer, "first\rsecond\r\r");
        [$multiline] = makeMultilineReader($reader);

        try {
            expect($multiline->read())->toBe("first\nsecond");
        } finally {
            fclose($reader);
        }
    });
});
