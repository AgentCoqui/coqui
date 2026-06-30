<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares when a loop should stop iterating.
 *
 * Built from the "termination_condition" section of a loop definition JSON.
 * Three types are supported:
 * - evaluation_bound: stop when evaluator approves (value: {criteria, max_review_rounds})
 * - iteration_bound: stop after N cycles (value: int)
 * - goal_bound: stop when LLM evaluates goal as achieved (value: {goal_prompt, max_iterations})
 */
final readonly class TerminationCondition
{
    /**
     * @param TerminationType $type            How termination is determined
     * @param string|null     $criteria        Evaluation criteria text (evaluation_bound only)
     * @param int             $maxReviewRounds Max rejection cycles before escalation (evaluation_bound)
     * @param int|null        $maxIterations   Fixed iteration limit (iteration_bound, goal_bound)
     * @param string|null     $goalPrompt      LLM evaluation prompt template (goal_bound only)
     */
    public function __construct(
        public TerminationType $type,
        public ?string $criteria = null,
        public int $maxReviewRounds = 5,
        public ?int $maxIterations = null,
        public ?string $goalPrompt = null,
    ) {
        match ($type) {
            TerminationType::EvaluationBound => $criteria !== null && $criteria !== ''
                ? true
                : throw new \InvalidArgumentException('evaluation_bound requires non-empty "criteria"'),
            TerminationType::IterationBound => $maxIterations !== null && $maxIterations > 0
                ? true
                : throw new \InvalidArgumentException('iteration_bound requires "value" > 0'),
            TerminationType::GoalBound => $maxIterations !== null && $maxIterations > 0
                ? true
                : throw new \InvalidArgumentException('goal_bound requires "max_iterations" > 0'),
        };
    }

    /**
     * Build from the "termination_condition" section of a loop definition JSON.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $type = TerminationType::tryFrom($data['type'] ?? '')
            ?? throw new \InvalidArgumentException(
                sprintf('Unknown termination type: "%s". Valid: %s', $data['type'] ?? '', implode(', ', array_column(TerminationType::cases(), 'value'))),
            );

        $value = $data['value'] ?? null;

        return match ($type) {
            TerminationType::EvaluationBound => new self(
                type: $type,
                criteria: is_array($value) ? ($value['criteria'] ?? null) : (is_string($value) ? $value : null),
                maxReviewRounds: is_array($value) ? (int) ($value['max_review_rounds'] ?? 5) : 5,
            ),
            TerminationType::IterationBound => new self(
                type: $type,
                maxIterations: is_int($value) ? $value : (is_numeric($value) ? (int) $value : null),
            ),
            TerminationType::GoalBound => new self(
                type: $type,
                goalPrompt: is_array($value) ? ($value['goal_prompt'] ?? null) : null,
                maxIterations: is_array($value) ? (is_numeric($value['max_iterations'] ?? null) ? (int) $value['max_iterations'] : null) : null,
            ),
        };
    }

    /**
     * Serialize to JSON-safe array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            TerminationType::EvaluationBound => [
                'type' => $this->type->value,
                'value' => [
                    'criteria' => $this->criteria,
                    'max_review_rounds' => $this->maxReviewRounds,
                ],
            ],
            TerminationType::IterationBound => [
                'type' => $this->type->value,
                'value' => $this->maxIterations,
            ],
            TerminationType::GoalBound => [
                'type' => $this->type->value,
                'value' => array_filter([
                    'goal_prompt' => $this->goalPrompt,
                    'max_iterations' => $this->maxIterations,
                ], static fn(mixed $v) => $v !== null),
            ],
        };
    }
}
