<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Utility;

/**
 * Shared prompt-size validation for API and task-oriented input surfaces.
 *
 * These limits intentionally reject oversized inputs instead of truncating
 * them, preserving full prompt fidelity for accepted requests.
 */
final class PromptSizeValidator
{
    /** Maximum prompt length for API and task-oriented input surfaces (1 MiB). */
    public const int API_MAX_PROMPT_BYTES = 1_048_576;

    /**
     * Validate a user-supplied text field against the shared API prompt limit.
     *
     * @return string|null Error message, or null if valid
     */
    public static function validateApiText(string $value, string $label = 'Prompt'): ?string
    {
        if (strlen($value) > self::API_MAX_PROMPT_BYTES) {
            return sprintf(
                '%s exceeds maximum length of %s bytes',
                $label,
                number_format(self::API_MAX_PROMPT_BYTES),
            );
        }

        return null;
    }
}