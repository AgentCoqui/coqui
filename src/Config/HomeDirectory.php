<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Cross-platform home directory resolution.
 *
 * Priority chain: HOME → USERPROFILE → guarded posix → sys_get_temp_dir().
 * Safe on Windows (no posix extension required), Linux, and macOS.
 */
final class HomeDirectory
{
    /**
     * Resolve the current user's home directory.
     *
     * Checks environment variables in order (HOME for Unix, USERPROFILE for
     * Windows), with a guarded POSIX fallback and sys_get_temp_dir() as a
     * last resort. Never throws.
     */
    public static function resolve(): string
    {
        // Unix standard
        $home = self::env('HOME');
        if ($home !== '') {
            return $home;
        }

        // Windows standard
        $home = self::env('USERPROFILE');
        if ($home !== '') {
            return $home;
        }

        // POSIX fallback (Unix only — extension not available on Windows)
        if (function_exists('posix_getpwuid') && function_exists('posix_getuid')) {
            $info = posix_getpwuid(posix_getuid());
            if (is_array($info) && $info['dir'] !== '') {
                return $info['dir'];
            }
        }

        return sys_get_temp_dir();
    }

    /**
     * Read an environment variable from all available sources.
     */
    private static function env(string $name): string
    {
        if (isset($_SERVER[$name]) && is_string($_SERVER[$name]) && $_SERVER[$name] !== '') {
            return $_SERVER[$name];
        }

        if (isset($_ENV[$name]) && is_string($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }

        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : '';
    }
}
