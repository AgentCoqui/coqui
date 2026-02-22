<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\FileUploadMetadata;
use CoquiBot\Coqui\Storage\FileUploadStorage;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/coqui-upload-test-' . bin2hex(random_bytes(8));
    mkdir($this->tmpDir, 0o755, true);
    $this->storage = new FileUploadStorage($this->tmpDir);
    $this->sessionId = bin2hex(random_bytes(16));
});

afterEach(function () {
    // Recursively clean up temp directory
    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $cleanup($path) : unlink($path);
        }
        rmdir($dir);
    };

    $cleanup($this->tmpDir);
});

test('stores a file and returns metadata', function () {
    $metadata = $this->storage->store(
        $this->sessionId,
        'Hello, world!',
        'test.txt',
        'text/plain',
    );

    expect($metadata)->toBeInstanceOf(FileUploadMetadata::class);
    expect($metadata->id)->toBeString()->toHaveLength(32);
    expect($metadata->originalName)->toBe('test.txt');
    expect($metadata->mimeType)->toBe('text/plain');
    expect($metadata->size)->toBe(13);
    expect($metadata->isImage)->toBeFalse();
    expect($metadata->storedPath)->toContain('.txt');
    expect($metadata->createdAt)->not->toBeEmpty();
    expect(file_exists($metadata->storedPath))->toBeTrue();
    expect(file_get_contents($metadata->storedPath))->toBe('Hello, world!');
});

test('stores image file and marks as image', function () {
    $metadata = $this->storage->store(
        $this->sessionId,
        'fake-png-data',
        'photo.png',
        'image/png',
    );

    expect($metadata->isImage)->toBeTrue();
    expect($metadata->mimeType)->toBe('image/png');
});

test('lists files for session', function () {
    $this->storage->store($this->sessionId, 'file1', 'a.txt', 'text/plain');
    $this->storage->store($this->sessionId, 'file2', 'b.txt', 'text/plain');

    $files = $this->storage->list($this->sessionId);

    expect($files)->toHaveCount(2);
    expect($files[0]->originalName)->toBe('a.txt');
    expect($files[1]->originalName)->toBe('b.txt');
});

test('returns empty list for session with no uploads', function () {
    expect($this->storage->list('nonexistent-session'))->toBeEmpty();
});

test('gets specific file metadata by id', function () {
    $stored = $this->storage->store($this->sessionId, 'content', 'doc.json', 'application/json');

    $retrieved = $this->storage->get($this->sessionId, $stored->id);

    expect($retrieved)->not->toBeNull();
    expect($retrieved->id)->toBe($stored->id);
    expect($retrieved->originalName)->toBe('doc.json');
});

test('returns null for nonexistent file', function () {
    expect($this->storage->get($this->sessionId, 'nonexistent-id'))->toBeNull();
});

test('gets file path for stored file', function () {
    $stored = $this->storage->store($this->sessionId, 'data', 'test.txt', 'text/plain');

    $path = $this->storage->getFilePath($this->sessionId, $stored->id);

    expect($path)->not->toBeNull();
    expect(file_exists($path))->toBeTrue();
});

test('returns null file path for nonexistent file', function () {
    expect($this->storage->getFilePath($this->sessionId, 'nonexistent'))->toBeNull();
});

test('deletes a file', function () {
    $stored = $this->storage->store($this->sessionId, 'to-delete', 'temp.txt', 'text/plain');
    $path = $stored->storedPath;

    $result = $this->storage->delete($this->sessionId, $stored->id);

    expect($result)->toBeTrue();
    expect(file_exists($path))->toBeFalse();
    expect($this->storage->get($this->sessionId, $stored->id))->toBeNull();
});

test('delete returns false for nonexistent file', function () {
    expect($this->storage->delete($this->sessionId, 'nonexistent'))->toBeFalse();
});

test('cleanup removes all session files and directory', function () {
    $this->storage->store($this->sessionId, 'file1', 'a.txt', 'text/plain');
    $this->storage->store($this->sessionId, 'file2', 'b.txt', 'text/plain');

    $this->storage->cleanup($this->sessionId);

    expect($this->storage->list($this->sessionId))->toBeEmpty();
});

test('cleanup handles nonexistent session gracefully', function () {
    // Should not throw
    $this->storage->cleanup('nonexistent-session');

    expect(true)->toBeTrue();
});

test('rejects file exceeding max size', function () {
    // Create content just over the limit
    $oversized = str_repeat('x', FileUploadStorage::MAX_FILE_SIZE + 1);

    expect(fn() => $this->storage->store(
        $this->sessionId,
        $oversized,
        'huge.txt',
        'text/plain',
    ))->toThrow(RuntimeException::class, 'exceeds maximum size');
});

