<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\FileSystemException;
use CoquiBot\Coqui\Support\FileSystemOperations;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/coqui-fs-ops-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0755, true);
    $this->fs = new FileSystemOperations($this->root);
});

afterEach(function () {
    // Recursive cleanup
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->root);
});

// ---------------------------------------------------------------
// Read operations
// ---------------------------------------------------------------

test('read returns file content', function () {
    file_put_contents($this->root . '/hello.txt', 'Hello, World!');

    expect($this->fs->read('hello.txt'))->toBe('Hello, World!');
});

test('read throws for missing file', function () {
    $this->fs->read('missing.txt');
})->throws(FileSystemException::class);

test('readLines splits on LF', function () {
    file_put_contents($this->root . '/lines.txt', "a\nb\nc");

    $result = $this->fs->readLines('lines.txt');

    expect($result['lines'])->toBe(['a', 'b', 'c']);
    expect($result['eol'])->toBe("\n");
});

test('readLines splits on CRLF', function () {
    file_put_contents($this->root . '/crlf.txt', "a\r\nb\r\nc");

    $result = $this->fs->readLines('crlf.txt');

    expect($result['lines'])->toBe(['a', 'b', 'c']);
    expect($result['eol'])->toBe("\r\n");
});

test('exists returns true for existing file', function () {
    file_put_contents($this->root . '/exists.txt', '');

    expect($this->fs->exists('exists.txt'))->toBeTrue();
});

test('exists returns false for missing file', function () {
    expect($this->fs->exists('nope.txt'))->toBeFalse();
});

test('isFile and isDir distinguish correctly', function () {
    file_put_contents($this->root . '/file.txt', '');
    mkdir($this->root . '/subdir');

    expect($this->fs->isFile('file.txt'))->toBeTrue();
    expect($this->fs->isDir('file.txt'))->toBeFalse();
    expect($this->fs->isDir('subdir'))->toBeTrue();
    expect($this->fs->isFile('subdir'))->toBeFalse();
});

// ---------------------------------------------------------------
// Write operations
// ---------------------------------------------------------------

test('write creates file with content', function () {
    $this->fs->write('new-file.txt', 'content here');

    expect(file_get_contents($this->root . '/new-file.txt'))->toBe('content here');
});

test('write creates parent directories', function () {
    $this->fs->write('deep/nested/dir/file.txt', 'deep content');

    expect(file_get_contents($this->root . '/deep/nested/dir/file.txt'))->toBe('deep content');
});

test('write overwrites existing file atomically', function () {
    file_put_contents($this->root . '/overwrite.txt', 'old');

    $this->fs->write('overwrite.txt', 'new');

    expect(file_get_contents($this->root . '/overwrite.txt'))->toBe('new');
});

test('writeLines joins with given EOL', function () {
    $this->fs->writeLines('joined.txt', ['a', 'b', 'c'], "\r\n");

    expect(file_get_contents($this->root . '/joined.txt'))->toBe("a\r\nb\r\nc");
});

test('append creates file if missing', function () {
    $bytes = $this->fs->append('appended.txt', 'first');

    expect($bytes)->toBe(5);
    expect(file_get_contents($this->root . '/appended.txt'))->toBe('first');
});

test('append adds to existing file', function () {
    file_put_contents($this->root . '/log.txt', 'line1');

    $this->fs->append('log.txt', "\nline2");

    expect(file_get_contents($this->root . '/log.txt'))->toBe("line1\nline2");
});

test('delete removes file', function () {
    file_put_contents($this->root . '/remove-me.txt', '');

    $this->fs->delete('remove-me.txt');

    expect(file_exists($this->root . '/remove-me.txt'))->toBeFalse();
});

test('delete throws for missing file', function () {
    $this->fs->delete('gone.txt');
})->throws(FileSystemException::class);

test('delete throws for directory', function () {
    mkdir($this->root . '/dir');

    $this->fs->delete('dir');
})->throws(FileSystemException::class);

