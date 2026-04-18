<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\BackstoryFileDiscovery;
use CoquiBot\Coqui\Backstory\BackstoryFileEntry;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-backstory-discovery-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('discover returns empty for non-existent directory', function () {
    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir . '/nonexistent');

    expect($entries)->toBe([]);
});

test('discover returns empty for empty directory', function () {
    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    expect($entries)->toBe([]);
});

test('discover finds supported files', function () {
    file_put_contents($this->tempDir . '/file1.txt', 'hello');
    file_put_contents($this->tempDir . '/file2.md', '# hello');
    file_put_contents($this->tempDir . '/file3.json', '{}');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    expect($entries)->toHaveCount(3);
    expect(array_map(fn(BackstoryFileEntry $e) => $e->relativePath, $entries))
        ->toBe(['file1.txt', 'file2.md', 'file3.json']);
});

test('discover ignores unsupported extensions', function () {
    file_put_contents($this->tempDir . '/file1.txt', 'hello');
    file_put_contents($this->tempDir . '/file2.exe', 'binary');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->relativePath)->toBe('file1.txt');
});

test('discover includes supported code file extensions', function () {
    file_put_contents($this->tempDir . '/story.mdx', '# Story');
    file_put_contents($this->tempDir . '/script.php', '<?php echo "hi";');
    file_put_contents($this->tempDir . '/notes.xml', '<root><name>Alice</name></root>');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    expect(array_map(fn(BackstoryFileEntry $e) => $e->relativePath, $entries))
        ->toBe(['notes.xml', 'script.php', 'story.mdx']);
});

test('discover ignores hidden files and directories', function () {
    file_put_contents($this->tempDir . '/.hidden', 'secret');
    file_put_contents($this->tempDir . '/visible.txt', 'hello');
    mkdir($this->tempDir . '/.hiddendir', 0755, true);
    file_put_contents($this->tempDir . '/.hiddendir/file.txt', 'hidden');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    expect($entries)->toHaveCount(1);
    expect($entries[0]->relativePath)->toBe('visible.txt');
});

test('numbered files sort before unnumbered', function () {
    file_put_contents($this->tempDir . '/zebra.txt', 'z');
    file_put_contents($this->tempDir . '/01-intro.txt', 'i');
    file_put_contents($this->tempDir . '/alpha.txt', 'a');
    file_put_contents($this->tempDir . '/02-middle.txt', 'm');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    $paths = array_map(fn(BackstoryFileEntry $e) => $e->relativePath, $entries);
    expect($paths)->toBe(['01-intro.txt', '02-middle.txt', 'alpha.txt', 'zebra.txt']);
});

test('natural sort within numbered files', function () {
    file_put_contents($this->tempDir . '/2-second.txt', 's');
    file_put_contents($this->tempDir . '/10-tenth.txt', 't');
    file_put_contents($this->tempDir . '/1-first.txt', 'f');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    $paths = array_map(fn(BackstoryFileEntry $e) => $e->relativePath, $entries);
    expect($paths)->toBe(['1-first.txt', '2-second.txt', '10-tenth.txt']);
});

test('files before subdirectories at each level', function () {
    file_put_contents($this->tempDir . '/root-file.txt', 'r');
    mkdir($this->tempDir . '/subfolder', 0755, true);
    file_put_contents($this->tempDir . '/subfolder/sub-file.txt', 's');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    $paths = array_map(fn(BackstoryFileEntry $e) => $e->relativePath, $entries);
    expect($paths)->toBe(['root-file.txt', 'subfolder/sub-file.txt']);
});

test('recursive discovery with numbered folders', function () {
    mkdir($this->tempDir . '/01-early', 0755, true);
    mkdir($this->tempDir . '/02-late', 0755, true);
    mkdir($this->tempDir . '/misc', 0755, true);

    file_put_contents($this->tempDir . '/01-early/001-file.txt', 'a');
    file_put_contents($this->tempDir . '/01-early/002-file.txt', 'b');
    file_put_contents($this->tempDir . '/02-late/file.txt', 'c');
    file_put_contents($this->tempDir . '/misc/note.txt', 'd');
    file_put_contents($this->tempDir . '/top.txt', 'e');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    $paths = array_map(fn(BackstoryFileEntry $e) => $e->relativePath, $entries);
    expect($paths)->toBe([
        'top.txt',
        '01-early/001-file.txt',
        '01-early/002-file.txt',
        '02-late/file.txt',
        'misc/note.txt',
    ]);
});

test('entries have correct extension', function () {
    file_put_contents($this->tempDir . '/data.csv', "a,b\n1,2");
    file_put_contents($this->tempDir . '/config.yaml', 'key: value');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    $extensions = array_map(fn(BackstoryFileEntry $e) => $e->extension, $entries);
    sort($extensions);
    expect($extensions)->toBe(['csv', 'yaml']);
});

test('entries have correct absolute paths', function () {
    file_put_contents($this->tempDir . '/file.txt', 'hello');

    $discovery = new BackstoryFileDiscovery();
    $entries = $discovery->discover($this->tempDir);

    expect($entries[0]->absolutePath)->toBe($this->tempDir . '/file.txt');
});

test('inspect reports unsupported files without hiding supported ones', function () {
    file_put_contents($this->tempDir . '/01-supported.txt', 'hello');
    file_put_contents($this->tempDir . '/02-unsupported.exe', 'binary-ish');
    mkdir($this->tempDir . '/folder', 0755, true);
    file_put_contents($this->tempDir . '/folder/note.bin', 'bits');

    $discovery = new BackstoryFileDiscovery();
    $inventory = $discovery->inspect($this->tempDir);

    expect($inventory->totalFiles())->toBe(3);
    expect($inventory->supportedFiles())->toBe(1);
    expect($inventory->unsupportedFiles())->toBe(2);
    expect(array_map(fn(BackstoryFileEntry $e) => $e->relativePath, $inventory->supportedEntries))
        ->toBe(['01-supported.txt']);
    expect(array_map(static fn($e) => $e->relativePath, $inventory->unsupportedEntries))
        ->toBe(['02-unsupported.exe', 'folder/note.bin']);
    expect($inventory->unsupportedEntries[0]->reason)->toBe('Unsupported extension: .exe');
});

test('inspect reports files without extensions as unsupported', function () {
    file_put_contents($this->tempDir . '/README', 'plain');

    $discovery = new BackstoryFileDiscovery();
    $inventory = $discovery->inspect($this->tempDir);

    expect($inventory->supportedEntries)->toBe([]);
    expect($inventory->unsupportedEntries)->toHaveCount(1);
    expect($inventory->unsupportedEntries[0]->extension)->toBe('');
    expect($inventory->unsupportedEntries[0]->reason)->toBe('Unsupported file without an extension');
});
