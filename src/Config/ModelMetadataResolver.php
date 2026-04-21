<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Contract\CoquiDefaults;

final class ModelMetadataResolver
{
    /** @var array<string, array<string, ModelDefinition>> */
    private array $providerModelCache = [];

    public function __construct(
        private readonly DefaultsLoader $defaults,
        private readonly ModelFamilyResolver $familyResolver,
        private readonly ?OpenClawConfig $config = null,
        private readonly ?ProviderFactory $providerFactory = null,
    ) {}

    public function resolve(string $modelString, bool $preferProvider = false): ?ModelDefinition
    {
        [$provider, $modelId] = $this->splitModelString($modelString);
        if ($provider === null || $modelId === null) {
            return null;
        }

        $configured = $this->resolveConfiguredDefinition($provider, $modelId);
        $live = $preferProvider ? $this->discoverProviderModel($provider, $modelId) : null;
        $curated = $this->resolveCuratedDefinition($provider, $modelId);
        $family = $this->resolveFamilyDefinition($provider, $modelId, $configured, $live, $curated);

        return $this->composeDefinition($provider, $modelId, $configured, $live, $curated, $family, $preferProvider);
    }

    public function enrichDiscovered(string $provider, ModelDefinition $definition): ModelDefinition
    {
        $curated = $this->resolveCuratedDefinition($provider, $definition->id);
        $family = $this->resolveFamilyDefinition($provider, $definition->id, null, $definition, $curated);

        return $this->composeDefinition($provider, $definition->id, null, $definition, $curated, $family, true)
            ?? $definition;
    }

    /**
     * @return array<string, ModelDefinition>
     */
    public function configuredModels(bool $preferProvider = false): array
    {
        if ($this->config === null) {
            return [];
        }

        $providers = $this->config->get('models.providers', []);
        if (!is_array($providers)) {
            return [];
        }

        $models = [];

        foreach ($providers as $providerName => $providerConfig) {
            if (!is_array($providerConfig)) {
                continue;
            }

            $configuredModels = $providerConfig['models'] ?? null;
            if (!is_array($configuredModels)) {
                continue;
            }

            foreach ($configuredModels as $modelData) {
                if (!is_array($modelData) || !is_string($modelData['id'] ?? null) || $modelData['id'] === '') {
                    continue;
                }

                $fullId = $providerName . '/' . $modelData['id'];
                $models[$fullId] = $this->resolve($fullId, $preferProvider)
                    ?? ModelDefinition::fromOpenClaw($providerName, $modelData);
            }
        }

        return $models;
    }

    private function resolveConfiguredDefinition(string $provider, string $modelId): ?ModelDefinition
    {
        if ($this->config === null) {
            return null;
        }

        return $this->config->getModelDefinition($provider . '/' . $modelId)
            ?? $this->config->getModelDefinition($modelId);
    }

    private function resolveCuratedDefinition(string $provider, string $modelId): ?ModelDefinition
    {
        $curated = $this->defaults->curatedModel($provider, $modelId);
        if ($curated === null) {
            return null;
        }

        return ModelDefinition::fromOpenClaw($provider, $curated);
    }

    private function resolveFamilyDefinition(
        string $provider,
        string $modelId,
        ?ModelDefinition $configured,
        ?ModelDefinition $live,
        ?ModelDefinition $curated,
    ): ?ModelDefinition {
        $family = $this->firstNonEmptyString([
            $configured?->family,
            $live?->family,
            $curated?->family,
            $this->familyResolver->resolveFamily($modelId),
        ]);

        if ($family === null) {
            return null;
        }

        $defaults = $this->defaults->familyDefaults($family);
        if ($defaults === null) {
            return null;
        }

        return new ModelDefinition(
            id: $modelId,
            name: $modelId,
            provider: $provider,
            capabilities: [ModelCapability::Text],
            contextWindow: $defaults['contextWindow'],
            maxTokens: $defaults['maxTokens'],
            family: $family,
            metadataSource: 'family-default',
            fieldSources: [
                'contextWindow' => 'family-default',
                'maxTokens' => 'family-default',
            ],
        );
    }

    private function discoverProviderModel(string $provider, string $modelId): ?ModelDefinition
    {
        if ($this->providerFactory === null) {
            return null;
        }

        if (!array_key_exists($provider, $this->providerModelCache)) {
            $definitions = [];

            try {
                foreach ($this->providerFactory->create($provider . '/' . $modelId)->models() as $definition) {
                    $definitions[$definition->id] = $definition;
                }
            } catch (\Throwable) {
                $definitions = [];
            }

            $this->providerModelCache[$provider] = $definitions;
        }

        return $this->providerModelCache[$provider][$modelId] ?? null;
    }

