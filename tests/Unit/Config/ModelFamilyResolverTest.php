<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\ModelFamilyResolver;

/**
 * Build a resolver with the real family keys from defaults.json.
 */
function buildResolver(): ModelFamilyResolver
{
    $defaults = new DefaultsLoader();

    return new ModelFamilyResolver($defaults->familyNames());
}

// --- Grok family ---

test('resolves grok-4 to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-4'))->toBe('grok');
});

test('resolves grok-4.20-0309-reasoning to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-4.20-0309-reasoning'))->toBe('grok');
});

test('resolves grok-4.20-0309-non-reasoning to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-4.20-0309-non-reasoning'))->toBe('grok');
});

test('resolves grok-4-1-fast-reasoning to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-4-1-fast-reasoning'))->toBe('grok');
});

test('resolves grok-4-1-fast-non-reasoning to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-4-1-fast-non-reasoning'))->toBe('grok');
});

test('resolves grok-4.20-multi-agent-0309 to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-4.20-multi-agent-0309'))->toBe('grok');
});

test('resolves grok-3 to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-3'))->toBe('grok');
});

test('resolves grok-3-mini to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-3-mini'))->toBe('grok');
});

test('resolves grok-2-vision-1212 to grok family', function () {
    expect(buildResolver()->resolveFamily('grok-2-vision-1212'))->toBe('grok');
});

// --- GPT family ---

test('resolves gpt-5.4 to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5.4'))->toBe('gpt');
});

test('resolves gpt-5.4-mini to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5.4-mini'))->toBe('gpt');
});

test('resolves gpt-4.1 to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-4.1'))->toBe('gpt');
});

test('resolves gpt-oss:20b to gpt family (tag stripped)', function () {
    expect(buildResolver()->resolveFamily('gpt-oss:20b'))->toBe('gpt');
});

test('resolves gpt-5.1 to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5.1'))->toBe('gpt');
});

test('resolves gpt-5 to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5'))->toBe('gpt');
});

test('resolves gpt-5-mini to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5-mini'))->toBe('gpt');
});

test('resolves gpt-5-nano to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5-nano'))->toBe('gpt');
});

test('resolves gpt-5.4-pro to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5.4-pro'))->toBe('gpt');
});

test('resolves gpt-5.2 to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-5.2'))->toBe('gpt');
});

test('resolves gpt-4o to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-4o'))->toBe('gpt');
});

test('resolves gpt-4o-mini to gpt family', function () {
    expect(buildResolver()->resolveFamily('gpt-4o-mini'))->toBe('gpt');
});

// --- o-series models (no family match — curated only) ---

test('o3 does not match any family', function () {
    expect(buildResolver()->resolveFamily('o3'))->toBeNull();
});

test('o4-mini does not match any family', function () {
    expect(buildResolver()->resolveFamily('o4-mini'))->toBeNull();
});

// --- Claude family ---

test('resolves claude-opus-4-6 to claude family', function () {
    expect(buildResolver()->resolveFamily('claude-opus-4-6'))->toBe('claude');
});

test('resolves claude-sonnet-4-5 to claude family', function () {
    expect(buildResolver()->resolveFamily('claude-sonnet-4-5'))->toBe('claude');
});

test('resolves claude-haiku-4-5 to claude family', function () {
    expect(buildResolver()->resolveFamily('claude-haiku-4-5'))->toBe('claude');
});

// --- Gemini family ---

test('resolves gemini-2.5-pro to gemini family', function () {
    expect(buildResolver()->resolveFamily('gemini-2.5-pro'))->toBe('gemini');
});

test('resolves gemini-3.1-flash-lite-preview to gemini family', function () {
    expect(buildResolver()->resolveFamily('gemini-3.1-flash-lite-preview'))->toBe('gemini');
});

// --- Qwen family ---

test('resolves qwen3.5:latest to qwen family', function () {
    expect(buildResolver()->resolveFamily('qwen3.5:latest'))->toBe('qwen');
});

test('resolves qwen3-coder:30b to qwen family', function () {
    expect(buildResolver()->resolveFamily('qwen3-coder:30b'))->toBe('qwen');
});

test('resolves qwen3-vl:latest to qwen family', function () {
    expect(buildResolver()->resolveFamily('qwen3-vl:latest'))->toBe('qwen');
});

// --- DeepSeek family ---

test('resolves deepseek-r1:latest to deepseek family', function () {
    expect(buildResolver()->resolveFamily('deepseek-r1:latest'))->toBe('deepseek');
});

