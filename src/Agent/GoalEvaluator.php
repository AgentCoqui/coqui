<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CoquiBot\Coqui\Contract\GoalEvaluationResult;

/**
 * Single-shot LLM evaluator for goal_bound loop termination.
 *
 * Sends the loop goal, evaluation prompt, and the latest stage output to a utility
 * model and parses a structured ACHIEVED/NOT_ACHIEVED judgment. Follows the
 * TitleGenerator/VisionAnalyzer pattern — one LLM call, no tool use, catches all errors.
 */
final readonly class GoalEvaluator
{
    public function __construct(
        private ProviderInterface $provider,
    ) {}

    /**
     * Evaluate whether the loop's goal has been achieved.
     *
     * @param string $goal             The loop's original goal
     * @param string|null $goalPrompt  Custom evaluation prompt from the definition (may be null)
     * @param string $lastStageOutput  Output from the last completed stage
     * @param list<array{iteration_number: int, outcome_summary: string|null, status: string}> $previousOutcomes
     */
    public function evaluate(
        string $goal,
        ?string $goalPrompt,
        string $lastStageOutput,
        array $previousOutcomes = [],
    ): GoalEvaluationResult {
        try {
            $systemPrompt = <<<'SYSTEM'
            You are a goal achievement evaluator for an automated loop system.
            Your job is to determine whether the stated goal has been achieved based on the work output provided.

            You MUST respond with EXACTLY one of these two words on the first line:
            ACHIEVED
            NOT_ACHIEVED

            Follow it with a brief rationale (1-3 sentences) explaining your judgment.

            Be strict: the goal must be fully met, not just partially addressed.
            If the work is incomplete, has errors, or only partially addresses the goal, respond NOT_ACHIEVED.
            SYSTEM;

            $userPrompt = "## Goal\n{$goal}\n\n";

            if ($goalPrompt !== null && $goalPrompt !== '') {
                $userPrompt .= "## Evaluation Criteria\n{$goalPrompt}\n\n";
            }

            if ($previousOutcomes !== []) {
                $outcomeLines = [];
                foreach ($previousOutcomes as $po) {
                    $summary = $po['outcome_summary'] ?? $po['status'];
                    $outcomeLines[] = "- Iteration {$po['iteration_number']}: {$summary}";
                }
                $userPrompt .= "## Previous Iterations\n" . implode("\n", $outcomeLines) . "\n\n";
            }

            $userPrompt .= "## Latest Work Output\n{$lastStageOutput}\n\n";
            $userPrompt .= "Has the goal been achieved? Respond ACHIEVED or NOT_ACHIEVED followed by your rationale.";

            $response = $this->provider->chat([
                new SystemMessage($systemPrompt),
                new UserMessage($userPrompt),
            ]);

            return $this->parseResponse($response->content);
        } catch (\Throwable) {
            // Non-fatal — return NOT_ACHIEVED on any failure
            return new GoalEvaluationResult(
                achieved: false,
                rationale: 'Evaluation failed due to an internal error. Continuing loop.',
            );
        }
    }

    private function parseResponse(string $content): GoalEvaluationResult
    {
        $content = trim($content);
        $firstLine = strtoupper(trim((string) strtok($content, "\n")));

        $achieved = str_contains($firstLine, 'ACHIEVED') && !str_contains($firstLine, 'NOT_ACHIEVED');

        // Extract rationale (everything after the first line)
        $rationale = trim(substr($content, strlen((string) strtok($content, "\n"))));
        if ($rationale === '') {
            $rationale = $achieved ? 'Goal achieved.' : 'Goal not yet achieved.';
        }

        return new GoalEvaluationResult(
            achieved: $achieved,
            rationale: $rationale,
        );
    }
}
