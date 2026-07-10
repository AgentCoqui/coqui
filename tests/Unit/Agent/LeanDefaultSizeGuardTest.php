<?php

declare(strict_types=1);

require_once __DIR__ . '/LeanHarness.php';

use CarmeloSantana\PHPAgents\Context\HeuristicCounter;
use CoquiBot\Coqui\Tool\StubTool;

it('keeps the lean system prompt small', function () {
    $leanAgent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $fullAgent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $counter = new HeuristicCounter();
    $lean = $counter->count($leanAgent->getSystemPromptText());
    $full = $counter->count($fullAgent->getSystemPromptText());

    // Measured reality (the source audit undercounted): full ~11.4k, lean ~4.7k.
    // Guard for regressions with headroom, and assert a meaningful reduction vs full.
    expect($lean)->toBeLessThan(5500);
    expect($lean)->toBeLessThan((int) ($full * 0.6));
});

it('carries far fewer full-schema standalone tools under lean than full', function () {
    // Deferred standalone tools are advertised as minimal StubTools (callable,
    // tiny), not omitted — so assert on the count of FULL-schema tools, which
    // shrinks to just the core set under lean.
    $fullSchemaCount = fn($agent) => count(array_filter(
        $agent->tools(),
        fn($t) => !($t instanceof StubTool),
    ));

    $lean = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $full = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);

    expect($fullSchemaCount($lean))->toBeLessThan($fullSchemaCount($full));

    // Core standalone tools stay full under lean; non-core are stubs.
    $leanByName = [];
    foreach ($lean->tools() as $t) { $leanByName[$t->name()] = $t; }
    expect($leanByName['php_execute'])->not->toBeInstanceOf(StubTool::class);
    expect($leanByName['spawn_agent'])->toBeInstanceOf(StubTool::class);
});

it('full profile restores the pre-change eager surface (all full-schema)', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $byName = [];
    foreach ($agent->tools() as $t) { $byName[$t->name()] = $t; }

    // Previously-eager standalone tools are all present again, in full (non-stub).
    foreach (['spawn_agent', 'vision_analyze', 'summarize_conversation', 'extract_memories'] as $n) {
        expect($byName)->toHaveKey($n);
        expect($byName[$n])->not->toBeInstanceOf(StubTool::class);
    }
});
