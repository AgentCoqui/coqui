<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Loads and provides typed access to config/defaults.json.
 *
 * Centralizes all hardcoded defaults (providers, models, base URLs, roles)
 * into a single editable JSON file shipped with the package.
 */
final readonly class DefaultsLoader
{
    /** @var array<string, mixed> */
    private array $data;

    public function __construct(?string $path = null)
    {
        $path ??= dirname(__DIR__, 2) . '/config/defaults.json';

        if (!file_exists($path)) {
            throw new \RuntimeException("Defaults file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException("Unable to read defaults file: {$path}");
        }

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Invalid defaults file: expected JSON object");
        }

        $this->data = $decoded;
    }

    /**
     * Get all provider names.
     *
     * @return string[]
     */
    public function providerNames(): array
    {
        /** @var string[] */
        return array_keys($this->data['providers'] ?? []);
    }

    /**
     * Get a provider's configuration.
     *
     * @return array<string, mixed>
     */
    public function provider(string $name): array
    {
        return $this->data['providers'][$name] ?? [];
    }

    /**
     * Get all providers and their configurations.
     *
     * @return array<string, array<string, mixed>>
     */
    public function providers(): array
    {
        return $this->data['providers'] ?? [];
    }

    /**
     * Get a provider's display name.
     */
    public function providerDisplayName(string $provider): string
    {
        return $this->data['providers'][$provider]['name'] ?? $provider;
    }

    /**
     * Get a provider's description.
     */
    public function providerDescription(string $provider): string
    {
        return $this->data['providers'][$provider]['description'] ?? '';
    }

    /**
     * Get the default base URL for a provider.
     */
    public function defaultBaseUrl(string $provider): string
    {
        return $this->data['providers'][$provider]['baseUrl'] ?? '';
    }

    /**
     * Check if a provider requires an API key.
     */
    public function requiresApiKey(string $provider): bool
    {
        return $this->data['providers'][$provider]['requiresApiKey'] ?? false;
    }

    /**
     * Get the env var name for a provider's API key.
     */
    public function apiKeyEnvVar(string $provider): ?string
    {
        return $this->data['providers'][$provider]['apiKeyEnvVar'] ?? null;
    }

    /**
     * Check if a provider supports live model discovery.
     */
    public function supportsModelDiscovery(string $provider): bool
    {
        return $this->data['providers'][$provider]['supportsModelDiscovery'] ?? false;
    }

    /**
     * Get curated models for a provider (fallback when discovery fails).
     *
     * @return array<int, array<string, mixed>>
     */
    public function curatedModels(string $provider): array
    {
        return $this->data['providers'][$provider]['curatedModels'] ?? [];
    }

    /**
     * Get all role definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    public function roles(): array
    {
        return $this->data['roles'] ?? [];
    }

    /**
     * Get role names.
     *
     * @return string[]
     */
    public function roleNames(): array
    {
        /** @var string[] */
        return array_keys($this->data['roles'] ?? []);
    }

    /**
     * Get a role's description.
     */
    public function roleDescription(string $role): string
    {
        return $this->data['roles'][$role]['description'] ?? '';
    }

    /**
     * Check if a role is required.
     */
    public function isRoleRequired(string $role): bool
    {
        return $this->data['roles'][$role]['required'] ?? false;
    }

    /**
     * Get the default model string (provider/model format).
     */
    public function defaultModel(): string
    {
        return $this->data['defaults']['model'] ?? 'ollama/qwen3:latest';
    }

    /**
     * Get the default workspace path.
     */
    public function defaultWorkspace(): string
    {
        return $this->data['defaults']['workspace'] ?? '.workspace';
    }
}
