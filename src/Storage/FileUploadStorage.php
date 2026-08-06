<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

/**
 * Upload MIME/size policy for the content endpoints.
 *
 * Under CAP 0.5.0 uploaded bytes are persisted content-addressed in the
 * {@see \CoquiBot\Coqui\Content\ContentStore} (immutable, deduplicated, referenced
 * by typed message `attachments[]`), so this type no longer stores files. It is
 * the single source of truth for what the upload endpoint accepts: the maximum
 * blob size and the allowed MIME set.
 */
final class FileUploadStorage
{
    /** Maximum file size in bytes (50 MiB). */
    public const int MAX_FILE_SIZE = 52_428_800;

    /** @var string[] Allowed image MIME types. */
    private const array IMAGE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    /** @var string[] Allowed document MIME types. */
    private const array DOCUMENT_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/html',
        'text/xml',
        'text/x-php',
        'text/javascript',
        'application/json',
        'application/xml',
        'application/pdf',
        'application/x-yaml',
    ];

    /**
     * Check if a MIME type is an allowed image type.
     */
    public function isImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::IMAGE_MIME_TYPES, true);
    }

    /**
     * Check if a MIME type is allowed for upload.
     */
    public function isAllowedMimeType(string $mimeType): bool
    {
        return in_array($mimeType, self::IMAGE_MIME_TYPES, true)
            || in_array($mimeType, self::DOCUMENT_MIME_TYPES, true);
    }

    /**
     * @return string[] All allowed MIME types.
     */
    public static function allowedMimeTypes(): array
    {
        return [...self::IMAGE_MIME_TYPES, ...self::DOCUMENT_MIME_TYPES];
    }
}
