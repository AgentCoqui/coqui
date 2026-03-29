<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares when a loop should stop iterating.
 *
 * Built from the "termination_condition" section of a loop definition JSON.
 * Four types are supported:
 * - evaluation_bound: stop when evaluator approves (value: {criteria, max_review_rounds})
 * - iteration_bound: stop after N cycles (value: int)
 * - time_bound: stop at a deadline (value: ISO 8601 string)
 * - manual: never auto-stop
 */
final readonly class TerminationCondition
{
    /**
     * @param TerminationType $type      How termination is determined
     * @param string|null     $criteria  Evaluation criteria text (evaluation_bound only)
     * @param int             $maxReviewRounds  Max rejection cycles before escalation (evaluation_bound)
     * @param int|null        $maxIterations    Fixed iteration limit (iteration_bound only)
     * @param string|null     $deadline         ISO 8601 deadline (time_bound only)
     */
    public function __construct(
        public TerminationType $type,
        public ?string $criteria = null,
        public int $maxReviewRounds = 5,
        public ?int $maxIterations = null,
        public ?string $deadline = null,
    ) {
        match ($type) {
            TerminationType::EvaluationBound => $criteria !== null && $criteria !== ''
                ? true
                : throw new \InvalidArgumentException('evaluation_bound requires non-empty "criteria"'),
            TerminationType::IterationBound => $maxIterations !== null && $maxIterations > 0
                ? true
                : throw new \InvalidArgumentException('iteration_bound requires "value" > 0'),
            TerminationType::TimeBound => $deadline !== null && $deadline !== ''
                ? true
                : throw new \InvalidArgumentException('time_bound requires a non-empty "value" (ISO 8601 datetime)'),
            TerminationType::Manual => true,
        };
    }

    /**
     * Build from the "termination_condition" section of a loop definition JSON.
     *
     * @param array{type: string, value?: mixed} $data
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
            TerminationType::TimeBound => new self(
                type: $type,
                deadline: is_string($value) ? $value : null,
            ),
            TerminationType::Manual => new self(type: $type),
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
            TerminationType::TimeBound => [
                'type' => $this->type->value,
                'value' => $this->deadline,
            ],
            TerminationType::Manual => [
                'type' => $this->type->value,
            ],
        };
    }
}
