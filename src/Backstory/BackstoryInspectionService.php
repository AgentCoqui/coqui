<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

use CoquiBot\Coqui\Config\ProfileDiscovery;

/**
 * Builds a read-only backstory inspection payload for API and REPL consumers.
 */
final readonly class BackstoryInspectionService
{
    private BackstoryAssembler $assembler;

    public function __construct(
        private string $workspacePath,
        private ProfileDiscovery $profileDiscovery,
        ?BackstoryAssembler $assembler = null,
    ) {
        $this->assembler = $assembler ?? new BackstoryAssembler();
    }

    /**
     * @return array<string, mixed>
     */
    public function inspect(?string $profile = null): array
    {
        $profileName = is_string($profile) ? strtolower(trim($profile)) : null;
        if ($profileName === null || $profileName === '') {
            return $this->unprofiledPayload();
        }

        if (!$this->profileDiscovery->profileExists($profileName)) {
            throw new \InvalidArgumentException(sprintf('Unknown profile "%s".', $profile));
        }

        $profilePath = $this->profileDiscovery->getProfilePath($profileName);
        $sourceFolder = BackstoryManifest::backstoryDir($profilePath);
        $generatedBackstoryPath = rtrim($profilePath, '/') . '/backstory.md';
        $manifest = $this->assembler->getManifest($profilePath);
        $manifestFiles = $manifest !== null ? $manifest->files : [];
        $unsupportedFiles = $manifest !== null ? $manifest->unsupportedFiles : [];
        $errors = $manifest !== null ? $manifest->errors : [];

        $fileEntries = $this->buildFileEntries($profileName, $manifestFiles);
        $unsupportedEntries = $this->buildUnsupportedEntries($profileName, $unsupportedFiles);
        $folderEntries = $this->buildFolderEntries($fileEntries, $unsupportedEntries);
        $content = is_file($generatedBackstoryPath) ? file_get_contents($generatedBackstoryPath) : false;
        $lastModifiedAt = $this->latestModifiedAt($fileEntries, $unsupportedEntries);
        $totalSizeBytes = array_sum(array_column($fileEntries, 'size_bytes')) + array_sum(array_column($unsupportedEntries, 'size_bytes'));
        $successfulFileCount = count(array_filter(
            $fileEntries,
            static fn(array $entry): bool => $entry['status'] === 'ok',
        ));

        return [
            'profile' => $profileName,
            'available' => true,
            'reason' => null,
            'source_folder' => $this->relativeToWorkspace($sourceFolder),
            'generated_backstory_path' => $this->relativeToWorkspace($generatedBackstoryPath),
            'source_folder_exists' => is_dir($sourceFolder),
            'has_generated_backstory' => is_file($generatedBackstoryPath),
            'generated_at' => $manifest !== null && $manifest->generatedAt !== '' ? $manifest->generatedAt : null,
            'last_modified_at' => $lastModifiedAt,
            'content_hash' => $manifest !== null && $manifest->contentHash !== '' ? $manifest->contentHash : null,
            'needs_regeneration' => is_dir($sourceFolder) ? $this->assembler->needsRegeneration($profilePath) : false,
            'total_files' => $manifest !== null ? $manifest->totalFiles : 0,
            'supported_file_count' => $manifest?->supportedFilesCount() ?? 0,
            'successful_file_count' => $successfulFileCount,
            'unsupported_file_count' => $manifest?->unsupportedFileCount() ?? 0,
            'failed_file_count' => $manifest !== null ? $manifest->failedFiles : 0,
            'total_tokens' => $manifest !== null ? $manifest->totalTokens : 0,
            'total_size_bytes' => $totalSizeBytes,
            'content' => $content !== false ? $content : null,
            'files' => $fileEntries,
            'folders' => $folderEntries,
            'unsupported_files' => $unsupportedEntries,
            'errors' => $errors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unprofiledPayload(): array
    {
        return [
            'profile' => null,
            'available' => false,
            'reason' => 'no_active_profile',
            'source_folder' => null,
            'generated_backstory_path' => null,
            'source_folder_exists' => false,
            'has_generated_backstory' => false,
            'generated_at' => null,
            'last_modified_at' => null,
            'content_hash' => null,
            'needs_regeneration' => false,
            'total_files' => 0,
            'supported_file_count' => 0,
            'successful_file_count' => 0,
            'unsupported_file_count' => 0,
            'failed_file_count' => 0,
            'total_tokens' => 0,
            'total_size_bytes' => 0,
            'content' => null,
            'files' => [],
            'folders' => [],
            'unsupported_files' => [],
            'errors' => [],
        ];
    }

    /**
     * @param array<int, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    private function buildFileEntries(string $profileName, array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $relativePath = (string) ($file['relative_path'] ?? '');
            $entries[] = [
                'path' => $this->backstoryWorkspacePath($profileName, $relativePath),
                'relative_path' => $relativePath,
                'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                'token_estimate' => (int) ($file['token_estimate'] ?? 0),
                'status' => (string) ($file['status'] ?? 'unknown'),
                'error' => $file['error'] ?? null,
                'modified_at' => is_string($file['modified_at'] ?? null) && $file['modified_at'] !== '' ? $file['modified_at'] : null,
                'sha256' => (string) ($file['sha256'] ?? ''),
            ];
        }

        usort($entries, static fn(array $left, array $right): int => $left['relative_path'] <=> $right['relative_path']);

        return $entries;
    }

    /**
     * @param array<int, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    private function buildUnsupportedEntries(string $profileName, array $files): array
    {
        $entries = [];
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            $relativePath = (string) ($file['relative_path'] ?? '');
            $entries[] = [
                'path' => $this->backstoryWorkspacePath($profileName, $relativePath),
                'relative_path' => $relativePath,
                'extension' => (string) ($file['extension'] ?? ''),
                'reason' => (string) ($file['reason'] ?? ''),
                'size_bytes' => (int) ($file['size_bytes'] ?? 0),
                'modified_at' => is_string($file['modified_at'] ?? null) && $file['modified_at'] !== '' ? $file['modified_at'] : null,
                'sha256' => (string) ($file['sha256'] ?? ''),
            ];
        }

        usort($entries, static fn(array $left, array $right): int => $left['relative_path'] <=> $right['relative_path']);

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $fileEntries
     * @param array<int, array<string, mixed>> $unsupportedEntries
     * @return array<int, array<string, mixed>>
     */
    private function buildFolderEntries(array $fileEntries, array $unsupportedEntries): array
    {
        $folders = [];

        foreach ($fileEntries as $entry) {
            $folder = dirname((string) $entry['relative_path']);
            if ($folder === '.') {
                $folder = '';
            }

            if (!isset($folders[$folder])) {
                $folders[$folder] = [
                    'path' => $folder,
                    'total_tokens' => 0,
                    'total_size_bytes' => 0,
                    'file_count' => 0,
                    'unsupported_file_count' => 0,
                    'failed_file_count' => 0,
                    'last_modified_at' => null,
                    '_mtime_ts' => null,
                ];
            }

            $folders[$folder]['total_tokens'] += (int) $entry['token_estimate'];
            $folders[$folder]['total_size_bytes'] += (int) $entry['size_bytes'];
            $folders[$folder]['file_count']++;
            if ($entry['status'] !== 'ok') {
                $folders[$folder]['failed_file_count']++;
            }

            $this->applyFolderModifiedAt($folders[$folder], $entry['modified_at']);
        }

        foreach ($unsupportedEntries as $entry) {
            $folder = dirname((string) $entry['relative_path']);
            if ($folder === '.') {
                $folder = '';
            }

            if (!isset($folders[$folder])) {
                $folders[$folder] = [
                    'path' => $folder,
                    'total_tokens' => 0,
                    'total_size_bytes' => 0,
                    'file_count' => 0,
                    'unsupported_file_count' => 0,
                    'failed_file_count' => 0,
                    'last_modified_at' => null,
                    '_mtime_ts' => null,
                ];
            }

            $folders[$folder]['total_size_bytes'] += (int) $entry['size_bytes'];
            $folders[$folder]['unsupported_file_count']++;
            $this->applyFolderModifiedAt($folders[$folder], $entry['modified_at']);
        }

        foreach ($folders as &$folder) {
            unset($folder['_mtime_ts']);
        }
        unset($folder);

        $folderEntries = array_values($folders);
        usort($folderEntries, static function (array $left, array $right): int {
            $tokenComparison = $right['total_tokens'] <=> $left['total_tokens'];
            if ($tokenComparison !== 0) {
                return $tokenComparison;
            }

            return $left['path'] <=> $right['path'];
        });

        return $folderEntries;
    }

    /**
     * @param array<int, array<string, mixed>> $fileEntries
     * @param array<int, array<string, mixed>> $unsupportedEntries
     */
    private function latestModifiedAt(array $fileEntries, array $unsupportedEntries): ?string
    {
        $latest = null;

        foreach ([$fileEntries, $unsupportedEntries] as $entrySet) {
            foreach ($entrySet as $entry) {
                $timestamp = isset($entry['modified_at']) && is_string($entry['modified_at'])
                    ? strtotime($entry['modified_at'])
                    : false;

                if ($timestamp !== false && ($latest === null || $timestamp > $latest)) {
                    $latest = (int) $timestamp;
                }
            }
        }

        return $latest !== null ? date('c', $latest) : null;
    }

    /**
     * @param array<string, mixed> $folder
     */
    private function applyFolderModifiedAt(array &$folder, ?string $modifiedAt): void
    {
        if ($modifiedAt === null || $modifiedAt === '') {
            return;
        }

        $timestamp = strtotime($modifiedAt);
        if ($timestamp === false) {
            return;
        }

        if ($folder['_mtime_ts'] === null || $timestamp > $folder['_mtime_ts']) {
            $folder['_mtime_ts'] = (int) $timestamp;
            $folder['last_modified_at'] = date('c', (int) $timestamp);
        }
    }

    private function backstoryWorkspacePath(string $profileName, string $relativePath): string
    {
        $prefix = 'profiles/' . $profileName . '/backstory';

        return $relativePath !== '' ? $prefix . '/' . $relativePath : $prefix;
    }

    private function relativeToWorkspace(string $path): string
    {
        $normalizedWorkspacePath = rtrim(str_replace('\\', '/', $this->workspacePath), '/');
        $normalizedPath = str_replace('\\', '/', $path);

        if ($normalizedWorkspacePath !== '' && str_starts_with($normalizedPath, $normalizedWorkspacePath . '/')) {
            return substr($normalizedPath, strlen($normalizedWorkspacePath) + 1);
        }

        return $normalizedPath;
    }
}