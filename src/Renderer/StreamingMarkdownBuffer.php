<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use Closure;

/**
 * Line-buffered streaming markdown renderer.
 *
 * Accumulates text deltas and flushes complete markdown blocks through
 * MarkdownRenderer::render(). Code fences are buffered entirely until
 * the closing fence arrives. Other blocks flush on blank lines or when
 * the next line starts a new block-level element.
 */
final class StreamingMarkdownBuffer
{
    private string $buffer = '';
    private bool $inCodeFence = false;
    private string $codeFenceChar = '';
    private int $codeFenceLength = 0;
    /** @var string[] */
    private array $codeFenceLines = [];

    /**
     * @param Closure(string): void $writer  Called with rendered ANSI output
     */
    public function __construct(
        private readonly Closure $writer,
    ) {}

    /**
     * Feed a text delta from the LLM stream.
     */
    public function feed(string $delta): void
    {
        $this->buffer .= $delta;
        $this->processBuffer();
    }

    /**
     * Flush any remaining buffered content (call at end of stream).
     */
    public function flush(): void
    {
        $markdown = $this->buffer;
        if ($this->codeFenceLines !== []) {
            $markdown = implode("\n", $this->codeFenceLines);
            if ($this->buffer !== '') {
                $markdown .= "\n" . $this->buffer;
            }
        }

        if ($markdown === '' || $this->bufferIsOnlyFenceMarker($markdown)) {
            $this->reset();
            return;
        }

        $rendered = MarkdownRenderer::render($markdown);
        ($this->writer)($rendered);
        $this->reset();
    }

    /**
     * Reset buffer state for a new stream.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->inCodeFence = false;
        $this->codeFenceChar = '';
        $this->codeFenceLength = 0;
        $this->codeFenceLines = [];
    }

    private function processBuffer(): void
    {
        // Split buffer into complete lines + trailing incomplete fragment
        $lines = explode("\n", $this->buffer);
        $incomplete = array_pop($lines); // last element has no trailing \n

        if ($lines === []) {
            return; // no complete lines yet — wait for more data
        }

        $flushable = [];

        foreach ($lines as $line) {
            if ($this->inCodeFence) {
                $this->codeFenceLines[] = $line;
                if ($this->isClosingFence($line)) {
                    $this->inCodeFence = false;
                    $this->codeFenceChar = '';
                    $this->codeFenceLength = 0;
                    $this->flushLines($this->codeFenceLines);
                    $this->codeFenceLines = [];
                }
                continue;
            }

            $openingFence = $this->detectOpeningFence($line);
            if ($openingFence !== null) {
                if ($flushable !== []) {
                    $this->flushLines($flushable);
                    $flushable = [];
                }
                $this->inCodeFence = true;
                $this->codeFenceChar = $openingFence['char'];
                $this->codeFenceLength = $openingFence['length'];
                $this->codeFenceLines = [$line];
                continue;
            }

            $flushable[] = $line;

            // Flush on block boundaries
            if ($this->isFlushPoint($line)) {
                $this->flushLines($flushable);
                $flushable = [];
            }
        }

        // Rebuild buffer: unflushed lines + incomplete trailing fragment
        $remaining = $flushable;
        $this->buffer = $remaining !== []
            ? implode("\n", $remaining) . "\n" . $incomplete
            : $incomplete;
    }

    /**
     * Render and emit a set of complete lines.
     *
     * @param string[] $lines
     */
    private function flushLines(array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $markdown = implode("\n", $lines) . "\n";
        $rendered = MarkdownRenderer::render($markdown);
        ($this->writer)($rendered);
    }

    private function isFlushPoint(string $line): bool
    {
        $trimmed = trim($line);

        // Blank line — paragraph separator
        if ($trimmed === '') {
            return true;
        }

        // Heading — self-contained block
        if (preg_match('/^#{1,6}\s/', $line)) {
            return true;
        }

        // Thematic break
        if (preg_match('/^(\*{3,}|-{3,}|_{3,})\s*$/', $trimmed)) {
            return true;
        }

        return false;
    }

    /**
     * @return array{char: string, length: int}|null
     */
    private function detectOpeningFence(string $line): ?array
    {
        if (!preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $matches)) {
            return null;
        }

        $fence = $matches[1];
        if ($fence[0] === '`' && str_contains($matches[2], '`')) {
            return null;
        }

        return [
            'char' => $fence[0],
            'length' => strlen($fence),
        ];
    }

    private function isClosingFence(string $line): bool
    {
        if ($this->codeFenceChar === '' || $this->codeFenceLength < 3) {
            return false;
        }

        return preg_match(
            '/^ {0,3}' . preg_quote($this->codeFenceChar, '/') . '{' . $this->codeFenceLength . ',}[ \t]*$/',
            $line,
        ) === 1;
    }

    private function bufferIsOnlyFenceMarker(?string $markdown = null): bool
    {
        $markdown ??= $this->buffer;

        return preg_match('/^\s*(`{3,}|~{3,})\s*$/', $markdown) === 1;
    }
}
