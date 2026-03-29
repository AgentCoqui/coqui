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
    $realRoot = realpath($this->root);

    expect(str_starts_with($resolved, $realRoot))->toBeTrue();
});

test('resolvePath blocks directory traversal', function () {
    $resolved = $this->fs->resolvePath('../../etc/passwd');

    // Should either resolve to root or stay within root
    // resolvePath returns rootPath (not realRoot) as fallback, so check both
    $inRoot = str_starts_with($resolved, $this->root)
        || str_starts_with($resolved, realpath($this->root));
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
