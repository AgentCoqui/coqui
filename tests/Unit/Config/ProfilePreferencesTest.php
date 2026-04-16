<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ProfilePreferences;

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

    $prefs = ProfilePreferences::fromFile($path);

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

    $prefs = ProfilePreferences::fromFile($path);

    expect($prefs->isEmpty())->toBeTrue();

    unlink($path);
});

test('fromFile returns empty for missing file', function () {
    $prefs = ProfilePreferences::fromFile('/nonexistent/path/preferences.json');

    expect($prefs->isEmpty())->toBeTrue();
});

test('empty creates an empty preferences object', function () {
    $prefs = ProfilePreferences::empty();

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

    $prefs = ProfilePreferences::fromFile($path);
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

    $prefs = ProfilePreferences::fromFile($path);

    expect($prefs->renderPromptSection())->toBeNull();
    expect($prefs->isEmpty())->toBeFalse();

    unlink($path);
});
