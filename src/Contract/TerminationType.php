<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Defines how a loop determines when to stop iterating.
 */
enum TerminationType: string
{
    /** Stop after the evaluator approves the work against criteria. */
    case EvaluationBound = 'evaluation_bound';

    /** Stop after a fixed number of iteration cycles. */
    case IterationBound = 'iteration_bound';

    /** Stop when a deadline is reached. */
    case TimeBound = 'time_bound';

    /** Never auto-stop — requires explicit user cancellation. */
    case Manual = 'manual';

    /** Stop when an LLM evaluates the goal as achieved. */
    case GoalBound = 'goal_bound';

    /** Stop when a tool's numeric output meets a threshold. */
    case ToolBound = 'tool_bound';
}
