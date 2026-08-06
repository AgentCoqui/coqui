<?php

declare(strict_types=1);

// Vendors a pinned snapshot of the CAP spec (schemas + conformance vectors + seeds)
// into tests/conformance/spec/, mirroring the spec repo's root layout so manifest
// paths resolve verbatim. Refresh-only; never hand-edit the vendored files.

$specRepo = getenv('COQUI_SPEC_REPO')
    ?: dirname(__DIR__, 2) . '/coqui-agent-spec';
$dest = dirname(__DIR__) . '/tests/conformance/spec';

if (!is_dir($specRepo)) {
    fwrite(STDERR, "spec repo not found: {$specRepo}\n");
    exit(1);
}

/** Recursively copy $src dir into $dst, only for files matching $exts (null = all). */
$copyTree = function (string $src, string $dst, ?array $exts = null) use (&$copyTree): int {
    if (!is_dir($src)) {
        return 0;
    }
    @mkdir($dst, 0o775, true);
    $count = 0;
    foreach (scandir($src) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $s = $src . '/' . $entry;
        $d = $dst . '/' . $entry;
        if (is_dir($s)) {
            $count += $copyTree($s, $d, $exts);
        } else {
            $ext = strtolower(pathinfo($s, PATHINFO_EXTENSION));
            if ($exts !== null && !in_array($ext, $exts, true)) {
                continue;
            }
            copy($s, $d);
            $count++;
        }
    }
    return $count;
};

// Clean the vendored tree so removals in the spec propagate.
$rm = function (string $path) use (&$rm): void {
    if (is_dir($path)) {
        foreach (scandir($path) as $e) {
            if ($e !== '.' && $e !== '..') {
                $rm($path . '/' . $e);
            }
        }
        @rmdir($path);
    } elseif (is_file($path)) {
        @unlink($path);
    }
};
$rm($dest);
@mkdir($dest, 0o775, true);

$n = 0;
$n += $copyTree($specRepo . '/schema', $dest . '/schema');
$n += $copyTree($specRepo . '/conformance/vectors', $dest . '/conformance/vectors', ['json']);
$n += $copyTree($specRepo . '/seeds/loops', $dest . '/seeds/loops', ['json']);
foreach (['conformance/checklist.md', 'conformance/error-coverage.json'] as $rel) {
    $s = $specRepo . '/' . $rel;
    if (is_file($s)) {
        @mkdir(dirname($dest . '/' . $rel), 0o775, true);
        copy($s, $dest . '/' . $rel);
        $n++;
    }
}

$commit = trim((string) @shell_exec('git -C ' . escapeshellarg($specRepo) . ' rev-parse HEAD 2>/dev/null'));
$stamp = 'source_commit: ' . ($commit !== '' ? $commit : 'unknown') . "\n"
    . "files_copied: {$n}\n";
file_put_contents($dest . '/SNAPSHOT.txt', $stamp);

echo "Vendored {$n} files into tests/conformance/spec/ (spec @ "
    . ($commit !== '' ? substr($commit, 0, 7) : 'unknown') . ")\n";
