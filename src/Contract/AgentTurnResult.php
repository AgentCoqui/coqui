<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

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
     * @param ?string   $error           Error message if the turn failed.
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
        public ?string $error = null,
    ) {}

    public function isError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Build a stats summary line (e.g. for terminal display).
     */
    public function statsSummary(): string
    {
        $parts = ["Iterations: {$this->iterations}"];

        if ($this->totalTokens > 0) {
            $parts[] = "Tokens: {$this->totalTokens}";
        }

        if (!empty($this->toolsUsed)) {
            $parts[] = 'Tools: ' . implode(', ', $this->toolsUsed);
        }

        if ($this->durationMs > 0) {
            $seconds = round($this->durationMs / 1000, 1);
            $parts[] = "Duration: {$seconds}s";
        }

        return implode(' | ', $parts);
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
            'error' => $this->error,
        ];
    }
}