test('rejects disallowed MIME type', function () {
    expect(fn() => $this->storage->store(
        $this->sessionId,
        'binary stuff',
        'program.exe',
        'application/x-msdownload',
    ))->toThrow(RuntimeException::class, 'not allowed');
});

test('isImageMimeType identifies image types', function () {
    expect($this->storage->isImageMimeType('image/jpeg'))->toBeTrue();
    expect($this->storage->isImageMimeType('image/png'))->toBeTrue();
    expect($this->storage->isImageMimeType('image/gif'))->toBeTrue();
    expect($this->storage->isImageMimeType('image/webp'))->toBeTrue();
    expect($this->storage->isImageMimeType('text/plain'))->toBeFalse();
    expect($this->storage->isImageMimeType('application/json'))->toBeFalse();
});

test('isAllowedMimeType validates all supported types', function () {
    // Images
    expect($this->storage->isAllowedMimeType('image/jpeg'))->toBeTrue();
    expect($this->storage->isAllowedMimeType('image/png'))->toBeTrue();

    // Documents
    expect($this->storage->isAllowedMimeType('text/plain'))->toBeTrue();
    expect($this->storage->isAllowedMimeType('text/markdown'))->toBeTrue();
    expect($this->storage->isAllowedMimeType('application/json'))->toBeTrue();
    expect($this->storage->isAllowedMimeType('application/pdf'))->toBeTrue();
    expect($this->storage->isAllowedMimeType('text/csv'))->toBeTrue();
    expect($this->storage->isAllowedMimeType('application/x-yaml'))->toBeTrue();

    // Rejected
    expect($this->storage->isAllowedMimeType('application/x-msdownload'))->toBeFalse();
    expect($this->storage->isAllowedMimeType('application/zip'))->toBeFalse();
    expect($this->storage->isAllowedMimeType('video/mp4'))->toBeFalse();
});

test('allowedMimeTypes returns combined list', function () {
    $types = FileUploadStorage::allowedMimeTypes();

    expect($types)->toContain('image/jpeg');
    expect($types)->toContain('text/plain');
    expect($types)->toContain('application/json');
    expect(count($types))->toBeGreaterThan(10);
});

test('sanitizes filenames with path traversal attempts', function () {
    $stored = $this->storage->store(
        $this->sessionId,
        'sneaky content',
        '../../../etc/passwd',
        'text/plain',
    );

    // The stored file should NOT be outside the session directory
    expect($stored->originalName)->toBe('passwd'); // basename() strips path
    expect($stored->storedPath)->toContain($this->tmpDir);
});

test('handles multiple sessions independently', function () {
    $sessionA = 'session-a-' . bin2hex(random_bytes(4));
    $sessionB = 'session-b-' . bin2hex(random_bytes(4));

    $this->storage->store($sessionA, 'fileA', 'a.txt', 'text/plain');
    $this->storage->store($sessionB, 'fileB', 'b.txt', 'text/plain');

    $filesA = $this->storage->list($sessionA);
    $filesB = $this->storage->list($sessionB);

    expect($filesA)->toHaveCount(1);
    expect($filesA[0]->originalName)->toBe('a.txt');
    expect($filesB)->toHaveCount(1);
    expect($filesB[0]->originalName)->toBe('b.txt');

    // Cleanup session B shouldn't affect session A
    $this->storage->cleanup($sessionB);
    expect($this->storage->list($sessionA))->toHaveCount(1);
});

test('stored file extension matches original', function () {
    $mdFile = $this->storage->store($this->sessionId, '# Title', 'readme.md', 'text/markdown');
    expect($mdFile->storedPath)->toEndWith('.md');

    $jsonFile = $this->storage->store($this->sessionId, '{}', 'data.json', 'application/json');
    expect($jsonFile->storedPath)->toEndWith('.json');
});

test('json serialization excludes stored path', function () {
    $stored = $this->storage->store($this->sessionId, 'test', 'file.txt', 'text/plain');

    $json = json_encode($stored, JSON_THROW_ON_ERROR);
    $data = json_decode($json, true);

    expect($data)->toHaveKey('id');
    expect($data)->toHaveKey('original_name');
    expect($data)->toHaveKey('mime_type');
    expect($data)->toHaveKey('size');
    expect($data)->toHaveKey('is_image');
    expect($data)->toHaveKey('created_at');
    expect($data)->not->toHaveKey('stored_path');
    expect($data)->not->toHaveKey('storedPath');
});
