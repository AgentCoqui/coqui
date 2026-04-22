<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use PHPUnit\Framework\Assert;

require_once dirname(__DIR__, 3) . '/scripts/generate-model-defaults.php';

test('resolveOllamaDiscoveryUrls includes configured and additional generator-only endpoints', function () {
    $urls = resolveOllamaDiscoveryUrls(
        ['ollama-url' => ['http://ollama:11434/v1', 'http://localhost:11434']],
        ['baseUrl' => 'http://localhost:11434/v1'],
    );

    Assert::assertSame([
        'http://localhost:11434/v1',
        'http://ollama:11434/v1',
    ], $urls);
});

test('mergeDiscoveredModel preserves curated budgets when discovered values are heuristic', function () {
    $definition = new ModelDefinition(
        id: 'gpt-5.4',
        name: 'gpt-5.4',
        provider: 'openai',
        reasoning: true,
        contextWindow: 4096,
        maxTokens: 2048,
        vision: true,
        thinking: true,
        metadataSource: 'heuristic',
        fieldSources: [
            'contextWindow' => 'heuristic',
            'maxTokens' => 'heuristic',
        ],
    );

    $merged = mergeDiscoveredModel($definition, [
        'id' => 'gpt-5.4',
        'name' => 'GPT-5.4 (Flagship)',
        'recommended' => true,
        'contextWindow' => 1000000,
        'maxTokens' => 128000,
        'reasoning' => true,
        'vision' => true,
        'thinking' => true,
        'family' => 'gpt',
        'metadataSource' => 'static-fallback',
        'fieldSources' => [
            'contextWindow' => 'static-fallback',
            'maxTokens' => 'static-fallback',
        ],
        'cost' => [
            'input' => 2.5,
            'output' => 15.0,
        ],
    ]);

    Assert::assertSame(1000000, $merged['contextWindow']);
    Assert::assertSame(128000, $merged['maxTokens']);
    Assert::assertSame('static-fallback', $merged['metadataSource']);
    Assert::assertSame([
        'contextWindow' => 'static-fallback',
        'maxTokens' => 'static-fallback',
    ], $merged['fieldSources']);
});

test('recoverProviderCatalog keeps existing curated xai models when direct discovery is empty', function () {
    $existingCurated = [
        [
            'id' => 'grok-4',
            'name' => 'Grok 4 (Flagship Reasoning)',
            'contextWindow' => 2000000,
            'maxTokens' => 131072,
            'reasoning' => true,
            'vision' => true,
            'thinking' => true,
            'family' => 'grok',
        ],
    ];

    $recovery = recoverProviderCatalog('xai', [
        'providers' => [
            'openrouter' => [
                'curatedModels' => [
                    [
                        'id' => 'x-ai/grok-4.1-fast',
                        'name' => 'xAI: Grok 4.1 Fast',
                        'contextWindow' => 2000000,
                        'maxTokens' => 16384,
                    ],
                    [
                        'id' => 'x-ai/grok-4-fast',
                        'name' => 'xAI: Grok 4 Fast',
                        'contextWindow' => 2000000,
                        'maxTokens' => 16384,
                    ],
                ],
            ],
        ],
    ], $existingCurated);

    Assert::assertIsArray($recovery);
    Assert::assertSame('existing-curated', $recovery['source']);
    if (!array_key_exists('models', $recovery)) {
        Assert::fail('Expected models recovery payload for xai.');
    }

    Assert::assertSame($existingCurated, $recovery['models']);
});

test('recoverProviderCatalog derives minimax models from openrouter mirror entries', function () {
    $recovery = recoverProviderCatalog('minimax', [
        'providers' => [
            'openrouter' => [
                'curatedModels' => [
                    [
                        'id' => 'minimax/minimax-m2.5',
                        'name' => 'MiniMax: MiniMax M2.5',
                        'contextWindow' => 196608,
                        'maxTokens' => 16384,
                        'toolCalls' => true,
                        'metadataSource' => 'heuristic',
                        'fieldSources' => [
                            'contextWindow' => 'provider-api',
                            'maxTokens' => 'heuristic',
                        ],
                    ],
                    [
                        'id' => 'x-ai/grok-4',
                        'name' => 'xAI: Grok 4',
                        'contextWindow' => 2000000,
                        'maxTokens' => 16384,
                    ],
                ],
            ],
        ],
    ], []);

    Assert::assertIsArray($recovery);
    Assert::assertSame('openrouter-mirror', $recovery['source']);
    if (!array_key_exists('definitions', $recovery)) {
        Assert::fail('Expected mirrored definitions for minimax.');
    }

    $definitions = $recovery['definitions'];

    Assert::assertCount(1, $definitions);
    Assert::assertSame('minimax-m2.5', $definitions[0]->id);
    Assert::assertSame('MiniMax M2.5', $definitions[0]->name);
    Assert::assertSame('minimax', $definitions[0]->family);
    Assert::assertSame('openrouter-mirror', $definitions[0]->fieldSources['contextWindow']);
});