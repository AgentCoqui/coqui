<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolProfileResolver;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Build a real config from an agents.defaults fragment. Use OpenClawConfig
 * (not a hand-rolled ConfigInterface double — the interface has 7 methods)
 * so dot-notation resolution is exercised for real.
 */
function leanConfig(array $agentsDefaults): OpenClawConfig
{
    return OpenClawConfig::fromArray(['agents' => ['defaults' => $agentsDefaults]]);
}

it('defaults to the lean profile and lean core sets', function () {
    $r = new ToolProfileResolver(leanConfig([]));

    expect($r->profile())->toBe('lean');
    expect($r->isFull())->toBeFalse();
    expect($r->coreToolkits())->toBe(CoquiDefaults::LEAN_CORE_TOOLKITS);
    expect($r->coreTools())->toBe(CoquiDefaults::LEAN_CORE_TOOLS);
});

it('resolves the full profile to every system toolkit and no tool deferral', function () {
    $r = new ToolProfileResolver(leanConfig(['toolProfile' => 'full']));

    expect($r->isFull())->toBeTrue();
    expect($r->coreToolkits())->toBe(CoquiDefaults::SYSTEM_TOOLKITS);
    // full => every standalone tool is core (nothing defers).
    expect($r->coreTools())->toBe(CoquiDefaults::ALL_STANDALONE_TOOLS);
    expect($r->coreTools())->toContain('php_execute')->toContain('spawn_agent');
});

it('treats an unknown profile as lean', function () {
    $r = new ToolProfileResolver(leanConfig(['toolProfile' => 'bogus']));
    expect($r->profile())->toBe('lean');
});

it('lets an explicit coreToolkits list override the profile preset', function () {
    $r = new ToolProfileResolver(leanConfig([
        'coreToolkits' => ['FileSystemToolkit', 'MemoryToolkit'],
    ]));
    expect($r->coreToolkits())->toBe(['FileSystemToolkit', 'MemoryToolkit']);
});
