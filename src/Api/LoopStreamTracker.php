<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Contract\LoopStreamEvent;
use CoquiBot\Coqui\Contract\LoopStreamState;

/**
 * Pure change-detection for the loop events stream. Given the previous and
 * current observed state, returns the single most-significant nudge to emit
 * (or null when nothing moved). No I/O, no ReactPHP — fully unit-testable.
 *
 * Precedence: done > stage_changed > activity. `diff(null, $now)` produces the
 * initial emit, unifying connect and per-tick logic.
 */
final class LoopStreamTracker
{
    /** @var list<string> */
    private const array TERMINAL = ['completed', 'failed', 'cancelled'];

    public static function diff(?LoopStreamState $prev, LoopStreamState $now): ?LoopStreamEvent
    {
        $nowTerminal = in_array($now->status, self::TERMINAL, true);
        $prevTerminal = $prev !== null && in_array($prev->status, self::TERMINAL, true);

        if ($nowTerminal) {
            return $prevTerminal ? null : new LoopStreamEvent('done', ['status' => $now->status]);
        }

        if ($prev === null
            || $now->status !== $prev->status
            || $now->currentIteration !== $prev->currentIteration
            || $now->currentStage !== $prev->currentStage
        ) {
            return new LoopStreamEvent('stage_changed', [
                'iteration' => $now->currentIteration,
                'stage_index' => $now->currentStage,
                'status' => $now->status,
            ]);
        }

        if ($now->latestActivityId !== $prev->latestActivityId) {
            return new LoopStreamEvent('activity', ['cursor' => $now->latestActivityId]);
        }

        return null;
    }
}
