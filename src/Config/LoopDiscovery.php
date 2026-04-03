<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

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
            mkdir($this->loopsDir, 0755, true);
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

                $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
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
     * Never overwrites user-edited files.
     */
    public function seedBuiltinLoops(): void
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
     * Invalidate the in-memory cache, forcing a re-scan on next access.
     */
    public function invalidateCache(): void
    {
        $this->discovered = null;
        $this->rawDefinitions = null;
    }
}
