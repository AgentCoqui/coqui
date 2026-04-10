<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\WatchJob;

use CoquiBot\Coqui\Contract\ScheduleFileDefinition;
use CoquiBot\Coqui\Contract\WatchJobInterface;
use CoquiBot\Coqui\Contract\WatchJobResult;
use CoquiBot\Coqui\Storage\ScheduleStore;

/**
 * Watches workspace/schedules/ for JSON files and syncs them into ScheduleStore.
 *
 * Tracks file mtimes to detect additions, modifications, and removals.
 * Uses ScheduleFileDefinition for parsing/validation and ScheduleStore::upsertFilesystem()
 * for idempotent persistence.
 */
final class ScheduleFileWatchJob implements WatchJobInterface
{
    /** @var array<string, int> Known file path → mtime map from last scan */
    private array $knownFiles = [];

    public function __construct(
        private readonly string $schedulesDir,
        private readonly ScheduleStore $scheduleStore,
    ) {}

    public function name(): string
    {
        return 'schedules';
    }

    public function scan(): WatchJobResult
    {
        $added = 0;
        $modified = 0;
        $removed = 0;
        $errors = [];

        // Scan directory for current .json files
        $currentFiles = $this->scanDirectory();

        // Detect additions and modifications
        foreach ($currentFiles as $path => $mtime) {
            if (!isset($this->knownFiles[$path])) {
                // New file
                try {
                    $this->syncFile($path);
                    $added++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s: %s', basename($path), $e->getMessage());
                }
            } elseif ($this->knownFiles[$path] !== $mtime) {
                // Modified file
                try {
                    $this->syncFile($path);
                    $modified++;
                } catch (\Throwable $e) {
                    $errors[] = sprintf('%s: %s', basename($path), $e->getMessage());
                }
            }
        }

        // Detect removals — files we knew about that are no longer on disk
        $removedPaths = array_diff_key($this->knownFiles, $currentFiles);
        if ($removedPaths !== []) {
            // Delete filesystem schedules whose source_path is no longer present
            $activePaths = array_keys($currentFiles);
            $removed = $this->scheduleStore->deleteRemovedFilesystemSchedules($activePaths);
        }

        // Update known state
        $this->knownFiles = $currentFiles;

        return new WatchJobResult($added, $modified, $removed, $errors);
    }

    /**
     * Parse a schedule file and upsert into the store.
     */
    private function syncFile(string $path): void
    {
        $def = ScheduleFileDefinition::fromFile($path);

        $this->scheduleStore->upsertFilesystem(
            name: $def->name,
            sourcePath: $def->sourcePath,
            scheduleExpression: $def->expression,
            prompt: $def->prompt,
            role: $def->role,
            maxIterations: $def->maxIterations,
            description: $def->description,
            timezone: $def->timezone,
            maxFailures: $def->maxFailures,
            enabled: $def->enabled,
            metadata: $def->metadata,
        );
    }

    /**
     * Scan the schedules directory for *.json files.
     *
     * @return array<string, int> Absolute path → mtime
     */
    private function scanDirectory(): array
    {
        if (!is_dir($this->schedulesDir)) {
            return [];
        }

        $files = [];

        $entries = scandir($this->schedulesDir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            $fullPath = $this->schedulesDir . '/' . $entry;
            if (!is_file($fullPath)) {
                continue;
            }

            $mtime = filemtime($fullPath);
            if ($mtime !== false) {
                $files[$fullPath] = $mtime;
            }
        }

        return $files;
    }
}
