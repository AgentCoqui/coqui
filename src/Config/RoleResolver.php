<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\SystemRole;

/**
 * Resolves role-based model assignments.
 *
 * Priority: role file model field → openclaw.json → primary model.
 * Merges config-defined roles with file-based roles from RoleDiscovery.
 */
final class RoleResolver
{
    /** @var array<string, string|array<string, mixed>> */
    private array $roles;

    private string $primaryModel;

    public function __construct(
        private readonly ConfigInterface $config,
        ?DefaultsLoader $defaults = null,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        private readonly ?ProfileDiscovery $profileDiscovery = null,
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
    public function resolve(string $role, ?string $profile = null): string
    {
        $profileRole = $this->resolveProfileRoleProperties($role, $profile);
        if ($profileRole?->model !== null) {
            return $this->config->resolveModel($profileRole->model);
        }

        $globalRole = $this->resolveGlobalRoleProperties($role);
        if ($globalRole?->model !== null) {
            return $this->config->resolveModel($globalRole->model);
        }

        $configuredRoleModel = $this->resolveConfiguredRoleModel($role);
        if ($configuredRoleModel !== null) {
            return $this->config->resolveModel($configuredRoleModel);
        }

        $profileModel = $this->resolveProfileModel($profile);
        if ($profileModel !== null) {
            return $this->config->resolveModel($profileModel);
        }

        return $this->config->resolveModel($this->primaryModel);
    }

    /**
     * Resolve the utility model for cheap single-shot tasks
     * (titles, summarization, memory compression).
     *
     * Resolution chain:
     * 1. agents.defaults.model.utility (openclaw.json) / COQUI_UTILITY_MODEL env
     * 2. title-generator role (preserves role file model override)
     * 3. Primary model fallback
     */
    public function resolveUtility(?string $profile = null): string
    {
        if ($this->config instanceof OpenClawConfig) {
            $utilityModel = $this->config->getUtilityModel();
            if ($utilityModel !== '') {
                return $this->config->resolveModel($utilityModel);
            }
        }

        return $this->resolve(SystemRole::TitleGenerator->value, $profile);
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
     * Resolve the max iterations for a given role.
     *
     * Priority: role file max_iterations field → agents.defaults.maxIterations
     * from openclaw.json → CoquiDefaults::MAX_ITERATIONS (48).
     *
     * A return value of 0 means unlimited (sentinel handled by AbstractAgent).
     */
    public function resolveMaxIterations(string $role, ?string $profile = null): int
    {
        $profileRole = $this->resolveProfileRoleProperties($role, $profile);
        if ($profileRole?->maxIterations !== null) {
            return $profileRole->maxIterations;
        }

        $globalRole = $this->resolveGlobalRoleProperties($role);
        if ($globalRole?->maxIterations !== null) {
            return $globalRole->maxIterations;
        }

        // 2. Check openclaw.json global default
        $configValue = $this->config->get('agents.defaults.maxIterations');

        if (is_int($configValue) || (is_string($configValue) && is_numeric($configValue))) {
            return (int) $configValue;
        }

        // 3. Hardcoded fallback
        return CoquiDefaults::MAX_ITERATIONS;
    }

    private function resolveConfiguredRoleModel(string $role): ?string
    {
        $configured = $this->roles[$role] ?? null;

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if (is_array($configured)) {
            $model = $configured['model'] ?? null;
            if (is_string($model) && $model !== '') {
                return $model;
            }
        }

        return null;
    }

    private function resolveProfileModel(?string $profile): ?string
    {
        if ($profile === null || $this->profileDiscovery === null) {
            return null;
        }

        try {
            return $this->profileDiscovery->readProfileModel($profile);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveProfileRoleProperties(string $role, ?string $profile): ?\CoquiBot\Coqui\Contract\RoleProperties
    {
        if ($profile === null || $this->roleDiscovery === null || $this->profileDiscovery === null) {
            return null;
        }

        try {
            $profilePath = $this->profileDiscovery->getProfilePath($profile);

            return $this->roleDiscovery->getProfileRole($role, $profilePath);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveGlobalRoleProperties(string $role): ?\CoquiBot\Coqui\Contract\RoleProperties
    {
        if ($this->roleDiscovery === null) {
            return null;
        }

        try {
            return $this->roleDiscovery->getRole($role);
        } catch (\Throwable) {
            return null;
        }
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
     * Get roles that users can switch to or spawn as child agents.
     *
     * Excludes template roles (is_template=true) which are only used
     * by internal utility classes (e.g. TitleGenerator).
     *
     * @return string[]
     */
    public function selectableRoles(): array
    {
        $roles = $this->availableRoles();

        if ($this->roleDiscovery === null) {
            return $roles;
        }

        return array_values(array_filter($roles, function (string $role): bool {
            try {
                $properties = $this->roleDiscovery->getRole($role);
                return !$properties->isTemplate;
            } catch (\Throwable) {
                // Config-only roles (no file) are always selectable
                return true;
            }
        }));
    }

    /**
     * System roles that are always present and never editable.
     *
     * These roles are synthesized by the resolver — they have no role file
     * and their instructions live in agent classes. They always appear in
     * the roles API output with is_system=true and editable=false.
     */
    private const array SYSTEM_ROLES = [
        'orchestrator' => [
            'display_name' => 'Orchestrator',
            'description' => 'Primary system role with full tool access. Routes tasks, manages sessions, and delegates to child agents. All conversations start with this role.',
            'access_level' => 'full',
        ],
    ];

    /**
     * Get all roles with full metadata for API output.
     *
     * Merges system roles (orchestrator), config-defined roles, and
     * file-based discovered roles. System roles always appear with
     * is_system=true and editable=false.
     *
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        $result = [];

        // 1. Synthesize system roles (always present, never editable)
        foreach (self::SYSTEM_ROLES as $name => $meta) {
            $result[$name] = [
                'name' => $name,
                'model' => $this->resolve($name),
                'display_name' => $meta['display_name'],
                'description' => $meta['description'],
                'access_level' => $meta['access_level'],
                'is_builtin' => true,
                'is_system' => true,
                'is_template' => false,
                'ignore_updates' => false,
                'editable' => false,
                'max_iterations' => $this->resolveMaxIterations($name),
            ];
        }

        // 2. Merge config-defined roles (those only in openclaw.json, no file)
        foreach ($this->roles as $role => $model) {
            if (isset($result[$role])) {
                // System role — update model only
                $result[$role]['model'] = $this->resolve($role);
                continue;
            }
            $result[$role] = [
                'name' => $role,
                'model' => $this->resolve($role),
                'max_iterations' => $this->resolveMaxIterations($role),
            ];
        }

        // 3. Merge discovered roles (file properties take precedence for metadata)
        if ($this->roleDiscovery !== null) {
            foreach ($this->roleDiscovery->discoverAll() as $name => $properties) {
                if (isset($result[$name]) && ($result[$name]['is_system'] ?? false)) {
                    // System role — do not override with file-based data
                    continue;
                }

                $model = $properties->model !== null
                    ? $this->config->resolveModel($properties->model)
                    : ($result[$name]['model'] ?? $this->config->resolveModel($this->primaryModel));

                $result[$name] = [
                    'name' => $name,
                    'model' => $model,
                    'display_name' => $properties->displayName,
                    'description' => $properties->description,
                    'access_level' => $properties->accessLevel,
                    'is_builtin' => $properties->isBuiltin,
                    'is_system' => false,
                    'is_template' => $properties->isTemplate,
                    'ignore_updates' => $properties->ignoreUpdates,
                    'editable' => true,
                    'max_iterations' => $this->resolveMaxIterations($name),
                ];
            }
        }

        return $result;
    }

    /**
     * Check if a role is a system-managed role (not editable).
     */
    public function isSystemRole(string $name): bool
    {
        return isset(self::SYSTEM_ROLES[$name]);
    }
}
