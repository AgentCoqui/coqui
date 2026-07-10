<?php

declare(strict_types=1);

require_once __DIR__ . '/LeanHarness.php';

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Tool\StubTool;

/**
 * Discover-then-call proof for the lean profile, with no live model.
 *
 * The essential claim: a tool deferred under the lean profile is (1) DISCOVERABLE
 * via tool_search — its full description stays in the BM25 registry — and
 * (2) CALLABLE — the minimal StubTool advertised in tools() forwards execute()
 * to the real tool, so the agent can act on it immediately after discovery.
 *
 * We exercise `package_info` rather than the brief's `spawn_agent`: it is a
 * deferred (non-core) standalone tool under lean (see CoquiDefaults::LEAN_CORE_TOOLS
 * vs ALL_STANDALONE_TOOLS), but unlike spawn_agent it is read-only and boots no
 * child agent, keeping the test deterministic and side-effect free. The claim is
 * about the discover-then-call PATH, not the specific tool.
 */

/** @return array<string, \CarmeloSantana\PHPAgents\Contract\ToolInterface> name => tool */
function discoverThenCallToolsByName($agent): array
{
    $byName = [];
    foreach ($agent->tools() as $t) {
        $byName[$t->name()] = $t;
    }
    return $byName;
}

it('discovers a lean-deferred tool via tool_search then invokes its callable stub (no live model)', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $byName = discoverThenCallToolsByName($agent);

    // --- DISCOVER --------------------------------------------------------
    // tool_search stays eager (a core lean tool) and searches the full BM25
    // registry, where the deferred tool keeps its real description.
    expect($byName)->toHaveKey('tool_search');
    expect($byName['tool_search'])->not->toBeInstanceOf(StubTool::class);

    $search = $byName['tool_search']->execute(['query' => 'inspect installed composer packages']);
    expect($search->status)->toBe(ToolResultStatus::Success);
    expect($search->content)->toContain('package_info');

    // --- CALL ------------------------------------------------------------
    // The discovered deferred tool is present in tools() as a minimal StubTool
    // (empty params, [STUB] description) yet remains executable: execute()
    // forwards to the real PackageInfoTool, so we get its real output — not a
    // "tool not found" / uncallable error.
    expect($byName)->toHaveKey('package_info');
    expect($byName['package_info'])->toBeInstanceOf(StubTool::class);
    expect($byName['package_info']->parameters())->toBe([]); // minimal footprint

    $call = $byName['package_info']->execute([
        'action' => 'classes',
        'package' => 'carmelosantana/php-agents',
    ]);

    // Forwarded to the real tool: a successful class listing, not a stub error.
    expect($call->status)->toBe(ToolResultStatus::Success);
    expect($call->content)->toContain('Classes in carmelosantana/php-agents');
    expect($call->content)->not->toContain('not found');

    // --- CONTRAST --------------------------------------------------------
    // A core tool (php_execute) is present in FULL under lean — not a stub —
    // proving deferral is selective, not blanket.
    expect($byName)->toHaveKey('php_execute');
    expect($byName['php_execute'])->not->toBeInstanceOf(StubTool::class);
});
