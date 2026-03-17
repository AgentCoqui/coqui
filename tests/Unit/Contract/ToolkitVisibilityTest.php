<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\ToolkitVisibility;

test('enum cases map to expected string values', function () {
    expect(ToolkitVisibility::Enabled->value)->toBe('enabled');
    expect(ToolkitVisibility::Stub->value)->toBe('stub');
    expect(ToolkitVisibility::Disabled->value)->toBe('disabled');
});

test('tryFrom returns correct case', function () {
    expect(ToolkitVisibility::tryFrom('enabled'))->toBe(ToolkitVisibility::Enabled);
    expect(ToolkitVisibility::tryFrom('stub'))->toBe(ToolkitVisibility::Stub);
    expect(ToolkitVisibility::tryFrom('disabled'))->toBe(ToolkitVisibility::Disabled);
    expect(ToolkitVisibility::tryFrom('unknown'))->toBeNull();
});

test('isAlwaysEnabled returns true for protected tools', function () {
    expect(ToolkitVisibility::isAlwaysEnabled('tool_search'))->toBeTrue();
    expect(ToolkitVisibility::isAlwaysEnabled('credentials'))->toBeTrue();
});

test('isAlwaysEnabled returns false for non-protected tools', function () {
    expect(ToolkitVisibility::isAlwaysEnabled('spawn_agent'))->toBeFalse();
    expect(ToolkitVisibility::isAlwaysEnabled('vision_analyze'))->toBeFalse();
    expect(ToolkitVisibility::isAlwaysEnabled('custom_tool'))->toBeFalse();
});

test('canDisable returns false for ALWAYS_ENABLED tools', function () {
    expect(ToolkitVisibility::canDisable('tool_search'))->toBeFalse();
    expect(ToolkitVisibility::canDisable('credentials'))->toBeFalse();
});

test('canDisable returns false for CANNOT_DISABLE tools', function () {
    expect(ToolkitVisibility::canDisable('spawn_agent'))->toBeFalse();
    expect(ToolkitVisibility::canDisable('vision_analyze'))->toBeFalse();
    expect(ToolkitVisibility::canDisable('restart_coqui'))->toBeFalse();
});

test('canDisable returns true for unprotected tools', function () {
    expect(ToolkitVisibility::canDisable('package_info'))->toBeTrue();
    expect(ToolkitVisibility::canDisable('php_execute'))->toBeTrue();
    expect(ToolkitVisibility::canDisable('custom_tool'))->toBeTrue();
});

test('canStub returns false only for ALWAYS_ENABLED tools', function () {
    expect(ToolkitVisibility::canStub('tool_search'))->toBeFalse();
    expect(ToolkitVisibility::canStub('credentials'))->toBeFalse();
    expect(ToolkitVisibility::canStub('spawn_agent'))->toBeTrue();
    expect(ToolkitVisibility::canStub('custom_tool'))->toBeTrue();
});

test('ALWAYS_ENABLED and CANNOT_DISABLE constants contain expected tools', function () {
    expect(ToolkitVisibility::ALWAYS_ENABLED)->toContain('tool_search');
    expect(ToolkitVisibility::ALWAYS_ENABLED)->toContain('credentials');
    expect(ToolkitVisibility::CANNOT_DISABLE)->toContain('spawn_agent');
    expect(ToolkitVisibility::CANNOT_DISABLE)->toContain('vision_analyze');
    expect(ToolkitVisibility::CANNOT_DISABLE)->toContain('restart_coqui');
});
