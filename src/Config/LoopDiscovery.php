<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\LoopDefinition;

/**
 * Boot-time discovery for loop definitions.
 *
 * Scans workspace/loops/ for .json files, parses them into LoopDefinition
 * value objects, and caches results. Seeds built-in definitions from
 * config/loops/ on first boot.
 */
final class LoopDiscovery
{
    private readonly string $loopsDir;
    private readonly string $builtinLoopsDir;

    /** @var array<string, LoopDefinition>|null Cached discovery results keyed by name */
    private ?array $discovered = null;

    /** @var array<string, array<string, mixed>>|null Cached raw JSON arrays keyed by name */
    private ?array $rawDefinitions = null;

    public function __construct(
        string $workspacePath,
        ?string $projectRoot = null,
    ) {
        $this->loopsDir = PathHelper::trimTrailingSlash($workspacePath) . '/loops';
        $this->builtinLoopsDir = ($projectRoot !== null ? PathHelper::trimTrailingSlash($projectRoot) : dirname(__DIR__, 2)) . '/config/loops';
    }

    /**
     * Scan the loops directory and return all valid loop definitions.
     *
     * @return array<string, LoopDefinition>
     */
    public function discoverAll(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $this->discovered = [];

        if (!is_dir($this->loopsDir)) {
            return $this->discovered;
        }

        $entries = scandir($this->loopsDir);
        if ($entries === false) {
            return $this->discovered;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            $filePath = $this->loopsDir . '/' . $entry;
            if (!is_file($filePath)) {
                continue;
            }

            try {
                $json = file_get_contents($filePath);
                if ($json === false) {
                    continue;
                }

                $definition = LoopDefinition::fromJson($json);
                $this->discovered[$definition->name] = $definition;
            } catch (\InvalidArgumentException | \JsonException) {
                // Malformed definition — silently skip
                continue;
            }
        }

        return $this->discovered;
    }

    /**
     * Resolve a loop definition by name.
     *
     * @throws \RuntimeException If the loop name is not found.
     */
    public function get(string $name): LoopDefinition
    {
        $loops = $this->discoverAll();

        if (isset($loops[$name])) {
            return $loops[$name];
        }

        throw new \RuntimeException(sprintf('Loop definition not found: "%s"', $name));
    }

    /**
     * Return all available loop definition names.
     *
     * @return list<string>
     */
    public function availableLoops(): array
    {
        return array_keys($this->discoverAll());
    }

    /**
     * Check if a loop definition exists by name.
     */
    public function exists(string $name): bool
    {
        return isset($this->discoverAll()[$name]);
    }

    /**
     * Return the raw decoded JSON array for a loop definition.
     *
     * Unlike get(), this does NOT parse into LoopDefinition objects,
     * allowing callers to apply template parameter substitution before parsing.
     *
     * @return array<string, mixed>
     * @throws \RuntimeException If the loop name is not found.
     */
    public function getRawDefinition(string $name): array
    {
        $raw = $this->discoverAllRaw();

        if (isset($raw[$name])) {
            return $raw[$name];
        }

        throw new \RuntimeException(sprintf('Loop definition not found: "%s"', $name));
    }

    /**
     * Return the absolute path to the loops directory.
     */
    public function loopsDir(): string
    {
        return $this->loopsDir;
    }

    /**
     * Ensure the loops directory exists.
     */
    public function ensureLoopsDir(): void
    {
        if (!is_dir($this->loopsDir)) {
            mkdir($this->loopsDir, CoquiDefaults::DIRECTORY_MODE, true);
        }
    }

