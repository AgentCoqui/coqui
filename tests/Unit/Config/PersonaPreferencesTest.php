<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\PersonaPreferences;

test('fromFile parses valid preferences.json', function () {
    $path = sys_get_temp_dir() . '/coqui-prefs-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
            'Verbosity' => 'Moderate',
        ],
        'behavior' => [
            'emoji' => true,
            'code_style' => 'concise',
        ],
    ]));

    $prefs = PersonaPreferences::fromFile($path);

    expect($prefs->isEmpty())->toBeFalse();
    expect($prefs->hasPromptDirectives())->toBeTrue();
    expect($prefs->getBehavior('emoji'))->toBeTrue();
    expect($prefs->getBehavior('code_style'))->toBe('concise');
    expect($prefs->getBehavior('missing', 'default'))->toBe('default');

    unlink($path);
});

test('fromFile returns empty for invalid JSON', function () {
    $path = sys_get_temp_dir() . '/coqui-prefs-bad-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, 'not json');

    $prefs = PersonaPreferences::fromFile($path);

    expect($prefs->isEmpty())->toBeTrue();
    expect($prefs->isValid())->toBeFalse();
    expect($prefs->getValidationErrors())->toContain('Invalid JSON in preferences.json.');

    unlink($path);
});

test('fromFile returns empty for missing file', function () {
    $prefs = PersonaPreferences::fromFile('/nonexistent/path/preferences.json');

    expect($prefs->isEmpty())->toBeTrue();
});

test('empty creates an empty preferences object', function () {
    $prefs = PersonaPreferences::empty();

    expect($prefs->isEmpty())->toBeTrue();
    expect($prefs->hasPromptDirectives())->toBeFalse();
    expect($prefs->renderPromptSection())->toBeNull();
});

test('renderPromptSection formats directives as markdown', function () {
    $path = sys_get_temp_dir() . '/coqui-prefs-render-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
            'Verbosity' => 'Moderate',
        ],
    ]));

    $prefs = PersonaPreferences::fromFile($path);
    $section = $prefs->renderPromptSection();

    expect($section)->toContain('## Preferences');
    expect($section)->toContain('- **Tone:** Warm and curious');
    expect($section)->toContain('- **Verbosity:** Moderate');

    unlink($path);
});

test('renderPromptSection returns null when no directives', function () {
    $path = sys_get_temp_dir() . '/coqui-prefs-empty-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode([
        'behavior' => ['emoji' => true],
    ]));

    $prefs = PersonaPreferences::fromFile($path);

    expect($prefs->renderPromptSection())->toBeNull();
    expect($prefs->isEmpty())->toBeFalse();

    unlink($path);
});

test('fromFile parses prompts policy and backstory label', function () {
    $path = sys_get_temp_dir() . '/coqui-prefs-prompts-' . bin2hex(random_bytes(4)) . '.json';
    file_put_contents($path, json_encode([
        'prompts' => [
            'features' => [
                'artifacts' => false,
                'loops' => true,
                'background_tasks' => false,
            ],
            'prompt_sections' => [
                'tools' => 'stub',
                'project' => false,
            ],
            'roles' => [
                'allow' => ['orchestrator', 'coder'],
                'deny' => ['muse'],
            ],
            'labels' => [
                'backstory' => 'Lore',
            ],
        ],
    ]));

    $prefs = PersonaPreferences::fromFile($path);

    expect($prefs->isValid())->toBeTrue();
    expect($prefs->isFeatureEnabled('artifacts'))->toBeFalse();
    expect($prefs->isFeatureEnabled('loops'))->toBeTrue();
    expect($prefs->isFeatureEnabled('background_tasks'))->toBeFalse();
    expect($prefs->isPromptSectionStubbed('tools'))->toBeTrue();
    expect($prefs->isPromptSectionEnabled('project_context'))->toBeFalse();
    expect($prefs->allowedRoles())->toBe(['orchestrator', 'coder']);
    expect($prefs->deniedRoles())->toBe(['muse']);
    expect($prefs->isRoleAllowed('coder'))->toBeTrue();
    expect($prefs->isRoleAllowed('muse'))->toBeFalse();
    expect($prefs->getBackstoryLabel())->toBe('Lore');

    unlink($path);
});

test('fromFile records validation errors for invalid prompt policy', function () {
    $personaDir = sys_get_temp_dir() . '/coqui-prefs-invalid-' . bin2hex(random_bytes(4));
    mkdir($personaDir, 0755, true);
    file_put_contents($personaDir . '/security.md', '');

    $path = $personaDir . '/preferences.json';
    file_put_contents($path, json_encode([
        'prompts' => [
            'prompt_sections' => [
                'security' => false,
            ],
            'roles' => [
                'allow' => ['coder', 'muse'],
                'deny' => ['muse', 'orchestrator'],
            ],
        ],
    ]));

    $prefs = PersonaPreferences::fromFile($path);

    expect($prefs->isValid())->toBeFalse();
    expect($prefs->getPromptSectionMode('security'))->toBeTrue();
    expect($prefs->getValidationErrors())->toContain('prompts.prompt_sections.security cannot be changed. Use a persona-specific security.md override instead.');
    expect($prefs->getValidationErrors())->toContain('prompts.roles.allow must include orchestrator.');
    expect($prefs->getValidationErrors())->toContain('prompts.roles.deny cannot include orchestrator.');
    expect($prefs->getValidationErrors())->toContain('prompts.roles.allow and prompts.roles.deny overlap for: muse.');
    expect($prefs->getValidationErrors())->toContain('Persona security.md override must not be empty. Remove the file to fall back to workspace or default security.');

    unlink($path);
    unlink($personaDir . '/security.md');
    rmdir($personaDir);
});