test('resolves deepseek-r1:32b to deepseek family', function () {
    expect(buildResolver()->resolveFamily('deepseek-r1:32b'))->toBe('deepseek');
});

// --- Llama family ---

test('resolves llama3.1:8b to llama family', function () {
    expect(buildResolver()->resolveFamily('llama3.1:8b'))->toBe('llama');
});

// --- Granite family ---

test('resolves granite3.3:8b to granite family', function () {
    expect(buildResolver()->resolveFamily('granite3.3:8b'))->toBe('granite');
});

test('resolves granite4:3b to granite family', function () {
    expect(buildResolver()->resolveFamily('granite4:3b'))->toBe('granite');
});

// --- Nemotron family ---

test('resolves nemotron-cascade-2:30b to nemotron family', function () {
    expect(buildResolver()->resolveFamily('nemotron-cascade-2:30b'))->toBe('nemotron');
});

test('resolves nemotron-3-nano:4b to nemotron family', function () {
    expect(buildResolver()->resolveFamily('nemotron-3-nano:4b'))->toBe('nemotron');
});

// --- GLM family ---

test('resolves glm-4.7-flash:latest to glm family', function () {
    expect(buildResolver()->resolveFamily('glm-4.7-flash:latest'))->toBe('glm');
});

// --- LFM family ---

test('resolves lfm2:24b to lfm family', function () {
    expect(buildResolver()->resolveFamily('lfm2:24b'))->toBe('lfm');
});

// --- Mistral family ---

test('resolves mistral-large-latest to mistral family', function () {
    expect(buildResolver()->resolveFamily('mistral-large-latest'))->toBe('mistral');
});

test('resolves mistral-small-latest to mistral family', function () {
    expect(buildResolver()->resolveFamily('mistral-small-latest'))->toBe('mistral');
});

// --- Special case: ministral → mistral ---

test('resolves ministral-3:8b to mistral family (special case)', function () {
    expect(buildResolver()->resolveFamily('ministral-3:8b'))->toBe('mistral');
});

// --- Special case: devstral → codestral ---

test('resolves devstral:24b to codestral family (special case)', function () {
    expect(buildResolver()->resolveFamily('devstral:24b'))->toBe('codestral');
});

test('resolves devstral-latest to codestral family (special case)', function () {
    expect(buildResolver()->resolveFamily('devstral-latest'))->toBe('codestral');
});

// --- Codestral family ---

test('resolves codestral-latest to codestral family', function () {
    expect(buildResolver()->resolveFamily('codestral-latest'))->toBe('codestral');
});

// --- Magistral family ---

test('resolves magistral-medium-latest to magistral family', function () {
    expect(buildResolver()->resolveFamily('magistral-medium-latest'))->toBe('magistral');
});

// --- MiniMax family ---

test('resolves minimax-01 to minimax family', function () {
    expect(buildResolver()->resolveFamily('minimax-01'))->toBe('minimax');
});

test('resolves minimax-text-01 to minimax family', function () {
    expect(buildResolver()->resolveFamily('minimax-text-01'))->toBe('minimax');
});

// --- OpenRouter sub-provider format ---

test('resolves openai/gpt-5.4 (OpenRouter format) to gpt family', function () {
    expect(buildResolver()->resolveFamily('openai/gpt-5.4'))->toBe('gpt');
});

test('resolves anthropic/claude-opus-4-6 (OpenRouter format) to claude family', function () {
    expect(buildResolver()->resolveFamily('anthropic/claude-opus-4-6'))->toBe('claude');
});

test('resolves google/gemini-2.5-flash (OpenRouter format) to gemini family', function () {
    expect(buildResolver()->resolveFamily('google/gemini-2.5-flash'))->toBe('gemini');
});

test('resolves meta-llama/llama-3.3-70b-instruct (OpenRouter format) to llama family', function () {
    expect(buildResolver()->resolveFamily('meta-llama/llama-3.3-70b-instruct'))->toBe('llama');
});

// --- Edge cases ---

test('returns null for empty string', function () {
    expect(buildResolver()->resolveFamily(''))->toBeNull();
});

test('returns null for unknown model', function () {
    expect(buildResolver()->resolveFamily('unknown-model-xyz'))->toBeNull();
});

test('does not match partial family prefix followed by letter', function () {
    // "glm" should not match "glacier-model"
    expect(buildResolver()->resolveFamily('glacier-model'))->toBeNull();
});

test('does not match lfm against lfmodel', function () {
    expect(buildResolver()->resolveFamily('lfmodel-v2'))->toBeNull();
});
