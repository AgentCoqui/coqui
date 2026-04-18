<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\BackstoryFileEntry;
use CoquiBot\Coqui\Backstory\BackstoryManifest;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-backstory-manifest-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('save and load round-trip', function () {
    $manifest = new BackstoryManifest(
        generatedAt: '2026-04-18T12:00:00+00:00',
        contentHash: 'sha256:abc123',
        files: [
            [
                'relative_path' => 'file1.txt',
                'sha256' => 'def456',
                'size_bytes' => 100,
                'modified_at' => '2026-04-18T11:00:00+00:00',
                'token_estimate' => 25,
                'status' => 'ok',
                'error' => null,
            ],
        ],
        errors: [],
        unsupportedFiles: [
            [
                'relative_path' => 'ignored.exe',
                'extension' => 'exe',
                'sha256' => '987654',
                'size_bytes' => 42,
                'modified_at' => '2026-04-18T10:30:00+00:00',
                'reason' => 'Unsupported extension: .exe',
            ],
        ],
        totalTokens: 25,
        totalFiles: 2,
        failedFiles: 0,
    );

    $path = $this->tempDir . '/.backstory-manifest.json';
    $manifest->save($path);

    $loaded = BackstoryManifest::load($path);

    expect($loaded->generatedAt)->toBe('2026-04-18T12:00:00+00:00');
    expect($loaded->contentHash)->toBe('sha256:abc123');
    expect($loaded->totalTokens)->toBe(25);
    expect($loaded->totalFiles)->toBe(2);
    expect($loaded->failedFiles)->toBe(0);
    expect($loaded->files)->toHaveCount(1);
    expect($loaded->files[0]['relative_path'])->toBe('file1.txt');
    expect($loaded->errors)->toBe([]);
    expect($loaded->unsupportedFiles)->toHaveCount(1);
    expect($loaded->unsupportedFiles[0]['relative_path'])->toBe('ignored.exe');
    expect($loaded->unsupportedFileCount())->toBe(1);
    expect($loaded->supportedFilesCount())->toBe(1);
});

test('load returns empty manifest for missing file', function () {
    $loaded = BackstoryManifest::load($this->tempDir . '/nonexistent.json');

    expect($loaded->generatedAt)->toBe('');
    expect($loaded->contentHash)->toBe('');
    expect($loaded->totalFiles)->toBe(0);
});

test('load returns empty manifest for invalid json', function () {
    $path = $this->tempDir . '/bad.json';
    file_put_contents($path, 'not json');

    $loaded = BackstoryManifest::load($path);
    expect($loaded->generatedAt)->toBe('');
});

test('hasChanged returns true for empty manifest', function () {
    $manifest = new BackstoryManifest();

    file_put_contents($this->tempDir . '/file.txt', 'hello');
    $entries = [
        new BackstoryFileEntry('file.txt', $this->tempDir . '/file.txt', 'txt'),
    ];

    expect($manifest->hasChanged($entries))->toBeTrue();
});

test('hasChanged returns false when content hash matches', function () {
    file_put_contents($this->tempDir . '/file.txt', 'hello');
    $entries = [
        new BackstoryFileEntry('file.txt', $this->tempDir . '/file.txt', 'txt'),
    ];

    $hash = BackstoryManifest::computeContentHash($entries);
    $manifest = new BackstoryManifest(contentHash: $hash);

    expect($manifest->hasChanged($entries))->toBeFalse();
});

test('hasChanged returns true when file is modified', function () {
    file_put_contents($this->tempDir . '/file.txt', 'hello');
    $entries = [
        new BackstoryFileEntry('file.txt', $this->tempDir . '/file.txt', 'txt'),
    ];

    $hash = BackstoryManifest::computeContentHash($entries);
    $manifest = new BackstoryManifest(contentHash: $hash);

    // Modify the file
    file_put_contents($this->tempDir . '/file.txt', 'world');

    expect($manifest->hasChanged($entries))->toBeTrue();
});

test('hasChanged returns true when file is added', function () {
    file_put_contents($this->tempDir . '/file1.txt', 'hello');
    $entries1 = [
        new BackstoryFileEntry('file1.txt', $this->tempDir . '/file1.txt', 'txt'),
    ];

    $hash = BackstoryManifest::computeContentHash($entries1);
    $manifest = new BackstoryManifest(contentHash: $hash);

    // Add a second file
    file_put_contents($this->tempDir . '/file2.txt', 'world');
    $entries2 = [
        new BackstoryFileEntry('file1.txt', $this->tempDir . '/file1.txt', 'txt'),
        new BackstoryFileEntry('file2.txt', $this->tempDir . '/file2.txt', 'txt'),
    ];

    expect($manifest->hasChanged($entries2))->toBeTrue();
});

test('hasChanged returns true when unsupported file is added', function () {
    file_put_contents($this->tempDir . '/file1.txt', 'hello');
    $entries = [
        new BackstoryFileEntry('file1.txt', $this->tempDir . '/file1.txt', 'txt'),
    ];

    $hash = BackstoryManifest::computeContentHash($entries);
    $manifest = new BackstoryManifest(contentHash: $hash, totalFiles: 1);

    file_put_contents($this->tempDir . '/ignored.exe', 'nope');
    $unsupported = [
        new \CoquiBot\Coqui\Backstory\BackstoryUnsupportedFileEntry(
            'ignored.exe',
            $this->tempDir . '/ignored.exe',
            'exe',
            'Unsupported extension: .exe',
        ),
    ];

    expect($manifest->hasChanged($entries, $unsupported))->toBeTrue();
});

test('computeContentHash is stable for same content', function () {
    file_put_contents($this->tempDir . '/file.txt', 'hello');
    $entries = [
        new BackstoryFileEntry('file.txt', $this->tempDir . '/file.txt', 'txt'),
    ];

    $hash1 = BackstoryManifest::computeContentHash($entries);
    $hash2 = BackstoryManifest::computeContentHash($entries);

    expect($hash1)->toBe($hash2);
    expect($hash1)->toStartWith('sha256:');
});

test('manifestPath returns correct path', function () {
    $path = BackstoryManifest::manifestPath('/profiles/test');
    expect($path)->toBe('/profiles/test/.backstory-manifest.json');
});

test('backstoryDir returns correct path', function () {
    $path = BackstoryManifest::backstoryDir('/profiles/test');
    expect($path)->toBe('/profiles/test/backstory');
});
