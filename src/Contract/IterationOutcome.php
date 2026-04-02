<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Result of evaluating whether a loop iteration cycle should continue.
 *
 * Returned by LoopExecutor::evaluateIteration() after all stages in a cycle complete.
 */
enum IterationOutcome: string
{
    /** All termination criteria met — loop is complete. */
    case Complete = 'complete';

    /** Criteria not yet met — advance to next iteration. */
    case Continue = 'continue';

    /** Unrecoverable failure — loop should stop with error. */
    case Failed = 'failed';

    /** Iteration limit or deadline reached — loop stops. */
    case LimitReached = 'limit_reached';
}
