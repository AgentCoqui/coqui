<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Contract\FileUploadMetadata;

/**
 * Manages uploaded file storage for API sessions.
 *
 * Files are organized in per-session directories within the workspace:
 *   workspace/uploads/{session_id}/{uuid}.{ext}
 *
 * A manifest.json file in each session directory tracks metadata
 * (original name, MIME type, etc.) for all uploaded files.
 */
final class FileUploadStorage
{
    /** Maximum file size in bytes (50 MiB). */
    public const int MAX_FILE_SIZE = 52_428_800;

    /** Maximum number of files per upload request. */
    public const int MAX_FILES_PER_REQUEST = 20;

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

    private readonly string $uploadsDir;

    public function __construct(
        string $workspacePath,
    ) {
        $this->uploadsDir = PathHelper::trimTrailingSlash($workspacePath) . '/uploads';
    }

    /**
     * Store an uploaded file in the session's upload directory.
     *
     * @param string $sessionId  The session this file belongs to.
     * @param string $contents   Raw file contents (from ReactPHP UploadedFile stream).
     * @param string $originalName  Original filename from the upload.
     * @param string $mimeType   MIME type from the upload Content-Type header.
     */
    public function store(
        string $sessionId,
        string $contents,
        string $originalName,
        string $mimeType,
    ): FileUploadMetadata {
        $size = strlen($contents);

        if ($size > self::MAX_FILE_SIZE) {
            throw new \RuntimeException(sprintf(
                'File "%s" exceeds maximum size of %d bytes',
                $originalName,
                self::MAX_FILE_SIZE,
            ));
        }

        if (!$this->isAllowedMimeType($mimeType)) {
            throw new \RuntimeException(sprintf(
                'File type "%s" is not allowed',
                $mimeType,
            ));
        }

        $sessionDir = $this->sessionDir($sessionId);
        $this->ensureDirectory($sessionDir);

        $id = bin2hex(random_bytes(16));
        $extension = $this->extractExtension($originalName);
        $storedFilename = $extension !== '' ? "{$id}.{$extension}" : $id;
        $storedPath = $sessionDir . '/' . $storedFilename;

        file_put_contents($storedPath, $contents);

        $metadata = new FileUploadMetadata(
            id: $id,
            originalName: $this->sanitizeFilename($originalName),
            mimeType: $mimeType,
            size: $size,
            isImage: $this->isImageMimeType($mimeType),
            storedPath: $storedPath,
            createdAt: date('c'),
        );

        $this->appendToManifest($sessionId, $metadata);

        return $metadata;
    }

    /**
     * List all uploaded files for a session.
     *
     * @return FileUploadMetadata[]
     */
    public function list(string $sessionId): array
    {
        return $this->loadManifest($sessionId);
    }

    /**
     * Get metadata for a specific file.
     */
    public function get(string $sessionId, string $fileId): ?FileUploadMetadata
    {
        $manifest = $this->loadManifest($sessionId);

        foreach ($manifest as $entry) {
            if ($entry->id === $fileId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Get the filesystem path for an uploaded file.
     *
     * Returns null if the file doesn't exist or doesn't belong to the session.
     */
    public function getFilePath(string $sessionId, string $fileId): ?string
    {
        $metadata = $this->get($sessionId, $fileId);

        if ($metadata === null) {
            return null;
        }

        if (!file_exists($metadata->storedPath)) {
            return null;
        }

        return $metadata->storedPath;
    }

    /**
     * Delete a specific uploaded file.
     */
    public function delete(string $sessionId, string $fileId): bool
    {
        $metadata = $this->get($sessionId, $fileId);

        if ($metadata === null) {
            return false;
        }

        // Delete the actual file
        if (file_exists($metadata->storedPath)) {
            unlink($metadata->storedPath);
        }

        // Update manifest
        $this->removeFromManifest($sessionId, $fileId);

        return true;
    }

    /**
     * Delete all uploaded files for a session.
     */
    public function cleanup(string $sessionId): void
    {
        $sessionDir = $this->sessionDir($sessionId);

        if (!is_dir($sessionDir)) {
            return;
        }

        $files = glob($sessionDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }

        rmdir($sessionDir);
    }

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

    private function sessionDir(string $sessionId): string
    {
        // Sanitize session ID to prevent path traversal
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', $sessionId);

        return $this->uploadsDir . '/' . $safe;
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
    }

    private function extractExtension(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }

    private function sanitizeFilename(string $filename): string
    {
        // Strip path components and null bytes
        $filename = basename($filename);
        $filename = str_replace("\0", '', $filename);

        // Limit length
        if (mb_strlen($filename) > 255) {
            $ext = $this->extractExtension($filename);
            $name = mb_substr(pathinfo($filename, PATHINFO_FILENAME), 0, 240);
            $filename = $ext !== '' ? "{$name}.{$ext}" : $name;
        }

        return $filename;
    }

    /**
     * Append a file entry to the session manifest.
     */
    private function appendToManifest(string $sessionId, FileUploadMetadata $metadata): void
    {
        $manifest = $this->loadManifest($sessionId);
        $manifest[] = $metadata;
        $this->saveManifest($sessionId, $manifest);
    }

    /**
     * Remove a file entry from the session manifest.
     */
    private function removeFromManifest(string $sessionId, string $fileId): void
    {
        $manifest = $this->loadManifest($sessionId);
        $filtered = array_values(array_filter(
            $manifest,
            static fn(FileUploadMetadata $m): bool => $m->id !== $fileId,
        ));
        $this->saveManifest($sessionId, $filtered);
    }

    /**
     * Load the manifest for a session.
     *
     * @return FileUploadMetadata[]
     */
    private function loadManifest(string $sessionId): array
    {
        $path = $this->sessionDir($sessionId) . '/manifest.json';

        if (!file_exists($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $item) {
            if (!is_array($item) || !isset($item['id'], $item['original_name'], $item['mime_type'])) {
                continue;
            }

            $entries[] = new FileUploadMetadata(
                id: (string) $item['id'],
                originalName: (string) $item['original_name'],
                mimeType: (string) $item['mime_type'],
                size: (int) ($item['size'] ?? 0),
                isImage: (bool) ($item['is_image'] ?? false),
                storedPath: (string) ($item['stored_path'] ?? ''),
                createdAt: (string) ($item['created_at'] ?? ''),
            );
        }

        return $entries;
    }

    /**
     * Save the manifest for a session.
     *
     * @param FileUploadMetadata[] $manifest
     */
    private function saveManifest(string $sessionId, array $manifest): void
    {
        $sessionDir = $this->sessionDir($sessionId);
        $this->ensureDirectory($sessionDir);

        $data = array_map(static fn(FileUploadMetadata $m): array => [
            'id' => $m->id,
            'original_name' => $m->originalName,
            'mime_type' => $m->mimeType,
            'size' => $m->size,
            'is_image' => $m->isImage,
            'stored_path' => $m->storedPath,
            'created_at' => $m->createdAt,
        ], $manifest);

        file_put_contents(
            $sessionDir . '/manifest.json',
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }
}
