<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Immutable result of a code review cycle (one or more code→review rounds).
 *
 * Returned by CodeReviewCycle::run() to the caller (SpawnAgentTool or
 * AgentRunner) with the final coder output, review verdict, and usage stats.
 */
final readonly class CodeReviewResult
{
    public function __construct(
        public string $finalContent,
        public bool $approved,
        public string $reviewFeedback,
        public int $roundsUsed,
        public int $totalTokens,
        public int $coderIterations,
        public int $reviewerIterations,
    ) {}

    /**
     * Build a summary string suitable for appending to the coder's result
     * when returning to the orchestrator via ToolResult.
     */
    public function buildSummary(): string
    {
        $status = $this->approved ? '✓ APPROVED' : '✗ NEEDS CHANGES';
        $lines = [
            '',
            "---",
            "**Code Review: {$status}** (round {$this->roundsUsed})",
        ];

        if ($this->reviewFeedback !== '') {
            $lines[] = '';
            $lines[] = $this->reviewFeedback;
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'approved' => $this->approved,
            'review_feedback' => $this->reviewFeedback,
            'rounds_used' => $this->roundsUsed,
            'total_tokens' => $this->totalTokens,
            'coder_iterations' => $this->coderIterations,
            'reviewer_iterations' => $this->reviewerIterations,
        ];
    }
}
