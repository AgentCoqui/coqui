<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\FileUploadStorage;

beforeEach(function () {
    $this->policy = new FileUploadStorage();
});

test('isImageMimeType identifies image types', function () {
    expect($this->policy->isImageMimeType('image/jpeg'))->toBeTrue();
    expect($this->policy->isImageMimeType('image/png'))->toBeTrue();
    expect($this->policy->isImageMimeType('image/gif'))->toBeTrue();
    expect($this->policy->isImageMimeType('image/webp'))->toBeTrue();
    expect($this->policy->isImageMimeType('text/plain'))->toBeFalse();
    expect($this->policy->isImageMimeType('application/json'))->toBeFalse();
});

test('isAllowedMimeType validates all supported types', function () {
    // Images
    expect($this->policy->isAllowedMimeType('image/jpeg'))->toBeTrue();
    expect($this->policy->isAllowedMimeType('image/png'))->toBeTrue();

    // Documents
    expect($this->policy->isAllowedMimeType('text/plain'))->toBeTrue();
    expect($this->policy->isAllowedMimeType('text/markdown'))->toBeTrue();
    expect($this->policy->isAllowedMimeType('application/json'))->toBeTrue();
    expect($this->policy->isAllowedMimeType('application/pdf'))->toBeTrue();
    expect($this->policy->isAllowedMimeType('text/csv'))->toBeTrue();
    expect($this->policy->isAllowedMimeType('application/x-yaml'))->toBeTrue();

    // Rejected
    expect($this->policy->isAllowedMimeType('application/x-msdownload'))->toBeFalse();
    expect($this->policy->isAllowedMimeType('application/zip'))->toBeFalse();
    expect($this->policy->isAllowedMimeType('video/mp4'))->toBeFalse();
});

test('allowedMimeTypes returns combined list', function () {
    $types = FileUploadStorage::allowedMimeTypes();

    expect($types)->toContain('image/jpeg');
    expect($types)->toContain('text/plain');
    expect($types)->toContain('application/json');
    expect(count($types))->toBeGreaterThan(10);
});

test('MAX_FILE_SIZE is the 50 MiB blob ceiling', function () {
    expect(FileUploadStorage::MAX_FILE_SIZE)->toBe(52_428_800);
});
