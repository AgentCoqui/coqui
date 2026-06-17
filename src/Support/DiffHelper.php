<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Standalone unified diff generator using a pure PHP Myers LCS algorithm.
 *
 * Extracted from EditHistory to decouple diff generation from the SQLite
 * storage layer. Used by both EditHistory (undo diffs) and FileSystemToolkit
 * (dry-run previews).
 */
final class DiffHelper
{
    /**
     * Compute a unified diff between two strings.
     *
     * Produces output similar to `diff -u` with context lines.
     */
    public static function unifiedDiff(
        string $old,
        string $new,
        string $oldLabel = 'original',
        string $newLabel = 'modified',
        int $contextLines = 3,
    ): string {
        if ($old === $new) {
            return "No changes.\n";
        }

        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        $diff = self::myersDiff($oldLines, $newLines);

        $output = "--- {$oldLabel}\n+++ {$newLabel}\n";

        $hunks = self::buildHunks($diff, count($oldLines), count($newLines), $contextLines);
        foreach ($hunks as $hunk) {
            $output .= $hunk;
        }

        return $output;
    }

    /**
     * Generate a concise preview of changes with match context.
     *
     * Returns a summary string showing the number of changes and a unified diff.
     * Useful for dry-run previews where metadata should accompany the diff.
     *
     * @param array<string, mixed> $metadata Key-value pairs to include in the header.
     */
    public static function preview(
        string $old,
        string $new,
        string $filePath,
        array $metadata = [],
        int $contextLines = 3,
    ): string {
        $header = "--- Preview: {$filePath} ---\n";

        foreach ($metadata as $key => $value) {
            $header .= "{$key}: {$value}\n";
        }

        $diff = self::unifiedDiff(
            $old,
            $new,
            'a/' . basename($filePath),
            'b/' . basename($filePath),
            $contextLines,
        );

        return $header . $diff;
    }

    /**
     * Simple Myers-like diff producing operation tags per line.
     *
     * @param string[] $old
     * @param string[] $new
     * @return list<array{op: string, old?: string, new?: string, oldIdx?: int, newIdx?: int}>
     */
    public static function myersDiff(array $old, array $new): array
    {
        $oldLen = count($old);
        $newLen = count($new);

        // Build LCS table
        $lcs = [];
        for ($i = 0; $i <= $oldLen; $i++) {
            for ($j = 0; $j <= $newLen; $j++) {
                if ($i === 0 || $j === 0) {
                    $lcs[$i][$j] = 0;
                } elseif ($old[$i - 1] === $new[$j - 1]) {
                    $lcs[$i][$j] = $lcs[$i - 1][$j - 1] + 1;
                } else {
                    $lcs[$i][$j] = max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
                }
            }
        }

        // Backtrack to produce diff
        $result = [];
        $i = $oldLen;
        $j = $newLen;

        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $old[$i - 1] === $new[$j - 1]) {
                array_unshift($result, ['op' => 'equal', 'old' => $old[$i - 1], 'oldIdx' => $i - 1, 'newIdx' => $j - 1]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($result, ['op' => 'add', 'new' => $new[$j - 1], 'newIdx' => $j - 1]);
                $j--;
            } else {
                array_unshift($result, ['op' => 'remove', 'old' => $old[$i - 1], 'oldIdx' => $i - 1]);
                $i--;
            }
        }

        return $result;
    }

    /**
     * Build unified diff hunks from a diff operation list.
     *
     * @param list<array{op: string, old?: string, new?: string, oldIdx?: int, newIdx?: int}> $diff
     * @return list<string>
     */
    private static function buildHunks(array $diff, int $oldLen, int $newLen, int $contextLines): array
    {
        // Find change regions
        $changeIndices = [];
        foreach ($diff as $idx => $entry) {
            if ($entry['op'] !== 'equal') {
                $changeIndices[] = $idx;
            }
        }

        if ($changeIndices === []) {
            return ["No changes.\n"];
        }

        // Group changes into hunks with context
        $hunks = [];
        $hunkStart = null;
        $hunkEnd = null;

        foreach ($changeIndices as $ci) {
            $start = max(0, $ci - $contextLines);
            $end = min(count($diff) - 1, $ci + $contextLines);

            if ($hunkStart === null) {
                $hunkStart = $start;
                $hunkEnd = $end;
            } elseif ($start <= $hunkEnd + 1) {
                $hunkEnd = $end;
            } else {
                $hunks[] = self::formatHunk($diff, $hunkStart, $hunkEnd);
                $hunkStart = $start;
                $hunkEnd = $end;
            }
        }

        // $changeIndices is non-empty (checked above), so hunkStart/hunkEnd are always set here
        $hunks[] = self::formatHunk($diff, $hunkStart, $hunkEnd);

        return $hunks;
    }

    /**
     * Format a single unified diff hunk.
     *
     * @param list<array{op: string, old?: string, new?: string, oldIdx?: int, newIdx?: int}> $diff
     */
    private static function formatHunk(array $diff, int $start, int $end): string
    {
        $oldStart = 1;
        $newStart = 1;
        $oldCount = 0;
        $newCount = 0;
        $lines = '';

        // Calculate starting line numbers
        for ($i = 0; $i < $start; $i++) {
            if ($diff[$i]['op'] === 'equal' || $diff[$i]['op'] === 'remove') {
                $oldStart++;
            }
            if ($diff[$i]['op'] === 'equal' || $diff[$i]['op'] === 'add') {
                $newStart++;
            }
        }

        for ($i = $start; $i <= $end && $i < count($diff); $i++) {
            $entry = $diff[$i];
            switch ($entry['op']) {
                case 'equal':
                    $lines .= ' ' . ($entry['old'] ?? '') . "\n";
                    $oldCount++;
                    $newCount++;
                    break;
                case 'remove':
                    $lines .= '-' . ($entry['old'] ?? '') . "\n";
                    $oldCount++;
                    break;
                case 'add':
                    $lines .= '+' . ($entry['new'] ?? '') . "\n";
                    $newCount++;
                    break;
            }
        }

        return sprintf("@@ -%d,%d +%d,%d @@\n%s", $oldStart, $oldCount, $newStart, $newCount, $lines);
    }
}
