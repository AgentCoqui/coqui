<?php

declare(strict_types=1);

require_once __DIR__ . '/LeanHarness.php';

it('renders a concise capability index listing deferred categories under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $prompt = $agent->getSystemPromptText();

    expect($prompt)->toContain('tool_search');
    // toolkit categories
    expect($prompt)->toContain('memory');
    expect($prompt)->toContain('loops');
    // deferred standalone capabilities
    expect($prompt)->toContain('spawn_agent');
    // stays compact: single index section, not per-toolkit guidance blocks
    expect(substr_count($prompt, '## DEFERRED'))->toBe(1);
});

it('omits the capability index entirely under full (nothing deferred)', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $prompt = $agent->getSystemPromptText();

    expect($prompt)->not->toContain('## DEFERRED');
});

it('drops a toolkit from the index once it is pinned eager', function () {
    $agent = makeOrchestrator([
        'agents.defaults.toolProfile' => 'lean',
    ], pinEager: ['MemoryToolkit']); // harness sets toolkit-loading.json override

    $prompt = $agent->getSystemPromptText();
    // Memory is now eager: its tool guidance (prompts/tools/memory.md, uniquely
    // identified by "Importance Scoring") returns, and MemoryToolkit is no longer
    // recorded as deferred.
    expect($prompt)->toContain('Importance Scoring');
    expect(array_column($agent->getDeferredToolkitInfo(), 'name'))->not->toContain('MemoryToolkit');
});
