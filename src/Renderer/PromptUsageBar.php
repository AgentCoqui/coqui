<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Renders prompt budget progress bars for the /prompt REPL command.
 *
 * Two visualizations:
 * 1. Section breakdown — proportional token usage by prompt category
 *    (soul, base, tools, context, project, toolkit guidelines)
 * 2. Context impact — prompt footprint against the model's context window
 *
 * Works from the plain array returned by PromptBudgetSnapshot::toArray(),
 * so no coupling to the value object itself.
 */
final class PromptUsageBar
{
    /** @var array<string, string> Color styles per prompt category. */
    private const array SECTION_STYLES = [
        'soul' => 'fg=#d787d7',
        'base' => 'fg=#5f87ff',
        'tools' => 'fg=#d7af5f',
        'context' => 'fg=#87d787',
        'project' => 'fg=#5fd7ff',
        'toolkit' => 'fg=#ff8700',
        'budget' => 'fg=#af87ff',
    ];

    /** @var array<string, string> Human-readable labels per prompt category. */
    private const array SECTION_LABELS = [
        'soul' => 'Soul',
        'base' => 'Base',
        'tools' => 'Tools',
        'context' => 'Context',
        'project' => 'Project',
        'toolkit' => 'Toolkit',
        'budget' => 'Budget',
    ];

    /**
     * Render the prompt section breakdown bar.
     *
     * Groups prompt_sections by visual category and renders a proportional
     * bar showing how tokens are distributed across prompt components.
     *
     * @param array<string, mixed> $budgetSnapshot Output of PromptBudgetSnapshot::toArray().
     */
    public static function renderSectionBreakdown(
        SymfonyStyle $io,
        array $budgetSnapshot,
        int $width = 50,
    ): void {
        /** @var array<int, array{id: string, group: string, tokens: int}> $sections */
        $sections = $budgetSnapshot['prompt_sections'] ?? [];

        if ($sections === []) {
            return;
        }

        $categories = self::groupByCategory($sections);
        $totalTokens = (int) ($budgetSnapshot['prompt_tokens'] ?? 0);

        if ($totalTokens <= 0) {
            return;
        }

        $segments = [];
        foreach ($categories as $category => $tokens) {
            if ($tokens <= 0) {
                continue;
            }

            $style = self::SECTION_STYLES[$category] ?? 'fg=white';
            $label = self::SECTION_LABELS[$category] ?? ucfirst($category);

            $segments[] = new ProgressBarSegment(
                label: $label,
                value: $tokens,
                style: $style,
            );
        }

        $label = sprintf(
            '<fg=gray>• Prompt</> <fg=gray>[%s tokens]</>',
            number_format($totalTokens),
        );

        $bar = new ProgressBar($width);
        $bar->render(
            io: $io,
            total: $totalTokens,
            segments: $segments,
            emptyStyle: 'fg=#444444',
            showPercent: false,
            showLegend: true,
            label: $label,
        );
    }

    /**
     * Render the context window impact bar.
     *
     * Shows how much of the model's context window is consumed by the
     * prompt text and tool schemas, with remaining space as empty.
     *
     * @param array<string, mixed> $budgetSnapshot Output of PromptBudgetSnapshot::toArray().
     */
    public static function renderContextImpact(
        SymfonyStyle $io,
        array $budgetSnapshot,
        int $width = 50,
    ): void {
        /** @var array{max_tokens: int, reserved_tokens: int}|null $contextWindow */
        $contextWindow = $budgetSnapshot['context_window'] ?? null;

        if ($contextWindow === null) {
            return;
        }

        $maxTokens = $contextWindow['max_tokens'];
        $reservedTokens = $contextWindow['reserved_tokens'];
        $effectiveBudget = max(0, $maxTokens - $reservedTokens);

        if ($effectiveBudget <= 0) {
            return;
        }

        $promptTokens = (int) ($budgetSnapshot['prompt_tokens'] ?? 0);
        $toolTokens = (int) ($budgetSnapshot['tool_tokens'] ?? 0);
        $totalUsed = $promptTokens + $toolTokens;
        $percent = min(100.0, ($totalUsed / $effectiveBudget) * 100);

        $segments = [];

        if ($promptTokens > 0) {
            $segments[] = new ProgressBarSegment(
                label: 'Prompt',
                value: $promptTokens,
                style: 'fg=#5f87ff',
            );
        }

        if ($toolTokens > 0) {
            $segments[] = new ProgressBarSegment(
                label: 'Tool Schemas',
                value: $toolTokens,
                style: 'fg=#d7af5f',
            );
        }

        $formattedMax = self::formatTokenCount($maxTokens);
        $label = sprintf('<fg=gray>• Context</> <fg=gray>[%s]</>', $formattedMax);

        $bar = new ProgressBar($width);
        $bar->render(
            io: $io,
            total: $effectiveBudget,
            segments: $segments,
            emptyStyle: 'fg=#444444',
            showPercent: true,
            showLegend: true,
            label: $label,
        );
    }

    /**
     * Group prompt sections into visual categories by their group field.
     *
     * @param array<int, array{id: string, group: string, tokens: int}> $sections
     * @return array<string, int> Category → total tokens.
     */
    private static function groupByCategory(array $sections): array
    {
        $categories = [];

        foreach ($sections as $section) {
            $category = self::resolveCategory($section['id'], $section['group']);
            $tokens = $section['tokens'];
            $categories[$category] = ($categories[$category] ?? 0) + $tokens;
        }

        // Return in a stable display order
        $ordered = [];
        foreach (array_keys(self::SECTION_LABELS) as $key) {
            if (isset($categories[$key]) && $categories[$key] > 0) {
                $ordered[$key] = $categories[$key];
            }
        }

        return $ordered;
    }

    /**
     * Map a prompt section to its visual category.
     */
    private static function resolveCategory(string $id, string $group): string
    {
        // Soul is its own category within the identity group
        if ($group === 'identity' && $id === 'prompt.soul') {
            return 'soul';
        }

        return match ($group) {
            'identity' => 'base',
            'tool_prompts' => 'tools',
            'memory', 'tool_discovery' => 'context',
            'project' => 'project',
            'toolkit_guidelines' => 'toolkit',
            'iteration_budget' => 'budget',
            default => 'tools',
        };
    }

    /**
     * Format a token count as a human-readable string (e.g. "128K", "1M").
     */
    private static function formatTokenCount(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            $value = $tokens / 1_000_000;
            return ($value == (int) $value ? (int) $value : number_format($value, 1)) . 'M';
        }

        if ($tokens >= 1_000) {
            $value = $tokens / 1_000;
            return ($value == (int) $value ? (int) $value : number_format($value, 1)) . 'K';
        }

        return (string) $tokens;
    }
}
