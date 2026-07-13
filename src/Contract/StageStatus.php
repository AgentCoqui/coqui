<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Machine-readable outcome status for a loop stage.
 *
 * Producer (non-gate) stages self-signal a status with no LLM call — defaulting
 * to Done, optionally emitting Blocked / NeedsContext via a leading sentinel.
 */
enum StageStatus: string
{
    case Done = 'done';
    case DoneWithConcerns = 'done_with_concerns';
    case Blocked = 'blocked';
    case NeedsContext = 'needs_context';

    /**
     * Cheap, tolerant self-signal parse of a producer stage's output.
     *
     * Scans the first few lines for a line-leading sentinel — `STATUS: BLOCKED`
     * or `STATUS: NEEDS_CONTEXT` (case-insensitive), optionally indented, at the
     * start of any of the first 5 lines. Mid-sentence prose that merely contains
     * the phrase does not match. Anything absent or unrecognized resolves to Done.
     */
    public static function fromProducerSignal(string $output): self
    {
        $head = implode("\n", array_slice(explode("\n", $output), 0, 5));
        if (preg_match('/^\s*status:\s*(blocked|needs_context)/im', $head, $m) === 1) {
            return strtolower($m[1]) === 'blocked' ? self::Blocked : self::NeedsContext;
        }

        return self::Done;
    }

    /** Whether this status must halt the loop into escalation. */
    public function halts(): bool
    {
        return $this === self::Blocked || $this === self::NeedsContext;
    }
}