test('createDir makes nested directory', function () {
    $this->fs->createDir('a/b/c');

    expect(is_dir($this->root . '/a/b/c'))->toBeTrue();
});

test('createDir is idempotent for existing directory', function () {
    mkdir($this->root . '/exists', 0755, true);

    $this->fs->createDir('exists'); // should not throw

    expect(is_dir($this->root . '/exists'))->toBeTrue();
});

// ---------------------------------------------------------------
// Path sandboxing
// ---------------------------------------------------------------

test('resolvePath stays within root', function () {
    file_put_contents($this->root . '/ok.txt', '');

    $resolved = $this->fs->resolvePath('ok.txt');
    $rawRoot = realpath($this->root);
    // Normalize to forward slashes with lowercase drive letter (matches resolvePath output)
    $realRoot = $rawRoot !== false
        ? strtolower(substr(str_replace('\\', '/', $rawRoot), 0, 2)) . substr(str_replace('\\', '/', $rawRoot), 2)
        : str_replace('\\', '/', $this->root);

    expect(str_starts_with($resolved, $realRoot))->toBeTrue();
});

test('resolvePath blocks directory traversal', function () {
    $resolved = $this->fs->resolvePath('../../etc/passwd');

    // Normalize both sides to forward-slash / lowercase-drive for cross-platform comparison
    $normalize = static function (string $p): string {
        $p = str_replace('\\', '/', $p);
        if (preg_match('/^[A-Z]:\//', $p) === 1) {
            $p = strtolower($p[0]) . substr($p, 1);
        }
        return $p;
    };

    $normalizedResolved = $normalize($resolved);
    $normalizedRoot     = $normalize($this->root);
    $rawReal            = realpath($this->root);
    $normalizedReal     = $rawReal !== false ? $normalize($rawReal) : $normalizedRoot;

    $inRoot = str_starts_with($normalizedResolved, $normalizedRoot)
        || str_starts_with($normalizedResolved, $normalizedReal);
    expect($inRoot)->toBeTrue();
});

test('resolvePath resolves relative segments', function () {
    mkdir($this->root . '/sub');
    file_put_contents($this->root . '/sub/file.txt', '');

    $resolved = $this->fs->resolvePath('sub/./file.txt');

    expect($resolved)->toEndWith('/sub/file.txt');
})->skip(PHP_OS_FAMILY === 'Windows', 'realpath() returns backslash paths on Windows');

test('makeRelative strips root prefix', function () {
    $abs = $this->root . '/sub/file.txt';

    expect($this->fs->makeRelative($abs))->toBe('sub/file.txt');
});

test('makeRelative normalizes backslash-separated absolute paths', function () {
    $abs = str_replace('/', '\\', $this->root) . '\\sub\\file.txt';

    expect($this->fs->makeRelative($abs))->toBe('sub/file.txt');
});

// ---------------------------------------------------------------
// Mount support
// ---------------------------------------------------------------

test('resolvePath allows mount paths', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-mount-' . bin2hex(random_bytes(8));
    mkdir($mountDir, 0755, true);
    file_put_contents($mountDir . '/data.csv', 'a,b,c');

    // Create symlink in root
    symlink($mountDir, $this->root . '/mnt');

    $fs = new FileSystemOperations($this->root, [
        ['realPath' => realpath($mountDir), 'readOnly' => false],
    ]);

    $resolved = $fs->resolvePath('mnt/data.csv');
    expect(file_exists($resolved))->toBeTrue();

    // Cleanup
    unlink($this->root . '/mnt');
    unlink($mountDir . '/data.csv');
    rmdir($mountDir);
})->skip(PHP_OS_FAMILY === 'Windows', 'symlink() requires Developer Mode on Windows');

