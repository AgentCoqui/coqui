<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

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
        $normalized = $resolved !== false ? $resolved : $path;
        $normalized = str_replace('\\', '/', $normalized);
        $normalized = rtrim($normalized, '/');

        if (DIRECTORY_SEPARATOR === '\\') {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }
}