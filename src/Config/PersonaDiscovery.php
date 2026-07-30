<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;


use CoquiBot\Coqui\Contract\CoquiDefaults;
/**
 * Discovers and resolves personality profiles from the workspace.
 *
 * Profiles live under {workspace}/profiles/{name}/ and must contain a soul.md.
 * Each profile defines an alternate persona/identity for the agent while sharing
 * the global memory store and toolkit surface.
 *
 * Resolution: profile dir → workspace prompts → default prompts dir (3-tier fallback).
 */
final class PersonaDiscovery
{
    /** @var array<string, array{name: string, display_name: string, description: string, path: string}>|null */
    private ?array $cache = null;

    private readonly PersonaParser $parser;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $this->parser = new PersonaParser();
    }

    /**
     * Absolute path to the profiles directory.
     */
    public function profilesDir(): string
    {
        return rtrim($this->workspacePath, '/') . '/personas';
    }

    /**
     * Ensure the profiles directory exists.
     */
    public function ensureProfilesDir(): void
    {
        $dir = $this->profilesDir();
        if (!is_dir($dir)) {
            mkdir($dir, CoquiDefaults::DIRECTORY_MODE, true);
        }
    }

    /**
     * Discover all available profiles.
     *
     * A valid profile is a subdirectory of profiles/ that contains a soul.md file.
     *
     * @return array<string, array{name: string, display_name: string, description: string, path: string}>
     */
    public function discoverAll(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $dir = $this->profilesDir();
        if (!is_dir($dir)) {
            $this->cache = [];
            return $this->cache;
        }

        $entries = scandir($dir);
        if ($entries === false) {
            $this->cache = [];
            return $this->cache;
        }

        $profiles = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $profileDir = $dir . '/' . $entry;
            if (!is_dir($profileDir)) {
                continue;
            }

            $soulPath = $profileDir . '/soul.md';
            if (!is_file($soulPath)) {
                continue;
            }

            $name = strtolower($entry);
            $profiles[$name] = [
                'name' => $name,
                'display_name' => $this->humanizeName($name),
                'description' => $this->extractDescriptionFromFile($soulPath),
                'path' => $profileDir,
            ];
        }

        ksort($profiles);
        $this->cache = $profiles;

        return $this->cache;
    }

    /**
     * Check if a named profile exists.
     */
    public function profileExists(string $name): bool
    {
        $profiles = $this->discoverAll();
        return isset($profiles[strtolower($name)]);
    }

    /**
     * Get the absolute path to a profile directory.
     *
     * @throws \InvalidArgumentException If the profile does not exist.
     */
    public function getProfilePath(string $name): string
    {
        $profiles = $this->discoverAll();
        $key = strtolower($name);

        if (!isset($profiles[$key])) {
            throw new \InvalidArgumentException(sprintf('Profile "%s" not found.', $name));
        }

        return $profiles[$key]['path'];
    }

    /**
     * Read the soul.md content for a profile.
     *
     * @throws \InvalidArgumentException If the profile does not exist.
     */
    public function readSoul(string $name): string
    {
        $path = $this->getProfilePath($name) . '/soul.md';

        return $this->parser->readFile($path)['body'];
    }

    public function readProfileModel(string $name): ?string
    {
        $path = $this->getProfilePath($name) . '/soul.md';
        $metadata = $this->parser->readFile($path)['metadata'];
        $model = $metadata['model'] ?? null;

        return is_string($model) && trim($model) !== '' ? trim($model) : null;
    }

    /**
     * List available profile names.
     *
     * @return list<string>
     */
    public function availableProfiles(): array
    {
        return array_keys($this->discoverAll());
    }

    /**
     * Clear the discovery cache (e.g. after creating a new profile directory).
     */
    public function invalidateCache(): void
    {
        $this->cache = null;
    }

    /**
     * Absolute path to the samples/responses/ directory for a profile.
     */
    public function getSamplesDir(string $name): string
    {
        return $this->getProfilePath($name) . '/samples/responses';
    }

    /**
     * List response sample files for a profile.
     *
     * @return list<string> Absolute paths to .md files in samples/responses/
     */
    public function listResponseSamples(string $name): array
    {
        $dir = $this->getSamplesDir($name);
        if (!is_dir($dir)) {
            return [];
        }

        $entries = scandir($dir);
        if ($entries === false) {
            return [];
        }

        $samples = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            if (is_file($path) && str_ends_with($entry, '.md')) {
                $samples[] = $path;
            }
        }

        sort($samples);
        return $samples;
    }

    /**
     * Extract a brief description from the first paragraph of soul.md.
     *
     * Accepts a profile name and looks up its soul.md path. Returns null if
     * the profile does not exist or soul.md is empty.
     */
    public function extractDescription(string $name): ?string
    {
        $profiles = $this->discoverAll();
        $key = strtolower($name);
        if (!isset($profiles[$key])) {
            return null;
        }

        return $this->extractDescriptionFromFile($profiles[$key]['path'] . '/soul.md');
    }

    /**
     * Extract a brief description from the first paragraph of a soul.md file.
     *
     * Skips any leading heading (lines starting with #) and returns the first
     * non-empty paragraph as a description. Truncates to 120 characters.
     */
    private function extractDescriptionFromFile(string $soulPath): string
    {
        try {
            $content = $this->parser->readFile($soulPath)['body'];
        } catch (\RuntimeException) {
            return '';
        }

        if (trim($content) === '') {
            return '';
        }

        $lines = explode("\n", trim($content));
        $description = '';

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip headings and empty lines until we find body text
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                if ($description !== '') {
                    break; // End of first paragraph
                }
                continue;
            }

            $description .= ($description !== '' ? ' ' : '') . $trimmed;
        }

        if (mb_strlen($description) > 120) {
            return mb_substr($description, 0, 117) . '...';
        }

        return $description;
    }

    private function humanizeName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
