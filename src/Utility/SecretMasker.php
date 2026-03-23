<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Utility;

/**
 * Consistent secret masking across the application.
 *
 * Shows the first and last N characters with the middle replaced by asterisks.
 * For short secrets (≤ prefix + suffix length), masks the entire value.
 */
final class SecretMasker
{
    /**
     * Mask a secret string, preserving prefix and suffix characters.
     */
    public static function mask(string $secret, int $prefixLen = 4, int $suffixLen = 4): string
    {
        $length = mb_strlen($secret);
        $minVisible = $prefixLen + $suffixLen;

        if ($length <= $minVisible) {
            return str_repeat('*', $length);
        }

        return mb_substr($secret, 0, $prefixLen)
            . str_repeat('*', $length - $minVisible)
            . mb_substr($secret, -$suffixLen);
    }
}
