<?php

declare(strict_types=1);

use CoquiBot\Coqui\Contract\FileUploadMetadata;

test('constructs with all properties', function () {
    $metadata = new FileUploadMetadata(
        id: 'abc123',
        originalName: 'photo.jpg',
        mimeType: 'image/jpeg',
        size: 1024,
        isImage: true,
        storedPath: '/tmp/uploads/abc123.jpg',
        createdAt: '2025-01-01T00:00:00+00:00',
    );

    expect($metadata->id)->toBe('abc123');
    expect($metadata->originalName)->toBe('photo.jpg');
    expect($metadata->mimeType)->toBe('image/jpeg');
    expect($metadata->size)->toBe(1024);
    expect($metadata->isImage)->toBeTrue();
    expect($metadata->storedPath)->toBe('/tmp/uploads/abc123.jpg');
    expect($metadata->createdAt)->toBe('2025-01-01T00:00:00+00:00');
});

test('json serialization includes required fields', function () {
    $metadata = new FileUploadMetadata(
        id: 'def456',
        originalName: 'document.pdf',
        mimeType: 'application/pdf',
        size: 2048,
        isImage: false,
        storedPath: '/tmp/uploads/def456.pdf',
        createdAt: '2025-06-15T12:00:00+00:00',
    );

    $json = json_encode($metadata, JSON_THROW_ON_ERROR);
    $data = json_decode($json, true);

    expect($data)->toEqual([
        'id' => 'def456',
        'original_name' => 'document.pdf',
        'mime_type' => 'application/pdf',
        'size' => 2048,
        'is_image' => false,
        'created_at' => '2025-06-15T12:00:00+00:00',
    ]);
});

test('json serialization excludes stored path', function () {
    $metadata = new FileUploadMetadata(
        id: 'sec789',
        originalName: 'secret.txt',
        mimeType: 'text/plain',
        size: 100,
        isImage: false,
        storedPath: '/var/uploads/sec789.txt',
        createdAt: '2025-01-01T00:00:00+00:00',
    );

    $json = json_encode($metadata, JSON_THROW_ON_ERROR);

    expect($json)->not->toContain('stored_path');
    expect($json)->not->toContain('storedPath');
    expect($json)->not->toContain('/var/uploads');
});

test('is readonly', function () {
    $metadata = new FileUploadMetadata(
        id: 'ro123',
        originalName: 'file.txt',
        mimeType: 'text/plain',
        size: 10,
        isImage: false,
        storedPath: '/tmp/ro123.txt',
        createdAt: '2025-01-01T00:00:00+00:00',
    );

    $ref = new \ReflectionClass($metadata);

    expect($ref->isReadOnly())->toBeTrue();
});
