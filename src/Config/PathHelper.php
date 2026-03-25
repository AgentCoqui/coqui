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
}
