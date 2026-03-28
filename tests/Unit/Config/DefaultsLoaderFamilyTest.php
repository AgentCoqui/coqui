<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\DefaultsLoader;

test('familyNames returns all family keys', function () {
    $loader = new DefaultsLoader();
    $names = $loader->familyNames();

    expect($names)->toContain('gpt');
    expect($names)->toContain('claude');
    expect($names)->toContain('gemini');
    expect($names)->toContain('grok');
    expect($names)->toContain('mistral');
    expect($names)->toContain('magistral');
    expect($names)->toContain('codestral');
    expect($names)->toContain('minimax');
    expect($names)->toContain('qwen');
    expect($names)->toContain('deepseek');
    expect($names)->toContain('llama');
    expect($names)->toContain('granite');
    expect($names)->toContain('nemotron');
    expect($names)->toContain('glm');
    expect($names)->toContain('lfm');
    expect($names)->toHaveCount(15);
});

test('family returns full family data with budget defaults', function () {
    $loader = new DefaultsLoader();
    $family = $loader->family('grok');

    expect($family)->toHaveKey('name');
    expect($family)->toHaveKey('description');
    expect($family)->toHaveKey('contextWindow');
    expect($family)->toHaveKey('maxTokens');
    expect($family['name'])->toBe('Grok');
    expect($family['contextWindow'])->toBe(131072);
    expect($family['maxTokens'])->toBe(32768);
});

test('family returns empty array for unknown family', function () {
    $loader = new DefaultsLoader();

    expect($loader->family('nonexistent'))->toBe([]);
});

test('familyDefaults returns contextWindow and maxTokens for known family', function () {
    $loader = new DefaultsLoader();
    $defaults = $loader->familyDefaults('grok');

    expect($defaults)->not->toBeNull();
    expect($defaults)->toBe(['contextWindow' => 131072, 'maxTokens' => 32768]);
});

test('familyDefaults returns correct values for gemini family', function () {
    $loader = new DefaultsLoader();
    $defaults = $loader->familyDefaults('gemini');

    expect($defaults)->toBe(['contextWindow' => 1000000, 'maxTokens' => 65536]);
});

test('familyDefaults returns correct values for lfm family', function () {
    $loader = new DefaultsLoader();
    $defaults = $loader->familyDefaults('lfm');

    expect($defaults)->toBe(['contextWindow' => 32000, 'maxTokens' => 8192]);
});

test('familyDefaults returns null for unknown family', function () {
    $loader = new DefaultsLoader();

    expect($loader->familyDefaults('nonexistent'))->toBeNull();
});

test('familyDefaults casts values to integers', function () {
    $loader = new DefaultsLoader();
    $defaults = $loader->familyDefaults('gpt');

    expect($defaults['contextWindow'])->toBeInt();
    expect($defaults['maxTokens'])->toBeInt();
});
