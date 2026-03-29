<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\ModelFamilyResolver;

/**
 * Tests the 4-layer context window resolution chain that
 * OrchestratorAgent::resolveContextWindow() implements:
 *
 * Layer 1: User-configured model definition (openclaw.json) — not tested here
 * Layer 2: Curated model from defaults.json
 * Layer 3: Family-level defaults from defaults.json
 * Layer 4: Conservative hardcoded fallback (128K/4K)
 *
 * These tests validate Layers 2–3 using DefaultsLoader and ModelFamilyResolver
 * with the real defaults.json file to ensure correct context windows are resolved
 * for production model strings.
 */

function buildTestResolver(): ModelFamilyResolver
{
    return new ModelFamilyResolver((new DefaultsLoader())->familyNames());
}

function buildTestLoader(): DefaultsLoader
{
    return new DefaultsLoader();
}

// --- Layer 2: Curated model lookup ---

test('gpt-5.1 resolves to 400K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-5.1');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(400000);
    expect($curated['maxTokens'])->toBe(128000);
});

test('gpt-5.4 resolves to 1M context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-5.4');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(1000000);
    expect($curated['maxTokens'])->toBe(128000);
});

test('gpt-5 resolves to 400K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-5');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(400000);
    expect($curated['maxTokens'])->toBe(128000);
});

test('gpt-5-mini resolves to 400K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-5-mini');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(400000);
    expect($curated['maxTokens'])->toBe(128000);
});

test('gpt-5.2 resolves to 400K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-5.2');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(400000);
    expect($curated['maxTokens'])->toBe(128000);
});

test('gpt-4.1 resolves to 200K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-4.1');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(200000);
    expect($curated['maxTokens'])->toBe(32768);
});

test('gpt-4.1-mini resolves to 200K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-4.1-mini');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(200000);
    expect($curated['maxTokens'])->toBe(32768);
});

test('gpt-4o resolves to 128K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-4o');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(128000);
    expect($curated['maxTokens'])->toBe(16384);
});

test('o3 resolves to 200K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'o3');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(200000);
    expect($curated['maxTokens'])->toBe(100000);
});

test('o4-mini resolves to 200K context via curated model', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'o4-mini');

    expect($curated)->not->toBeNull();
    expect($curated['contextWindow'])->toBe(200000);
    expect($curated['maxTokens'])->toBe(100000);
});

// --- Layer 2: Curated model builds correct ModelDefinition ---

test('curated gpt-5.1 builds ModelDefinition with correct context window', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'gpt-5.1');

    expect($curated)->not->toBeNull();

    $def = ModelDefinition::fromOpenClaw('openai', $curated);

    expect($def->contextWindow)->toBe(400000);
    expect($def->maxTokens)->toBe(128000);
    expect($def->id)->toBe('gpt-5.1');
    expect($def->provider)->toBe('openai');
});

test('curated o3 builds ModelDefinition with correct context window', function () {
    $loader = buildTestLoader();
    $curated = $loader->curatedModel('openai', 'o3');

    expect($curated)->not->toBeNull();

    $def = ModelDefinition::fromOpenClaw('openai', $curated);

    expect($def->contextWindow)->toBe(200000);
    expect($def->maxTokens)->toBe(100000);
});

// --- Layer 3: Family-level fallback ---

test('unknown GPT model falls back to GPT family defaults (400K/128K)', function () {
    $loader = buildTestLoader();
    $resolver = buildTestResolver();

    // This model doesn't exist in curated list
    $curated = $loader->curatedModel('openai', 'gpt-6.0-ultra');
    expect($curated)->toBeNull();

    // But it resolves to the GPT family
    $family = $resolver->resolveFamily('gpt-6.0-ultra');
    expect($family)->toBe('gpt');

    // Family defaults provide the fallback
    $familyDefaults = $loader->familyDefaults($family);
    expect($familyDefaults)->not->toBeNull();
    expect($familyDefaults['contextWindow'])->toBe(400000);
    expect($familyDefaults['maxTokens'])->toBe(128000);
});

test('unknown GPT mini variant falls back to GPT family defaults', function () {
    $loader = buildTestLoader();
    $resolver = buildTestResolver();

    $curated = $loader->curatedModel('openai', 'gpt-5.5-mini');
    expect($curated)->toBeNull();

    $family = $resolver->resolveFamily('gpt-5.5-mini');
    expect($family)->toBe('gpt');

    $familyDefaults = $loader->familyDefaults($family);
    expect($familyDefaults['contextWindow'])->toBe(400000);
    expect($familyDefaults['maxTokens'])->toBe(128000);
});

// --- Layer 4: Unknown model with no family match ---

test('completely unknown model has no curated entry and no family match', function () {
    $loader = buildTestLoader();
    $resolver = buildTestResolver();

    $curated = $loader->curatedModel('openai', 'totally-unknown-model');
    expect($curated)->toBeNull();

    $family = $resolver->resolveFamily('totally-unknown-model');
    expect($family)->toBeNull();

    // Would fall through to Layer 4: hardcoded fallback (128K/4K)
});

// --- Full resolution chain simulation ---

test('simulates full 4-layer resolution chain for gpt-5.1', function () {
    $loader = buildTestLoader();
    $resolver = buildTestResolver();
    $modelId = 'gpt-5.1';
    $provider = 'openai';

    // Layer 2: curated model found — this is where gpt-5.1 resolves
    $curated = $loader->curatedModel($provider, $modelId);
    expect($curated)->not->toBeNull();

    $def = ModelDefinition::fromOpenClaw($provider, $curated);
    expect($def->contextWindow)->toBe(400000)
        ->and($def->maxTokens)->toBe(128000);
});

test('simulates full 4-layer resolution chain for unknown gpt model', function () {
    $loader = buildTestLoader();
    $resolver = buildTestResolver();
    $modelId = 'gpt-99';
    $provider = 'openai';

    // Layer 2: no curated entry
    $curated = $loader->curatedModel($provider, $modelId);
    expect($curated)->toBeNull();

    // Layer 3: family match succeeds
    $family = $resolver->resolveFamily($modelId);
    expect($family)->toBe('gpt');

    $familyDefaults = $loader->familyDefaults($family);
    expect($familyDefaults)->not->toBeNull();
    expect($familyDefaults['contextWindow'])->toBe(400000)
        ->and($familyDefaults['maxTokens'])->toBe(128000);
});

// --- Pricing verification ---

test('curated models have correct pricing from OpenAI docs', function () {
    $loader = buildTestLoader();

    $models = [
        'gpt-5.4' => ['input' => 2.50, 'output' => 15.00],
        'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
        'gpt-5.4-nano' => ['input' => 0.20, 'output' => 1.25],
        'gpt-5.4-pro' => ['input' => 30.00, 'output' => 180.00],
        'gpt-5.2' => ['input' => 1.75, 'output' => 14.00],
        'gpt-5.1' => ['input' => 1.25, 'output' => 10.00],
        'gpt-5' => ['input' => 1.25, 'output' => 10.00],
        'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
        'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-4.1-nano' => ['input' => 0.10, 'output' => 0.40],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'o3' => ['input' => 2.00, 'output' => 8.00],
        'o4-mini' => ['input' => 1.10, 'output' => 4.40],
    ];

    foreach ($models as $id => $expectedCost) {
        $curated = $loader->curatedModel('openai', $id);
        expect($curated)->not->toBeNull("Curated model '{$id}' not found");
        expect($curated['cost']['input'])->toBe($expectedCost['input'], "Input cost mismatch for {$id}");
        expect($curated['cost']['output'])->toBe($expectedCost['output'], "Output cost mismatch for {$id}");
    }
});
