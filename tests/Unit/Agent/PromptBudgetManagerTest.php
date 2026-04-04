<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CoquiBot\Coqui\Agent\PromptBudgetManager;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;

test('prompt budget manager builds context-aware snapshot', function () {
    $manager = new PromptBudgetManager();

    $snapshot = $manager->buildSnapshot(
        role: 'coder',
        model: 'openai/gpt-4o',
        toolCount: 12,
        toolkitCount: 4,
        promptTokens: 1_200,
        toolTokens: 800,
        toolkitBreakdown: [
            [
                'name' => 'LoopToolkit',
                'class' => 'CoquiBot\\Coqui\\Toolkit\\LoopToolkit',
                'guidelines_tokens' => 200,
                'tools_tokens' => 300,
                'total_tokens' => 500,
            ],
        ],
        promptSections: [
            [
                'id' => 'context.core-memories',
                'title' => 'Core Memories',
                'group' => 'memory',
                'priority' => 'workflow',
                'pinned' => true,
                'deferrable' => false,
                'included' => true,
                'decision' => 'pinned_workflow',
                'rationale' => 'Core memories preserve durable user context.',
                'source' => null,
                'tokens' => 240,
            ],
        ],
        appliedLoadingModes: [
            'LoopToolkit' => ToolkitLoadingMode::Deferred,
            'SkillToolkit' => ToolkitLoadingMode::Eager,
        ],
        loadingDecisions: [
            [
                'name' => 'LoopToolkit',
                'package' => 'coqui/core',
                'description' => 'Loop automation tools',
                'mode' => 'deferred',
                'configured_mode' => 'auto',
                'reason' => 'auto_deferred_budget',
                'tokens' => 500,
                'frequency' => 0,
                'rank' => 2,
            ],
        ],
        deferredToolkits: [
            [
                'name' => 'LoopToolkit',
                'description' => 'Loop automation tools',
                'package' => 'coqui/core',
            ],
        ],
        toolkitBudget: [
            'effective_role' => 'coder',
            'budget_tokens' => 5000,
            'budget_source' => 'agents.defaults.roles.coder.toolkitTokenBudget',
            'promotion_budget_percent' => 50,
            'promotion_budget_source' => 'agents.defaults.toolkitPromotionBudgetPercent',
            'promotion_budget_tokens' => 2500,
            'auto_candidate_count' => 3,
            'auto_candidate_tokens' => 4500,
            'used_promotion_budget_tokens' => 2000,
            'within_budget' => false,
            'deferred_count' => 1,
        ],
        contextWindow: new ContextWindow(maxTok: 10_000, reservedTok: 1_000),
    );

    $data = $snapshot->toArray();

    expect($data['role'])->toBe('coder');
    expect($data['total_tokens'])->toBe(2_000);
    expect($data['applied_loading_modes']['LoopToolkit'])->toBe('deferred');
    expect($data['toolkit_budget']['budget_source'])->toBe('agents.defaults.roles.coder.toolkitTokenBudget');
    expect($data['prompt_sections'][0]['pinned'])->toBeTrue();
    expect($data['prompt_sections'][0]['rationale'])->toBe('Core memories preserve durable user context.');
    expect($data['context_window']['used_tokens'])->toBe(2_000);
    expect($data['context_window']['effective_budget'])->toBe(9_000);
    expect($data['context_window']['available_tokens'])->toBe(7_000);
});