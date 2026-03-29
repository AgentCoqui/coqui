<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\EditHistory;

beforeEach(function () {
    $this->storagePath = sys_get_temp_dir() . '/coqui-history-' . bin2hex(random_bytes(8));
    mkdir($this->storagePath, 0755, true);
    $this->history = new EditHistory($this->storagePath);

    // Create a temp "workspace" for test files
    $this->workDir = sys_get_temp_dir() . '/coqui-histwork-' . bin2hex(random_bytes(8));
    mkdir($this->workDir, 0755, true);
});

afterEach(function () {
    // Recursive cleanup for storage
    foreach ([$this->storagePath, $this->workDir] as $dir) {
        if (!is_dir($dir)) {
            continue;
        }
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
});

// ---------------------------------------------------------------
// Record & Retrieve
// ---------------------------------------------------------------

test('record returns an edit ID', function () {
    $id = $this->history->record('/tmp/test.php', 'write_file', 'original content');

    expect($id)->toBeInt();
    expect($id)->toBeGreaterThan(0);
});

test('getBackup retrieves recorded content', function () {
    $id = $this->history->record('/tmp/test.php', 'replace_in_file', 'original text');

    $backup = $this->history->getBackup($id);

    expect($backup['id'])->toBe($id);
    expect($backup['file_path'])->toBe('/tmp/test.php');
    expect($backup['operation'])->toBe('replace_in_file');
    expect($backup['content'])->toBe('original text');
});

test('getBackup throws for non-existent edit', function () {
    $this->history->getBackup(9999);
})->throws(RuntimeException::class, 'Edit #9999 not found');

test('record stores metadata', function () {
    $id = $this->history->record(
        '/tmp/test.php',
        'replace_in_file',
        'content',
        ['search' => 'foo', 'replace' => 'bar'],
    );

    $edits = $this->history->list(null, 1);

    expect($edits)->toHaveCount(1);
    $meta = json_decode($edits[0]['metadata'], true);
    expect($meta['search'])->toBe('foo');
});

// ---------------------------------------------------------------
// List & Filter
// ---------------------------------------------------------------

test('list returns recent edits', function () {
    $this->history->record('/tmp/a.php', 'write_file', 'a');
    $this->history->record('/tmp/b.php', 'write_file', 'b');
    $this->history->record('/tmp/a.php', 'replace_in_file', 'a-v2');

    $all = $this->history->list(null, 10);
    expect(count($all))->toBe(3);

    $filtered = $this->history->list('/tmp/a.php', 10);
    expect(count($filtered))->toBe(2);
});

test('getLastEdits returns most recent first', function () {
    $this->history->record('/tmp/x.php', 'write_file', 'v1');
    $this->history->record('/tmp/x.php', 'replace_in_file', 'v2');

    $edits = $this->history->getLastEdits('/tmp/x.php', 2);

    expect($edits)->toHaveCount(2);
    expect($edits[0]['operation'])->toBe('replace_in_file');
    expect($edits[1]['operation'])->toBe('write_file');
});

test('getEditsSince filters by timestamp', function () {
    $before = (new DateTimeImmutable())->format('c');

    // Small delay to ensure timestamp difference
    usleep(10_000);

    $this->history->record('/tmp/t.php', 'write_file', 'content');

    $edits = $this->history->getEditsSince($before);
    expect(count($edits))->toBeGreaterThanOrEqual(1);
    expect($edits[0]['file_path'])->toBe('/tmp/t.php');
});

// ---------------------------------------------------------------
// Remove
// ---------------------------------------------------------------

test('removeEdit deletes record and backup file', function () {
    $id = $this->history->record('/tmp/rm.php', 'write_file', 'to remove');

    // Verify backup exists
    $backup = $this->history->getBackup($id);
    expect($backup['content'])->toBe('to remove');

    $this->history->removeEdit($id);

    // Record should be gone
    expect(fn () => $this->history->getBackup($id))
        ->toThrow(RuntimeException::class);
});

// ---------------------------------------------------------------
// Diff
// ---------------------------------------------------------------

test('generateDiffFromContent produces unified diff', function () {
    $id = $this->history->record('/tmp/diff.php', 'write_file', "line1\nline2\nline3");

    $diff = $this->history->generateDiffFromContent($id, "line1\nline2-modified\nline3");

    expect($diff)->toContain('---');
    expect($diff)->toContain('+++');
    expect($diff)->toContain('-line2');
    expect($diff)->toContain('+line2-modified');
});

test('generateDiffFromContent returns no changes for identical content', function () {
    $id = $this->history->record('/tmp/same.php', 'write_file', "same\ncontent");

    $diff = $this->history->generateDiffFromContent($id, "same\ncontent");

    expect($diff)->toContain('No changes');
});

test('generateDiff shows deleted file marker when file is missing', function () {
    $id = $this->history->record('/tmp/nonexistent-' . bin2hex(random_bytes(8)) . '.php', 'write_file', 'old');

    $diff = $this->history->generateDiff($id);

    expect($diff)->toContain('/dev/null');
    expect($diff)->toContain('File deleted');
})->skip(PHP_OS_FAMILY === 'Windows', '/dev/null does not exist on Windows');

test('generateDiff produces diff for real file', function () {
    $path = $this->workDir . '/real.php';
    file_put_contents($path, "before\n");

    $id = $this->history->record($path, 'write_file', "before\n");

    // Modify the file
    file_put_contents($path, "after\n");

    $diff = $this->history->generateDiff($id);

    expect($diff)->toContain('-before');
    expect($diff)->toContain('+after');
});

// ---------------------------------------------------------------
// Prune
// ---------------------------------------------------------------

test('prune removes nothing when all edits are recent', function () {
    $this->history->record('/tmp/p.php', 'write_file', 'recent');

    $count = $this->history->prune(7);

    expect($count)->toBe(0);
    expect($this->history->list())->toHaveCount(1);
});

// ---------------------------------------------------------------
// Database setup
// ---------------------------------------------------------------

test('database and backup directory are created lazily', function () {
    $freshPath = sys_get_temp_dir() . '/coqui-lazy-' . bin2hex(random_bytes(8));
    $history = new EditHistory($freshPath);

    // No DB yet
    expect(file_exists($freshPath . '/history.db'))->toBeFalse();

    // Trigger creation
    $history->record('/tmp/lazy.php', 'write_file', 'lazy');

    expect(file_exists($freshPath . '/history.db'))->toBeTrue();
    expect(is_dir($freshPath . '/backups'))->toBeTrue();

    // Cleanup
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($freshPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($freshPath);
});
