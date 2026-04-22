<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Cross-platform path helpers.
 *
 * Centralizes DIRECTORY_SEPARATOR-aware operations so the codebase
 * works correctly on both Unix (/) and Windows (\) paths.
 */
final class PathHelper
{
    /**
     * Trim trailing directory separators from a path.
     *
     * Unlike `rtrim($path, '/')`, this handles both `/` and `\` so
     * Windows paths like `C:\Users\foo\` are trimmed correctly.
     *
     * Root paths (`/` on Unix, `C:\` on Windows) are preserved —
     * stripping the last separator would produce an invalid path.
     */
    public static function trimTrailingSlash(string $path): string
    {
        $trimmed = rtrim($path, '/\\');

        // Preserve Unix root
        if ($trimmed === '' && str_starts_with($path, '/')) {
            return '/';
        }

        // Preserve Windows drive root (e.g. "C:" → "C:\")
        if (preg_match('/^[A-Za-z]:$/', $trimmed)) {
            return $trimmed . '\\';
        }

        return $trimmed;
    }

    /**
     * Normalize path separators and drive-letter casing for safe comparisons.
     */
    public static function normalizeForComparison(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        if (preg_match('/^[A-Z]:/i', $normalized) === 1) {
            $normalized = strtolower($normalized[0]) . substr($normalized, 1);
        }

        return $normalized;
    }

    /**
     * Determine whether a path is absolute on Unix or Windows.
     */
    public static function isAbsolutePath(string $path): bool
    {
        $normalized = self::normalizeForComparison($path);

        return str_starts_with($normalized, '/') || preg_match('/^[a-z]:\//', $normalized) === 1;
    }

    /**
     * Check whether a path is the same as or nested under a base path.
     */
    public static function isWithinBasePath(string $path, string $basePath): bool
    {
        $normalizedPath = self::trimTrailingSlash(self::normalizeForComparison($path));
        $normalizedBase = self::trimTrailingSlash(self::normalizeForComparison($basePath));

        return $normalizedPath === $normalizedBase
            || str_starts_with($normalizedPath, $normalizedBase . '/');
    }

    /**
     * Convert a local file URL into a normalized filesystem path.
     *
     * Supports Unix paths plus Windows drive-letter paths in both RFC-style
     * `file:///C:/path` and relaxed `file://C:\path` forms used in tests.
     * Non-local authorities are rejected.
     */
    public static function fileUrlToPath(string $url): ?string
    {
        $trimmed = trim($url);
        if ($trimmed === '' || stripos($trimmed, 'file://') !== 0) {
            return null;
        }

        $remainder = str_replace('\\', '/', substr($trimmed, 7));
        if ($remainder === '') {
            return null;
        }

        if (preg_match('#^localhost(?=/|$)#i', $remainder) === 1) {
            $remainder = substr($remainder, 9);
        } elseif (!str_starts_with($remainder, '/') && preg_match('/^[A-Za-z]:\//', $remainder) !== 1) {
            return null;
        }

        $decoded = rawurldecode($remainder);
        if (preg_match('#^/[A-Za-z]:/#', $decoded) === 1) {
            $decoded = substr($decoded, 1);
        }

        return self::normalizeForComparison($decoded);
    }
}
