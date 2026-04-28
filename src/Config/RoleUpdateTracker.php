<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

/**
 * Tracks content hashes of built-in role files to detect updates.
 *
 * Manages workspace/data/role-hashes.json which stores per-role:
 * - builtinHash: SHA-256 of config/roles/{name}.md at last seed/update
 * - seededHash: SHA-256 of what was written to workspace/roles/{name}.md
 *
 * Enables auto-update of unmodified roles and notification for modified ones.
 */
final class RoleUpdateTracker
{
    /** @var array<string, array{builtinHash: string, seededHash: string}> */
    private array $hashes;

    private readonly string $hashFilePath;

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $builtinRolesDir,
    ) {
        $this->hashFilePath = PathHelper::trimTrailingSlash($workspacePath) . '/data/role-hashes.json';
        $this->hashes = $this->load();
    }

    /**
     * Record hashes for a role after seeding or updating.
     */
    public function recordHash(string $roleName, string $builtinHash, string $seededHash): void
    {
        $this->hashes[$roleName] = [
            'builtinHash' => $builtinHash,
            'seededHash' => $seededHash,
        ];
        $this->save();
    }

    /**
     * Check all built-in roles for available updates.
     *
     * @return list<RoleUpdateInfo>
     */
    public function checkForUpdates(RoleDiscovery $roleDiscovery): array
    {
        $updates = [];
        $builtinDir = $this->builtinRolesDir;

        if (!is_dir($builtinDir)) {
            return [];
        }

        $entries = scandir($builtinDir);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }

            $builtinPath = $builtinDir . '/' . $entry;
            $workspacePath = PathHelper::trimTrailingSlash($this->workspacePath) . '/roles/' . $entry;
            $roleName = basename($entry, '.md');

            if (!file_exists($workspacePath)) {
                continue;
            }

            $currentBuiltinHash = $this->hashFile($builtinPath);
            $currentWorkspaceHash = $this->hashFile($workspacePath);
            $stored = $this->hashes[$roleName] ?? null;

            // No stored hashes — first run with tracker; compute retrospectively
            if ($stored === null) {
                $this->recordHash($roleName, $currentBuiltinHash, $currentWorkspaceHash);
                continue;
            }

            // Built-in hasn't changed since last seed
            if ($stored['builtinHash'] === $currentBuiltinHash) {
                continue;
            }

            // Check if the user has modified their workspace copy
            $isUserModified = $stored['seededHash'] !== $currentWorkspaceHash;

            // Check ignore_updates flag
            $ignoreUpdates = false;
            try {
                $properties = $roleDiscovery->getRole($roleName);
                $ignoreUpdates = $properties->ignoreUpdates;
            } catch (\Throwable) {
                // Role may not be discoverable — treat as not-ignored
            }

            $updates[] = new RoleUpdateInfo(
                roleName: $roleName,
                hasBuiltinUpdate: true,
                isUserModified: $isUserModified,
                ignoreUpdates: $ignoreUpdates,
            );
        }

        return $updates;
    }

    /**
     * Apply a built-in update to a workspace role.
     *
     * Creates a backup of the workspace copy, then overwrites it with the
     * built-in version and updates the stored hashes.
     */
    public function applyUpdate(string $roleName, RoleDiscovery $roleDiscovery): bool
    {
        $builtinPath = $this->builtinRolesDir . '/' . $roleName . '.md';
        $workspacePath = PathHelper::trimTrailingSlash($this->workspacePath) . '/roles/' . $roleName . '.md';

        if (!file_exists($builtinPath) || !file_exists($workspacePath)) {
            return false;
        }

        // Create backup via RoleDiscovery (preserves existing backup pattern)
        try {
            $role = $roleDiscovery->getRole($roleName);
            // Backup is handled internally by updateRole, but we're doing a direct copy
            // so we backup manually
            $backupsDir = PathHelper::trimTrailingSlash($this->workspacePath) . '/backups/roles';
            if (!is_dir($backupsDir)) {
                mkdir($backupsDir, 0755, true);
            }
            $timestamp = date('Ymd-His');
            $backupName = sprintf('%s-v%d-%s.md', $role->name, $role->version, $timestamp);
            copy($workspacePath, $backupsDir . '/' . $backupName);
        } catch (\Throwable) {
            // Best-effort backup
        }

        // Copy built-in to workspace
        copy($builtinPath, $workspacePath);

        // Update hashes
        $builtinHash = $this->hashFile($builtinPath);
        $this->recordHash($roleName, $builtinHash, $builtinHash);

        // Invalidate role cache
        $roleDiscovery->invalidateCache();

        return true;
    }

    /**
     * Auto-update unmodified roles and return roles that need user notification.
     *
     * @return list<RoleUpdateInfo> Roles with updates that require user action (modified + not ignored)
     */
    public function autoUpdateAndNotify(RoleDiscovery $roleDiscovery): array
    {
        $allUpdates = $this->checkForUpdates($roleDiscovery);
        $needsNotification = [];

        foreach ($allUpdates as $update) {
            if ($update->ignoreUpdates) {
                continue;
            }

            if (!$update->isUserModified) {
                // Unmodified — auto-update silently
                $this->applyUpdate($update->roleName, $roleDiscovery);
            } else {
                // Modified — needs user review
                $needsNotification[] = $update;
            }
        }

        return $needsNotification;
    }

    /**
     * Get stored hashes for a specific role.
     *
     * @return array{builtinHash: string, seededHash: string}|null
     */
    public function getStoredHashes(string $roleName): ?array
    {
        return $this->hashes[$roleName] ?? null;
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
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->hashFilePath,
            json_encode($this->hashes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }
}
