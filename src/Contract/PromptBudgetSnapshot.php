<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Immutable preview snapshot of prompt and toolkit budget state.
 *
 * @param array<int, array{name: string, class: string, guidelines_tokens: int, tools_tokens: int, total_tokens: int}> $toolkitBreakdown
 * @param array<int, array{id: string, title: string, group: string, priority: string, pinned: bool, deferrable: bool, included: bool, decision: string, rationale: string, source: string|null, tokens: int}> $promptSections
 * @param array<string, string> $appliedLoadingModes
 * @param array<int, array{name: string, package: string, description: string, mode: string, configured_mode: string, reason: string, tokens: int, frequency: int|null, rank: int|null}> $loadingDecisions
 * @param array<int, array{name: string, description: string, package: string}> $deferredToolkits
 * @param array{effective_role: string, budget_tokens: int, budget_source: string, promotion_budget_percent: int, promotion_budget_source: string, promotion_budget_tokens: int, auto_candidate_count: int, auto_candidate_tokens: int, used_promotion_budget_tokens: int, within_budget: bool, deferred_count: int} $toolkitBudget
 */
final readonly class PromptBudgetSnapshot
{
    /**
     * @param array<int, array{name: string, class: string, guidelines_tokens: int, tools_tokens: int, total_tokens: int}> $toolkitBreakdown
        * @param array<int, array{id: string, title: string, group: string, priority: string, pinned: bool, deferrable: bool, included: bool, decision: string, rationale: string, source: string|null, tokens: int}> $promptSections
     * @param array<string, string> $appliedLoadingModes
     * @param array<int, array{name: string, package: string, description: string, mode: string, configured_mode: string, reason: string, tokens: int, frequency: int|null, rank: int|null}> $loadingDecisions
     * @param array<int, array{name: string, description: string, package: string}> $deferredToolkits
     * @param array{effective_role: string, budget_tokens: int, budget_source: string, promotion_budget_percent: int, promotion_budget_source: string, promotion_budget_tokens: int, auto_candidate_count: int, auto_candidate_tokens: int, used_promotion_budget_tokens: int, within_budget: bool, deferred_count: int} $toolkitBudget
     */
    public function __construct(
        public string $role,
        public string $model,
        public int $toolCount,
        public int $toolkitCount,
        public int $promptTokens,
        public int $toolTokens,
        public int $totalTokens,
        public array $toolkitBreakdown,
        public array $promptSections,
        public array $appliedLoadingModes,
        public array $loadingDecisions,
        public array $deferredToolkits,
        public array $toolkitBudget,
        public ?ContextUsageSnapshot $contextWindow = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'model' => $this->model,
            'tool_count' => $this->toolCount,
            'toolkit_count' => $this->toolkitCount,
            'prompt_tokens' => $this->promptTokens,
            'tool_tokens' => $this->toolTokens,
            'total_tokens' => $this->totalTokens,
            'toolkit_breakdown' => $this->toolkitBreakdown,
            'prompt_sections' => $this->promptSections,
            'applied_loading_modes' => $this->appliedLoadingModes,
            'loading_decisions' => $this->loadingDecisions,
            'deferred_toolkits' => $this->deferredToolkits,
            'toolkit_budget' => $this->toolkitBudget,
            'context_window' => $this->contextWindow?->toArray(),
        ];
    }
}