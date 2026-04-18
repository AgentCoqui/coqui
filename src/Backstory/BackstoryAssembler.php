<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

use CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory;

/**
 * Orchestrates backstory generation from source files.
 *
 * Pipeline: discover → extract → assemble → write backstory.md → save manifest.
 *
 * Uses streaming writes to handle large backstories (150k+ tokens)
 * without holding the entire output in memory.
 */
final class BackstoryAssembler
{
    private readonly BackstoryFileDiscovery $discovery;
    private readonly ExtractorFactory $extractorFactory;

    public function __construct(
        ?BackstoryFileDiscovery $discovery = null,
        ?ExtractorFactory $extractorFactory = null,
    ) {
        $this->extractorFactory = $extractorFactory ?? new ExtractorFactory();
        $this->discovery = $discovery ?? new BackstoryFileDiscovery($this->extractorFactory);
    }

    /**
     * Generate backstory.md from the backstory/ source directory.
     */
    public function generate(string $profilePath): BackstoryResult
    {
        $startTime = hrtime(true);

        $backstoryDir = BackstoryManifest::backstoryDir($profilePath);
        $outputPath = rtrim($profilePath, '/') . '/backstory.md';
        $manifestPath = BackstoryManifest::manifestPath($profilePath);

        $entries = $this->discovery->discover($backstoryDir);

        if ($entries === []) {
            // No source files — clean up generated output if it existed
            if (is_file($outputPath) && is_file($manifestPath)) {
                unlink($outputPath);
                unlink($manifestPath);
            }

            return new BackstoryResult(
                totalFiles: 0,
                failedFiles: 0,
                totalTokens: 0,
                generationTimeMs: self::elapsedMs($startTime),
            );
        }

        // Streaming write — avoids holding entire backstory in memory
        $handle = fopen($outputPath, 'w');
        if ($handle === false) {
            return new BackstoryResult(
                totalFiles: count($entries),
                failedFiles: count($entries),
                totalTokens: 0,
                generationTimeMs: self::elapsedMs($startTime),
                errors: [['relative_path' => 'backstory.md', 'error' => 'Failed to open output file for writing']],
            );
        }

        try {
            return $this->writeBackstory($handle, $entries, $manifestPath, $startTime);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Check if regeneration is needed based on manifest hash comparison.
     */
    public function needsRegeneration(string $profilePath): bool
    {
        $backstoryDir = BackstoryManifest::backstoryDir($profilePath);
        if (!is_dir($backstoryDir)) {
            return false;
        }

        $outputPath = rtrim($profilePath, '/') . '/backstory.md';
        $manifestPath = BackstoryManifest::manifestPath($profilePath);

        // No manifest or no output → needs generation
        if (!is_file($manifestPath) || !is_file($outputPath)) {
            // Only if there are actually source files
            return $this->discovery->discover($backstoryDir) !== [];
        }

        $manifest = BackstoryManifest::load($manifestPath);
        $entries = $this->discovery->discover($backstoryDir);

        return $manifest->hasChanged($entries);
    }

    /**
     * Load the manifest for a given profile. Returns null if no manifest exists.
     */
    public function getManifest(string $profilePath): ?BackstoryManifest
    {
        $manifestPath = BackstoryManifest::manifestPath($profilePath);
        if (!is_file($manifestPath)) {
            return null;
        }

        return BackstoryManifest::load($manifestPath);
    }

    /**
     * Check if a profile has a backstory source directory.
     */
    public static function hasBackstoryDir(string $profilePath): bool
    {
        return is_dir(BackstoryManifest::backstoryDir($profilePath));
    }

    /**
     * @param resource $handle
     * @param list<BackstoryFileEntry> $entries
     */
    private function writeBackstory($handle, array $entries, string $manifestPath, int $startTime): BackstoryResult
    {
        fwrite($handle, "## Backstory\n\n");

        $manifestFiles = [];
        $manifestErrors = [];
        $totalTokens = 0;
        $failedCount = 0;
        $isFirst = true;

        foreach ($entries as $entry) {
            $extractor = $this->extractorFactory->get($entry->extension);
            if ($extractor === null) {
                // Should not happen since discovery filters by supported extensions
                $manifestErrors[] = [
                    'relative_path' => $entry->relativePath,
                    'error' => 'No extractor for extension: ' . $entry->extension,
                    'timestamp' => date('c'),
                ];
                $failedCount++;
                continue;
            }

            $result = $extractor->extract($entry->absolutePath);

            $fileHash = hash_file('sha256', $entry->absolutePath);
            $fileSize = filesize($entry->absolutePath);
            $fileMtime = filemtime($entry->absolutePath);

            if ($result->success && $result->content !== null) {
                if (!$isFirst) {
                    fwrite($handle, "\n\n");
                }
                $isFirst = false;

                fwrite($handle, '### File: /' . $entry->relativePath . "\n\n");
                fwrite($handle, $result->content);

                $totalTokens += $result->tokenEstimate;

                $manifestFiles[] = [
                    'relative_path' => $entry->relativePath,
                    'sha256' => $fileHash !== false ? $fileHash : '',
                    'size_bytes' => $fileSize !== false ? $fileSize : 0,
                    'modified_at' => $fileMtime !== false ? date('c', $fileMtime) : '',
                    'token_estimate' => $result->tokenEstimate,
                    'status' => 'ok',
                    'error' => null,
                ];
            } else {
                $failedCount++;

                $error = $result->error ?? 'Unknown extraction error';

                $manifestFiles[] = [
                    'relative_path' => $entry->relativePath,
                    'sha256' => $fileHash !== false ? $fileHash : '',
                    'size_bytes' => $fileSize !== false ? $fileSize : 0,
                    'modified_at' => $fileMtime !== false ? date('c', $fileMtime) : '',
                    'token_estimate' => 0,
                    'status' => 'failed',
                    'error' => $error,
                ];

                $manifestErrors[] = [
                    'relative_path' => $entry->relativePath,
                    'error' => $error,
                    'timestamp' => date('c'),
                ];
            }
        }

        // Ensure file ends with newline
        fwrite($handle, "\n");

        // Build and save manifest
        $manifest = new BackstoryManifest(
            generatedAt: date('c'),
            contentHash: BackstoryManifest::computeContentHash($entries),
            files: $manifestFiles,
            errors: $manifestErrors,
            totalTokens: $totalTokens,
            totalFiles: count($entries),
            failedFiles: $failedCount,
        );
        $manifest->save($manifestPath);

        $resultErrors = array_map(
            static fn(array $e): array => ['relative_path' => $e['relative_path'], 'error' => $e['error']],
            $manifestErrors,
        );

        return new BackstoryResult(
            totalFiles: count($entries),
            failedFiles: $failedCount,
            totalTokens: $totalTokens,
            generationTimeMs: self::elapsedMs($startTime),
            errors: $resultErrors,
        );
    }

    private static function elapsedMs(int $startNs): float
    {
        return (hrtime(true) - $startNs) / 1_000_000;
    }
}
