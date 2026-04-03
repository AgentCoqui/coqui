<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Contract\ToolBoundEvaluationResult;

/**
 * Executes a tool by name and compares its numeric output against a threshold.
 *
 * Used by LoopExecutor for tool_bound termination: after each iteration,
 * the specified tool is called and its output parsed as a number. The result
 * is compared against the configured threshold using the declared operator.
 *
 * Delegates tool resolution and execution to BackgroundToolExecutor, which
 * builds the same toolkit stack as OrchestratorAgent.
 */
final class ToolBoundEvaluator
{
    public function __construct(
        private readonly BackgroundToolExecutor $executor,
    ) {}

    /**
     * Execute the tool and compare its numeric output against the threshold.
     *
     * @param array<string, mixed> $arguments Tool arguments
     */
    public function evaluate(
        string $toolName,
        array $arguments,
        string $operator,
        float $threshold,
    ): ToolBoundEvaluationResult {
        try {
            $result = $this->executor->execute($toolName, $arguments);

            if ($result->status === ToolResultStatus::Error) {
                return new ToolBoundEvaluationResult(
                    met: false,
                    actualValue: 0.0,
                    operator: $operator,
                    threshold: $threshold,
                    error: $result->content,
                );
            }

            $numericValue = $this->parseNumericOutput($result->content);

            if ($numericValue === null) {
                return new ToolBoundEvaluationResult(
                    met: false,
                    actualValue: 0.0,
                    operator: $operator,
                    threshold: $threshold,
                    error: sprintf(
                        'Tool "%s" output is not numeric: %s',
                        $toolName,
                        mb_substr($result->content, 0, 200),
                    ),
                );
            }

            $met = $this->compare($numericValue, $operator, $threshold);

            return new ToolBoundEvaluationResult(
                met: $met,
                actualValue: $numericValue,
                operator: $operator,
                threshold: $threshold,
            );
        } catch (\Throwable $e) {
            return new ToolBoundEvaluationResult(
                met: false,
                actualValue: 0.0,
                operator: $operator,
                threshold: $threshold,
                error: sprintf('Tool execution failed: %s', $e->getMessage()),
            );
        }
    }

    /**
     * Parse tool output as a numeric value.
     *
     * Trims whitespace and attempts to extract a leading number from the output.
     */
    private function parseNumericOutput(string $output): ?float
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return null;
        }

        if (is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        // Try to extract a leading number (e.g. "42 tests passed" → 42.0)
        if (preg_match('/^(-?\d+(?:\.\d+)?)/', $trimmed, $matches) === 1) {
            return (float) $matches[1];
        }

        return null;
    }

    /**
     * Compare a value against a threshold using the given operator.
     */
    private function compare(float $value, string $operator, float $threshold): bool
    {
        return match ($operator) {
            '>=' => $value >= $threshold,
            '>' => $value > $threshold,
            '<=' => $value <= $threshold,
            '<' => $value < $threshold,
            '==' => abs($value - $threshold) < PHP_FLOAT_EPSILON,
            '!=' => abs($value - $threshold) >= PHP_FLOAT_EPSILON,
            default => false,
        };
    }
}