test('write throws for read-only mount path', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-romount-' . bin2hex(random_bytes(8));
    mkdir($mountDir, 0755, true);
    file_put_contents($mountDir . '/ro.txt', 'readonly');

    symlink($mountDir, $this->root . '/mnt');

    $fs = new FileSystemOperations($this->root, [
        ['realPath' => realpath($mountDir), 'readOnly' => true],
    ]);

    $threw = false;
    try {
        $fs->write('mnt/ro.txt', 'should fail');
    } catch (FileSystemException $e) {
        $threw = true;
        expect($e->getMessage())->toContain('read-only');
    } finally {
        // Cleanup
        unlink($this->root . '/mnt');
        unlink($mountDir . '/ro.txt');
        rmdir($mountDir);
    }

    expect($threw)->toBeTrue();
})->skip(PHP_OS_FAMILY === 'Windows', 'symlink() requires Developer Mode on Windows');

// ---------------------------------------------------------------
// EOL detection
// ---------------------------------------------------------------

test('detectEol detects LF', function () {
    expect(FileSystemOperations::detectEol("a\nb\nc"))->toBe("\n");
});

test('detectEol detects CRLF', function () {
    expect(FileSystemOperations::detectEol("a\r\nb\r\nc"))->toBe("\r\n");
});

test('detectEol detects CR', function () {
    expect(FileSystemOperations::detectEol("a\rb\rc"))->toBe("\r");
});

test('detectEol defaults to LF for empty string', function () {
    expect(FileSystemOperations::detectEol(''))->toBe("\n");
});

// ---------------------------------------------------------------
// Glob resolution
// ---------------------------------------------------------------

test('resolveGlob matches files within sandbox', function () {
    file_put_contents($this->root . '/a.php', '');
    file_put_contents($this->root . '/b.php', '');
    file_put_contents($this->root . '/c.txt', '');

    $matches = $this->fs->resolveGlob('*.php');

    expect(count($matches))->toBe(2);
    foreach ($matches as $m) {
        expect($m)->toEndWith('.php');
    }
});

// ---------------------------------------------------------------
// Recursive glob (** pattern)
// ---------------------------------------------------------------

test('resolveGlob with ** matches files in subdirectories', function () {
    mkdir($this->root . '/sub', 0755, true);
    file_put_contents($this->root . '/sub/nested.php', '');
    file_put_contents($this->root . '/sub/other.txt', '');

    $matches = $this->fs->resolveGlob('**/*.php');

    $relatives = array_map(fn(string $p): string => $this->fs->makeRelative($p), $matches);
    sort($relatives);

    // **/*.php requires at least one parent directory component (fnmatch FNM_PATHNAME)
    // so root-level files are not matched; use *.php for those
    expect($relatives)->toBe(['sub/nested.php']);
})->skip(PHP_OS_FAMILY === 'Windows', 'RecursiveDirectoryIterator returns backslash paths on Windows');

test('resolveGlob with ** does not return files outside sandbox', function () {
    $external = sys_get_temp_dir() . '/coqui-ext-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($external, '<?php');

    mkdir($this->root . '/sub');
    file_put_contents($this->root . '/sub/good.php', '');

    $matches = $this->fs->resolveGlob('**/*.php');

    foreach ($matches as $match) {
        expect($match)->not->toBe($external);
    }

    unlink($external);
});

// ---------------------------------------------------------------
// Path-escape throws
// ---------------------------------------------------------------

test('resolvePath throws when symlink escapes sandbox', function () {
    $external = sys_get_temp_dir() . '/coqui-escape-' . bin2hex(random_bytes(8));
    mkdir($external, 0755, true);
    file_put_contents($external . '/secret.txt', 'outside');

    symlink($external, $this->root . '/escape-link');

    $threw = false;
    try {
        $this->fs->resolvePath('escape-link/secret.txt');
    } catch (FileSystemException $e) {
        $threw = true;
    } finally {
        unlink($this->root . '/escape-link');
        unlink($external . '/secret.txt');
        rmdir($external);
    }

    expect($threw)->toBeTrue();
})->skip(PHP_OS_FAMILY === 'Windows', 'symlink() requires Developer Mode on Windows');

