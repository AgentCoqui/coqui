<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

/**
 * Tracks file hashes and metadata for backstory change detection.
 *
 * Stored as JSON at `profiles/{name}/.backstory-manifest.json`.
 */
final class BackstoryManifest
{
    private const int VERSION = 2;

    /**
     * @param array<int, array{relative_path: string, sha256: string, size_bytes: int, modified_at: string, token_estimate: int, status: string, error: string|null}> $files
     * @param array<int, array{relative_path: string, error: string, timestamp: string}> $errors
     * @param array<int, array{relative_path: string, extension: string, sha256: string, size_bytes: int, modified_at: string, reason: string}> $unsupportedFiles
     */
    public function __construct(
        public string $generatedAt = '',
        public string $contentHash = '',
        public array $files = [],
        public array $errors = [],
        public array $unsupportedFiles = [],
        public int $totalTokens = 0,
        public int $totalFiles = 0,
        public int $failedFiles = 0,
    ) {}

    /**
     * Load a manifest from disk. Returns a fresh empty manifest if file is missing or invalid.
     */
    public static function load(string $manifestPath): self
    {
        if (!is_file($manifestPath)) {
            return new self();
        }

        $json = file_get_contents($manifestPath);
        if ($json === false) {
            return new self();
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return new self();
        }

        return new self(
            generatedAt: (string) ($data['generated_at'] ?? ''),
            contentHash: (string) ($data['content_hash'] ?? ''),
            files: is_array($data['files'] ?? null) ? $data['files'] : [],
            errors: is_array($data['errors'] ?? null) ? $data['errors'] : [],
            unsupportedFiles: is_array($data['unsupported_files'] ?? null) ? $data['unsupported_files'] : [],
            totalTokens: (int) ($data['total_tokens'] ?? 0),
            totalFiles: (int) ($data['total_files'] ?? 0),
            failedFiles: (int) ($data['failed_files'] ?? 0),
        );
    }

    public function save(string $manifestPath): void
    {
        $data = [
            'version' => self::VERSION,
            'generated_at' => $this->generatedAt,
            'content_hash' => $this->contentHash,
            'files' => $this->files,
            'errors' => $this->errors,
            'unsupported_files' => $this->unsupportedFiles,
            'total_tokens' => $this->totalTokens,
            'total_files' => $this->totalFiles,
            'failed_files' => $this->failedFiles,
        ];

        file_put_contents(
            $manifestPath,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Check if the backstory source files have changed since the last generation.
     *
     * Computes a composite content hash from individual file hashes and compares
     * against the stored hash. This is O(n) file hash reads — no full content loading.
     *
     * @param list<BackstoryFileEntry> $supportedEntries Discovered supported file entries (already sorted)
     * @param list<BackstoryUnsupportedFileEntry> $unsupportedEntries Discovered unsupported file entries (already sorted)
     */
    public function hasChanged(array $supportedEntries, array $unsupportedEntries = []): bool
    {
        if ($this->contentHash === '') {
            return true;
        }

        return $this->contentHash !== self::computeContentHash($supportedEntries, $unsupportedEntries);
    }

    /**
     * Compute a composite hash from sorted file paths and their individual SHA-256 hashes.
     *
     * @param list<BackstoryFileEntry> $supportedEntries
     * @param list<BackstoryUnsupportedFileEntry> $unsupportedEntries
     */
    public static function computeContentHash(array $supportedEntries, array $unsupportedEntries = []): string
    {
        $ctx = hash_init('sha256');

        foreach ($supportedEntries as $entry) {
            hash_update($ctx, "supported\n");
            hash_update($ctx, $entry->relativePath);
            hash_update($ctx, "\n");
            $fileHash = hash_file('sha256', $entry->absolutePath);
            if ($fileHash !== false) {
                hash_update($ctx, $fileHash);
            }
            hash_update($ctx, "\n");
        }

        foreach ($unsupportedEntries as $entry) {
            hash_update($ctx, "unsupported\n");
            hash_update($ctx, $entry->relativePath);
            hash_update($ctx, "\n");
            hash_update($ctx, $entry->extension);
            hash_update($ctx, "\n");

            $fileHash = hash_file('sha256', $entry->absolutePath);
            if ($fileHash !== false) {
                hash_update($ctx, $fileHash);
            }
            hash_update($ctx, "\n");
        }

        return 'sha256:' . hash_final($ctx);
    }

    public function unsupportedFileCount(): int
    {
        return count($this->unsupportedFiles);
    }

    public function supportedFilesCount(): int
    {
        return max(0, $this->totalFiles - $this->unsupportedFileCount());
    }

    /**
     * Path to the manifest file for a given profile.
     */
    public static function manifestPath(string $profilePath): string
    {
        return rtrim($profilePath, '/') . '/.backstory-manifest.json';
    }

    /**
     * Path to the backstory source directory for a given profile.
     */
    public static function backstoryDir(string $profilePath): string
    {
        return rtrim($profilePath, '/') . '/backstory';
    }
}
