<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

/**
 * SQLite-backed edit history with file backups for undo support.
 *
 * Each mutating edit operation records the original file content as a backup
 * and logs the operation in SQLite. Supports undo by edit ID, undo last N
 * edits on a file, listing recent edits, pruning old backups, and generating
 * unified diffs between backup and current state.
 *
 * The database and backup directory are created lazily on first use.
 */
final class EditHistory
{
    private ?\PDO $db = null;
    private readonly string $dbPath;
    private readonly string $backupDir;

    public function __construct(
        string $storagePath,
    ) {
        $this->dbPath = rtrim($storagePath, DIRECTORY_SEPARATOR) . '/history.db';
        $this->backupDir = rtrim($storagePath, DIRECTORY_SEPARATOR) . '/backups';
    }

    /**
     * Record an edit operation with a backup of the original content.
     *
     * @param array<string, mixed> $metadata Additional context about the operation.
     * @return int The edit ID.
     */
    public function record(
        string $filePath,
        string $operation,
        string $originalContent,
        array $metadata = [],
    ): int {
        $db = $this->connect();

        $timestamp = (new \DateTimeImmutable())->format('c');
        $backupName = sprintf('%d_%s_%s', (int) (microtime(true) * 1000), $operation, basename($filePath));
        $backupName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $backupName) ?? $backupName;
        $backupPath = $this->backupDir . DIRECTORY_SEPARATOR . $backupName;

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            @mkdir($this->backupDir, 0755, true);
        }

        if (@file_put_contents($backupPath, $originalContent) === false) {
            throw new \RuntimeException('Failed to write backup: ' . $backupPath);
        }

        $stmt = $db->prepare(
            'INSERT INTO edits (file_path, operation, timestamp, backup_path, metadata)
             VALUES (:file_path, :operation, :timestamp, :backup_path, :metadata)',
        );
        $stmt->execute([
            ':file_path' => $filePath,
            ':operation' => $operation,
            ':timestamp' => $timestamp,
            ':backup_path' => $backupPath,
            ':metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
        ]);

        return (int) $db->lastInsertId();
    }

    /**
     * Retrieve the backup content for a specific edit.
     *
     * @return array{id: int, file_path: string, operation: string, timestamp: string, content: string}
     */
    public function getBackup(int $editId): array
    {
        $db = $this->connect();

        $stmt = $db->prepare('SELECT * FROM edits WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException(sprintf('Edit #%d not found', $editId));
        }

        $content = @file_get_contents($row['backup_path']);
        if ($content === false) {
            throw new \RuntimeException(sprintf('Backup file missing for edit #%d', $editId));
        }

        return [
            'id' => (int) $row['id'],
            'file_path' => $row['file_path'],
            'operation' => $row['operation'],
            'timestamp' => $row['timestamp'],
            'content' => $content,
        ];
    }

    /**
     * Get the last N edits for a specific file.
     *
     * @return list<array{id: int, file_path: string, operation: string, timestamp: string, content: string}>
     */
    public function getLastEdits(string $filePath, int $count = 1): array
    {
        $db = $this->connect();

        $stmt = $db->prepare(
            'SELECT * FROM edits WHERE file_path = :file_path ORDER BY id DESC LIMIT :count',
        );
        $stmt->bindValue(':file_path', $filePath);
        $stmt->bindValue(':count', $count, \PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $content = @file_get_contents($row['backup_path']);
            if ($content === false) {
                continue;
            }

            $results[] = [
                'id' => (int) $row['id'],
                'file_path' => $row['file_path'],
                'operation' => $row['operation'],
                'timestamp' => $row['timestamp'],
                'content' => $content,
            ];
        }

        return $results;
    }

    /**
     * Remove an edit record and its backup file.
     */
    public function removeEdit(int $editId): void
    {
        $db = $this->connect();

        $stmt = $db->prepare('SELECT backup_path FROM edits WHERE id = :id');
        $stmt->execute([':id' => $editId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false && is_file($row['backup_path'])) {
            @unlink($row['backup_path']);
        }

        $stmt = $db->prepare('DELETE FROM edits WHERE id = :id');
        $stmt->execute([':id' => $editId]);
    }

    /**
     * List recent edits, optionally filtered by file path.
     *
     * @return list<array{id: int, file_path: string, operation: string, timestamp: string, metadata: string}>
     */
    public function list(?string $filePath = null, int $limit = 20): array
    {
        $db = $this->connect();

        if ($filePath !== null) {
            $stmt = $db->prepare(
                'SELECT id, file_path, operation, timestamp, metadata FROM edits
                 WHERE file_path = :file_path ORDER BY id DESC LIMIT :limit',
            );
            $stmt->bindValue(':file_path', $filePath);
        } else {
            $stmt = $db->prepare(
                'SELECT id, file_path, operation, timestamp, metadata FROM edits
                 ORDER BY id DESC LIMIT :limit',
            );
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => (int) $row['id'],
                'file_path' => $row['file_path'],
                'operation' => $row['operation'],
                'timestamp' => $row['timestamp'],
                'metadata' => $row['metadata'],
            ];
        }

        return $results;
    }

    /**
     * Get edits recorded since a given ISO 8601 timestamp.
     *
     * Used by the REPL to show a post-turn file-change summary.
     *
     * @return list<array{id: int, file_path: string, operation: string, timestamp: string, metadata: string}>
     */
    public function getEditsSince(string $sinceTimestamp): array
    {
        $db = $this->connect();

        $stmt = $db->prepare(
            'SELECT id, file_path, operation, timestamp, metadata FROM edits
             WHERE timestamp >= :since ORDER BY id ASC',
        );
        $stmt->bindValue(':since', $sinceTimestamp);
        $stmt->execute();

        $results = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $results[] = [
                'id' => (int) $row['id'],
                'file_path' => $row['file_path'],
                'operation' => $row['operation'],
                'timestamp' => $row['timestamp'],
                'metadata' => $row['metadata'],
            ];
        }

        return $results;
    }

    /**
     * Generate a unified diff between the backup (original) and the current file content.
     *
     * Uses PHP's built-in output diff format rather than shelling out.
     * Falls back to a simple diff if the file no longer exists.
     */
    public function generateDiff(int $editId): string
    {
        $backup = $this->getBackup($editId);
        $filePath = $backup['file_path'];
        $originalContent = $backup['content'];

        // Try to read the current file content
        if (!file_exists($filePath)) {
            return sprintf("--- a/%s\n+++ /dev/null\n@@ File deleted @@\n", basename($filePath));
        }

        $currentContent = file_get_contents($filePath);
        if ($currentContent === false) {
            return sprintf("--- a/%s\n+++ /dev/null\n@@ File deleted @@\n", basename($filePath));
        }

        return $this->computeUnifiedDiff(
            $originalContent,
            $currentContent,
            'a/' . basename($filePath),
            'b/' . basename($filePath),
        );
    }

    /**
     * Generate a unified diff between the backup content and an arbitrary current string.
     *
     * Useful when the caller already has the absolute file path resolved.
     */
    public function generateDiffFromContent(int $editId, string $currentContent): string
    {
        $backup = $this->getBackup($editId);

        return $this->computeUnifiedDiff(
            $backup['content'],
            $currentContent,
            'a/' . basename($backup['file_path']),
            'b/' . basename($backup['file_path']),
        );
    }

    /**
     * Prune edit records and backup files older than the given number of days.
     *
     * @return int Number of edits pruned.
     */
    public function prune(int $keepDays = 7): int
    {
        $db = $this->connect();

        $cutoff = (new \DateTimeImmutable())->modify("-{$keepDays} days")->format('c');

        // Fetch backup paths before deleting rows
        $stmt = $db->prepare('SELECT backup_path FROM edits WHERE timestamp <= :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        $count = 0;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if (is_file($row['backup_path'])) {
                @unlink($row['backup_path']);
            }
            $count++;
        }

        $stmt = $db->prepare('DELETE FROM edits WHERE timestamp <= :cutoff');
        $stmt->execute([':cutoff' => $cutoff]);

        return $count;
    }

    /**
     * Compute a unified diff between two strings.
     *
     * Produces output similar to `diff -u` with context lines.
     */
    private function computeUnifiedDiff(
        string $old,
        string $new,
        string $oldLabel = 'original',
        string $newLabel = 'modified',
        int $contextLines = 3,
    ): string {
        $oldLines = explode("\n", $old);
        $newLines = explode("\n", $new);

        if ($old === $new) {
            return "No changes.\n";
        }

        // Use shortest-edit-distance (Myers) via built-in xdiff or manual LCS
        $diff = $this->myersDiff($oldLines, $newLines);

        // Format as unified diff
        $output = "--- {$oldLabel}\n+++ {$newLabel}\n";

        $hunks = $this->buildHunks($diff, count($oldLines), count($newLines), $contextLines);
        foreach ($hunks as $hunk) {
            $output .= $hunk;
        }

        return $output;
    }

    /**
     * Simple Myers-like diff producing operation tags per line.
     *
     * @param string[] $old
     * @param string[] $new
     * @return list<array{op: string, old?: string, new?: string, oldIdx?: int, newIdx?: int}>
     */
    private function myersDiff(array $old, array $new): array
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
            } elseif ($i > 0) {
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
    private function buildHunks(array $diff, int $oldLen, int $newLen, int $contextLines): array
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
                $hunks[] = $this->formatHunk($diff, $hunkStart, $hunkEnd);
                $hunkStart = $start;
                $hunkEnd = $end;
            }
        }

        // $changeIndices is non-empty (checked above), so hunkStart/hunkEnd are always set here
        $hunks[] = $this->formatHunk($diff, $hunkStart, $hunkEnd);

        return $hunks;
    }

    /**
     * Format a single unified diff hunk.
     *
     * @param list<array{op: string, old?: string, new?: string, oldIdx?: int, newIdx?: int}> $diff
     */
    private function formatHunk(array $diff, int $start, int $end): string
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

    private function connect(): \PDO
    {
        if ($this->db !== null) {
            return $this->db;
        }

        $dir = dirname($this->dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->db = new \PDO('sqlite:' . $this->dbPath);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');

        $this->db->exec(
            'CREATE TABLE IF NOT EXISTS edits (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_path TEXT NOT NULL,
                operation TEXT NOT NULL,
                timestamp TEXT NOT NULL,
                backup_path TEXT NOT NULL,
                metadata TEXT DEFAULT "{}"
            )',
        );

        return $this->db;
    }
}
