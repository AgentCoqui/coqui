<?php

declare(strict_types=1);

require_once __DIR__ . '/LeanHarness.php';

function deferredBasenames($agent): array
{
    return array_column($agent->getDeferredToolkitInfo(), 'name');
}

it('default (no config) is lean', function () {
    $agent = makeOrchestrator([]);
    expect(deferredBasenames($agent))->toContain('MemoryToolkit');
});

it('coreToolkits list overrides the profile preset', function () {
    $agent = makeOrchestrator([
        'agents.defaults.toolProfile' => 'lean',
        'agents.defaults.coreToolkits' => ['FileSystemToolkit', 'ShellToolkit', 'MemoryToolkit'],
    ]);
    // Memory now core => not deferred.
    expect(deferredBasenames($agent))->not->toContain('MemoryToolkit');
});

it('a per-toolkit eager override wins even under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean'], pinEager: ['LoopToolkit']);
    expect(deferredBasenames($agent))->not->toContain('LoopToolkit');
});

it('full profile defers nothing built-in', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $builtins = ['MemoryToolkit', 'LoopToolkit', 'WebToolkit', 'ScheduleToolkit'];
    foreach ($builtins as $b) {
        expect(deferredBasenames($agent))->not->toContain($b);
    }
});
