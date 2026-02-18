<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;

/**
 * Resolves role-based model assignments.
 *
 * Priority: role file model field → openclaw.json → primary model.
 * Merges config-defined roles with file-based roles from RoleDiscovery.
 */
final class RoleResolver
{
    /** @var array<string, string> */
    private array $roles;

    private string $primaryModel;

    public function __construct(
        private readonly ConfigInterface $config,
        ?DefaultsLoader $defaults = null,
        private readonly ?RoleDiscovery $roleDiscovery = null,
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
     * Priority: role file model field → openclaw.json → primary model.
     */
    public function resolve(string $role): string
    {
        // 1. Check if the role file defines a model override
        if ($this->roleDiscovery !== null) {
            try {
                $properties = $this->roleDiscovery->getRole($role);
                if ($properties->model !== null) {
                    return $this->config->resolveModel($properties->model);
                }
            } catch (\Throwable) {
                // Fall through to config-based resolution
            }
        }

        // 2. Check openclaw.json roles mapping
        $modelOrAlias = $this->roles[$role] ?? $this->primaryModel;

        return $this->config->resolveModel($modelOrAlias);
    }

    /**
     * Check if a role is explicitly configured (in config or discovered).
     */
    public function hasRole(string $role): bool
    {
        if (isset($this->roles[$role])) {
            return true;
        }

        return $this->roleDiscovery !== null && $this->roleDiscovery->roleExists($role);
    }

    /**
     * Get all available role names (union of config and discovered roles).
     *
     * @return string[]
     */
    public function availableRoles(): array
    {
        $roles = array_keys($this->roles);

        if ($this->roleDiscovery !== null) {
            $discovered = $this->roleDiscovery->availableRoles();
            $roles = array_unique(array_merge($roles, $discovered));
        }

        sort($roles);

        return $roles;
    }

    /**
     * Get the full role-to-model mapping with display metadata.
     *
     * @return array<string, array{model: string, display_name?: string, description?: string}>
     */
    public function toArray(): array
    {
        $result = [];

        // Start with config-defined roles
        foreach ($this->roles as $role => $model) {
            $result[$role] = [
                'model' => $this->config->resolveModel($model),
            ];
        }

        // Merge discovered roles (file properties take precedence for metadata)
        if ($this->roleDiscovery !== null) {
            foreach ($this->roleDiscovery->discoverAll() as $name => $properties) {
                $model = $properties->model !== null
                    ? $this->config->resolveModel($properties->model)
                    : ($result[$name]['model'] ?? $this->config->resolveModel($this->primaryModel));

                $result[$name] = [
                    'model' => $model,
                    'display_name' => $properties->displayName,
                    'description' => $properties->description,
                    'access_level' => $properties->accessLevel,
                    'is_builtin' => $properties->isBuiltin,
                ];
            }
        }

        return $result;
    }
}
