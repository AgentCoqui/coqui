<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Manages per-toolkit loading mode (system / eager / deferred).
 *
 * Persists overrides to workspace/toolkit-loading.json. System toolkits
 * (defined in CoquiDefaults::SYSTEM_TOOLKITS) are always System mode and
 * cannot be changed. All other toolkits default to Deferred.
 *
 * Orthogonal to ToolkitVisibilityRegistry — visibility controls whether
 * the LLM can see the tool at all, loading mode controls *when* it enters context.
 */
final class ToolkitLoadingRegistry
{
    /**
     * Loading modes.
     *
     * - system:   Always loaded with full schema (hardcoded, immutable)
     * - eager:    Loaded with full schema when budget allows (user override)
     * - deferred: Wrapped as StubToolkit, discoverable via tool_search (default for non-system)
     */
    private const string MODE_SYSTEM = 'system';
    private const string MODE_EAGER = 'eager';
    private const string MODE_DEFERRED = 'deferred';

    private const array VALID_MODES = [self::MODE_SYSTEM, self::MODE_EAGER, self::MODE_DEFERRED];

    private string $filePath;

    /** @var array<string, string>|null classBasename => mode */
    private ?array $cache = null;

    public function __construct(string $workspacePath)
    {
        $this->filePath = PathHelper::trimTrailingSlash($workspacePath) . '/toolkit-loading.json';
    }

    /**
     * Get the loading mode for a toolkit (by class basename).
     *
     * System toolkits always return 'system', regardless of persisted state.
     * Non-system toolkits check persisted overrides, defaulting to 'deferred'.
     */
    public function getMode(string $classBasename): string
    {
        if ($this->isSystem($classBasename)) {
            return self::MODE_SYSTEM;
        }

        $data = $this->load();

        return $data[$classBasename] ?? self::MODE_DEFERRED;
    }

    /**
     * Set the loading mode for a toolkit.
     *
     * @throws \InvalidArgumentException When trying to change a system toolkit or using invalid mode
     */
    public function setMode(string $classBasename, string $mode): void
    {
        if ($this->isSystem($classBasename)) {
            throw new \InvalidArgumentException(
                sprintf('Toolkit "%s" is a system toolkit and cannot have its loading mode changed.', $classBasename),
            );
        }

        if (!in_array($mode, [self::MODE_EAGER, self::MODE_DEFERRED], true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid loading mode "%s". Use "eager" or "deferred".', $mode),
            );
        }

        $data = $this->load();

        if ($mode === self::MODE_DEFERRED) {
            // Remove entry — deferred is the default, no need to persist
            unset($data[$classBasename]);
        } else {
            $data[$classBasename] = $mode;
        }

        $this->save($data);
    }

    /**
     * Check if a toolkit is a system toolkit (always loaded).
     */
    public function isSystem(string $classBasename): bool
    {
        return in_array($classBasename, CoquiDefaults::SYSTEM_TOOLKITS, true);
    }

    /**
     * Check if a toolkit should be loaded eagerly (system or explicit eager override).
     */
    public function shouldLoadEagerly(string $classBasename): bool
    {
        $mode = $this->getMode($classBasename);

        return $mode === self::MODE_SYSTEM || $mode === self::MODE_EAGER;
    }

    /**
     * Return all persisted overrides for display.
     *
     * @return array<string, string> classBasename => mode
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
     * @return array<string, string>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!file_exists($this->filePath)) {
            return $this->cache = [];
        }

        $raw = file_get_contents($this->filePath);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            return $this->cache = [];
        }

        // Filter to valid modes only
        $filtered = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value) && in_array($value, self::VALID_MODES, true)) {
                $filtered[$key] = $value;
            }
        }

        return $this->cache = $filtered;
    }

    /**
     * @param array<string, string> $data
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
