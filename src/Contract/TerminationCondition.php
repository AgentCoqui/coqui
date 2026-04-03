<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares when a loop should stop iterating.
 *
 * Built from the "termination_condition" section of a loop definition JSON.
 * Six types are supported:
 * - evaluation_bound: stop when evaluator approves (value: {criteria, max_review_rounds})
 * - iteration_bound: stop after N cycles (value: int)
 * - time_bound: stop at a deadline (value: ISO 8601 string)
 * - manual: never auto-stop
 * - goal_bound: stop when LLM evaluates goal as achieved (value: {goal_prompt, max_iterations})
 * - tool_bound: stop when tool output meets threshold (value: {tool, arguments, operator, threshold, max_iterations})
 */
final readonly class TerminationCondition
{
    private const VALID_OPERATORS = ['>=', '>', '<=', '<', '==', '!='];

    /**
     * @param TerminationType $type            How termination is determined
     * @param string|null     $criteria        Evaluation criteria text (evaluation_bound only)
     * @param int             $maxReviewRounds Max rejection cycles before escalation (evaluation_bound)
     * @param int|null        $maxIterations   Fixed iteration limit (iteration_bound, goal_bound, tool_bound)
     * @param string|null     $deadline        ISO 8601 deadline (time_bound only)
     * @param string|null     $goalPrompt      LLM evaluation prompt template (goal_bound only)
     * @param string|null     $toolName        Tool to execute for threshold check (tool_bound only)
     * @param array<string, mixed>|null $toolArguments Arguments passed to the tool (tool_bound only)
     * @param string|null     $operator        Comparison operator: >=, >, <=, <, ==, != (tool_bound only)
     * @param float|null      $threshold       Target value for comparison (tool_bound only)
     */
    public function __construct(
        public TerminationType $type,
        public ?string $criteria = null,
        public int $maxReviewRounds = 5,
        public ?int $maxIterations = null,
        public ?string $deadline = null,
        public ?string $goalPrompt = null,
        public ?array $toolArguments = null,
        public ?string $toolName = null,
        public ?string $operator = null,
        public ?float $threshold = null,
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
            TerminationType::GoalBound => $maxIterations !== null && $maxIterations > 0
                ? true
                : throw new \InvalidArgumentException('goal_bound requires "max_iterations" > 0'),
            TerminationType::ToolBound => match (true) {
                $toolName === null || $toolName === '' => throw new \InvalidArgumentException('tool_bound requires a "tool" name'),
                $operator === null || !in_array($operator, self::VALID_OPERATORS, true) => throw new \InvalidArgumentException(
                    sprintf('tool_bound requires a valid "operator" (%s)', implode(', ', self::VALID_OPERATORS)),
                ),
                $threshold === null => throw new \InvalidArgumentException('tool_bound requires a "threshold" value'),
                $maxIterations === null || $maxIterations < 1 => throw new \InvalidArgumentException('tool_bound requires "max_iterations" > 0'),
                default => true,
            },
            TerminationType::Manual => true,
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
            TerminationType::TimeBound => new self(
                type: $type,
                deadline: is_string($value) ? $value : null,
            ),
            TerminationType::GoalBound => new self(
                type: $type,
                goalPrompt: is_array($value) ? ($value['goal_prompt'] ?? null) : null,
                maxIterations: is_array($value) ? (is_numeric($value['max_iterations'] ?? null) ? (int) $value['max_iterations'] : null) : null,
            ),
            TerminationType::ToolBound => new self(
                type: $type,
                toolName: is_array($value) ? ($value['tool'] ?? null) : null,
                toolArguments: is_array($value) && is_array($value['arguments'] ?? null) ? $value['arguments'] : null,
                operator: is_array($value) ? ($value['operator'] ?? null) : null,
                threshold: is_array($value) && is_numeric($value['threshold'] ?? null) ? (float) $value['threshold'] : null,
                maxIterations: is_array($value) ? (is_numeric($value['max_iterations'] ?? null) ? (int) $value['max_iterations'] : null) : null,
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
            TerminationType::GoalBound => [
                'type' => $this->type->value,
                'value' => array_filter([
                    'goal_prompt' => $this->goalPrompt,
                    'max_iterations' => $this->maxIterations,
                ], static fn(mixed $v) => $v !== null),
            ],
            TerminationType::ToolBound => [
                'type' => $this->type->value,
                'value' => array_filter([
                    'tool' => $this->toolName,
                    'arguments' => $this->toolArguments,
                    'operator' => $this->operator,
                    'threshold' => $this->threshold,
                    'max_iterations' => $this->maxIterations,
                ], static fn(mixed $v) => $v !== null),
            ],
            TerminationType::Manual => [
                'type' => $this->type->value,
            ],
        };
    }
}
