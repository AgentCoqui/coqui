<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ProfilePreferences;

it('accepts context as a prompt_sections gate', function () {
    $prefs = ProfilePreferences::fromArray([
        'prompts' => ['prompt_sections' => ['context' => false]],
    ]);

    expect($prefs->validationErrors)->toBe([]);
    expect($prefs->isPromptSectionEnabled('context', true))->toBeFalse();
});

it('accepts context stub mode', function () {
    $prefs = ProfilePreferences::fromArray([
        'prompts' => ['prompt_sections' => ['context' => 'stub']],
    ]);

    expect($prefs->validationErrors)->toBe([]);
    expect($prefs->isPromptSectionStubbed('context'))->toBeTrue();
});
