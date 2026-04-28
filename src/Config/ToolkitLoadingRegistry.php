<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;

/**
 * Manages per-toolkit loading mode overrides.
 *
 * Persists overrides to workspace/toolkit-loading.json. The tri-state model:
 *
 * - System:   Always loaded (hardcoded in CoquiDefaults::SYSTEM_TOOLKITS, immutable)
 * - Eager:    User override — always loaded regardless of budget
 * - Deferred: User override — always deferred regardless of budget/frequency
 * - Auto:     Budget gate decides (default for all non-system toolkits)
 *
 * Only Eager and Deferred are persisted. Removing an entry returns to Auto.
 * System is resolved from CoquiDefaults::SYSTEM_TOOLKITS at query time.
 *
 * Orthogonal to ToolkitVisibilityRegistry — visibility controls whether
 * the LLM can see the tool at all, loading mode controls *when* it enters context.
 */
final class ToolkitLoadingRegistry
{
    private const array PERSISTABLE_MODES = ['eager', 'deferred'];

    private string $filePath;

    /** @var array<string, string>|null classBasename => mode string */
    private ?array $cache = null;

    public function __construct(string $workspacePath)
    {
        $this->filePath = PathHelper::trimTrailingSlash($workspacePath) . '/toolkit-loading.json';
    }

    /**
     * Get the loading mode for a toolkit (by class basename).
     *
     * Resolution: System (hardcoded) → Persisted override (Eager/Deferred) → Auto (default).
     */
    public function getMode(string $classBasename): ToolkitLoadingMode
    {
        if ($this->isSystem($classBasename)) {
            return ToolkitLoadingMode::System;
        }

        $data = $this->load();
        $persisted = $data[$classBasename] ?? null;

        return match ($persisted) {
            'eager' => ToolkitLoadingMode::Eager,
            'deferred' => ToolkitLoadingMode::Deferred,
            default => ToolkitLoadingMode::Auto,
        };
    }

    /**
     * Set the loading mode override for a toolkit.
     *
     * Only Eager and Deferred can be set. Use resetMode() to return to Auto.
     *
     * @throws \InvalidArgumentException When trying to change a system toolkit or using a non-persistable mode
     */
    public function setMode(string $classBasename, ToolkitLoadingMode $mode): void
    {
        if ($this->isSystem($classBasename)) {
            throw new \InvalidArgumentException(
                sprintf('Toolkit "%s" is a system toolkit and cannot have its loading mode changed.', $classBasename),
            );
        }

        if (!$mode->isPersistable()) {
            throw new \InvalidArgumentException(
                sprintf('Loading mode "%s" cannot be set directly. Use Eager or Deferred.', $mode->value),
            );
        }

        $data = $this->load();
        $data[$classBasename] = $mode->value;
        $this->save($data);
    }

    /**
     * Remove a persisted override, returning the toolkit to Auto mode.
     *
     * @throws \InvalidArgumentException When trying to reset a system toolkit
     */
    public function resetMode(string $classBasename): void
    {
        if ($this->isSystem($classBasename)) {
            throw new \InvalidArgumentException(
                sprintf('Toolkit "%s" is a system toolkit and cannot be reset.', $classBasename),
            );
        }

        $data = $this->load();

        if (!isset($data[$classBasename])) {
            return;
        }

        unset($data[$classBasename]);
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
     * Return all persisted overrides for display.
     *
     * @return array<string, string> classBasename => mode value string
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

        // Filter to persistable modes only
        $filtered = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value) && in_array($value, self::PERSISTABLE_MODES, true)) {
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