    private function composeDefinition(
        string $provider,
        string $modelId,
        ?ModelDefinition $configured,
        ?ModelDefinition $live,
        ?ModelDefinition $curated,
        ?ModelDefinition $family,
        bool $preferProvider,
    ): ?ModelDefinition {
        $sources = array_values(array_filter([$configured, $live, $curated, $family]));
        if ($sources === []) {
            return null;
        }

        $identityPriority = [$configured, $live, $curated, $family];
        $budgetPriority = $preferProvider
            ? [$live, $configured, $curated, $family]
            : [$configured, $live, $curated, $family];
        $identitySources = $this->nonNullDefinitions($identityPriority);
        $budgetSources = $this->nonNullDefinitions($budgetPriority);

        $contextWindow = $this->selectContextWindow($budgetSources);
        $maxTokens = $this->selectMaxTokens($budgetSources);

        if ($contextWindow === null) {
            $contextWindow = CoquiDefaults::CONTEXT_WINDOW_FALLBACK;
        }

        if ($maxTokens === null) {
            $maxTokens = CoquiDefaults::CONTEXT_WINDOW_RESERVED;
        }

        $extras = [];
        foreach (array_reverse($sources) as $source) {
            $extras = array_replace($extras, $source->extras);
        }

        return new ModelDefinition(
            id: $modelId,
            name: $this->firstNonEmptyString(array_map(static fn(ModelDefinition $definition): string => $definition->name, $sources)) ?? $modelId,
            provider: $provider,
            capabilities: $this->mergeCapabilities($sources),
            reasoning: $this->anyTrue($sources, static fn(ModelDefinition $definition): bool => $definition->reasoning),
            contextWindow: $contextWindow,
            maxTokens: $maxTokens,
            alias: $this->firstNonEmptyString(array_map(static fn(ModelDefinition $definition): ?string => $definition->alias, $identitySources)),
            numCtx: $this->firstPositiveInt(array_map(static fn(ModelDefinition $definition): ?int => $definition->numCtx, $budgetSources)),
            family: $this->firstNonEmptyString(array_map(static fn(ModelDefinition $definition): ?string => $definition->family, $identitySources)),
            toolCalls: $this->anyTrue($sources, static fn(ModelDefinition $definition): bool => $definition->toolCalls),
            vision: $this->anyTrue($sources, static fn(ModelDefinition $definition): bool => $definition->vision),
            thinking: $this->anyTrue($sources, static fn(ModelDefinition $definition): bool => $definition->thinking),
            metadataSource: $this->firstNonEmptyString(array_map(static fn(ModelDefinition $definition): ?string => $definition->metadataSource, $budgetSources)) ?? 'merged',
            fieldSources: $this->mergeFieldSources($sources),
            extras: $extras,
        );
    }

    /**
     * @param array<int, ?ModelDefinition> $definitions
     */
    private function selectContextWindow(array $definitions): ?int
    {
        foreach ($definitions as $definition) {
            if (!$definition instanceof ModelDefinition) {
                continue;
            }

            $numCtx = $definition->numCtx;
            if ($numCtx !== null && $numCtx > CoquiDefaults::CONTEXT_WINDOW_RESERVED) {
                return $numCtx;
            }

            $contextWindow = $definition->contextWindow;
            if ($contextWindow > CoquiDefaults::CONTEXT_WINDOW_RESERVED) {
                return $contextWindow;
            }
        }

        foreach ($definitions as $definition) {
            if ($definition instanceof ModelDefinition && $definition->contextWindow > 0) {
                return $definition->contextWindow;
            }
        }

        return null;
    }

    /**
     * @param array<int, ?ModelDefinition> $definitions
     */
    private function selectMaxTokens(array $definitions): ?int
    {
        foreach ($definitions as $definition) {
            if ($definition instanceof ModelDefinition && $definition->maxTokens > 0) {
                return $definition->maxTokens;
            }
        }

        return null;
    }

    /**
     * @param ModelDefinition[] $definitions
     * @return ModelCapability[]
     */
    private function mergeCapabilities(array $definitions): array
    {
        $capabilities = [];

        foreach ($definitions as $definition) {
            foreach ($definition->capabilities as $capability) {
                $capabilities[$capability->value] = $capability;
            }

            if ($definition->vision) {
                $capabilities[ModelCapability::Image->value] = ModelCapability::Image;
            }
        }

        if ($capabilities === []) {
            $capabilities[ModelCapability::Text->value] = ModelCapability::Text;
        }

        return array_values($capabilities);
    }

    /**
     * @param ModelDefinition[] $definitions
     * @return array<string, string>
     */
    private function mergeFieldSources(array $definitions): array
    {
        $fieldSources = [];

        foreach (array_reverse($definitions) as $definition) {
            $fieldSources = array_replace($fieldSources, $definition->fieldSources);
        }

        return $fieldSources;
    }

    /**
     * @param array<int, ?string> $values
     */
    private function firstNonEmptyString(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param array<int, ?int> $values
     */
    private function firstPositiveInt(array $values): ?int
    {
        foreach ($values as $value) {
            if (is_int($value) && $value > 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param ModelDefinition[] $definitions
     * @param callable(ModelDefinition): bool $predicate
     */
    private function anyTrue(array $definitions, callable $predicate): bool
    {
        foreach ($definitions as $definition) {
            if ($predicate($definition)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, ?ModelDefinition> $definitions
     * @return ModelDefinition[]
     */
    private function nonNullDefinitions(array $definitions): array
    {
        return array_values(array_filter(
            $definitions,
            static fn(?ModelDefinition $definition): bool => $definition instanceof ModelDefinition,
        ));
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function splitModelString(string $modelString): array
    {
        $parts = explode('/', $modelString, 2);
        if ($parts[0] === '') {
            return [null, null];
        }

        if (count($parts) === 1 || $parts[1] === '') {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }
}