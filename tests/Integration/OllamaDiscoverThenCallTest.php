<?php

declare(strict_types=1);

require_once __DIR__ . '/../Unit/Agent/LeanHarness.php';

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;

/**
 * Optional real-endpoint integration test for the lean discover-then-call path.
 *
 * SKIPPED by default. To run it, start a local Ollama endpoint (default
 * http://localhost:11434/v1) and export COQUI_OLLAMA_IT=1. Optionally override
 * the model with COQUI_OLLAMA_MODEL (default ollama/qwen3:latest — must match a
 * model you have pulled).
 *
 * The synthetic sibling (tests/Unit/Agent/LeanDefaultDiscoverThenCallTest.php)
 * already proves the mechanism deterministically. This test only adds confidence
 * that a real small model can drive the same path end to end, so it is gated and
 * excluded from CI by the 'integration'/'ollama' groups.
 */
it('a real small Ollama model discovers then calls a deferred tool', function () {
    if (getenv('COQUI_OLLAMA_IT') !== '1') {
        test()->markTestSkipped(
            'Set COQUI_OLLAMA_IT=1 with a running Ollama endpoint to run this integration test.',
        );
    }

    $model = getenv('COQUI_OLLAMA_MODEL') ?: 'ollama/qwen3:latest';

    // Build a lean orchestrator on the real endpoint. makeOrchestrator points the
    // ollama provider at http://localhost:11434/v1, so with COQUI_OLLAMA_IT=1 this
    // becomes a genuine live call rather than the offline harness default.
    $agent = makeOrchestrator([
        'agents.defaults.toolProfile' => 'lean',
        'agents.defaults.model.primary' => $model,
        'agents.defaults.roles.orchestrator' => $model,
    ]);

    // Sanity: the deferred tool is discoverable in the registry the live model sees.
    $byName = [];
    foreach ($agent->tools() as $t) {
        $byName[$t->name()] = $t;
    }

    expect($byName)->toHaveKey('tool_search');

    $search = $byName['tool_search']->execute(['query' => 'inspect installed composer packages']);
    expect($search->status)->toBe(ToolResultStatus::Success);
    expect($search->content)->toContain('package_info');
})->group('integration', 'ollama');
