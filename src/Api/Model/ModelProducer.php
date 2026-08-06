<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Model;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;

/**
 * Serializes a {@see ModelDefinition} into the strict CAP `model.json` wire.
 *
 * The producer emits exactly the schema-declared fields (id, display_name,
 * context_window, max_output_tokens, tokenizer_hint, capabilities) and drops the
 * internal/ad-hoc metadata (provider, reasoning, input, family, metadataSource)
 * so the served object satisfies the schema's `additionalProperties: false`.
 */
final class ModelProducer
{
    /**
     * @return array{
     *     id: string,
     *     display_name: string|null,
     *     context_window: int,
     *     max_output_tokens: int|null,
     *     tokenizer_hint: string,
     *     capabilities: list<string>
     * }
     */
    public static function toWire(ModelDefinition $model): array
    {
        return [
            'id' => self::wireId($model),
            'display_name' => trim($model->name) === '' ? null : $model->name,
            'context_window' => $model->contextWindow,
            // The schema allows null or a positive integer only; an absent/zero
            // budget is served as null rather than a schema-invalid 0.
            'max_output_tokens' => $model->maxTokens > 0 ? $model->maxTokens : null,
            'tokenizer_hint' => self::deriveTokenizerHint($model),
            'capabilities' => self::capabilities($model),
        ];
    }

    /**
     * The provider-prefixed identifier the API echoes verbatim (e.g.
     * `anthropic/claude-opus-4`). A ModelDefinition carries the bare model id and
     * its provider separately; the served ModelId keeps them joined so a consumer
     * can resolve it directly.
     */
    private static function wireId(ModelDefinition $model): string
    {
        if ($model->provider === '' || str_contains($model->id, '/')) {
            return $model->id;
        }

        return $model->provider . '/' . $model->id;
    }

    /**
     * The declared capabilities in schema order (tools, vision, thinking),
     * including only the ones the model actually supports. Unique by construction.
     *
     * @return list<string>
     */
    private static function capabilities(ModelDefinition $model): array
    {
        $capabilities = [];

        if ($model->toolCalls) {
            $capabilities[] = 'tools';
        }

        if ($model->vision) {
            $capabilities[] = 'vision';
        }

        if ($model->thinking) {
            $capabilities[] = 'thinking';
        }

        return $capabilities;
    }

    /**
     * Best-effort tokenizer hint from the model's provider/family/id. There is no
     * first-class source for this today, so the mapping stays conservative: a
     * wrong hint is worse than `unknown`, which is the default whenever the
     * provider family is not recognized.
     */
    private static function deriveTokenizerHint(ModelDefinition $model): string
    {
        $provider = strtolower($model->provider);
        $haystack = strtolower(($model->family ?? '') . ' ' . $model->id);

        // Anthropic Claude family.
        if ($provider === 'anthropic' || str_contains($haystack, 'claude')) {
            return 'claude';
        }

        // OpenAI: separate the o200k_base encodings (o-series, gpt-4o, gpt-4.1,
        // gpt-5) from the older cl100k_base ones (gpt-4, gpt-3.5).
        if ($provider === 'openai' || str_contains($haystack, 'gpt') || str_contains($haystack, 'openai')) {
            if (
                str_contains($haystack, 'gpt-4o')
                || str_contains($haystack, 'gpt-4.1')
                || str_contains($haystack, 'gpt-5')
                || str_contains($haystack, 'o200k')
                || preg_match('/\bo[1-9]\b/', $haystack) === 1
            ) {
                return 'o200k_base';
            }

            if (
                str_contains($haystack, 'gpt-4')
                || str_contains($haystack, 'gpt-3.5')
                || str_contains($haystack, 'gpt-35')
            ) {
                return 'cl100k_base';
            }
        }

        return 'unknown';
    }
}
