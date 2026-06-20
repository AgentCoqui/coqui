<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Generates random hexadecimal identifiers.
 *
 * Centralizes the `bin2hex(random_bytes(...))` idiom used across the
 * storage layer for primary keys and other opaque IDs, so the source of
 * entropy and the default width live in one place.
 */
final class IdGenerator
{
    /** Default identifier width in random bytes (yields a 32-char hex string). */
    public const int DEFAULT_BYTES = 16;

    /**
     * Generate a random lowercase hexadecimal ID.
     *
     * @param positive-int $bytes Number of random bytes (the hex string is twice as long).
     */
    public static function hex(int $bytes = self::DEFAULT_BYTES): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }
}
