<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\RoleToolkitResolver;
use CoquiBot\Coqui\Contract\ToolkitVisibility;

// --- Constructor / hasRules() ---

test('null pattern allows everything', function () {
    $resolver = new RoleToolkitResolver(null);

    expect($resolver->hasRules())->toBeFalse();
    expect($resolver->isToolkitAllowed('Anything'))->toBeTrue();
    expect($resolver->isToolAllowed('anything'))->toBeTrue();
});

test('empty string allows everything', function () {
    $resolver = new RoleToolkitResolver('');

    expect($resolver->hasRules())->toBeFalse();
    expect($resolver->isToolkitAllowed('Anything'))->toBeTrue();
});

test('whitespace-only string allows everything', function () {
    $resolver = new RoleToolkitResolver('   ');

    expect($resolver->hasRules())->toBeFalse();
});

// --- Deny-all base (-*) ---

test('deny-all blocks everything except ALWAYS_ENABLED', function () {
    $resolver = new RoleToolkitResolver('-*');

    expect($resolver->isToolkitAllowed('FilesystemToolkit'))->toBeFalse();
    expect($resolver->isToolkitAllowed('ShellToolkit'))->toBeFalse();
    expect($resolver->isToolAllowed('exec'))->toBeFalse();

    // ALWAYS_ENABLED bypass
    expect($resolver->isToolAllowed('tool_search'))->toBeTrue();
    expect($resolver->isToolAllowed('credentials'))->toBeTrue();
});

// --- Allow-all base (+*) ---

test('allow-all allows everything', function () {
    $resolver = new RoleToolkitResolver('+*');

    expect($resolver->isToolkitAllowed('FilesystemToolkit'))->toBeTrue();
    expect($resolver->isToolAllowed('exec'))->toBeTrue();
});

// --- Deny-all with explicit allows ---

