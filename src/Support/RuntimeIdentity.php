<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CarmeloSantana\PathHelper\PathHelper;

final class RuntimeIdentity
{
    public static function fingerprintPath(string $path): string
    {
        $normalized = self::normalizePath($path);

        return substr(hash('sha256', $normalized), 0, 16);
    }

    private static function normalizePath(string $path): string
    {
        $resolved = realpath($path);
        $normalized = PathHelper::normalizeForComparison($resolved !== false ? $resolved : $path);
        $normalized = PathHelper::trimTrailingSlash($normalized);

        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }
}