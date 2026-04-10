<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CoquiBot\Coqui\Contract\ContextUsageSnapshot;
use CoquiBot\Coqui\Contract\PromptBudgetSnapshot;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;

/**
 * Builds immutable budget snapshots for prompt-preview surfaces.
 */
final readonly class PromptBudgetManager
{
    /**
     * @param array<int, array{name: string, class: string, guidelines_tokens: int, tools_tokens: int, total_tokens: int}> $toolkitBreakdown
        * @param array<int, array{id: string, title: string, group: string, priority: string, pinned: bool, deferrable: bool, included: bool, decision: string, rationale: string, source: string|null, tokens: int}> $promptSections
     * @param array<string, ToolkitLoadingMode> $appliedLoadingModes
     * @param array<int, array{name: string, package: string, description: string, mode: string, configured_mode: string, reason: string, tokens: int, frequency: int|null, rank: int|null}> $loadingDecisions
     * @param array<int, array{name: string, description: string, package: string}> $deferredToolkits
     * @param array{effective_role: string, budget_tokens: int, budget_source: string, promotion_budget_percent: int, promotion_budget_source: string, promotion_budget_tokens: int, auto_candidate_count: int, auto_candidate_tokens: int, used_promotion_budget_tokens: int, within_budget: bool, deferred_count: int} $toolkitBudget
     */
    public function buildSnapshot(
        string $role,
        string $model,
        int $toolCount,
        int $toolkitCount,
        int $promptTokens,
        int $toolTokens,
        array $toolkitBreakdown,
        array $promptSections,
        array $appliedLoadingModes,
        array $loadingDecisions,
        array $deferredToolkits,
        array $toolkitBudget,
        ?ContextWindowInterface $contextWindow = null,
    ): PromptBudgetSnapshot {
        $modeValues = [];
        foreach ($appliedLoadingModes as $name => $mode) {
            $modeValues[$name] = $mode->value;
        }
        ksort($modeValues);

        $contextSnapshot = null;
        if ($contextWindow !== null) {
            $effectiveBudget = max(0, $contextWindow->maxTokens() - $contextWindow->reservedTokens());
            $totalTokens = $promptTokens + $toolTokens;
            $usagePercent = $effectiveBudget > 0
                ? round(($totalTokens / $effectiveBudget) * 100, 1)
                : 100.0;

            $contextSnapshot = new ContextUsageSnapshot(
                maxTokens: $contextWindow->maxTokens(),
                reservedTokens: $contextWindow->reservedTokens(),
                usedTokens: $totalTokens,
                usagePercent: $usagePercent,
                breakdown: [
                    'system' => $promptTokens,
                    'tool' => $toolTokens,
                ],
            );
        }

        return new PromptBudgetSnapshot(
            role: $role,
            model: $model,
            toolCount: $toolCount,
            toolkitCount: $toolkitCount,
            promptTokens: $promptTokens,
            toolTokens: $toolTokens,
            totalTokens: $promptTokens + $toolTokens,
            toolkitBreakdown: $toolkitBreakdown,
            promptSections: $promptSections,
            appliedLoadingModes: $modeValues,
            loadingDecisions: $loadingDecisions,
            deferredToolkits: $deferredToolkits,
            toolkitBudget: $toolkitBudget,
            contextWindow: $contextSnapshot,
        );
    }
}