    /**
     * Scan the loops directory and return raw decoded JSON arrays for all definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function discoverAllRaw(): array
    {
        if ($this->rawDefinitions !== null) {
            return $this->rawDefinitions;
        }

        $this->rawDefinitions = [];

        if (!is_dir($this->loopsDir)) {
            return $this->rawDefinitions;
        }

        $entries = scandir($this->loopsDir);
        if ($entries === false) {
            return $this->rawDefinitions;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            $filePath = $this->loopsDir . '/' . $entry;
            if (!is_file($filePath)) {
                continue;
            }

            try {
                $json = file_get_contents($filePath);
                if ($json === false) {
                    continue;
                }

                $data = json_decode($json, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
                if (!is_array($data) || !isset($data['name'])) {
                    continue;
                }

                $this->rawDefinitions[(string) $data['name']] = $data;
            } catch (\JsonException) {
                continue;
            }
        }

        return $this->rawDefinitions;
    }

    /**
     * Seed built-in loop definitions from config/loops/ to workspace/loops/.
     *
     * Only copies definitions that don't already exist in the workspace.
     * Never overwrites user-edited files. When a LoopUpdateTracker is provided,
     * newly seeded files are recorded for future update detection.
     */
    public function seedBuiltinLoops(?LoopUpdateTracker $tracker = null): void
    {
        $this->ensureLoopsDir();

        if (!is_dir($this->builtinLoopsDir)) {
            return;
        }

        $entries = scandir($this->builtinLoopsDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (!str_ends_with($entry, '.json')) {
                continue;
            }

            $source = $this->builtinLoopsDir . '/' . $entry;
            $target = $this->loopsDir . '/' . $entry;

            // Only copy if target doesn't exist — never overwrite user edits
            if (!file_exists($target)) {
                copy($source, $target);

                // Record hashes for the newly seeded file
                if ($tracker !== null) {
                    $loopName = basename($entry, '.json');
                    $hash = $tracker->hashFile($source);
                    $tracker->recordHash($loopName, $hash, $hash);
                }
            }
        }

        // Invalidate cache so seeded definitions are visible
        $this->invalidateCache();
    }

    /**
     * Seed loop definitions from toolkit package directories into the workspace.
     *
     * Copies .json loop files from each package path to workspace/loops/.
     * Never overwrites existing files — workspace always wins.
     *
     * @param string[] $packageLoopPaths Absolute paths to package loop directories.
     */
    public function seedPackageLoops(array $packageLoopPaths): void
    {
        $this->ensureLoopsDir();

        foreach ($packageLoopPaths as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $entries = scandir($dir);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.json')) {
                    continue;
                }

                $source = $dir . '/' . $entry;
                $target = $this->loopsDir . '/' . $entry;

                if (!file_exists($target)) {
                    copy($source, $target);
                }
            }
        }

        $this->invalidateCache();
    }

    /**
     * Validate a definition name — must be a filesystem-safe slug. This is the
     * path-traversal guard: names become filenames.
     */
    public function isValidDefinitionName(string $name): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) === 1;
    }

    /**
     * True when a built-in definition of this name ships in config/loops/.
     */
    public function isBuiltin(string $name): bool
    {
        if (!$this->isValidDefinitionName($name)) {
            return false;
        }

        return is_file($this->builtinLoopsDir . '/' . $name . '.json');
    }

    /**
     * Validate and persist a loop definition to workspace/loops/{name}.json.
     *
     * @param array<string, mixed> $definition
     * @throws \InvalidArgumentException on an invalid name or structure
     * @throws \RuntimeException on a write failure
     */
    public function saveDefinition(string $name, array $definition): void
    {
        if (!$this->isValidDefinitionName($name)) {
            throw new \InvalidArgumentException(sprintf('Invalid loop definition name: "%s"', $name));
        }

        // The filename is authoritative for the definition's name.
        $definition['name'] = $name;

        // The on-disk file is the authoring source only. Server-owned tokens
        // (version, id, timestamps) live in the ObjectVersionStore and must
        // never be persisted into the definition file.
        unset($definition['version'], $definition['id'], $definition['created_at'], $definition['updated_at']);

        // Structural validation — throws InvalidArgumentException on a bad shape.
        $parsed = LoopDefinition::fromArray($definition);
        if ($parsed->roles === []) {
            throw new \InvalidArgumentException('A loop definition must declare at least one role');
        }

        $json = json_encode($definition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \InvalidArgumentException('Loop definition is not JSON-serializable');
        }

        $this->ensureLoopsDir();
        $path = $this->loopsDir . '/' . $name . '.json';
        if (file_put_contents($path, $json . "\n") === false) {
            throw new \RuntimeException(sprintf('Failed to write loop definition "%s"', $name));
        }

        $this->invalidateCache();
    }

    /**
     * Delete workspace/loops/{name}.json. Returns whether a file was removed.
     *
     * @throws \InvalidArgumentException on an invalid name
     */
    public function deleteDefinition(string $name): bool
    {
        if (!$this->isValidDefinitionName($name)) {
            throw new \InvalidArgumentException(sprintf('Invalid loop definition name: "%s"', $name));
        }

        $path = $this->loopsDir . '/' . $name . '.json';
        if (!is_file($path)) {
            return false;
        }

        $deleted = unlink($path);
        $this->invalidateCache();

        return $deleted;
    }

    /**
     * Invalidate the in-memory cache, forcing a re-scan on next access.
     */
    public function invalidateCache(): void
    {
        $this->discovered = null;
        $this->rawDefinitions = null;
    }
}
