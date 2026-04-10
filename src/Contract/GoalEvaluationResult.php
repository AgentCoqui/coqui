<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Result of an LLM goal achievement evaluation.
 *
 * Returned by GoalEvaluator after a single-shot LLM call that judges
 * whether the loop's goal has been achieved based on recent work output.
 */
final readonly class GoalEvaluationResult
{
    public function __construct(
        public bool $achieved,
        public string $rationale,
    ) {}
}
