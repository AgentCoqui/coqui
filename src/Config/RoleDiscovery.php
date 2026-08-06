<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\RoleProperties;
use CoquiBot\Coqui\Exception\RoleNotFoundException;
use CoquiBot\Coqui\Exception\RoleParseException;

/**
 * Boot-time role discovery.
 *
 * Scans workspace/roles/ for .md files with YAML frontmatter, parses
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

    /** @var array<string, array<string, RoleProperties>> Cached persona role results keyed by persona path */
    private array $personaDiscovered = [];

    public function __construct(
        string $workspacePath,
        ?string $projectRoot = null,
    ) {
        $this->rolesDir = PathHelper::trimTrailingSlash($workspacePath) . '/roles';
        $this->backupsDir = PathHelper::trimTrailingSlash($workspacePath) . '/backups/roles';
        $this->builtinRolesDir = ($projectRoot !== null ? PathHelper::trimTrailingSlash($projectRoot) : dirname(__DIR__, 2)) . '/config/roles';
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
    public function getRole(string $name, ?string $personaPath = null): RoleProperties
    {
        if ($personaPath !== null) {
            $personaRole = $this->getPersonaRole($name, $personaPath);
            if ($personaRole !== null) {
                return $personaRole;
            }
        }

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
    public function readInstructions(string $name, ?string $personaPath = null): string
    {
        $role = $this->getRole($name, $personaPath);

        return $this->parser->readBody($role->path);
    }

    /**
     * Check if a role exists.
     */
    public function roleExists(string $name, ?string $personaPath = null): bool
    {
        try {
            $this->getRole($name, $personaPath);
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
    public function availableRoles(?string $personaPath = null): array
    {
        $roles = array_keys($this->discoverAll());

        if ($personaPath !== null) {
            $roles = array_unique([...$roles, ...array_keys($this->discoverPersonaRoles($personaPath))]);
            sort($roles);
        }

        return $roles;
    }

    public function getPersonaRole(string $name, string $personaPath): ?RoleProperties
    {
        $roles = $this->discoverPersonaRoles($personaPath);

        return $roles[$name] ?? null;
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
            mkdir($this->rolesDir, CoquiDefaults::DIRECTORY_MODE, true);
        }
    }

    /**
     * Seed built-in roles from config/roles/ to workspace/roles/.
     *
     * Only copies roles that don't already exist in the workspace.
     * Never overwrites user-edited files.
     *
     * @param RoleUpdateTracker|null $tracker Optional tracker to record hashes for seeded roles.
     */
    public function seedBuiltinRoles(?RoleUpdateTracker $tracker = null): void
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

                // Record hash for newly seeded role
                if ($tracker !== null) {
                    $roleName = basename($entry, '.md');
                    $hash = $tracker->hashFile($source);
                    $tracker->recordHash($roleName, $hash, $hash);
                }
            }
        }

        // Invalidate cache so seeded roles are visible
        $this->invalidateCache();
    }

    /**
     * Seed roles from toolkit package directories into the workspace.
     *
     * Copies .md role files from each package path to workspace/roles/.
     * Never overwrites existing files — workspace always wins.
     * Package roles are not tracked by RoleUpdateTracker (no auto-update).
     *
     * @param string[] $packageRolePaths Absolute paths to package role directories.
     */
    public function seedPackageRoles(array $packageRolePaths): void
    {
        $this->ensureRolesDir();

        foreach ($packageRolePaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $entries = scandir($dir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                    continue;
                }

                $source = $dir . '/' . $entry;
                $target = $this->rolesDir . '/' . $entry;

                if (!file_exists($target)) {
                    copy($source, $target);
                }
            }
        }

        $this->invalidateCache();
    }

    /**
     * Reserved role names that cannot be created via CRUD.
     * These are system-managed roles synthesized by RoleResolver.
     */
    private const array RESERVED_NAMES = ['orchestrator'];

    /**
     * Check if a role name is reserved (system-managed).
     */
    public function isReservedName(string $name): bool
    {
        return in_array($name, self::RESERVED_NAMES, true);
    }

    /**
     * Whether a role name is a valid slug (delegates to the parser's rules).
     * This is also the path-traversal guard: names become filenames.
     */
    public function isValidRoleName(string $name): bool
    {
        return $this->parser->validateName($name) === [];
    }

    /**
     * Persist an API-authored role to workspace/roles/{name}.md.
     *
     * Mirrors LoopDiscovery::saveDefinition: the filename is authoritative for
     * the name, server-owned tokens (version/id/timestamps) are stripped and
     * never written (the version lives in ObjectVersionStore), and the on-disk
     * file is the authoring source only. The authoring body is the strict
     * role.put.json shape; display_name/description are synthesized because that
     * shape does not carry them, so the file re-parses cleanly.
     *
     * @param array<string, mixed> $body role.put.json authoring body
     * @throws \InvalidArgumentException on an invalid name/access_level or a reserved name
     * @throws \RuntimeException on a write failure
     */
    public function saveRole(string $name, array $body): void
    {
        if (!$this->isValidRoleName($name)) {
            throw new \InvalidArgumentException(sprintf('Invalid role name: "%s"', $name));
        }

        if ($this->isReservedName($name)) {
            throw new \InvalidArgumentException(sprintf('Role name "%s" is reserved and cannot be written.', $name));
        }

        // Server-owned tokens are never persisted into the authoring file.
        unset($body['version'], $body['id'], $body['created_at'], $body['updated_at']);

        $accessLevel = is_string($body['access_level'] ?? null) ? $body['access_level'] : '';
        if (!$this->parser->isValidAccessLevel($accessLevel)) {
            throw new \InvalidArgumentException(sprintf('Invalid access_level: "%s"', $accessLevel));
        }

        // Coqui stores toolkits as a comma-separated string; the wire shape is a
        // string list. Fold it down on write and RoleProducer unfolds it on read.
        $toolkits = null;
        if (isset($body['toolkits']) && is_array($body['toolkits'])) {
            $names = array_values(array_filter(
                array_map(static fn($t): string => is_string($t) ? trim($t) : '', $body['toolkits']),
                static fn(string $t): bool => $t !== '',
            ));
            $toolkits = $names === [] ? null : implode(',', $names);
        }

        $maxIterations = isset($body['max_iterations']) && is_numeric($body['max_iterations'])
            ? (int) $body['max_iterations']
            : null;

        $model = isset($body['model']) && is_string($body['model']) && $body['model'] !== ''
            ? $body['model']
            : null;

        $instructions = isset($body['instructions']) && is_string($body['instructions'])
            ? $body['instructions']
            : '';

        $displayName = str_replace(['-', '_'], ' ', $name);
        $displayName = ucwords($displayName);

        $props = new RoleProperties(
            name: $name,
            displayName: $displayName,
            description: sprintf('%s role.', $displayName),
            path: '',
            accessLevel: $accessLevel,
            model: $model,
            toolkits: $toolkits,
            maxIterations: $maxIterations,
        );

        $content = $this->parser->buildRoleFile($props, $instructions, includeVersion: false);

        $this->ensureRolesDir();
        $filePath = $this->rolesDir . '/' . $name . '.md';
        if (file_put_contents($filePath, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write role "%s"', $name));
        }

        $this->invalidateCache();
    }

    /**
     * Create a new role file.
     *
     * @param array<string, mixed>|RoleProperties $properties Frontmatter data or value object.
     *
     * @throws \RuntimeException If the role already exists or name is reserved.
     * @throws RoleParseException If properties are invalid.
     */
    public function createRole(array|RoleProperties $properties, string $instructions): RoleProperties
    {
        $this->ensureRolesDir();

        $props = $properties instanceof RoleProperties
            ? $properties
            : $this->buildPropertiesFromArray($properties);

        if ($this->isReservedName($props->name)) {
            throw new \RuntimeException(sprintf('Role name "%s" is reserved and cannot be created.', $props->name));
        }

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
            mkdir($this->backupsDir, CoquiDefaults::DIRECTORY_MODE, true);
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
        $this->personaDiscovered = [];
    }

    /**
     * @return array<string, RoleProperties>
     */
    private function discoverPersonaRoles(string $personaPath): array
    {
        if (isset($this->personaDiscovered[$personaPath])) {
            return $this->personaDiscovered[$personaPath];
        }

        $rolesDir = rtrim($personaPath, '/') . '/roles';
        $this->personaDiscovered[$personaPath] = [];

        if (!is_dir($rolesDir)) {
            return $this->personaDiscovered[$personaPath];
        }

        $entries = scandir($rolesDir);
        if ($entries === false) {
            return $this->personaDiscovered[$personaPath];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }

            $filePath = $rolesDir . '/' . $entry;
            if (!is_file($filePath)) {
                continue;
            }

            try {
                $properties = $this->parser->readProperties($filePath);
                $this->personaDiscovered[$personaPath][$properties->name] = $properties;
            } catch (RoleParseException) {
                continue;
            }
        }

        return $this->personaDiscovered[$personaPath];
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
            category: isset($data['category']) && is_string($data['category']) && $data['category'] !== ''
                ? $data['category']
                : 'general',
            isBuiltin: (bool) ($data['is_builtin'] ?? false),
            isTemplate: (bool) ($data['is_template'] ?? false),
            ignoreUpdates: (bool) ($data['ignore_updates'] ?? false),
            model: isset($data['model']) && is_string($data['model']) && $data['model'] !== '' ? $data['model'] : null,
            titleModel: isset($data['title_model']) && is_string($data['title_model']) && $data['title_model'] !== '' ? $data['title_model'] : null,
            maxIterations: isset($data['max_iterations']) && is_numeric($data['max_iterations']) ? (int) $data['max_iterations'] : null,
        );
    }
}