// ---------------------------------------------------------------
// Copy operations
// ---------------------------------------------------------------

test('copyPath copies a single file', function () {
    file_put_contents($this->root . '/src.txt', 'content');

    $count = $this->fs->copyPath('src.txt', 'dst.txt');

    expect($count)->toBe(1);
    expect(file_get_contents($this->root . '/dst.txt'))->toBe('content');
    expect(file_exists($this->root . '/src.txt'))->toBeTrue();
});

test('copyPath copies a directory recursively', function () {
    mkdir($this->root . '/src-dir/nested', 0755, true);
    file_put_contents($this->root . '/src-dir/a.txt', 'A');
    file_put_contents($this->root . '/src-dir/nested/b.txt', 'B');

    $count = $this->fs->copyPath('src-dir', 'dst-dir');

    expect($count)->toBeGreaterThanOrEqual(2);
    expect(file_get_contents($this->root . '/dst-dir/a.txt'))->toBe('A');
    expect(file_get_contents($this->root . '/dst-dir/nested/b.txt'))->toBe('B');
});

test('copyPath creates destination parent directories', function () {
    file_put_contents($this->root . '/f.txt', 'data');

    $this->fs->copyPath('f.txt', 'deep/nested/f.txt');

    expect(file_get_contents($this->root . '/deep/nested/f.txt'))->toBe('data');
});

test('copyPath throws for missing source', function () {
    $this->fs->copyPath('missing.txt', 'dst.txt');
})->throws(FileSystemException::class);

test('copyPath throws when source equals destination', function () {
    file_put_contents($this->root . '/same.txt', 'data');

    $this->fs->copyPath('same.txt', 'same.txt');
})->throws(FileSystemException::class, 'itself');

// ---------------------------------------------------------------
// Move operations
// ---------------------------------------------------------------

test('movePath moves a single file', function () {
    file_put_contents($this->root . '/old.txt', 'data');

    $count = $this->fs->movePath('old.txt', 'new.txt');

    expect($count)->toBe(1);
    expect(file_exists($this->root . '/old.txt'))->toBeFalse();
    expect(file_get_contents($this->root . '/new.txt'))->toBe('data');
});

test('movePath moves a directory', function () {
    mkdir($this->root . '/old-dir/sub', 0755, true);
    file_put_contents($this->root . '/old-dir/sub/f.txt', 'data');

    $count = $this->fs->movePath('old-dir', 'new-dir');

    expect($count)->toBeGreaterThanOrEqual(1);
    expect(is_dir($this->root . '/old-dir'))->toBeFalse();
    expect(file_get_contents($this->root . '/new-dir/sub/f.txt'))->toBe('data');
});

test('movePath throws for missing source', function () {
    $this->fs->movePath('missing.txt', 'dst.txt');
})->throws(FileSystemException::class);

test('movePath creates destination parent directories', function () {
    file_put_contents($this->root . '/f.txt', 'data');

    $this->fs->movePath('f.txt', 'deep/nested/f.txt');

    expect(file_exists($this->root . '/f.txt'))->toBeFalse();
    expect(file_get_contents($this->root . '/deep/nested/f.txt'))->toBe('data');
});

// ---------------------------------------------------------------
// Delete directory
// ---------------------------------------------------------------

test('deleteDirectory removes directory and all contents', function () {
    mkdir($this->root . '/to-delete/nested', 0755, true);
    file_put_contents($this->root . '/to-delete/a.txt', 'A');
    file_put_contents($this->root . '/to-delete/nested/b.txt', 'B');

    $this->fs->deleteDirectory($this->root . '/to-delete');

    expect(is_dir($this->root . '/to-delete'))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'deleteDirectory uses Unix-style recursive deletion that fails on Windows');

test('deleteDirectory is noop for non-existent directory', function () {
    $this->fs->deleteDirectory($this->root . '/nonexistent');

    expect(true)->toBeTrue(); // no exception
});
