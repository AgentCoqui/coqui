<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares an external directory mount accessible from the workspace.
 *
 * Mounts extend the agent's file access beyond the primary workspace.
 * Each mount has a unique alias (used as the symlink name under .workspace/mnt/),
 * an absolute path to the target directory, an access level (read-only or read-write),
 * and an optional description for the storage map.
 *
 * The primary workspace remains the default write target — mounts are only
 * written to when the user explicitly requests it.
 */
final readonly class MountDefinition
{
    /**
     * @param string  $path        Absolute path to the external directory
     * @param string  $alias       Unique short name used as symlink name (e.g. "projects")
     * @param string  $access      Access level: "ro" (read-only) or "rw" (read-write)
     * @param ?string $description Human-readable purpose for system prompt injection
     *
     * @throws \InvalidArgumentException If the path does not exist or is not a directory
     * @throws \InvalidArgumentException If the alias contains path separators or is empty
     * @throws \InvalidArgumentException If the access level is invalid
     */
    public function __construct(
        public string $path,
        public string $alias,
        public string $access = 'ro',
        public ?string $description = null,
    ) {
        if ($alias === '' || str_contains($alias, '/') || str_contains($alias, '\\')) {
            throw new \InvalidArgumentException(
                sprintf('Mount alias must be a non-empty string without path separators, got: "%s"', $alias),
            );
        }

        if (!in_array($access, ['ro', 'rw'], true)) {
            throw new \InvalidArgumentException(
                sprintf('Mount access must be "ro" or "rw", got: "%s"', $access),
            );
        }

        if (!is_dir($path)) {
            throw new \InvalidArgumentException(
                sprintf('Mount path does not exist or is not a directory: "%s"', $path),
            );
        }
    }

    public function isReadOnly(): bool
    {
        return $this->access === 'ro';
    }

    /**
     * Build from a config array (as read from openclaw.json).
     *
     * @param array{path: string, alias: string, access?: string, description?: string} $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            path: $config['path'],
            alias: $config['alias'],
            access: $config['access'] ?? 'ro',
            description: $config['description'] ?? null,
        );
    }
}
