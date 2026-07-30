<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\PersonaPreferences;

it('stores and returns a custom context label', function () {
    $prefs = PersonaPreferences::fromArray([
        'prompts' => ['labels' => ['context' => 'Reference']],
    ]);

    expect($prefs->validationErrors)->toBe([]);
    expect($prefs->getContextLabel())->toBe('Reference');
});

it('defaults the context label to Context', function () {
    expect(PersonaPreferences::empty()->getContextLabel())->toBe('Context');
});

it('rejects an empty context label', function () {
    $prefs = PersonaPreferences::fromArray([
        'prompts' => ['labels' => ['context' => '']],
    ]);

    expect($prefs->validationErrors)->not->toBe([]);
});
