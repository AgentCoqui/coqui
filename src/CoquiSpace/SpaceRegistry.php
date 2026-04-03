<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace;

/**
 * Registry constants, excluded package lists, and classification helpers.
 */
final class SpaceRegistry
{
    public const string DEFAULT_BASE_URL = 'https://coqui.space/api/v1';

    public const string ORIGIN_FILE = '.space-origin.json';

    public const string STATE_FILE = '.space-state.json';

    /**
     * Core packages that should never appear in "installed toolkits" listings
     * or be allowed to disable/remove via the space manager.
     */
    private const array EXCLUDED_PACKAGES = [
        'carmelosantana/php-agents',
    ];

    public static function isExcluded(string $package): bool
    {
        return in_array(strtolower(trim($package)), self::EXCLUDED_PACKAGES, true);
    }

    /**
     * Remove excluded packages from an array of items.
     *
     * Supports flat string arrays and associative arrays with 'name' or 'package' keys.
     *
     * @param array<int, string|array<string, mixed>> $items
     * @return array<int, string|array<string, mixed>>
     */
    public static function filterExcluded(array $items): array
    {
        return array_values(array_filter($items, static function (string|array $item): bool {
            if (is_string($item)) {
                return !self::isExcluded($item);
            }

            $name = $item['package'] ?? $item['name'] ?? '';

            return !self::isExcluded((string) $name);
        }));
    }

    /**
     * Extract owner handle from a response item.
     *
     * The API returns owner as a flat string in search results
     * and as an object {handle, displayName, image} in list results.
     *
     * @param array<string, mixed> $item
     */
    public static function extractOwner(array $item): string
    {
        $owner = $item['owner'] ?? '';

        if (is_string($owner)) {
            return $owner;
        }

        if (is_array($owner)) {
            return (string) ($owner['handle'] ?? '');
        }

        return '';
    }
}
