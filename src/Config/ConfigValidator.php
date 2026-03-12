<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

/**
 * Validates openclaw.json configuration data.
 *
 * Returns a list of human-readable error strings. An empty list means
 * the config is valid. Callers decide how to handle errors (block writes,
 * warn users, etc.).
 */
final class ConfigValidator
{
    /**
     * Validate a full config array.
     *
     * @param array<string, mixed> $data
     * @return string[] Validation errors (empty = valid)
     */
    public function validate(array $data): array
    {
        $errors = [];

        $errors = [...$errors, ...$this->validatePrimaryModel($data)];
        $errors = [...$errors, ...$this->validateRoles($data)];
        $errors = [...$errors, ...$this->validateFallbacks($data)];
        $errors = [...$errors, ...$this->validateProviders($data)];
        $errors = [...$errors, ...$this->validateMaxIterations($data)];
        $errors = [...$errors, ...$this->validateBlacklist($data)];
        $errors = [...$errors, ...$this->validateMounts($data)];
        $errors = [...$errors, ...$this->validateShellAllowedCommands($data)];
        $errors = [...$errors, ...$this->validateWorkspace($data)];
        $errors = [...$errors, ...$this->validateMemory($data)];
        $errors = [...$errors, ...$this->validateApi($data)];

        return $errors;
    }

