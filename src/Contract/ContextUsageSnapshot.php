<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CoquiBot\Coqui\Renderer\ProgressBarSegment;

/**
 * Immutable snapshot of context window usage broken down by message category.
 *
 * Built from a conversation's messages and a context window's limits after
 * each agent turn. The breakdown provides per-category token estimates so
 * the progress bar can render proportional colored segments.
 *
 * Categories:
 * - system:    System prompt and instructions (blue)
 * - memory:    Prompt memory sections injected into the current turn (green)
 * - user:      User messages (green)
 * - assistant: Assistant/LLM responses (cyan)
 * - tool:      Tool call results (yellow)
 * - summary:   Conversation summaries (magenta)
 *
 * Future categories can be added (e.g. "sprint", "skill") by extending the
 * breakdown array — the ProgressBar renderer handles arbitrary segment lists.
 */
final readonly class ContextUsageSnapshot
{
    /** @var array<string, string> Default color styles per category. */
    private const array CATEGORY_STYLES = [
        'system' => 'fg=#5f87ff',
        'memory' => 'fg=#7fd87f',
        'user' => 'fg=#87d787',
        'assistant' => 'fg=#5fd7ff',
        'tool' => 'fg=#d7af5f',
        'summary' => 'fg=#d787d7',
    ];

    /** @var array<string, string> Human-readable labels per category. */
    private const array CATEGORY_LABELS = [
        'system' => 'System',
        'memory' => 'Memory',
        'user' => 'User',
        'assistant' => 'Assistant',
        'tool' => 'Tools',
        'summary' => 'Summary',
    ];

    /**
     * @param int               $maxTokens      Context window maximum tokens.
     * @param int               $reservedTokens Tokens reserved for output generation.
     * @param int               $usedTokens     Total estimated tokens used.
     * @param float             $usagePercent   Percentage of effective budget used.
     * @param array<string,int> $breakdown      Category → estimated token count.
     */
    public function __construct(
        public int $maxTokens,
        public int $reservedTokens,
        public int $usedTokens,
        public float $usagePercent,
        public array $breakdown,
    ) {}

    /**
     * Effective budget: max tokens minus reserved output tokens.
     */
    public function effectiveBudget(): int
    {
        return max(0, $this->maxTokens - $this->reservedTokens);
    }

    /**
     * Tokens available (not yet used and not reserved).
     */
    public function availableTokens(): int
    {
        return max(0, $this->maxTokens - $this->usedTokens - $this->reservedTokens);
    }

    /**
     * Convert the breakdown into progress bar segments with default colors.
     *
     * @return ProgressBarSegment[]
     */
    public function toSegments(): array
    {
        $segments = [];

        foreach ($this->breakdown as $category => $tokens) {
            if ($tokens <= 0) {
                continue;
            }

            $style = self::CATEGORY_STYLES[$category] ?? 'fg=white';
            $label = self::CATEGORY_LABELS[$category] ?? ucfirst($category);

            $segments[] = new ProgressBarSegment(
                label: $label,
                value: $tokens,
                style: $style,
            );
        }

        return $segments;
    }

    /**
     * Format the max tokens as a human-readable string (e.g. "128K", "1M").
     */
    public function formatMaxTokens(): string
    {
        if ($this->maxTokens >= 1_000_000) {
            $value = $this->maxTokens / 1_000_000;
            return ($value == (int) $value ? (int) $value : number_format($value, 1)) . 'M';
        }

        if ($this->maxTokens >= 1_000) {
            $value = $this->maxTokens / 1_000;
            return ($value == (int) $value ? (int) $value : number_format($value, 1)) . 'K';
        }

        return (string) $this->maxTokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_tokens' => $this->maxTokens,
            'reserved_tokens' => $this->reservedTokens,
            'used_tokens' => $this->usedTokens,
            'usage_percent' => $this->usagePercent,
            'available_tokens' => $this->availableTokens(),
            'effective_budget' => $this->effectiveBudget(),
            'breakdown' => $this->breakdown,
        ];
    }
}
