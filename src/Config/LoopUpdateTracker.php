<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Tracks content hashes of built-in loop definition files to detect updates.
 *
 * Manages workspace/data/loop-hashes.json which stores per-loop:
 * - builtinHash: SHA-256 of config/loops/{name}.json at last seed/update
 * - seededHash: SHA-256 of what was written to workspace/loops/{name}.json
 *
 * Enables auto-update of unmodified loop definitions and notification for modified ones.
 * Mirrors the RoleUpdateTracker pattern.
 */
final class LoopUpdateTracker
{
    /** @var array<string, array{builtinHash: string, seededHash: string}> */
    private array $hashes;

    private readonly string $hashFilePath;

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $builtinLoopsDir,
    ) {
        $this->hashFilePath = PathHelper::trimTrailingSlash($workspacePath) . '/data/loop-hashes.json';
        $this->hashes = $this->load();
    }

    /**
     * Record hashes for a loop after seeding or updating.
     */
    public function recordHash(string $loopName, string $builtinHash, string $seededHash): void
    {
        $this->hashes[$loopName] = [
            'builtinHash' => $builtinHash,
            'seededHash' => $seededHash,
        ];
        $this->save();
    }

    /**
     * Check all built-in loops for available updates.
     *
     * @return list<LoopUpdateInfo>
     */
    public function checkForUpdates(): array
    {
        $updates = [];

        if (!is_dir($this->builtinLoopsDir)) {
            return [];
        }

        $entries = scandir($this->builtinLoopsDir);
        if ($entries === false) {
            return [];
        }

        $workspaceLoopsDir = PathHelper::trimTrailingSlash($this->workspacePath) . '/loops';

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
                continue;
            }

            $builtinPath = $this->builtinLoopsDir . '/' . $entry;
            $workspacePath = $workspaceLoopsDir . '/' . $entry;
            $loopName = basename($entry, '.json');

            if (!file_exists($workspacePath)) {
                continue;
            }

            $currentBuiltinHash = $this->hashFile($builtinPath);
            $currentWorkspaceHash = $this->hashFile($workspacePath);
            $stored = $this->hashes[$loopName] ?? null;

            // No stored hashes — first run with tracker; compute retrospectively
            if ($stored === null) {
                $this->recordHash($loopName, $currentBuiltinHash, $currentWorkspaceHash);
                continue;
            }

            // Built-in hasn't changed since last seed
            if ($stored['builtinHash'] === $currentBuiltinHash) {
                continue;
            }

            // Check if the user has modified their workspace copy
            $isUserModified = $stored['seededHash'] !== $currentWorkspaceHash;

            $updates[] = new LoopUpdateInfo(
                loopName: $loopName,
                hasBuiltinUpdate: true,
                isUserModified: $isUserModified,
            );
        }

        return $updates;
    }

    /**
     * Apply a built-in update to a workspace loop definition.
     *
     * Creates a backup of the workspace copy, then overwrites it with the
     * built-in version and updates the stored hashes.
     */
    public function applyUpdate(string $loopName): bool
    {
        $builtinPath = $this->builtinLoopsDir . '/' . $loopName . '.json';
        $workspacePath = PathHelper::trimTrailingSlash($this->workspacePath) . '/loops/' . $loopName . '.json';

        if (!file_exists($builtinPath) || !file_exists($workspacePath)) {
            return false;
        }

        // Create backup
        $backupsDir = PathHelper::trimTrailingSlash($this->workspacePath) . '/backups/loops';
        if (!is_dir($backupsDir)) {
            mkdir($backupsDir, CoquiDefaults::DIRECTORY_MODE, true);
        }
        $timestamp = date('Ymd-His');
        $backupName = sprintf('%s-%s.json', $loopName, $timestamp);
        copy($workspacePath, $backupsDir . '/' . $backupName);

        // Copy built-in to workspace
        copy($builtinPath, $workspacePath);

        // Update hashes
        $builtinHash = $this->hashFile($builtinPath);
        $this->recordHash($loopName, $builtinHash, $builtinHash);

        return true;
    }

    /**
     * Auto-update unmodified loops and return loops that need user notification.
     *
     * @param LoopDiscovery $loopDiscovery Used to invalidate cache after updates.
     * @return list<LoopUpdateInfo> Loops with updates that require user action (modified by user)
     */
    public function autoUpdateAndNotify(LoopDiscovery $loopDiscovery): array
    {
        $allUpdates = $this->checkForUpdates();
        $needsNotification = [];
        $anyUpdated = false;

        foreach ($allUpdates as $update) {
            if (!$update->isUserModified) {
                // Unmodified — auto-update silently
                if ($this->applyUpdate($update->loopName)) {
                    $anyUpdated = true;
                }
            } else {
                // Modified — needs user review
                $needsNotification[] = $update;
            }
        }

        if ($anyUpdated) {
            $loopDiscovery->invalidateCache();
        }

        return $needsNotification;
    }

    /**
     * Compute SHA-256 hash of a file's contents.
     */
    public function hashFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return '';
        }

        return hash('sha256', $content);
    }

    /**
     * @return array<string, array{builtinHash: string, seededHash: string}>
     */
    private function load(): array
    {
        if (!file_exists($this->hashFilePath)) {
            return [];
        }

        $content = file_get_contents($this->hashFilePath);
        if ($content === false) {
            return [];
        }

        $decoded = json_decode($content, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function save(): void
    {
        $dir = dirname($this->hashFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, CoquiDefaults::DIRECTORY_MODE, true);
        }

        file_put_contents(
            $this->hashFilePath,
            json_encode($this->hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
