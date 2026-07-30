<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\PersonaPreferences;

it('accepts context as a prompt_sections gate', function () {
    $prefs = PersonaPreferences::fromArray([
        'prompts' => ['prompt_sections' => ['context' => false]],
    ]);

    expect($prefs->validationErrors)->toBe([]);
    expect($prefs->isPromptSectionEnabled('context', true))->toBeFalse();
});

it('accepts context stub mode', function () {
    $prefs = PersonaPreferences::fromArray([
        'prompts' => ['prompt_sections' => ['context' => 'stub']],
    ]);

    expect($prefs->validationErrors)->toBe([]);
    expect($prefs->isPromptSectionStubbed('context'))->toBeTrue();
});
