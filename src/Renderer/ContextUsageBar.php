<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\ContextUsageSnapshot;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Builds a context usage snapshot from conversation + context window state,
 * then renders it as a colored progress bar.
 *
 * This is the bridge between the agent data model (Conversation, ContextWindow)
 * and the generic ProgressBar renderer. It knows how to categorize messages
 * by role, detect summary messages, merge prompt-section tokens, and estimate
 * per-category token usage.
 */
final class ContextUsageBar
{
    private const string SUMMARY_MARKER = '[CONVERSATION SUMMARY';

    /**
     * Build a context usage snapshot from a conversation and context window.
     *
     * Categorizes every message into system/memory/user/assistant/tool/summary,
     * folds prompt-section tokens into system or memory, and estimates token
     * counts using the same heuristic as
     * Conversation::estimateTokens() (1 token ≈ 4 chars).
     *
     * @param array<int, array{group?: string, tokens?: int}> $promptSections
     */
    public static function buildSnapshot(
        Conversation $conversation,
        ?ContextWindowInterface $contextWindow = null,
        array $promptSections = [],
    ): ContextUsageSnapshot {
        $breakdown = [
            'system' => 0,
            'memory' => 0,
            'user' => 0,
            'assistant' => 0,
            'tool' => 0,
            'summary' => 0,
        ];

        foreach ($promptSections as $section) {
            $tokens = (int) ($section['tokens'] ?? 0);
            if ($tokens <= 0) {
                continue;
            }

            if (($section['group'] ?? '') === 'memory') {
                $breakdown['memory'] += $tokens;
                continue;
            }

            $breakdown['system'] += $tokens;
        }

        foreach ($conversation->messages() as $message) {
            $content = $message->content();
            $chars = is_string($content)
                ? strlen($content)
                : strlen(json_encode($content) ?: '');

            // Account for tool call schemas in assistant messages
            if (!empty($message->toolCalls())) {
                $chars += strlen(json_encode(
                    array_map(
                        fn($tc) => ['id' => $tc->id, 'name' => $tc->name, 'arguments' => $tc->arguments],
                        $message->toolCalls(),
                    ),
                ) ?: '');
            }

            $tokens = (int) ceil($chars / 4);
            $category = self::categorizeMessage($message->role(), $content);
            $breakdown[$category] += $tokens;
        }

        // Use context window data when available; fall back to conversation estimate
        if ($contextWindow !== null) {
            $maxTokens = $contextWindow->maxTokens();
            $reservedTokens = $contextWindow->reservedTokens();
            $usedTokens = $contextWindow->usedTokens();
            $usagePercent = $contextWindow->usagePercent();
        } else {
            $maxTokens = CoquiDefaults::CONTEXT_WINDOW_FALLBACK;
            $reservedTokens = CoquiDefaults::CONTEXT_WINDOW_RESERVED;
            $usedTokens = array_sum($breakdown);
            $effective = $maxTokens - $reservedTokens;
            /** @phpstan-ignore greater.alwaysTrue */
            $usagePercent = $effective > 0
                ? round(($usedTokens / $effective) * 100, 1)
                : 100.0;
        }

        return new ContextUsageSnapshot(
            maxTokens: $maxTokens,
            reservedTokens: $reservedTokens,
            usedTokens: $usedTokens,
            usagePercent: $usagePercent,
            breakdown: $breakdown,
        );
    }

    /**
     * Render a context usage progress bar to the terminal.
     *
     * @param bool $showLegend Whether to show the category legend below the bar.
     */
    public static function render(
        SymfonyStyle $io,
        ContextUsageSnapshot $snapshot,
        bool $showLegend = true,
        int $width = 50,
    ): void {
        $bar = new ProgressBar($width);
        $total = $snapshot->effectiveBudget();
        $segments = $snapshot->toSegments();
        $label = sprintf('<fg=gray>• Context</> <fg=gray>[%s]</>', $snapshot->formatMaxTokens());

        $bar->render(
            io: $io,
            total: $total,
            segments: $segments,
            emptyStyle: 'fg=#444444',
            showPercent: true,
            showLegend: $showLegend,
            label: $label,
        );
    }

    /**
     * Categorize a message into a breakdown category.
     *
     * @param string|array<mixed> $content
     */
    private static function categorizeMessage(Role $role, string|array $content): string
    {
        if (is_string($content) && str_starts_with(ltrim($content), self::SUMMARY_MARKER)) {
            return 'summary';
        }

        return match ($role) {
            Role::System => 'system',
            Role::User => 'user',
            Role::Assistant => 'assistant',
            Role::Tool => 'tool',
        };
    }
}
