<?php

declare(strict_types=1);

require_once __DIR__ . '/LeanHarness.php';

use CoquiBot\Coqui\Tool\StubTool;

/** @return array<string, \CarmeloSantana\PHPAgents\Contract\ToolInterface> name => tool */
function toolsByName($agent): array
{
    $byName = [];
    foreach ($agent->tools() as $t) {
        $byName[$t->name()] = $t;
    }
    return $byName;
}

it('advertises non-core standalone tools as minimal stubs under lean', function () {
    $byName = toolsByName(makeOrchestrator(['agents.defaults.toolProfile' => 'lean']));

    // Core standalone tools are present in FULL (not stubbed).
    expect($byName)->toHaveKey('tool_search')->toHaveKey('config')->toHaveKey('php_execute');
    expect($byName['php_execute'])->not->toBeInstanceOf(StubTool::class);

    // Deferred standalone tools are still present but as minimal stubs — so they
    // remain in the agent's executable tool index (callable after tool_search
    // discovery) while their schema footprint shrinks. A fully-omitted tool would
    // be uncallable.
    foreach (['spawn_agent', 'vision_analyze', 'extract_memories'] as $deferred) {
        expect($byName)->toHaveKey($deferred);
        expect($byName[$deferred])->toBeInstanceOf(StubTool::class);
        expect($byName[$deferred]->parameters())->toBe([]); // stub carries no params
    }
});

it('still finds a deferred standalone tool via tool_search', function () {
    $byName = toolsByName(makeOrchestrator(['agents.defaults.toolProfile' => 'lean']));

    // Invoke tool_search directly — real-behavior proof the deferred tool is in
    // the BM25 registry with its full description.
    expect($byName)->toHaveKey('tool_search');
    $result = $byName['tool_search']->execute(['query' => 'spawn child agent']); // ToolResult
    expect($result->content)->toContain('spawn_agent');
});

it('keeps all standalone tools full (non-stub) under the full profile', function () {
    $byName = toolsByName(makeOrchestrator(['agents.defaults.toolProfile' => 'full']));

    expect($byName)->toHaveKey('spawn_agent')->toHaveKey('vision_analyze');
    expect($byName['spawn_agent'])->not->toBeInstanceOf(StubTool::class);
});

it('drops the prompt guidance of deferred standalone tools under lean', function () {
    // Deferring a standalone tool must also drop its dedicated prompts/tools/*.md,
    // not just hide the tool. Assert on body text unique to each prompt.
    $lean = makeOrchestrator(['agents.defaults.toolProfile' => 'lean'])->getSystemPromptText();
    $full = makeOrchestrator(['agents.defaults.toolProfile' => 'full'])->getSystemPromptText();

    // spawn_agent -> delegation.md ("Specialist Agents"); restart_coqui -> restart.md.
    expect($lean)->not->toContain('Specialist Agents');
    expect($full)->toContain('Specialist Agents');
});