test('deny-all with explicit toolkit allow', function () {
    $resolver = new RoleToolkitResolver('-*, +WebToolkit');

    expect($resolver->isToolkitAllowed('WebToolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('FilesystemToolkit'))->toBeFalse();
    expect($resolver->isToolkitAllowed('ShellToolkit'))->toBeFalse();
});

test('deny-all with multiple explicit allows', function () {
    $resolver = new RoleToolkitResolver('-*, +MemoryToolkit, +SkillToolkit, +CoquiSourceToolkit');

    expect($resolver->isToolkitAllowed('MemoryToolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('SkillToolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('CoquiSourceToolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('FilesystemToolkit'))->toBeFalse();
});

// --- Allow-all with explicit denies ---

test('allow-all with explicit toolkit deny', function () {
    $resolver = new RoleToolkitResolver('+*, -ShellToolkit');

    expect($resolver->isToolkitAllowed('ShellToolkit'))->toBeFalse();
    expect($resolver->isToolkitAllowed('FilesystemToolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('MemoryToolkit'))->toBeTrue();
});

test('allow-all with multiple denies', function () {
    $resolver = new RoleToolkitResolver('+*, -MemoryToolkit, -spawn_agent, -php_execute');

    expect($resolver->isToolkitAllowed('MemoryToolkit'))->toBeFalse();
    expect($resolver->isToolAllowed('spawn_agent'))->toBeFalse();
    expect($resolver->isToolAllowed('php_execute'))->toBeFalse();
    expect($resolver->isToolkitAllowed('FilesystemToolkit'))->toBeTrue();
});

// --- FQCN matching (basename extraction) ---

test('matches FQCN by class basename', function () {
    $resolver = new RoleToolkitResolver('-*, +WebToolkit');

    expect($resolver->isToolkitAllowed('CoquiBot\\Coqui\\Toolkit\\WebToolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('CoquiBot\\Coqui\\Toolkit\\FilesystemToolkit'))->toBeFalse();
});

// --- Package name matching ---

test('matches by package name', function () {
    $resolver = new RoleToolkitResolver('-*, +acme/my-toolkit');

    expect($resolver->isToolkitAllowed('AnyClass', 'acme/my-toolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('AnyClass', 'other/package'))->toBeFalse();
    expect($resolver->isToolkitAllowed('AnyClass'))->toBeFalse();
});

test('denies by package name', function () {
    $resolver = new RoleToolkitResolver('+*, -acme/dangerous');

    expect($resolver->isToolkitAllowed('AnyClass', 'acme/dangerous'))->toBeFalse();
    expect($resolver->isToolkitAllowed('AnyClass', 'acme/safe'))->toBeTrue();
});

// --- Case insensitivity ---

test('matching is case-insensitive', function () {
    $resolver = new RoleToolkitResolver('-*, +shelltoolkit');

    expect($resolver->isToolkitAllowed('ShellToolkit'))->toBeTrue();
    expect($resolver->isToolkitAllowed('SHELLTOOLKIT'))->toBeTrue();
});

// --- Last-match-wins ---

test('last match wins when conflicting rules', function () {
    // Allow all, deny ShellToolkit, then re-allow it
    $resolver = new RoleToolkitResolver('+*, -ShellToolkit, +ShellToolkit');

    expect($resolver->isToolkitAllowed('ShellToolkit'))->toBeTrue();
});

test('deny overrides earlier allow', function () {
    $resolver = new RoleToolkitResolver('-*, +ShellToolkit, -ShellToolkit');

    expect($resolver->isToolkitAllowed('ShellToolkit'))->toBeFalse();
});

// --- ALWAYS_ENABLED bypass ---

test('ALWAYS_ENABLED tools bypass deny-all', function () {
    $resolver = new RoleToolkitResolver('-*');

    expect($resolver->isToolAllowed('tool_search'))->toBeTrue();
    expect($resolver->isToolAllowed('credentials'))->toBeTrue();
});

test('ALWAYS_ENABLED tools bypass explicit deny', function () {
    $resolver = new RoleToolkitResolver('+*, -tool_search, -credentials');

    expect($resolver->isToolAllowed('tool_search'))->toBeTrue();
    expect($resolver->isToolAllowed('credentials'))->toBeTrue();
});

// --- getEffectiveVisibility() ---

test('effective visibility returns Disabled when role denies', function () {
    $resolver = new RoleToolkitResolver('+*, -ShellToolkit');

    $vis = $resolver->getEffectiveVisibility('ShellToolkit', ToolkitVisibility::Enabled);

    expect($vis)->toBe(ToolkitVisibility::Disabled);
});

test('effective visibility defers to global when role allows', function () {
    $resolver = new RoleToolkitResolver('+*');

    expect($resolver->getEffectiveVisibility('MyTool', ToolkitVisibility::Stub))
        ->toBe(ToolkitVisibility::Stub);
    expect($resolver->getEffectiveVisibility('MyTool', ToolkitVisibility::Enabled))
        ->toBe(ToolkitVisibility::Enabled);
});

test('effective visibility returns Enabled for ALWAYS_ENABLED regardless of rules', function () {
    $resolver = new RoleToolkitResolver('-*');

    expect($resolver->getEffectiveVisibility('tool_search', ToolkitVisibility::Disabled))
        ->toBe(ToolkitVisibility::Enabled);
    expect($resolver->getEffectiveVisibility('credentials', ToolkitVisibility::Stub))
        ->toBe(ToolkitVisibility::Enabled);
});

// --- Tool-level matching in mixed patterns ---

test('tool name denied alongside toolkit basenames', function () {
    $resolver = new RoleToolkitResolver('+*, -MemoryToolkit, -spawn_agent, -php_execute');

    expect($resolver->isToolAllowed('spawn_agent'))->toBeFalse();
    expect($resolver->isToolAllowed('php_execute'))->toBeFalse();
    expect($resolver->isToolAllowed('exec'))->toBeTrue();
    expect($resolver->isToolAllowed('read_file'))->toBeTrue();
});

// --- hasRules() ---

test('hasRules returns true when pattern is non-empty', function () {
    $resolver = new RoleToolkitResolver('-*');

    expect($resolver->hasRules())->toBeTrue();
});

test('hasRules returns false for null pattern', function () {
    $resolver = new RoleToolkitResolver(null);

    expect($resolver->hasRules())->toBeFalse();
});

// --- summarize_conversation filtering ---

test('deny-all blocks summarize_conversation', function () {
    $resolver = new RoleToolkitResolver('-*');

    expect($resolver->isToolAllowed('summarize_conversation'))->toBeFalse();
});

test('allow-all permits summarize_conversation', function () {
    $resolver = new RoleToolkitResolver('+*');

    expect($resolver->isToolAllowed('summarize_conversation'))->toBeTrue();
});

test('summarize_conversation is not ALWAYS_ENABLED', function () {
    expect(ToolkitVisibility::isAlwaysEnabled('summarize_conversation'))->toBeFalse();
});

test('summarize_conversation cannot be globally disabled', function () {
    expect(ToolkitVisibility::canDisable('summarize_conversation'))->toBeFalse();
});

test('summarize_conversation can be stubbed', function () {
    expect(ToolkitVisibility::canStub('summarize_conversation'))->toBeTrue();
});
