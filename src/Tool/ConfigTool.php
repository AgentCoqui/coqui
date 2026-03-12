<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;

/**
 * Agent-facing tool for reading and modifying openclaw.json configuration.
 *
 * Write operations are guarded by ConfigGuard — only model, role, and
 * iteration settings can be modified. Security-sensitive keys are denied.
 */
final class ConfigTool implements ToolInterface
{
    public function __construct(
        private readonly ConfigManager $configManager,
        private readonly ConfigGuard $configGuard,
    ) {}

    public function name(): string
    {
        return 'config';
    }

    public function description(): string
    {
        return <<<'DESC'
            Read or modify the Coqui configuration (openclaw.json).
            
            Available actions:
            - get: Read a config value by dot-notation key (e.g. "agents.defaults.model.primary").
            - set: Change a config value. Only model, role, and iteration settings can be modified.
            - show: Show the full sanitized config (API keys masked).
            - list_models: List all available models from configured providers.
            - switch_model: Change the primary model (shorthand for set with "agents.defaults.model.primary").
            
            Security-sensitive settings (blacklist, shell allowlist, workspace path, mounts,
            API keys, provider configurations) cannot be modified by the agent.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The config action to perform',
                values: ['get', 'set', 'show', 'list_models', 'switch_model'],
                required: true,
            ),
            new StringParameter(
                name: 'key',
                description: 'Dot-notation config key (e.g. "agents.defaults.model.primary"). Required for get and set.',
                required: false,
            ),
            new StringParameter(
                name: 'value',
                description: 'The value to set. Required for set and switch_model actions. For arrays, use JSON-encoded string.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        return match ($action) {
            'get' => $this->handleGet($input),
            'set' => $this->handleSet($input),
            'show' => $this->handleShow(),
            'list_models' => $this->handleListModels(),
            'switch_model' => $this->handleSwitchModel($input),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $input */
    private function handleGet(array $input): ToolResult
    {
        $key = $input['key'] ?? '';
        if ($key === '') {
            return ToolResult::error('Key is required for get action.');
        }

        $value = $this->configManager->getSanitized($key);

        if ($value === null) {
            return ToolResult::success("Config key '{$key}' is not set.");
        }

        $display = is_scalar($value) ? (string) $value : json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return ToolResult::success("{$key} = {$display}");
    }

    /** @param array<string, mixed> $input */
    private function handleSet(array $input): ToolResult
    {
        $key = $input['key'] ?? '';
        $rawValue = $input['value'] ?? '';

        if ($key === '') {
            return ToolResult::error('Key is required for set action.');
        }

        if ($rawValue === '') {
            return ToolResult::error('Value is required for set action.');
        }

        // Security check
        $denyReason = $this->configGuard->denyReason($key);
        if ($denyReason !== null) {
            return ToolResult::error($denyReason);
        }

        // Parse JSON values for complex types (arrays, objects)
        $value = $this->parseValue($rawValue);

        $errors = $this->configManager->set($key, $value);
        if (!empty($errors)) {
            return ToolResult::error("Validation failed:\n" . implode("\n", $errors));
        }

        $display = is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_SLASHES);

        return ToolResult::success("Config updated: {$key} = {$display}\nChanges take effect on the next agent turn.");
    }

    private function handleShow(): ToolResult
    {
        $data = $this->configManager->toArray();

        // Sanitize API keys in provider configs
        if (isset($data['models']['providers']) && is_array($data['models']['providers'])) {
            foreach ($data['models']['providers'] as $name => $provider) {
                if (is_array($provider) && isset($provider['apiKey'])) {
                    $data['models']['providers'][$name]['apiKey'] = '***';
                }
            }
        }

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return ToolResult::success("openclaw.json ({$this->configManager->path()}):\n{$json}");
    }

    private function handleListModels(): ToolResult
    {
        $config = $this->configManager->config();
        $providers = $config->get('models.providers', []);

        if (!is_array($providers) || empty($providers)) {
            return ToolResult::success('No models configured. Run /config edit to set up providers.');
        }

        $lines = [];
        $primary = $config->getPrimaryModel();

        foreach ($providers as $providerName => $providerConfig) {
            if (!is_array($providerConfig) || !isset($providerConfig['models'])) {
                continue;
            }

            foreach ($providerConfig['models'] as $model) {
                if (!is_array($model) || !isset($model['id'])) {
                    continue;
                }

                $modelId = "{$providerName}/{$model['id']}";
                $marker = ($modelId === $primary) ? ' ← current' : '';
                $name = $model['name'] ?? $model['id'];
                $lines[] = "  {$modelId} ({$name}){$marker}";
            }
        }

        if (empty($lines)) {
            return ToolResult::success('No models found in provider configurations.');
        }

        return ToolResult::success("Available models:\n" . implode("\n", $lines));
    }

    /** @param array<string, mixed> $input */
    private function handleSwitchModel(array $input): ToolResult
    {
        $model = $input['value'] ?? '';

        if ($model === '') {
            return ToolResult::error('Value is required for switch_model action. Provide the model ID (e.g. "anthropic/claude-sonnet-4-20250514").');
        }

        // Validate model format: provider/model[:tag]
        if (!preg_match('#^[a-zA-Z0-9_-]+/[a-zA-Z0-9._-]+(:[a-zA-Z0-9._-]+)?$#', $model)) {
            return ToolResult::error("Invalid model format: '{$model}'. Expected format: provider/model-name (e.g. 'openai/gpt-4o').");
        }

        $errors = $this->configManager->set('agents.defaults.model.primary', $model);
        if (!empty($errors)) {
            return ToolResult::error("Validation failed:\n" . implode("\n", $errors));
        }

        // Also update orchestrator role if it matches the old primary
        $config = $this->configManager->config();
        $orchestratorModel = $config->get('agents.defaults.roles.orchestrator');
        if ($orchestratorModel !== null && $orchestratorModel !== $model) {
            $this->configManager->set('agents.defaults.roles.orchestrator', $model);
        }

        return ToolResult::success("Primary model switched to: {$model}\nChanges take effect on the next agent turn.");
    }

    /**
     * Parse a string value — attempt JSON decode for arrays/objects.
     */
    private function parseValue(string $raw): mixed
    {
        // Try JSON decode for complex values
        if (str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        // Boolean strings
        if ($raw === 'true') {
            return true;
        }
        if ($raw === 'false') {
            return false;
        }

        // Numeric strings
        if (is_numeric($raw) && !str_contains($raw, '.')) {
            return (int) $raw;
        }

        return $raw;
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => [
                            'type' => 'string',
                            'description' => 'The config action to perform',
                            'enum' => ['get', 'set', 'show', 'list_models', 'switch_model'],
                        ],
                        'key' => [
                            'type' => 'string',
                            'description' => 'Dot-notation config key (e.g. "agents.defaults.model.primary"). Required for get and set.',
                        ],
                        'value' => [
                            'type' => 'string',
                            'description' => 'The value to set. Required for set and switch_model actions. For arrays, use JSON-encoded string.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
