<?php

declare(strict_types=1);

use CoquiBot\Coqui\Mcp\McpRuntime;
use CoquiBot\Coqui\Mcp\McpServerToolkit;

it('builds a runtime with no policy and an empty server list', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-runtime-' . uniqid();
    $runtime = McpRuntime::fromWorkspace($workspace);

    expect($runtime->serverToolkits())->toBe([]);
    expect($runtime->managementService())->not->toBeNull();
});

it('exposes one server toolkit per enabled server', function (): void {
    $workspace = sys_get_temp_dir() . '/mcp-runtime-' . uniqid();
    $runtime = McpRuntime::fromWorkspace($workspace);
    $runtime->config()->addServer('github', ['command' => 'npx', 'args' => ['-y', 'x']]);
    $runtime->config()->save();

    $toolkits = $runtime->serverToolkits();
    expect($toolkits)->toHaveCount(1);
    expect($toolkits[0])->toBeInstanceOf(McpServerToolkit::class);
});
