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
        $errors = [...$errors, ...$this->validateDefaultPersona($data)];
        $errors = [...$errors, ...$this->validateRoles($data)];
        $errors = [...$errors, ...$this->validateFallbacks($data)];
        $errors = [...$errors, ...$this->validateImageModel($data)];
        $errors = [...$errors, ...$this->validateImages($data)];
        $errors = [...$errors, ...$this->validateProviders($data)];
        $errors = [...$errors, ...$this->validateMaxIterations($data)];
        $errors = [...$errors, ...$this->validateBlacklist($data)];
        $errors = [...$errors, ...$this->validateMounts($data)];
        $errors = [...$errors, ...$this->validateShellAllowedCommands($data)];
        $errors = [...$errors, ...$this->validateMcp($data)];
        $errors = [...$errors, ...$this->validateWorkspace($data)];
        $errors = [...$errors, ...$this->validateMemory($data)];
        $errors = [...$errors, ...$this->validateApi($data)];
        $errors = [...$errors, ...$this->validateEditHistory($data)];
        $errors = [...$errors, ...$this->validateContext($data)];

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
    private function validateDefaultPersona(array $data): array
    {
        $persona = $data['agents']['defaults']['persona'] ?? null;

        if ($persona === null) {
            return [];
        }

        if (!is_string($persona)) {
            return ['agents.defaults.persona must be a string'];
        }

        if (trim($persona) === '') {
            return ['agents.defaults.persona must be a non-empty string'];
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
            if (is_array($model)) {
                $roleModel = $model['model'] ?? null;
                if ($roleModel !== null) {
                    if (!is_string($roleModel)) {
                        $errors[] = sprintf('agents.defaults.roles.%s.model must be a string', $name);
                    } elseif (!$this->isValidModelString($roleModel)) {
                        $errors[] = sprintf(
                            'agents.defaults.roles.%s.model: invalid model format "%s" (expected "provider/model")',
                            $name,
                            $roleModel,
                        );
                    }
                }

                $budget = $model['toolkitTokenBudget'] ?? null;
                if ($budget !== null && (!is_int($budget) || $budget <= 0)) {
                    $errors[] = sprintf(
                        'agents.defaults.roles.%s.toolkitTokenBudget must be a positive integer',
                        $name,
                    );
                }

                $promotion = $model['toolkitPromotionBudgetPercent'] ?? null;
                if ($promotion !== null && (!is_int($promotion) || $promotion < 0 || $promotion > 100)) {
                    $errors[] = sprintf(
                        'agents.defaults.roles.%s.toolkitPromotionBudgetPercent must be an integer between 0 and 100',
                        $name,
                    );
                }

                continue;
            }

            if (!is_string($model)) {
                $errors[] = sprintf('agents.defaults.roles.%s must be a string or an object', $name);
                continue;
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
    private function validateImageModel(array $data): array
    {
        $errors = [];
        $modelDefaults = $data['agents']['defaults']['model'] ?? null;

        if ($modelDefaults !== null && !is_array($modelDefaults)) {
            return ['agents.defaults.model must be an object'];
        }

        $imageModel = is_array($modelDefaults) ? ($modelDefaults['imageModel'] ?? null) : null;

        if ($imageModel !== null) {
            $primary = $imageModel;
            if (!is_string($primary) || $primary === '') {
                $errors[] = 'agents.defaults.model.imageModel must be a non-empty string';
            } elseif (!$this->isValidModelString($primary)) {
                $errors[] = sprintf(
                    'agents.defaults.model.imageModel must be in "provider/model" format, got: %s',
                    $primary,
                );
            }
        }

        $fallbacks = is_array($modelDefaults) ? ($modelDefaults['imageFallbacks'] ?? null) : null;
        if ($fallbacks !== null) {
            if (!is_array($fallbacks)) {
                $errors[] = 'agents.defaults.model.imageFallbacks must be an array';
            } else {
                foreach ($fallbacks as $index => $fallback) {
                    if (!is_string($fallback) || $fallback === '') {
                        $errors[] = sprintf('agents.defaults.model.imageFallbacks[%d] must be a non-empty string', $index);
                        continue;
                    }

                    if (!$this->isValidModelString($fallback)) {
                        $errors[] = sprintf('agents.defaults.model.imageFallbacks[%d]: invalid model format "%s"', $index, $fallback);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateImages(array $data): array
    {
        $images = $data['images'] ?? null;
        if ($images === null) {
            return [];
        }

        if (!is_array($images)) {
            return ['images must be an object'];
        }

        $errors = [];

        if (isset($images['ownerName']) && (!is_string($images['ownerName']) || trim($images['ownerName']) === '')) {
            $errors[] = 'images.ownerName must be a non-empty string';
        }

        if (isset($images['providers'])) {
            if (!is_array($images['providers'])) {
                $errors[] = 'images.providers must be an object';
            } else {
                foreach ($images['providers'] as $vendor => $settings) {
                    if (!is_array($settings)) {
                        $errors[] = sprintf('images.providers.%s must be an object', $vendor);
                        continue;
                    }

                    $vendorModel = $settings['model'] ?? null;
                    if ($vendorModel !== null && (!is_string($vendorModel) || trim($vendorModel) === '')) {
                        $errors[] = sprintf('images.providers.%s.model must be a non-empty string', $vendor);
                    }

                    $baseUrl = $settings['baseUrl'] ?? null;
                    if ($baseUrl !== null && is_string($baseUrl) && !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
                        $errors[] = sprintf('images.providers.%s.baseUrl: invalid URL "%s"', $vendor, $baseUrl);
                    }
                }
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
            set_error_handler(static fn (): bool => true);
            $valid = preg_match($pattern, '') !== false;
            restore_error_handler();
            if (!$valid) {
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
    private function validateMcp(array $data): array
    {
        $mcp = $data['agents']['defaults']['mcp'] ?? null;
        if ($mcp === null) {
            return [];
        }

        if (!is_array($mcp)) {
            return ['agents.defaults.mcp must be an object'];
        }

        $errors = [];
        $errors = [...$errors, ...$this->validateMcpCommandTuples(
            $mcp['allowedStdioCommands'] ?? null,
            'agents.defaults.mcp.allowedStdioCommands',
        )];
        $errors = [...$errors, ...$this->validateMcpCommandTuples(
            $mcp['deniedStdioCommands'] ?? null,
            'agents.defaults.mcp.deniedStdioCommands',
        )];

        return $errors;
    }

    /**
     * @return string[]
     */
    private function validateMcpCommandTuples(mixed $tuples, string $fieldName): array
    {
        if ($tuples === null) {
            return [];
        }

        if (!is_array($tuples)) {
            return [sprintf('%s must be an array of command tuples', $fieldName)];
        }

        $errors = [];

        foreach ($tuples as $index => $tuple) {
            if (!is_array($tuple) || !array_is_list($tuple) || $tuple === []) {
                $errors[] = sprintf('%s[%d] must be a non-empty array of strings', $fieldName, $index);
                continue;
            }

            foreach ($tuple as $partIndex => $part) {
                if (!is_string($part) || trim($part) === '') {
                    $errors[] = sprintf('%s[%d][%d] must be a non-empty string', $fieldName, $index, $partIndex);
                    continue;
                }

                if (preg_match('/[\r\n]/', $part) === 1) {
                    $errors[] = sprintf('%s[%d][%d] cannot contain line breaks', $fieldName, $index, $partIndex);
                }
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

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateEditHistory(array $data): array
    {
        $editHistory = $data['agents']['defaults']['editHistory'] ?? null;

        if ($editHistory === null) {
            return [];
        }

        if (!is_array($editHistory)) {
            return ['agents.defaults.editHistory must be an object'];
        }

        $errors = [];

        if (isset($editHistory['retentionDays'])) {
            if (!is_int($editHistory['retentionDays']) || $editHistory['retentionDays'] < 1) {
                $errors[] = 'agents.defaults.editHistory.retentionDays must be a positive integer';
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $data
     * @return string[]
     */
    private function validateContext(array $data): array
    {
        $context = $data['agents']['defaults']['context'] ?? null;
        if ($context === null) {
            return [];
        }

        if (!is_array($context)) {
            return ['agents.defaults.context must be an object'];
        }

        $errors = [];

        if (isset($context['budgetExitThreshold'])) {
            $threshold = $context['budgetExitThreshold'];
            if ((!is_int($threshold) && !is_float($threshold)) || $threshold < 0.0 || $threshold > 1.0) {
                $errors[] = 'agents.defaults.context.budgetExitThreshold must be a number between 0.0 and 1.0';
            }
        }

        if (isset($context['budgetExitWrapUpIterations'])) {
            $iterations = $context['budgetExitWrapUpIterations'];
            if (!is_int($iterations) || $iterations < 1) {
                $errors[] = 'agents.defaults.context.budgetExitWrapUpIterations must be a positive integer';
            }
        }

        if (isset($context['autoSummarizeMode'])) {
            $mode = $context['autoSummarizeMode'];
            if (!is_string($mode) || !in_array($mode, ['token', 'turn', 'manual'], true)) {
                $errors[] = 'agents.defaults.context.autoSummarizeMode must be "token", "turn", or "manual"';
            }
        }

        if (isset($context['autoSummarizeThreshold'])) {
            $threshold = $context['autoSummarizeThreshold'];
            if ((!is_int($threshold) && !is_float($threshold)) || $threshold < 0 || $threshold > 100) {
                $errors[] = 'agents.defaults.context.autoSummarizeThreshold must be a number between 0 and 100';
            }
        }

        if (isset($context['autoSummarizeTurnThreshold'])) {
            $turns = $context['autoSummarizeTurnThreshold'];
            if (!is_int($turns) || $turns < 1) {
                $errors[] = 'agents.defaults.context.autoSummarizeTurnThreshold must be a positive integer';
            }
        }

        if (isset($context['autoSummarizeKeepRecent'])) {
            $keep = $context['autoSummarizeKeepRecent'];
            if (!is_int($keep) || $keep < 1 || $keep > 20) {
                $errors[] = 'agents.defaults.context.autoSummarizeKeepRecent must be an integer between 1 and 20';
            }
        }

        if (isset($context['keepRecentTurns'])) {
            $keep = $context['keepRecentTurns'];
            if (!is_int($keep) || $keep < 1) {
                $errors[] = 'agents.defaults.context.keepRecentTurns must be a positive integer';
            }
        }

        if (isset($context['budgetSafetyMarginPercent'])) {
            $margin = $context['budgetSafetyMarginPercent'];
            if (!is_int($margin) || $margin < 0 || $margin > 50) {
                $errors[] = 'agents.defaults.context.budgetSafetyMarginPercent must be an integer between 0 and 50';
            }
        }

        if (isset($context['conversationHistoryInSystemPrompt']) && !is_bool($context['conversationHistoryInSystemPrompt'])) {
            $errors[] = 'agents.defaults.context.conversationHistoryInSystemPrompt must be a boolean';
        }

        return $errors;
    }
}