    /**
     * Validate that a model string matches the provider/model format.
     */
    public function isValidModelString(string $model): bool
    {
        // Format: provider/model[:tag] or provider/subprovider/model[:tag] (e.g. openrouter)
        return (bool) preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9._:/-]+$#', $model);
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validatePrimaryModel(array $data): array
    {
        $primary = $data['agents']['defaults']['model']['primary'] ?? null;

        if ($primary === null || $primary === '') {
            return ['agents.defaults.model.primary is required and must be a non-empty string'];
        }

        if (!is_string($primary)) {
            return ['agents.defaults.model.primary must be a string'];
        }

        if (!$this->isValidModelString($primary)) {
            return [sprintf(
                'agents.defaults.model.primary must be in "provider/model" format, got: %s',
                $primary,
            )];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateRoles(array $data): array
    {
        $roles = $data['agents']['defaults']['roles'] ?? null;
        if ($roles === null) {
            return [];
        }

        if (!is_array($roles)) {
            return ['agents.defaults.roles must be an object'];
        }

        $errors = [];
        foreach ($roles as $name => $model) {
            if (!is_string($model)) {
                continue; // Role might have complex config
            }
            if (!$this->isValidModelString($model)) {
                $errors[] = sprintf(
                    'agents.defaults.roles.%s: invalid model format "%s" (expected "provider/model")',
                    $name,
                    $model,
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateFallbacks(array $data): array
    {
        $fallbacks = $data['agents']['defaults']['model']['fallbacks'] ?? null;
        if ($fallbacks === null) {
            return [];
        }

        if (!is_array($fallbacks)) {
            return ['agents.defaults.model.fallbacks must be an array'];
        }

        $errors = [];
        foreach ($fallbacks as $i => $model) {
            if (!is_string($model)) {
                $errors[] = sprintf('agents.defaults.model.fallbacks[%d] must be a string', $i);
                continue;
            }
            if (!$this->isValidModelString($model)) {
                $errors[] = sprintf(
                    'agents.defaults.model.fallbacks[%d]: invalid model format "%s"',
                    $i,
                    $model,
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateProviders(array $data): array
    {
        $providers = $data['models']['providers'] ?? null;
        if ($providers === null) {
            return [];
        }

        if (!is_array($providers)) {
            return ['models.providers must be an object'];
        }

        $errors = [];
        foreach ($providers as $name => $providerConfig) {
            if (!is_array($providerConfig)) {
                $errors[] = sprintf('models.providers.%s must be an object', $name);
                continue;
            }

            $baseUrl = $providerConfig['baseUrl'] ?? null;
            if ($baseUrl !== null && is_string($baseUrl)) {
                if (!filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                    $errors[] = sprintf(
                        'models.providers.%s.baseUrl: invalid URL "%s"',
                        $name,
                        $baseUrl,
                    );
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateMaxIterations(array $data): array
    {
        $maxIter = $data['agents']['defaults']['maxIterations'] ?? null;
        if ($maxIter === null) {
            return [];
        }

        if (!is_int($maxIter)) {
            return ['agents.defaults.maxIterations must be an integer'];
        }

        if ($maxIter < 0) {
            return ['agents.defaults.maxIterations must be 0 (unlimited) or a positive integer'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateBlacklist(array $data): array
    {
        $blacklist = $data['agents']['defaults']['blacklist'] ?? null;
        if ($blacklist === null) {
            return [];
        }

        if (!is_array($blacklist)) {
            return ['agents.defaults.blacklist must be an array of regex patterns'];
        }

        $errors = [];
        foreach ($blacklist as $i => $pattern) {
            if (!is_string($pattern)) {
                $errors[] = sprintf('agents.defaults.blacklist[%d] must be a string', $i);
                continue;
            }

            // Verify pattern is valid regex
            if (@preg_match($pattern, '') === false) {
                $errors[] = sprintf(
                    'agents.defaults.blacklist[%d]: invalid regex pattern "%s"',
                    $i,
                    $pattern,
                );
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateMounts(array $data): array
    {
        $mounts = $data['agents']['defaults']['mounts'] ?? null;
        if ($mounts === null) {
            return [];
        }

        if (!is_array($mounts)) {
            return ['agents.defaults.mounts must be an array'];
        }

        $errors = [];
        $aliases = [];

        foreach ($mounts as $i => $mount) {
            $prefix = sprintf('agents.defaults.mounts[%d]', $i);

            if (!is_array($mount)) {
                $errors[] = "{$prefix} must be an object";
                continue;
            }

            // Required: path
            if (!isset($mount['path']) || !is_string($mount['path']) || $mount['path'] === '') {
                $errors[] = "{$prefix}.path is required and must be a non-empty string";
            }

            // Required: alias
            if (!isset($mount['alias']) || !is_string($mount['alias']) || $mount['alias'] === '') {
                $errors[] = "{$prefix}.alias is required and must be a non-empty string";
            } elseif (str_contains($mount['alias'], '/') || str_contains($mount['alias'], '\\')) {
                $errors[] = "{$prefix}.alias must not contain path separators";
            } elseif (isset($aliases[$mount['alias']])) {
                $errors[] = sprintf('%s.alias "%s" is a duplicate of mounts[%d]', $prefix, $mount['alias'], $aliases[$mount['alias']]);
            } else {
                $aliases[$mount['alias']] = $i;
            }

            // Optional: access
            if (isset($mount['access'])) {
                if (!is_string($mount['access']) || !in_array($mount['access'], ['ro', 'rw'], true)) {
                    $errors[] = "{$prefix}.access must be \"ro\" or \"rw\"";
                }
            }

            // Optional: description
            if (isset($mount['description']) && !is_string($mount['description'])) {
                $errors[] = "{$prefix}.description must be a string";
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateShellAllowedCommands(array $data): array
    {
        $commands = $data['agents']['defaults']['shellAllowedCommands'] ?? null;
        if ($commands === null) {
            return [];
        }

        if (!is_array($commands)) {
            return ['agents.defaults.shellAllowedCommands must be an array of strings'];
        }

        $errors = [];
        foreach ($commands as $i => $cmd) {
            if (!is_string($cmd) || $cmd === '') {
                $errors[] = sprintf('agents.defaults.shellAllowedCommands[%d] must be a non-empty string', $i);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateWorkspace(array $data): array
    {
        $workspace = $data['agents']['defaults']['workspace'] ?? null;
        if ($workspace === null) {
            return [];
        }

        if (!is_string($workspace) || $workspace === '') {
            return ['agents.defaults.workspace must be a non-empty string'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateMemory(array $data): array
    {
        $memory = $data['agents']['defaults']['memory'] ?? null;
        if ($memory === null) {
            return [];
        }

        if (!is_array($memory)) {
            return ['agents.defaults.memory must be an object'];
        }

        $errors = [];

        if (isset($memory['embeddingModel'])) {
            if (!is_string($memory['embeddingModel'])) {
                $errors[] = 'agents.defaults.memory.embeddingModel must be a string';
            } elseif (!$this->isValidModelString($memory['embeddingModel'])) {
                $errors[] = sprintf(
                    'agents.defaults.memory.embeddingModel: invalid model format "%s" (expected "provider/model")',
                    $memory['embeddingModel'],
                );
            }
        }

        if (isset($memory['enabled']) && !is_bool($memory['enabled'])) {
            $errors[] = 'agents.defaults.memory.enabled must be a boolean';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateApi(array $data): array
    {
        $api = $data['api'] ?? null;
        if ($api === null) {
            return [];
        }

        if (!is_array($api)) {
            return ['api must be an object'];
        }

        $errors = [];

        if (isset($api['key']) && (!is_string($api['key']) || $api['key'] === '')) {
            $errors[] = 'api.key must be a non-empty string';
        }

        if (isset($api['rateLimit'])) {
            if (!is_array($api['rateLimit'])) {
                $errors[] = 'api.rateLimit must be an object';
            } else {
                if (isset($api['rateLimit']['maxRequests'])) {
                    if (!is_int($api['rateLimit']['maxRequests']) || $api['rateLimit']['maxRequests'] <= 0) {
                        $errors[] = 'api.rateLimit.maxRequests must be a positive integer';
                    }
                }
                if (isset($api['rateLimit']['windowSeconds'])) {
                    if (!is_int($api['rateLimit']['windowSeconds']) || $api['rateLimit']['windowSeconds'] <= 0) {
                        $errors[] = 'api.rateLimit.windowSeconds must be a positive integer';
                    }
                }
            }
        }

        return $errors;
    }
}
