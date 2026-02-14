<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;

/**
 * Resolves role-based model assignments from openclaw.json.
 *
 * Reads agents.defaults.roles to map role names (orchestrator, coder, reviewer)
 * to provider/model strings. Falls back to the primary model when a role is undefined.
 */
final class RoleResolver
{
    /** @var array<string, string> */
    private array $roles;

    private string $primaryModel;

    public function __construct(
        private readonly ConfigInterface $config,
        ?DefaultsLoader $defaults = null,
    ) {
        $roles = $this->config->get('agents.defaults.roles', []);
        $this->roles = is_array($roles) ? $roles : [];

        $primary = $this->config->getPrimaryModel();
        $fallback = $defaults !== null ? $defaults->defaultModel() : 'ollama/qwen3:latest';
        $this->primaryModel = $primary !== '' ? $primary : $fallback;
    }

    /**
     * Resolve a role name to a provider/model string.
     *
     * If the role is not defined in config, falls back to the primary model.
     */
    public function resolve(string $role): string
    {
        $modelOrAlias = $this->roles[$role] ?? $this->primaryModel;

        // Resolve aliases through the config
        return $this->config->resolveModel($modelOrAlias);
    }

    /**
     * Check if a role is explicitly configured.
     */
    public function hasRole(string $role): bool
    {
        return isset($this->roles[$role]);
    }

    /**
     * Get all configured role names.
     *
     * @return string[]
     */
    public function availableRoles(): array
    {
        return array_keys($this->roles);
    }

    /**
     * Get the full role-to-model mapping.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $resolved = [];

        foreach ($this->roles as $role => $model) {
            $resolved[$role] = $this->config->resolveModel($model);
        }

        return $resolved;
    }
}
