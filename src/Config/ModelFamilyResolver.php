<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Resolves a model ID string to its model family.
 *
 * Uses prefix matching against known family keys from defaults.json.
 * Handles special cases where the model name prefix differs from
 * the family key (e.g. "ministral" → "mistral", "devstral" → "codestral").
 */
final readonly class ModelFamilyResolver
{
    /**
     * Model name prefixes that map to a different family than their literal prefix.
     *
     * @var array<string, string>
     */
    private const array SPECIAL_CASES = [
        'ministral' => 'mistral',
        'devstral' => 'codestral',
    ];

    /** @var string[] Family keys sorted by length descending (longest match first). */
    private array $sortedKeys;

    /**
     * @param string[] $familyKeys Known family keys from defaults.json.
     */
    public function __construct(array $familyKeys)
    {
        // Merge special case prefixes into the match list
        $allPrefixes = array_unique([...array_keys(self::SPECIAL_CASES), ...$familyKeys]);

        // Sort by length descending so "codestral" matches before "co", "deepseek" before "d", etc.
        usort($allPrefixes, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        $this->sortedKeys = $allPrefixes;
    }

    /**
     * Resolve a model ID to its family key.
     *
     * @param string $modelId Model ID (e.g. "grok-4", "qwen3.5:latest", "openai/gpt-5.4").
     * @return string|null The family key, or null if no family matches.
     */
    public function resolveFamily(string $modelId): ?string
    {
        if ($modelId === '') {
            return null;
        }

        // If modelId contains "/" (OpenRouter sub-provider format), try both
        // the full ID and the segment after the last "/"
        $candidates = [$modelId];
        $lastSlash = strrpos($modelId, '/');
        if ($lastSlash !== false) {
            $candidates[] = substr($modelId, $lastSlash + 1);
        }

        foreach ($candidates as $candidate) {
            $result = $this->matchPrefix($candidate);
            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Match a single model ID candidate against known family prefixes.
     */
    private function matchPrefix(string $modelId): ?string
    {
        // Strip Ollama tag suffix (":latest", ":8b", ":30b", etc.)
        $colonPos = strpos($modelId, ':');
        $base = $colonPos !== false ? substr($modelId, 0, $colonPos) : $modelId;

        if ($base === '') {
            return null;
        }

        $baseLower = strtolower($base);

        foreach ($this->sortedKeys as $prefix) {
            $prefixLower = strtolower($prefix);

            if (!str_starts_with($baseLower, $prefixLower)) {
                continue;
            }

            // Ensure the match boundary is valid: the next character (if any)
            // must be a non-letter (digit, hyphen, dot, or end-of-string).
            // This prevents "glm" from matching "glacier-model".
            $nextCharPos = strlen($prefix);
            if ($nextCharPos < strlen($base)) {
                $nextChar = $base[$nextCharPos];
                if (ctype_alpha($nextChar)) {
                    continue;
                }
            }

            // Map through special cases or return the prefix directly
            return self::SPECIAL_CASES[$prefix] ?? $prefix;
        }

        return null;
    }
}
