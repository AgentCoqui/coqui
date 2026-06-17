<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

/**
 * Single source of truth for openclaw.json configuration.
 *
 * Manages the config lifecycle: resolve path, load, and save.
 * The config lives in the workspace directory, making
 * it accessible (with guardrails) to the agent for self-configuration.
 *
 * On first boot, if no workspace config exists, the manager seeds it from
 * the project root config or DefaultsLoader defaults.
 */
final class ConfigManager
{
    private OpenClawConfig $config;
    private string $configPath;

    public function __construct(
        private readonly string $workspacePath,
        private readonly string $projectRoot,
        private readonly DefaultsLoader $defaultsLoader,
        private readonly ?ConfigValidator $validator = null,
    ) {
        $this->configPath = PathHelper::trimTrailingSlash($this->workspacePath) . '/openclaw.json';
    }

    /**
     * Load the config from the workspace. Seeds from project root or defaults on first boot.
     *
     * @param string|null $explicitPath CLI --config override (takes absolute precedence)
     */
    public function load(?string $explicitPath = null): OpenClawConfig
    {
        // CLI --config flag overrides everything — use that path as-is
        if ($explicitPath !== null && $explicitPath !== '') {
            if (!file_exists($explicitPath)) {
                throw new \RuntimeException(sprintf(
                    'Config file not found at explicit path: %s',
                    $explicitPath,
                ));
            }
            $this->configPath = realpath($explicitPath) ?: $explicitPath;
            $this->config = OpenClawConfig::fromFile($explicitPath);
            return $this->config;
        }

        // Primary: workspace config
        if (file_exists($this->configPath)) {
            $this->config = OpenClawConfig::fromFile($this->configPath);
            return $this->config;
        }

        // Seed from project root config if it exists
        $this->seedConfig();

        if (file_exists($this->configPath)) {
            $this->config = OpenClawConfig::fromFile($this->configPath);
            return $this->config;
        }

        // Fallback: build minimal config from defaults.
        // Intentionally not written to disk so the REPL wizard gate can fire
        // on the next interactive boot and isn't bypassed by a concurrent
        // headless/API process that boots before the user has configured anything.
        $this->config = $this->buildDefaultConfig();
        return $this->config;
    }

    /**
     * Save config data to workspace, with optional validation.
     *
     * @param array<string, mixed> $data Full config array to write
     * @return string[] Validation errors (empty on success)
     */
    public function save(array $data): array
    {
        if ($this->validator !== null) {
            $errors = $this->validator->validate($data);
            if (!empty($errors)) {
                return $errors;
            }
        }

        $this->ensureDirectory();

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($this->configPath, $json . "\n", LOCK_EX);

        $this->config = OpenClawConfig::fromArray($data);

        return [];
    }

    /**
     * Update a single dot-notation key in the config.
     *
     * @return string[] Validation errors (empty on success)
     */
    public function set(string $dotKey, mixed $value): array
    {
        $data = $this->toArray();
        $this->setNestedValue($data, $dotKey, $value);
        return $this->save($data);
    }

    /**
     * Remove a single dot-notation key from the config.
     *
     * @return string[] Validation errors (empty on success)
     */
    public function remove(string $dotKey): array
    {
        $data = $this->toArray();
        $this->removeNestedValue($data, $dotKey);
        return $this->save($data);
    }

    public function config(): OpenClawConfig
    {
        if (!isset($this->config)) {
            throw new \RuntimeException('Config not loaded. Call load() first.');
        }
        return $this->config;
    }

    public function path(): string
    {
        return $this->configPath;
    }

    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    /**
     * Get the raw config as an array for modification.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if (!file_exists($this->configPath)) {
            return [];
        }

        $json = file_get_contents($this->configPath);
        if ($json === false) {
            return [];
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Get a config value by dot-notation key, with API key sanitization.
     */
    public function getSanitized(string $dotKey): mixed
    {
        $value = $this->config->get($dotKey);

        // Sanitize API key fields
        if (is_string($value) && $this->isApiKeyField($dotKey)) {
            return $value !== '' ? '***' : '';
        }

        return $value;
    }

    /**
     * Seed workspace config from project root or defaults.
     */
    private function seedConfig(): void
    {
        $this->ensureDirectory();

        // Try project root openclaw.json
        $projectConfig = PathHelper::trimTrailingSlash($this->projectRoot) . '/openclaw.json';
        if (file_exists($projectConfig)) {
            $content = file_get_contents($projectConfig);
            if ($content !== false) {
                file_put_contents($this->configPath, $content, LOCK_EX);
                return;
            }
        }

        // Try bundled default (relative to coqui package root)
        $bundledConfig = dirname(__DIR__, 2) . '/openclaw.json';
        if (file_exists($bundledConfig) && $bundledConfig !== $projectConfig) {
            $content = file_get_contents($bundledConfig);
            if ($content !== false) {
                file_put_contents($this->configPath, $content, LOCK_EX);
                return;
            }
        }
    }

    private function buildDefaultConfig(): OpenClawConfig
    {
        $defaultModel = $this->defaultsLoader->defaultModel();

        return OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => $defaultModel],
                    'roles' => ['orchestrator' => $defaultModel],
                ],
            ],
        ]);
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->configPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Set a value in a nested array using dot notation.
     *
     * @param array<string, mixed> $data
     */
    private function setNestedValue(array &$data, string $dotKey, mixed $value): void
    {
        $keys = explode('.', $dotKey);
        $ref = &$data;

        foreach ($keys as $i => $key) {
            if ($i === count($keys) - 1) {
                $ref[$key] = $value;
                return;
            }
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                $ref[$key] = [];
            }
            $ref = &$ref[$key];
        }
    }

    /**
     * Remove a value from a nested array using dot notation.
     *
     * @param array<string, mixed> $data
     */
    private function removeNestedValue(array &$data, string $dotKey): void
    {
        $keys = explode('.', $dotKey);
        $lastKey = array_pop($keys);

        $ref = &$data;

        foreach ($keys as $key) {
            if (!isset($ref[$key]) || !is_array($ref[$key])) {
                return;
            }

            $ref = &$ref[$key];
        }

        unset($ref[$lastKey]);
    }

    private function isApiKeyField(string $dotKey): bool
    {
        $lower = strtolower($dotKey);
        return str_contains($lower, 'apikey')
            || str_contains($lower, 'api_key')
            || str_contains($lower, '.key');
    }
}
