<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Exception\ConfigNotFoundException;

final class OpenClawConfig implements ConfigInterface
{
    /** @var array<string, mixed> */
    private array $data;

    /** @var array<string, string> */
    private array $aliases = [];

    /** @var array<string, ModelDefinition> */
    private array $modelDefinitions = [];

    public static function fromFile(string $path): self
    {
        if (!file_exists($path)) {
            throw ConfigNotFoundException::forPath($path);
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw ConfigNotFoundException::unreadable($path);
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return new self($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(array $data)
    {
        $this->data = $data;
        $this->buildAliasMap();
        $this->buildModelDefinitions();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->data;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public function has(string $key): bool
    {
        $keys = explode('.', $key);
        $value = $this->data;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    public function resolveModel(string $modelOrAlias): string
    {
        return $this->aliases[$modelOrAlias] ?? $modelOrAlias;
    }

    public function getPrimaryModel(): string
    {
        $primary = $this->get('agents.defaults.model.primary', '');

        return is_string($primary) ? $primary : '';
    }

    public function getDefaultProfile(): ?string
    {
        $profile = $this->get('agents.defaults.profile');

        if (!is_string($profile)) {
            return null;
        }

        $profile = strtolower(trim($profile));

        return $profile !== '' ? $profile : null;
    }

    /**
     * Get fallback model strings, ordered by priority.
     *
     * @return string[]
     */
    public function getFallbacks(): array
    {
        $fallbacks = $this->get('agents.defaults.model.fallbacks', []);

        return is_array($fallbacks) ? $fallbacks : [];
    }

    /**
     * Get the utility model string for cheap single-shot tasks
     * (titles, summarization, memory compression).
     *
     * Resolution: openclaw.json → COQUI_UTILITY_MODEL env → empty string.
     * Empty string signals the caller to fall through to role-based resolution.
     */
    public function getUtilityModel(): string
    {
        $model = $this->get('agents.defaults.model.utility', '');

        if (is_string($model) && $model !== '') {
            return $model;
        }

        $env = getenv('COQUI_UTILITY_MODEL');

        return is_string($env) && $env !== '' ? $env : '';
    }

    public function getImageModel(): ?string
    {
        $model = $this->get('agents.defaults.imageModel.primary');

        return is_string($model) ? $model : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getImageConfig(): array
    {
        $config = $this->get('agents.defaults.imageModel', []);

        return is_array($config) ? $config : [];
    }

    /**
     * Get the maximum iteration cap for background tasks.
     *
     * This is a per-task safety limit that prevents any single background
     * task from running indefinitely. Configurable via openclaw.json.
     */
    public function getBackgroundTaskMaxIterations(): int
    {
        $value = $this->get('agents.defaults.backgroundTaskMaxIterations');

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        return CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS;
    }

    /**
     * Get code review configuration.
     *
     * @return array{enabled: bool, maxRounds: int, autoIterate: bool}
     */
    public function getCodeReviewConfig(): array
    {
        $enabled = $this->get('agents.defaults.codeReview.enabled');
        $maxRounds = $this->get('agents.defaults.codeReview.maxRounds');
        $autoIterate = $this->get('agents.defaults.codeReview.autoIterate');

        return [
            'enabled' => is_bool($enabled) ? $enabled : CoquiDefaults::CODE_REVIEW_ENABLED,
            'maxRounds' => is_int($maxRounds) && $maxRounds >= 1 ? $maxRounds : CoquiDefaults::CODE_REVIEW_MAX_ROUNDS,
            'autoIterate' => is_bool($autoIterate) ? $autoIterate : CoquiDefaults::CODE_REVIEW_AUTO_ITERATE,
        ];
    }

    public function getProviderConfig(string $provider): array
    {
        $config = $this->get("models.providers.{$provider}", []);

        return is_array($config) ? $config : [];
    }

    public function getModelDefinition(string $model): ?ModelDefinition
    {
        return $this->modelDefinitions[$model] ?? null;
    }

    /**
     * Get notification system configuration.
     *
     * @return array{
     *     enabled: bool,
     *     replDisplayLimit: int,
     *     promptInjectionLimit: int,
     *     retentionHours: array{informational: int, actionable: int},
     *     automation: array{enabled: bool, processTickSeconds: int, reclaimTickSeconds: int, leaseSeconds: int, batchSize: int, maxAttempts: int, retryDelaySeconds: int}
     * }
     */
    public function getNotificationConfig(): array
    {
        $enabled = $this->get('agents.defaults.notifications.enabled');
        $replLimit = $this->get('agents.defaults.notifications.replDisplayLimit');
        $promptLimit = $this->get('agents.defaults.notifications.promptInjectionLimit');
        $retInfoHours = $this->get('agents.defaults.notifications.retentionHours.informational');
        $retActionHours = $this->get('agents.defaults.notifications.retentionHours.actionable');
        $automationEnabled = $this->get('agents.defaults.notifications.automation.enabled');
        $processTickSeconds = $this->get('agents.defaults.notifications.automation.processTickSeconds');
        $reclaimTickSeconds = $this->get('agents.defaults.notifications.automation.reclaimTickSeconds');
        $leaseSeconds = $this->get('agents.defaults.notifications.automation.leaseSeconds');
        $batchSize = $this->get('agents.defaults.notifications.automation.batchSize');
        $maxAttempts = $this->get('agents.defaults.notifications.automation.maxAttempts');
        $retryDelaySeconds = $this->get('agents.defaults.notifications.automation.retryDelaySeconds');

        return [
            'enabled' => is_bool($enabled) ? $enabled : CoquiDefaults::NOTIFICATION_ENABLED,
            'replDisplayLimit' => is_int($replLimit) && $replLimit >= 1 ? $replLimit : CoquiDefaults::NOTIFICATION_REPL_DISPLAY_LIMIT,
            'promptInjectionLimit' => is_int($promptLimit) && $promptLimit >= 1 ? $promptLimit : CoquiDefaults::NOTIFICATION_PROMPT_INJECTION_LIMIT,
            'retentionHours' => [
                'informational' => is_int($retInfoHours) && $retInfoHours >= 1 ? $retInfoHours : CoquiDefaults::NOTIFICATION_RETENTION_INFORMATIONAL_HOURS,
                'actionable' => is_int($retActionHours) && $retActionHours >= 1 ? $retActionHours : CoquiDefaults::NOTIFICATION_RETENTION_ACTIONABLE_HOURS,
            ],
            'automation' => [
                'enabled' => is_bool($automationEnabled) ? $automationEnabled : CoquiDefaults::NOTIFICATION_AUTOMATION_ENABLED,
                'processTickSeconds' => is_int($processTickSeconds) && $processTickSeconds >= 1 ? $processTickSeconds : CoquiDefaults::NOTIFICATION_AUTOMATION_PROCESS_TICK_SECONDS,
                'reclaimTickSeconds' => is_int($reclaimTickSeconds) && $reclaimTickSeconds >= 1 ? $reclaimTickSeconds : CoquiDefaults::NOTIFICATION_AUTOMATION_RECLAIM_TICK_SECONDS,
                'leaseSeconds' => is_int($leaseSeconds) && $leaseSeconds >= 1 ? $leaseSeconds : CoquiDefaults::NOTIFICATION_AUTOMATION_LEASE_SECONDS,
                'batchSize' => is_int($batchSize) && $batchSize >= 1 ? $batchSize : CoquiDefaults::NOTIFICATION_AUTOMATION_BATCH_SIZE,
                'maxAttempts' => is_int($maxAttempts) && $maxAttempts >= 1 ? $maxAttempts : CoquiDefaults::NOTIFICATION_AUTOMATION_MAX_ATTEMPTS,
                'retryDelaySeconds' => is_int($retryDelaySeconds) && $retryDelaySeconds >= 1 ? $retryDelaySeconds : CoquiDefaults::NOTIFICATION_AUTOMATION_RETRY_DELAY_SECONDS,
            ],
        ];
    }

    /**
     * Get normalized channel configuration.
     *
     * @return array{
     *     defaults: array<string, mixed>,
     *     instances: array<string, mixed>
     * }
     */
    public function getChannelConfig(): array
    {
        $channels = $this->get('channels', []);
        $defaults = is_array($channels) && isset($channels['defaults']) && is_array($channels['defaults'])
            ? $channels['defaults']
            : [];
        $instances = is_array($channels) && isset($channels['instances']) && is_array($channels['instances'])
            ? $channels['instances']
            : [];

        $normalizedDefaults = [
            'unknownUserPolicy' => is_string($defaults['unknownUserPolicy'] ?? null)
                ? $defaults['unknownUserPolicy']
                : CoquiDefaults::CHANNEL_UNKNOWN_USER_POLICY,
            'executionPolicy' => is_string($defaults['executionPolicy'] ?? null)
                ? $defaults['executionPolicy']
                : CoquiDefaults::CHANNEL_EXECUTION_POLICY,
            'inboundRateLimit' => is_int($defaults['inboundRateLimit'] ?? null) && $defaults['inboundRateLimit'] > 0
                ? $defaults['inboundRateLimit']
                : CoquiDefaults::CHANNEL_INBOUND_RATE_LIMIT,
            'outboundConcurrency' => is_int($defaults['outboundConcurrency'] ?? null) && $defaults['outboundConcurrency'] > 0
                ? $defaults['outboundConcurrency']
                : CoquiDefaults::CHANNEL_OUTBOUND_CONCURRENCY,
            'healthCheckIntervalSeconds' => is_int($defaults['healthCheckIntervalSeconds'] ?? null) && $defaults['healthCheckIntervalSeconds'] > 0
                ? $defaults['healthCheckIntervalSeconds']
                : CoquiDefaults::CHANNEL_HEALTH_CHECK_INTERVAL_SECONDS,
        ];

        if (isset($defaults['defaultProfile']) && is_string($defaults['defaultProfile']) && trim($defaults['defaultProfile']) !== '') {
            $normalizedDefaults['defaultProfile'] = trim($defaults['defaultProfile']);
        }

        return [
            'defaults' => $normalizedDefaults,
            'instances' => $instances,
        ];
    }

    private function buildAliasMap(): void
    {
        $models = $this->get('agents.defaults.models', []);

        if (!is_array($models)) {
            return;
        }

        foreach ($models as $fullModel => $config) {
            if (is_array($config) && isset($config['alias']) && is_string($config['alias'])) {
                $this->aliases[$config['alias']] = $fullModel;
            }
        }
    }

    private function buildModelDefinitions(): void
    {
        $providers = $this->get('models.providers', []);

        if (!is_array($providers)) {
            return;
        }

        foreach ($providers as $providerName => $providerConfig) {
            if (!is_array($providerConfig) || !isset($providerConfig['models'])) {
                continue;
            }

            $models = $providerConfig['models'];
            if (!is_array($models)) {
                continue;
            }

            foreach ($models as $modelData) {
                if (!is_array($modelData) || !isset($modelData['id'])) {
                    continue;
                }

                $fullId = "{$providerName}/{$modelData['id']}";
                $this->modelDefinitions[$fullId] = ModelDefinition::fromOpenClaw(
                    $providerName,
                    $modelData,
                );
            }
        }
    }

    /**
     * Get the budget exit threshold (0.0–1.0).
     *
     * When context window usage reaches this percentage, the agent enters
     * a wrap-up window and exits with BudgetExhausted. 0.0 = disabled.
     */
    public function getBudgetExitThreshold(): float
    {
        $value = $this->get('agents.defaults.context.budgetExitThreshold');

        if (is_numeric($value)) {
            $float = (float) $value;
            if ($float >= 0.0 && $float <= 1.0) {
                return $float;
            }
        }

        return CoquiDefaults::BUDGET_EXIT_THRESHOLD;
    }

    /**
     * Get the number of wrap-up iterations after budget threshold is reached.
     */
    public function getBudgetExitWrapUpIterations(): int
    {
        $value = $this->get('agents.defaults.context.budgetExitWrapUpIterations');

        if (is_int($value) && $value >= 1) {
            return $value;
        }

        return CoquiDefaults::BUDGET_EXIT_WRAP_UP_ITERATIONS;
    }

    /**
     * Whether filesystem-backed artifacts are enabled.
     *
     * When true, eligible artifact types (plan, document, code, config)
     * write canonical content to the workspace filesystem and treat disk
     * as the source of truth. DB snapshots remain for version history
     * and coordination. Opt-in via config: agents.defaults.artifacts.filesystemBacked
     */
    public function isArtifactFilesystemBacked(): bool
    {
        $value = $this->get('agents.defaults.artifacts.filesystemBacked');

        return is_bool($value) ? $value : CoquiDefaults::ARTIFACT_FILESYSTEM_BACKED;
    }
}
