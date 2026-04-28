<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CoquiBot\Coqui\Contract\ToolkitVisibility;

/**
 * Manages toolkit and tool visibility state.
 *
 * Persists to workspace/toolkit-visibility.json:
 *   { "packages": { "vendor/pkg": "stub" }, "tools": { "spawn_agent": "stub" } }
 *
 * Missing entries default to Enabled. ALWAYS_ENABLED tools bypass all rules.
 */
final class ToolkitVisibilityRegistry
{
    private string $filePath;

    /** @var array{packages: array<string, string>, tools: array<string, string>}|null */
    private ?array $cache = null;

    public function __construct(string $workspacePath)
    {
        $this->filePath = PathHelper::trimTrailingSlash($workspacePath) . '/toolkit-visibility.json';
    }

    public function getPackageVisibility(string $package): ToolkitVisibility
    {
        $data = $this->load();
        $value = $data['packages'][$package] ?? null;

        if ($value === null) {
            return ToolkitVisibility::Enabled;
        }

        return ToolkitVisibility::tryFrom($value) ?? ToolkitVisibility::Enabled;
    }

    public function getToolVisibility(string $toolName): ToolkitVisibility
    {
        // ALWAYS_ENABLED tools cannot be changed
        if (ToolkitVisibility::isAlwaysEnabled($toolName)) {
            return ToolkitVisibility::Enabled;
        }

        $data = $this->load();
        $value = $data['tools'][$toolName] ?? null;

        if ($value === null) {
            return ToolkitVisibility::Enabled;
        }

        return ToolkitVisibility::tryFrom($value) ?? ToolkitVisibility::Enabled;
    }

    /**
     * Set the visibility for a toolkit package.
     *
     * Setting to Enabled removes the entry (missing = enabled by default).
     */
    public function setPackageVisibility(string $package, ToolkitVisibility $visibility): void
    {
        $data = $this->load();

        if ($visibility === ToolkitVisibility::Enabled) {
            unset($data['packages'][$package]);
        } else {
            $data['packages'][$package] = $visibility->value;
        }

        $this->save($data);
    }

    /**
     * Set the visibility for a standalone tool.
     *
     * @throws \InvalidArgumentException When trying to change a protected tool
     */
    public function setToolVisibility(string $toolName, ToolkitVisibility $visibility): void
    {
        if (ToolkitVisibility::isAlwaysEnabled($toolName)) {
            throw new \InvalidArgumentException(
                sprintf('Tool "%s" is ALWAYS_ENABLED and cannot be stubbed or disabled.', $toolName),
            );
        }

        if ($visibility === ToolkitVisibility::Disabled && !ToolkitVisibility::canDisable($toolName)) {
            throw new \InvalidArgumentException(
                sprintf('Tool "%s" is CANNOT_DISABLE — it can be stubbed but not disabled.', $toolName),
            );
        }

        $data = $this->load();

        if ($visibility === ToolkitVisibility::Enabled) {
            unset($data['tools'][$toolName]);
        } else {
            $data['tools'][$toolName] = $visibility->value;
        }

        $this->save($data);
    }

    /**
     * Return the full visibility state for display/API responses.
     *
     * @return array{packages: array<string, string>, tools: array<string, string>}
     */
    public function all(): array
    {
        return $this->load();
    }

    public function invalidateCache(): void
    {
        $this->cache = null;
    }

    /**
     * @return array{packages: array<string, string>, tools: array<string, string>}
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!file_exists($this->filePath)) {
            return $this->cache = ['packages' => [], 'tools' => []];
        }

        $raw = file_get_contents($this->filePath);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            return $this->cache = ['packages' => [], 'tools' => []];
        }

        return $this->cache = [
            'packages' => is_array($data['packages'] ?? null) ? $data['packages'] : [],
            'tools'    => is_array($data['tools'] ?? null) ? $data['tools'] : [],
        ];
    }

    /**
     * @param array{packages: array<string, string>, tools: array<string, string>} $data
     */
    private function save(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        file_put_contents($this->filePath, $json . "\n");
        $this->cache = $data;
    }
}
