<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\FileSystemException;
use CoquiBot\Coqui\Support\ImagePreviewService;

test('image preview service resolves workspace relative paths before formatting', function () {
    $workspace = sys_get_temp_dir() . '/coqui-image-preview-service-' . bin2hex(random_bytes(8));
    $imagePath = $workspace . '/images/example.png';

    mkdir(dirname($imagePath), 0755, true);
    file_put_contents($imagePath, 'fixture');

    try {
        $service = new ImagePreviewService(
            $workspace,
            static fn(string $path, int $width): array => [
                'preview' => 'PREVIEW:' . basename($path) . ':' . $width,
                'preview_format' => 'ansi_blocks',
                'unavailable_reason' => null,
            ],
        );

        $payload = $service->preview('images/example.png', 24);

        expect($payload['path'])->toBe(realpath($imagePath))
            ->and($payload['preview'])->toBe('PREVIEW:example.png:24')
            ->and($payload['preview_format'])->toBe('ansi_blocks');
    } finally {
        cleanupTestTree($workspace);
    }
});

test('image preview service accepts file urls inside the workspace', function () {
    $workspace = sys_get_temp_dir() . '/coqui-image-preview-file-url-' . bin2hex(random_bytes(8));
    $imagePath = $workspace . '/images/example.png';

    mkdir(dirname($imagePath), 0755, true);
    file_put_contents($imagePath, 'fixture');

    try {
        $service = new ImagePreviewService(
            $workspace,
            static fn(string $path, int $width): array => [
                'preview' => 'PREVIEW:' . basename($path) . ':' . $width,
                'preview_format' => 'ansi_blocks',
                'unavailable_reason' => null,
            ],
        );

        $payload = $service->preview('file://' . $imagePath);

        expect($payload['path'])->toBe(realpath($imagePath));
    } finally {
        cleanupTestTree($workspace);
    }
});

test('image preview service recognizes windows-style local image paths', function () {
    $service = new ImagePreviewService(sys_get_temp_dir());

    expect($service->canPreviewPath('C:\\Users\\Runner\\AppData\\Local\\Temp\\example.png'))->toBeTrue()
        ->and($service->canPreviewPath('file://C:\\Users\\Runner\\AppData\\Local\\Temp\\example.png'))->toBeTrue()
        ->and($service->canPreviewPath('file:///C:\\Users\\Runner\\AppData\\Local\\Temp\\example.png'))->toBeTrue();
});

test('image preview service rejects paths outside the workspace', function () {
    $workspace = sys_get_temp_dir() . '/coqui-image-preview-inside-' . bin2hex(random_bytes(8));
    $outside = sys_get_temp_dir() . '/coqui-image-preview-outside-' . bin2hex(random_bytes(8)) . '.png';

    mkdir($workspace, 0755, true);
    file_put_contents($outside, 'fixture');

    try {
        $service = new ImagePreviewService(
            $workspace,
            static fn(string $path, int $width): array => [
                'preview' => 'PREVIEW:' . basename($path) . ':' . $width,
                'preview_format' => 'ansi_blocks',
                'unavailable_reason' => null,
            ],
        );

        expect(fn() => $service->preview($outside))->toThrow(FileSystemException::class, 'Path escapes workspace boundary');
    } finally {
        cleanupTestTree($workspace);
        @unlink($outside);
    }
});

test('image preview service forwards unavailable preview reasons', function () {
    $workspace = sys_get_temp_dir() . '/coqui-image-preview-unavailable-' . bin2hex(random_bytes(8));
    $imagePath = $workspace . '/images/example.png';

    mkdir(dirname($imagePath), 0755, true);
    file_put_contents($imagePath, 'fixture');

    try {
        $service = new ImagePreviewService(
            $workspace,
            static fn(string $path, int $width): array => [
                'preview' => null,
                'preview_format' => null,
                'unavailable_reason' => 'ext-gd is not installed.',
            ],
        );

        $payload = $service->preview('images/example.png');

        expect($payload['preview'])->toBeNull()
            ->and($payload['unavailable_reason'])->toBe('ext-gd is not installed.');
    } finally {
        cleanupTestTree($workspace);
    }
});