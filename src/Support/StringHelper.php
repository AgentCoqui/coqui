<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Shared string manipulation helpers.
 *
 * Extracted from 6+ identical private truncate() methods and slug/name
 * sanitization patterns scattered across tools, toolkits, and agents.
 */
final class StringHelper
{
    /**
     * Truncate a string to a maximum length, appending a suffix when trimmed.
     *
     * Uses mb_* functions for multibyte safety and normalizes embedded newlines.
     */
    public static function truncate(string $value, int $maxLength, string $suffix = '…'): string
    {
        $value = str_replace(["\n", "\r"], ' ', $value);

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        $suffixLen = mb_strlen($suffix);

        return mb_substr($value, 0, max(0, $maxLength - $suffixLen)) . $suffix;
    }

    /**
     * Truncate a nullable string, returning null for null or empty input.
     */
    public static function truncateNullable(?string $value, int $maxLength, string $suffix = '…'): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::truncate($value, $maxLength, $suffix);
    }

    /**
     * Convert a string into a kebab-case slug safe for identifiers.
     *
     * Strips non-alphanumeric characters (except hyphens), collapses runs of
     * hyphens, and trims leading/trailing hyphens.
     */
    public static function slug(string $value): string
    {
        $value = strtolower($value);
        $value = (string) preg_replace('/[^a-z0-9-]/', '-', $value);
        $value = (string) preg_replace('/-+/', '-', $value);

        return trim($value, '-');
    }
}
