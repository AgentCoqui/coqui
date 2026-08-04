<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Budget\BudgetBreakdownProducer;
use CoquiBot\Coqui\Contract\ContextUsageSnapshot;
use CoquiBot\Coqui\Contract\PromptBudgetSnapshot;

/**
 * @param array<int, array<string, mixed>> $promptSections
 */
function makeBudgetSnapshot(array $promptSections): PromptBudgetSnapshot
{
    return new PromptBudgetSnapshot(
        role: 'orchestrator',
        model: 'ollama/qwen3:latest',
        toolCount: 0,
        toolkitCount: 0,
        promptTokens: 0,
        toolTokens: 0,
        totalTokens: 0,
        toolkitBreakdown: [],
        promptSections: $promptSections,
        appliedLoadingModes: [],
        loadingDecisions: [],
        deferredToolkits: [],
        toolkitBudget: [
            'effective_role' => 'orchestrator',
            'budget_tokens' => 0,
            'budget_source' => 'default',
            'promotion_budget_percent' => 0,
            'promotion_budget_source' => 'default',
            'promotion_budget_tokens' => 0,
            'auto_candidate_count' => 0,
            'auto_candidate_tokens' => 0,
            'used_promotion_budget_tokens' => 0,
            'within_budget' => true,
            'deferred_count' => 0,
        ],
        contextWindow: new ContextUsageSnapshot(
            maxTokens: 8192,
            reservedTokens: 0,
            usedTokens: 0,
            usagePercent: 0.0,
            breakdown: [],
        ),
    );
}

/**
 * @param string $priority One of critical/workflow/volatile.
 * @return array<string, mixed>
 */
function makeBudgetSection(string $id, string $priority, bool $included, int $tokens): array
{
    return [
        'id' => $id,
        'title' => ucfirst($id),
        'group' => 'system',
        'priority' => $priority,
        'pinned' => $priority !== 'volatile',
        'deferrable' => $priority === 'volatile',
        'included' => $included,
        'decision' => $included ? 'included' : 'over_budget',
        'rationale' => '',
        'source' => null,
        'tokens' => $tokens,
    ];
}

test('toWire maps section priority onto a discriminating shed rank', function () {
    $wire = (new BudgetBreakdownProducer())->toWire(makeBudgetSnapshot([
        makeBudgetSection('security', 'critical', true, 100),
        makeBudgetSection('workflow', 'workflow', true, 50),
        makeBudgetSection('memory', 'volatile', true, 25),
    ]));

    $ranks = array_column($wire['sections'], 'priority', 'name');

    // Lower is retained longer; volatile sheds first. The three ranks are
    // distinct — the field actually discriminates instead of collapsing to 0.
    expect($ranks['Security'])->toBe(0);
    expect($ranks['Workflow'])->toBe(1);
    expect($ranks['Memory'])->toBe(2);
    expect($ranks['Security'])->toBeLessThan($ranks['Memory']);
    expect(count(array_unique($ranks)))->toBe(3);
});

test('toWire zeroes estimated_tokens for excluded sections but keeps the included sum', function () {
    $wire = (new BudgetBreakdownProducer())->toWire(makeBudgetSnapshot([
        makeBudgetSection('security', 'critical', true, 100),
        makeBudgetSection('memory', 'volatile', false, 40),
    ]));

    $byName = [];
    foreach ($wire['sections'] as $section) {
        $byName[$section['name']] = $section;
    }

    // Included section keeps its real estimate; excluded section reports 0.
    expect($byName['Security']['estimated_tokens'])->toBe(100);
    expect($byName['Memory']['estimated_tokens'])->toBe(0);
    // total_estimated_tokens is the sum of included sections only.
    expect($wire['total_estimated_tokens'])->toBe(100);
});
