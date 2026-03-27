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
        if ($this->buffer === '') {
            return;
        }

        $rendered = MarkdownRenderer::render($this->buffer);
        ($this->writer)($rendered);
        $this->buffer = '';
        $this->inCodeFence = false;
        $this->codeFenceChar = '';
    }

    /**
     * Reset buffer state for a new stream.
     */
    public function reset(): void
    {
        $this->buffer = '';
        $this->inCodeFence = false;
        $this->codeFenceChar = '';
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
        $kept = [];

        foreach ($lines as $line) {
            if ($this->inCodeFence) {
                $kept[] = $line;
                // Check for closing fence
                if (preg_match('/^' . preg_quote($this->codeFenceChar, '/') . '{3,}\s*$/', $line)) {
                    $this->inCodeFence = false;
                    $this->codeFenceChar = '';
                    // Code block complete — move everything to flushable
                    array_push($flushable, ...$kept);
                    $kept = [];
                }
                continue;
            }

            // Check for opening fence
            if (preg_match('/^(`{3,}|~{3,})/', $line, $m)) {
                // Flush any accumulated non-fence content first
                if ($flushable !== []) {
                    $this->flushLines($flushable);
                    $flushable = [];
                }
                $this->inCodeFence = true;
                $this->codeFenceChar = $m[1][0];
                $kept[] = $line;
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
        $remaining = array_merge($kept, $flushable);
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
}
