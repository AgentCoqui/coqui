<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\RoleProperties;
use CoquiBot\Coqui\Exception\RoleNotFoundException;
use CoquiBot\Coqui\Exception\RoleParseException;

/**
 * Boot-time role discovery.
 *
 * Scans .workspace/roles/ for .md files with YAML frontmatter, parses
 * metadata only (progressive disclosure). Provides name resolution, body
 * loading for activation, and cache invalidation for CRUD operations.
 *
 * Seeds built-in roles from config/roles/ on first boot.
 */
final class RoleDiscovery
{
    private readonly string $rolesDir;
    private readonly string $builtinRolesDir;
    private readonly string $backupsDir;
    private readonly RoleParser $parser;

    /** @var array<string, RoleProperties>|null Cached discovery results keyed by name */
    private ?array $discovered = null;

    public function __construct(
        string $workspacePath,
        ?string $projectRoot = null,
    ) {
        $this->rolesDir = rtrim($workspacePath, '/') . '/roles';
        $this->backupsDir = rtrim($workspacePath, '/') . '/backups/roles';
        $this->builtinRolesDir = ($projectRoot !== null ? rtrim($projectRoot, '/') : dirname(__DIR__, 2)) . '/config/roles';
        $this->parser = new RoleParser();
    }

    /**
     * Scan the roles directory and return all valid roles.
     *
     * Only reads frontmatter (progressive disclosure). Silently skips
     * files that cannot be parsed.
     *
     * @return array<string, RoleProperties>
     */
    public function discoverAll(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];

        if (!is_dir($this->rolesDir)) {
            return $this->discovered;
        }

        $entries = scandir($this->rolesDir);
        if ($entries === false) {
            return $this->discovered;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_ends_with($entry, '.md')) {
                continue;
            }

            $filePath = $this->rolesDir . '/' . $entry;
            if (!is_file($filePath)) {
                continue;
            }

