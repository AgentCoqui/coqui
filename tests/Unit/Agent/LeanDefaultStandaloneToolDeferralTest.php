<?php

declare(strict_types=1);

require_once __DIR__ . '/LeanHarness.php';

it('omits non-core standalone tools from the LLM tool list under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);

    $names = array_map(fn($t) => $t->name(), $agent->tools());

    // core stays visible
    expect($names)->toContain('tool_search')->toContain('config')->toContain('php_execute');
    // deferred standalone tools are gone from the visible list
    expect($names)->not->toContain('spawn_agent');
    expect($names)->not->toContain('vision_analyze');
    expect($names)->not->toContain('extract_memories');
});

it('still finds a deferred standalone tool via tool_search', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);

    // Locate the tool_search tool in the visible list and invoke it directly —
    // real-behavior proof the deferred tool is in the BM25 registry.
    $search = null;
    foreach ($agent->tools() as $t) {
        if ($t->name() === 'tool_search') { $search = $t; break; }
    }
    expect($search)->not->toBeNull();

    $result = $search->execute(['query' => 'spawn child agent']); // ToolResult
    expect($result->content)->toContain('spawn_agent');
});

it('keeps all standalone tools visible under the full profile', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $names = array_map(fn($t) => $t->name(), $agent->tools());

    expect($names)->toContain('spawn_agent')->toContain('vision_analyze');
});
