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
}