            try {
                $properties = $this->parser->readProperties($filePath);
                $this->discovered[$properties->name] = $properties;
            } catch (RoleParseException) {
                // Not a valid role — silently skip
                continue;
            }
        }

        return $this->discovered;
    }

    /**
     * Resolve a role name to its properties.
     *
     * @throws RoleNotFoundException If the role name is not found.
     */
    public function getRole(string $name): RoleProperties
    {
        $roles = $this->discoverAll();

        if (isset($roles[$name])) {
            return $roles[$name];
        }

        throw RoleNotFoundException::forName($name);
    }

    /**
     * Return the full markdown body (instructions) for a named role.
     *
     * @throws RoleNotFoundException If the role name is not found.
     */
    public function readInstructions(string $name): string
    {
        $role = $this->getRole($name);

        return $this->parser->readBody($role->path);
    }

    /**
     * Check if a role exists.
     */
    public function roleExists(string $name): bool
    {
        try {
            $this->getRole($name);
            return true;
        } catch (RoleNotFoundException) {
            return false;
        }
    }

    /**
     * Return all available role names.
     *
     * @return string[]
     */
    public function availableRoles(): array
    {
        return array_keys($this->discoverAll());
    }

    /**
     * Return the absolute path to the roles directory.
     */
    public function rolesDir(): string
    {
        return $this->rolesDir;
    }

    /**
     * Create the roles directory if it doesn't exist.
     */
    public function ensureRolesDir(): void
    {
        if (!is_dir($this->rolesDir)) {
            mkdir($this->rolesDir, 0755, true);
        }
    }

    /**
     * Seed built-in roles from config/roles/ to .workspace/roles/.
     *
     * Only copies roles that don't already exist in the workspace.
     * Never overwrites user-edited files.
     */
    public function seedBuiltinRoles(): void
    {
        $this->ensureRolesDir();

        if (!is_dir($this->builtinRolesDir)) {
            return;
        }

        $entries = scandir($this->builtinRolesDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_ends_with($entry, '.md')) {
                continue;
            }

            $source = $this->builtinRolesDir . '/' . $entry;
            $target = $this->rolesDir . '/' . $entry;

            // Only copy if target doesn't exist — never overwrite user edits
            if (!file_exists($target)) {
                copy($source, $target);
            }
        }

        // Invalidate cache so seeded roles are visible
        $this->invalidateCache();
    }

    /**
     * Create a new role file.
     *
     * @param array<string, mixed>|RoleProperties $properties Frontmatter data or value object.
     *
     * @throws \RuntimeException If the role already exists.
     * @throws RoleParseException If properties are invalid.
     */
    public function createRole(array|RoleProperties $properties, string $instructions): RoleProperties
    {
        $this->ensureRolesDir();

        $props = $properties instanceof RoleProperties
            ? $properties
            : $this->buildPropertiesFromArray($properties);

        $filename = $props->name . '.md';
        $filePath = $this->rolesDir . '/' . $filename;

        if (file_exists($filePath)) {
            throw new \RuntimeException(sprintf('Role "%s" already exists.', $props->name));
        }

        $content = $this->parser->buildRoleFile($props, $instructions);
        file_put_contents($filePath, $content);
        $this->invalidateCache();

        // Re-read from disk to get the canonical properties with correct path
        return $this->getRole($props->name);
    }

    /**
     * Update an existing role file.
     *
     * Creates a backup before overwriting.
     *
     * @param array<string, mixed>|RoleProperties $properties Frontmatter data or value object.
     *
     * @throws RoleNotFoundException If the role doesn't exist.
     * @throws RoleParseException If properties are invalid.
     */
    public function updateRole(string $name, array|RoleProperties $properties, string $instructions): RoleProperties
    {
        $existing = $this->getRole($name);

        $props = $properties instanceof RoleProperties
            ? $properties
            : $this->buildPropertiesFromArray($properties, $existing->path);

        // Create backup
        $this->backupRole($existing);

        // Write updated file
        $content = $this->parser->buildRoleFile($props, $instructions);
        file_put_contents($existing->path, $content);
        $this->invalidateCache();

        // Re-read from disk to get the canonical properties
        return $this->getRole($name);
    }

    /**
     * Delete a role file.
     *
     * @throws RoleNotFoundException If the role doesn't exist.
     * @throws \RuntimeException If the role is built-in.
     */
    public function deleteRole(string $name): void
    {
        $role = $this->getRole($name);

        if ($role->isBuiltin) {
            throw new \RuntimeException(sprintf('Cannot delete built-in role "%s".', $name));
        }

        // Create backup before deletion
        $this->backupRole($role);

        unlink($role->path);
        $this->invalidateCache();
    }

    /**
     * Create a backup of a role file before modification.
     */
    private function backupRole(RoleProperties $role): void
    {
        if (!is_dir($this->backupsDir)) {
            mkdir($this->backupsDir, 0755, true);
        }

        $timestamp = date('Ymd-His');
        $backupName = sprintf('%s-v%d-%s.md', $role->name, $role->version, $timestamp);
        $backupPath = $this->backupsDir . '/' . $backupName;

        if (file_exists($role->path)) {
            copy($role->path, $backupPath);
        }
    }

    /**
     * Clear cached discovery results.
     */
    public function invalidateCache(): void
    {
        $this->discovered = null;
    }

    /**
     * Build a RoleProperties value object from a frontmatter array.
     *
     * @param array<string, mixed> $data Frontmatter key-value pairs.
     * @param string $path File path (empty string for new roles).
     */
    private function buildPropertiesFromArray(array $data, string $path = ''): RoleProperties
    {
        return new RoleProperties(
            name: (string) ($data['name'] ?? ''),
            displayName: (string) ($data['display_name'] ?? ''),
            description: (string) ($data['description'] ?? ''),
            path: $path,
            version: isset($data['version']) ? (int) $data['version'] : 1,
            accessLevel: (string) ($data['access_level'] ?? 'readonly'),
            isBuiltin: (bool) ($data['is_builtin'] ?? false),
            model: isset($data['model']) && is_string($data['model']) && $data['model'] !== '' ? $data['model'] : null,
            titleModel: isset($data['title_model']) && is_string($data['title_model']) && $data['title_model'] !== '' ? $data['title_model'] : null,
        );
    }
}
