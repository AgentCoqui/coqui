<?php

declare(strict_types=1);

/**
 * Windows compatibility regression tests.
 *
 * These tests verify that the codebase doesn't use unguarded POSIX, pcntl,
 * or readline functions that would crash on Windows. They run on all
 * platforms to catch regressions early.
 */

test('source code never calls posix_isatty directly', function () {
    // posix_isatty should be replaced by stream_isatty (cross-platform, PHP 7.2+)
    $srcDir = dirname(__DIR__, 3) . '/src';
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            $trimmed = ltrim($line);

            // Skip comments (lines starting with //, *, or #) and PHPDoc
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/**')) {
                continue;
            }

            if (str_contains($line, 'posix_isatty(')) {
                $found[] = str_replace('\\', '/', $file->getPathname()) . ':' . ($lineNum + 1);
            }
        }
    }

    expect($found)->toBeEmpty(
        'posix_isatty() should not be called directly — use stream_isatty() instead. Found in: '
        . implode(', ', $found),
    );
});

test('posix_getpwuid is always guarded by function_exists', function () {
    $srcDir = dirname(__DIR__, 3) . '/src';
    $unguarded = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        // Skip files that don't use posix_getpwuid at all
        if (!str_contains($content, 'posix_getpwuid')) {
            continue;
        }

        // Check that every posix_getpwuid call has a nearby function_exists guard
        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            if (str_contains($line, 'posix_getpwuid(') && !str_contains($line, '//')) {
                // Look for function_exists guard in the surrounding 5 lines
                $hasGuard = false;
                $start = max(0, $lineNum - 5);
                $end = min(count($lines) - 1, $lineNum + 1);

                for ($i = $start; $i <= $end; $i++) {
                    if (str_contains($lines[$i], "function_exists('posix_getpwuid')")) {
                        $hasGuard = true;
                        break;
                    }
                }

                if (!$hasGuard) {
                    $unguarded[] = str_replace('\\', '/', $file->getPathname()) . ':' . ($lineNum + 1);
                }
            }
        }
    }

    expect($unguarded)->toBeEmpty(
        'posix_getpwuid() must be guarded by function_exists(). Unguarded in: '
        . implode(', ', $unguarded),
    );
});

test('posix_kill is always guarded by function_exists', function () {
    $srcDir = dirname(__DIR__, 3) . '/src';
    $unguarded = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        if (!str_contains($content, 'posix_kill')) {
            continue;
        }

        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            $trimmed = ltrim($line);

            // Skip comments
            if (str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (str_contains($line, 'posix_kill(')) {
                // Look for function_exists guard or $hasSignals (pcntl implies posix) in surrounding lines
                $hasGuard = false;
                $start = max(0, $lineNum - 10);
                $end = min(count($lines) - 1, $lineNum + 1);

                for ($i = $start; $i <= $end; $i++) {
                    if (
                        str_contains($lines[$i], "function_exists('posix_kill')")
                        || str_contains($lines[$i], '$hasSignals')
                    ) {
                        $hasGuard = true;
                        break;
                    }
                }

                if (!$hasGuard) {
                    $unguarded[] = str_replace('\\', '/', $file->getPathname()) . ':' . ($lineNum + 1);
                }
            }
        }
    }

    expect($unguarded)->toBeEmpty(
        'posix_kill() must be guarded by function_exists() or $hasSignals. Unguarded in: '
        . implode(', ', $unguarded),
    );
});

test('readline callback functions are guarded', function () {
    $srcDir = dirname(__DIR__, 3) . '/src';
    $unguarded = [];

    $readlineFunctions = [
        'readline_callback_handler_install',
        'readline_callback_read_char',
        'readline_callback_handler_remove',
        'readline_add_history',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $content = file_get_contents($file->getPathname());
        if ($content === false) {
            continue;
        }

        $lines = explode("\n", $content);
        foreach ($lines as $lineNum => $line) {
            foreach ($readlineFunctions as $func) {
                if (!str_contains($line, $func . '(') || str_contains($line, '//')) {
                    continue;
                }

                // Check for a guard: either function_exists or $hasReadline in surrounding lines
                $hasGuard = false;
                $start = max(0, $lineNum - 30);
                $end = min(count($lines) - 1, $lineNum + 1);

                for ($i = $start; $i <= $end; $i++) {
                    if (
                        str_contains($lines[$i], "function_exists('{$func}')")
                        || str_contains($lines[$i], '$hasReadline')
                        || str_contains($lines[$i], 'function_exists(\'readline')
                    ) {
                        $hasGuard = true;
                        break;
                    }
                }

                if (!$hasGuard) {
                    $unguarded[] = str_replace('\\', '/', $file->getPathname()) . ':' . ($lineNum + 1) . " ({$func})";
                }
            }
        }
    }

    expect($unguarded)->toBeEmpty(
        'readline functions must be guarded for Windows compatibility. Unguarded in: '
        . implode(', ', $unguarded),
    );
});

test('HomeDirectory resolve works on any platform', function () {
    $home = \CoquiBot\Coqui\Config\HomeDirectory::resolve();

    expect($home)->toBeString();
    expect($home)->not->toBe('');
    expect(is_dir($home))->toBeTrue();
});
