<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\MountDefinition;

/**
 * Manages external directory mounts for the workspace.
 *
 * Creates symlinks under workspace/mnt/{alias} pointing to each mount's
 * real path, maintains the allowedPaths array for FilesystemToolkit, and
 * generates a storage map for system prompt injection.
 *
 * Mounts extend file access beyond the primary workspace while maintaining
 * workspace isolation as the core default. The agent writes to mounted
 * directories only when explicitly instructed.
 */
final class MountManager
{
    private readonly string $mountDir;

    /** @var MountDefinition[] */
    private readonly array $mounts;

    /**
     * @param string            $workspacePath Absolute path to the primary workspace
     * @param MountDefinition[] $mounts        Declared mount definitions from config
     */
    public function __construct(
        string $workspacePath,
        array $mounts = [],
    ) {
        $this->mountDir = PathHelper::trimTrailingSlash($workspacePath) . '/mnt';
        $this->mounts = array_values($mounts);
    }

    /**
     * Initialize the mount directory and create/update symlinks.
     *
     * Creates workspace/mnt/ if it doesn't exist, creates symlinks for
     * each declared mount, and removes stale symlinks for mounts no longer
     * declared in config.
     */
    public function initialize(): void
    {
        if ($this->mounts === []) {
            return;
        }

        if (!is_dir($this->mountDir)) {
            mkdir($this->mountDir, 0755, true);
        }

        // Track declared aliases to detect stale symlinks
        $declaredAliases = [];

        foreach ($this->mounts as $mount) {
            $declaredAliases[] = $mount->alias;
            $linkPath = $this->mountDir . '/' . $mount->alias;
            $targetPath = $mount->path;

            // Symlink already exists and points to correct target
            if (is_link($linkPath)) {
                $currentTarget = readlink($linkPath);
                if ($currentTarget !== false && realpath($currentTarget) === realpath($targetPath)) {
                    continue;
                }
                // Stale symlink pointing to wrong target — remove and recreate
                $this->removeSymlink($linkPath);
            }

            // Path exists but isn't a symlink (user created a real dir) — skip
            if (file_exists($linkPath)) {
                continue;
            }

            symlink($targetPath, $linkPath);
        }

        // Remove stale symlinks for mounts no longer declared
        $this->cleanupStaleLinks($declaredAliases);
    }

    /**
     * Build the allowedPaths array for FilesystemToolkit.
     *
     * Each entry contains the realpath of the mount target and a readOnly flag.
     * FilesystemToolkit uses this to permit symlink-resolved paths that would
     * otherwise be rejected by its sandbox check.
     *
     * @return array<int, array{realPath: string, readOnly: bool}>
     */
    public function allowedPaths(): array
    {
        $paths = [];

        foreach ($this->mounts as $mount) {
            $realPath = realpath($mount->path);
            if ($realPath === false) {
                continue;
            }

            $paths[] = [
                'realPath' => $realPath,
                'readOnly' => $mount->isReadOnly(),
            ];
        }

        return $paths;
    }

    /**
     * Build the allowedPaths array with all mounts forced to read-only.
     *
     * Used by child agents with readonly access level — they should never
     * write to mounts regardless of the mount's declared access level.
     *
     * @return array<int, array{realPath: string, readOnly: bool}>
     */
    public function allowedPathsReadOnly(): array
    {
        $paths = [];

        foreach ($this->mounts as $mount) {
            $realPath = realpath($mount->path);
            if ($realPath === false) {
                continue;
            }

            $paths[] = [
                'realPath' => $realPath,
                'readOnly' => true,
            ];
        }

        return $paths;
    }

    /**
     * Build a markdown-formatted storage map for system prompt injection.
     *
     * Shows the primary workspace, followed by each mount with alias,
     * access level, real path, and description.
     */
    public function storageMap(): string
    {
        if ($this->mounts === []) {
            return '';
        }

        $lines = [
            '### Mounted Directories',
            '',
            'Additional directories are available under `mnt/` in the workspace:',
            '',
            '| Mount Path | Real Path | Access | Description |',
            '|-----------|-----------|--------|-------------|',
        ];

        foreach ($this->mounts as $mount) {
            $access = $mount->isReadOnly() ? 'Read-only' : 'Read/Write';
            $description = $mount->description ?? '—';
            $lines[] = "| `mnt/{$mount->alias}/` | `{$mount->path}` | {$access} | {$description} |";
        }

        $lines[] = '';
        $lines[] = '**Write to mounted directories only when explicitly instructed to work in that location.**';
        $lines[] = 'Your default write target is always the primary workspace root.';
        $lines[] = 'Access mounted files using `mnt/{alias}/path/to/file` relative paths.';

        return implode("\n", $lines);
    }

    /**
     * Return additional paths for PhpExecuteTool's open_basedir.
     *
     * @return string[]
     */
    public function openBasedirPaths(): array
    {
        $paths = [];

        foreach ($this->mounts as $mount) {
            $realPath = realpath($mount->path);
            if ($realPath !== false) {
                $paths[] = $realPath;
            }
        }

        return $paths;
    }

    /**
     * Check if any mounts are configured.
     */
    public function hasMounts(): bool
    {
        return $this->mounts !== [];
    }

    /**
     * @return MountDefinition[]
     */
    public function mounts(): array
    {
        return $this->mounts;
    }

    /**
     * Remove symlinks in the mount directory that are no longer declared.
     *
     * @param string[] $declaredAliases
     */
    private function cleanupStaleLinks(array $declaredAliases): void
    {
        $entries = scandir($this->mountDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $linkPath = $this->mountDir . '/' . $entry;

            // Only remove symlinks — never delete real files/dirs
            if (is_link($linkPath) && !in_array($entry, $declaredAliases, true)) {
                $this->removeSymlink($linkPath);
            }
        }
    }

    /**
     * Remove a symlink, handling Windows directory symlinks correctly.
     *
     * On Windows, directory symlinks must be removed with rmdir() rather
     * than unlink(), which only works for file symlinks.
     */
    private function removeSymlink(string $linkPath): void
    {
        if (PHP_OS_FAMILY === 'Windows' && is_dir($linkPath)) {
            rmdir($linkPath);
        } else {
            unlink($linkPath);
        }
    }
}
