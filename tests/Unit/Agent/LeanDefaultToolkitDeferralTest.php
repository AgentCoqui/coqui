<?php

declare(strict_types=1);

require_once __DIR__ . '/LeanHarness.php';

it('defers non-core built-in toolkits under the lean profile', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);

    $deferred = array_column($agent->getDeferredToolkitInfo(), 'name');

    // FileSystem + Shell stay eager (never deferred).
    expect($deferred)->not->toContain('FileSystemToolkit');
    expect($deferred)->not->toContain('ShellToolkit');
    // Memory + Loop + Schedule are deferred.
    expect($deferred)->toContain('MemoryToolkit');
    expect($deferred)->toContain('LoopToolkit');
    expect($deferred)->toContain('ScheduleToolkit');
});

it('keeps every built-in toolkit eager under the full profile', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);

    $deferred = array_column($agent->getDeferredToolkitInfo(), 'name');
    foreach (['MemoryToolkit', 'LoopToolkit', 'ScheduleToolkit', 'WebToolkit'] as $builtin) {
        expect($deferred)->not->toContain($builtin);
    }
});

it('excludes deferred toolkit prompt slugs from the system prompt under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $prompt = $agent->getSystemPromptText();

    // The ~930-token memory guidance and loops guidance are gone. Assert on body
    // text unique to each tool prompt (immune to heading-shift + substring matches).
    expect($prompt)->not->toContain('Importance Scoring'); // prompts/tools/memory.md only
    expect($prompt)->not->toContain('How Loops Work');      // prompts/tools/loops.md only
});

it('retains passive memory recall even though memory tools are deferred', function () {
    // Seed one memory, then assert it still appears in the rendered prompt under
    // lean. Mirror the memory-store seeding used by the existing memory-injection
    // test (grep tests/ for MemoryStore usage) so recall is exercised for real.
    $agent = makeOrchestrator(
        ['agents.defaults.toolProfile' => 'lean'],
        seedMemories: ['The user prefers tabs over spaces.'],
    );
    $prompt = $agent->getSystemPromptText();

    // Recall block is injected independently of the (deferred) memory toolkit.
    expect($prompt)->toContain('tabs over spaces');
    // But the memory management guidance (memory.md) is not eagerly loaded.
    expect($prompt)->not->toContain('Importance Scoring');
});
