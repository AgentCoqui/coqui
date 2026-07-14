<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Severity of a reviewer finding. Critical/Important block the gate (force
 * rework); Minor accrues (surfaced, never blocks).
 */
enum StageSeverity: string
{
    case Critical = 'critical';
    case Important = 'important';
    case Minor = 'minor';

    /** Whether a finding of this severity blocks gate approval. */
    public function blocks(): bool
    {
        return $this === self::Critical || $this === self::Important;
    }
}
