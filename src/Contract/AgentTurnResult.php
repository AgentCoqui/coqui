<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CoquiBot\Coqui\Contract\BackgroundTaskSummary;

/**
 * Immutable value object representing the outcome of a single agent turn.
 *
 * Returned by AgentRunner::run() so callers can inspect the result
 * without relying on terminal side effects for display.
 */
final readonly class AgentTurnResult
{
    /**
     * @param string[]  $toolsUsed       Unique tool names invoked during the turn.
     * @param ?array<int, array{file_path: string, operation: string}> $fileEdits  Files edited during the turn.
    * @param ?array<int, array{actor_name: string, actor_role: string, content: string, round: int}> $actorResponses
     * @param ?string   $error           Error message if the turn failed.
     * @param ?DeferredWorkQueue $deferredWork  Non-critical tasks to run after stats are rendered.
     */
    public function __construct(
        public string $content,
        public int $iterations,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public int $durationMs,
        public array $toolsUsed,
        public int $childAgentCount,
        public bool $restartRequested,
        public bool $iterationLimitReached = false,
        public bool $budgetExhausted = false,
        public ?ContextUsageSnapshot $contextUsage = null,
        public ?array $fileEdits = null,
        public ?array $actorResponses = null,
        public ?string $error = null,
        public ?string $reviewFeedback = null,
        public ?bool $reviewApproved = null,
        public ?DeferredWorkQueue $deferredWork = null,
        public ?BackgroundTaskSummary $backgroundTasks = null,
    ) {}

    public function isError(): bool
    {
        return $this->error !== null;
    }

    public static function fromError(
        string $error,
        string $content = '',
        bool $restartRequested = false,
    ): self {
        return new self(
            content: $content,
            iterations: 0,
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            durationMs: 0,
            toolsUsed: [],
            childAgentCount: 0,
            restartRequested: $restartRequested,
            error: $error,
        );
    }

    /**
     * Build a stats summary line (e.g. for terminal display).
     */
    public function statsSummary(): string
    {
        $lines = [];

        $line1 = "Iterations: {$this->iterations}";
        if ($this->durationMs > 0) {
            $seconds = round($this->durationMs / 1000, 1);
            $line1 .= " | Duration: {$seconds}s";
        }
        $lines[] = $line1;

        if ($this->promptTokens > 0 || $this->completionTokens > 0) {
            $lines[] = "Input Tokens: " . number_format($this->promptTokens)
                . " | Output Tokens: " . number_format($this->completionTokens);
        }

        if (!empty($this->toolsUsed)) {
            $lines[] = 'Tools: ' . implode(', ', $this->toolsUsed);
        }

        return implode("\n", $lines);
    }

    /**
     * Serialize to an array suitable for JSON encoding.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'iterations' => $this->iterations,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'duration_ms' => $this->durationMs,
            'tools_used' => $this->toolsUsed,
            'child_agent_count' => $this->childAgentCount,
            'restart_requested' => $this->restartRequested,
            'iteration_limit_reached' => $this->iterationLimitReached,
            'budget_exhausted' => $this->budgetExhausted,
            'context_usage' => $this->contextUsage?->toArray(),
            'file_edits' => $this->fileEdits,
            'actor_responses' => $this->actorResponses,
            'error' => $this->error,
            'review_feedback' => $this->reviewFeedback,
            'review_approved' => $this->reviewApproved,
            'background_tasks' => $this->backgroundTasks?->toArray(),
        ];
    }
}
