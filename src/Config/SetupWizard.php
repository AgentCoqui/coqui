<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Provider\OllamaProvider;
use CarmeloSantana\PHPAgents\Provider\OpenAICompatibleProvider;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Interactive wizard that builds an openclaw.json config file.
 *
 * Guides the user through provider selection, API key entry,
 * model discovery, and role-based model assignment.
 */
final class SetupWizard
{
    /** @var array<string, array<string, mixed>> Configured providers with their settings */
    private array $configuredProviders = [];

    /** @var array<string, string> All available models as "provider/model" => "Display Name" */
    private array $availableModels = [];

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly DefaultsLoader $defaults,
    ) {}

    /**
     * Run the full setup wizard and return the generated config array.
     *
     * @return array<string, mixed>|null Returns null if the user aborts.
     */
    public function run(): ?array
    {
        $this->io->title('Coqui Setup Wizard');
        $this->io->text([
            'This wizard will help you configure Coqui with your preferred AI providers and models.',
            'You can re-run this anytime with <fg=cyan>coqui setup</> or <fg=cyan>/config edit</> in the REPL.',
            '',
        ]);

        // Step 1: Select providers
        $selectedProviders = $this->selectProviders();
        if (empty($selectedProviders)) {
            $this->io->warning('No providers selected. Aborting setup.');
            return null;
        }

        // Step 2: Configure each provider (base URL, API key, discover models)
        foreach ($selectedProviders as $provider) {
            $this->configureProvider($provider);
        }

        if (empty($this->availableModels)) {
            $this->io->warning('No models available. Check your provider configuration.');
            return null;
        }

        // Step 3: Assign roles
        $roles = $this->assignRoles();

        // Step 4: Set primary model
        $primaryModel = $this->selectPrimaryModel($roles);

        // Step 5: Configure workspace
        $workspace = $this->configureWorkspace();

        // Step 6: Build and preview
        $config = $this->buildConfig($primaryModel, $roles, $workspace);

        $this->io->section('Configuration Preview');
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $this->io->writeln($json);
        $this->io->newLine();

        if (!$this->io->confirm('Save this configuration?', true)) {
            $this->io->warning('Configuration not saved.');
            return null;
        }

        return $config;
    }

    /**
     * Run the wizard and save the result to a file.
     */
    public function runAndSave(string $outputPath): bool
    {
        $config = $this->run();

        if ($config === null) {
            return false;
        }

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, $json);
        $this->io->success("Configuration saved to {$outputPath}");

        return true;
    }

    /**
     * Step 1: Let the user select which providers to configure.
     *
     * @return string[]
     */
    private function selectProviders(): array
    {
        $this->io->section('Step 1: Select Providers');

        $choices = [];
        foreach ($this->defaults->providerNames() as $name) {
            $displayName = $this->defaults->providerDisplayName($name);
            $description = $this->defaults->providerDescription($name);
            $choices[$name] = "{$displayName} — {$description}";
        }

        $selected = $this->io->choice(
            'Which providers do you want to configure? (comma-separated for multiple)',
            array_values($choices),
            $choices['ollama'] ?? null,
        );

        // Resolve display strings back to provider keys
        $flipped = array_flip($choices);

        if (is_array($selected)) {
            return array_map(fn(string $s) => $flipped[$s], $selected);
        }

        return [$flipped[$selected]];
    }

    /**
     * Step 2: Configure a single provider (base URL, API key, model discovery).
     */
    private function configureProvider(string $provider): void
    {
        $displayName = $this->defaults->providerDisplayName($provider);
        $this->io->section("Configure {$displayName}");

        // Base URL
        $defaultUrl = $this->defaults->defaultBaseUrl($provider);
        $baseUrl = $this->io->ask('Base URL', $defaultUrl);
        $baseUrl = is_string($baseUrl) ? $baseUrl : $defaultUrl;

        // API Key
        $apiKey = '';
        if ($this->defaults->requiresApiKey($provider)) {
            $envVar = $this->defaults->apiKeyEnvVar($provider);
            $envValue = $envVar !== null ? (getenv($envVar) ?: '') : '';

            if ($envValue !== '') {
                $masked = substr($envValue, 0, 8) . str_repeat('*', max(0, strlen($envValue) - 12)) . substr($envValue, -4);
                $this->io->text("<fg=gray>Found API key in \${$envVar}:</> {$masked}");

                if ($this->io->confirm("Use the key from \${$envVar}?", true)) {
                    $apiKey = "env:{$envVar}";
                }
            }

            if ($apiKey === '') {
                $apiKey = $this->io->askHidden("API key for {$displayName} (or press Enter to skip)") ?? '';

                if ($apiKey === '' && $envVar !== null) {
                    $this->io->text("<fg=yellow>No API key provided.</> Set <fg=cyan>\${$envVar}</> before running Coqui.");
                }
            }
        }

        $this->configuredProviders[$provider] = [
            'baseUrl' => $baseUrl,
            'apiKey' => $apiKey,
            'api' => $this->defaults->provider($provider)['api'] ?? 'openai-completions',
        ];

        // Discover models
        $models = $this->discoverModels($provider, $baseUrl, $apiKey);

        // Let user select which models to include
        $selected = $this->selectModels($provider, $models);

        foreach ($selected as $model) {
            $fullId = "{$provider}/{$model['id']}";
            $this->availableModels[$fullId] = $model['name'] ?? $model['id'];
        }
    }

    /**
     * Discover models from a provider via API, falling back to curated defaults.
     *
     * @return array<int, array<string, mixed>>
     */
    private function discoverModels(string $provider, string $baseUrl, string $apiKey): array
    {
        if (!$this->defaults->supportsModelDiscovery($provider)) {
            $this->io->text('<fg=gray>Using curated model list (no live discovery for this provider).</>');
            return $this->defaults->curatedModels($provider);
        }

        $this->io->text('<fg=gray>Discovering available models...</>');

        try {
            $discovered = $this->fetchModelsFromProvider($provider, $baseUrl, $apiKey);

            if (!empty($discovered)) {
                $this->io->text(sprintf('<fg=green>Found %d models.</>', count($discovered)));
                return $discovered;
            }
        } catch (\Throwable $e) {
            $this->io->text("<fg=yellow>Discovery failed: {$e->getMessage()}</>");
        }

        $this->io->text('<fg=gray>Falling back to curated model list.</>');
        return $this->defaults->curatedModels($provider);
    }

    /**
     * Fetch models from a provider's API.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchModelsFromProvider(string $provider, string $baseUrl, string $apiKey): array
    {
        $resolvedKey = $this->resolveApiKey($apiKey);

        $definitions = match ($provider) {
            'ollama' => (new OllamaProvider(baseUrl: $baseUrl))->models(),
            default => (new OpenAICompatibleProvider(
                model: '',
                baseUrl: $baseUrl,
                apiKey: $resolvedKey,
            ))->models(),
        };

        return array_map(
            fn(ModelDefinition $m) => ['id' => $m->id, 'name' => $m->name],
            $definitions,
        );
    }

    /**
     * Let the user select which discovered models to include in the config.
     *
     * @param array<int, array<string, mixed>> $models
     * @return array<int, array<string, mixed>>
     */
    private function selectModels(string $provider, array $models): array
    {
        if (empty($models)) {
            $this->io->warning("No models available for {$provider}.");
            return [];
        }

        $displayName = $this->defaults->providerDisplayName($provider);

        // Build choice list
        $choices = [];
        foreach ($models as $model) {
            $label = $model['name'] ?? $model['id'];
            $recommended = ($model['recommended'] ?? false) ? ' (recommended)' : '';
            $choices[] = "{$label}{$recommended}";
        }

        // Add "All models" option for small lists
        if (count($models) <= 20) {
            $this->io->text(sprintf('<fg=gray>%d models available for %s:</>', count($models), $displayName));

            $selectedLabels = $this->io->choice(
                "Select models to include (comma-separated for multiple, or 'all')",
                ['All available models', ...$choices],
                'All available models',
            );

            if ($selectedLabels === 'All available models' || (is_array($selectedLabels) && in_array('All available models', $selectedLabels, true))) {
                return $models;
            }

            // Filter to selected
            $selectedSet = is_array($selectedLabels) ? $selectedLabels : [$selectedLabels];
            return array_values(array_filter($models, function (array $model) use ($selectedSet) {
                $label = $model['name'] ?? $model['id'];
                $recommended = ($model['recommended'] ?? false) ? ' (recommended)' : '';
                return in_array("{$label}{$recommended}", $selectedSet, true);
            }));
        }

        // For large lists (e.g. Ollama with many pulled models), show all
        $this->io->text(sprintf('<fg=gray>%d models available for %s. Including all.</>', count($models), $displayName));
        return $models;
    }

    /**
     * Step 3: Assign models to roles.
     *
     * @return array<string, string>
     */
    private function assignRoles(): array
    {
        $this->io->section('Step 3: Assign Models to Roles');

        $modelChoices = [];
        foreach ($this->availableModels as $fullId => $name) {
            $modelChoices[$fullId] = "{$name} ({$fullId})";
        }

        $roles = [];
        $roleDefinitions = $this->defaults->roles();

        foreach ($roleDefinitions as $roleName => $roleDef) {
            $description = $roleDef['description'] ?? '';
            $required = $roleDef['required'] ?? false;

            if (!$required) {
                $choices = ['Same as orchestrator', ...array_values($modelChoices)];
            } else {
                $choices = array_values($modelChoices);
            }

            $selected = $this->io->choice(
                "<fg=cyan>{$roleName}</> — {$description}",
                $choices,
                $choices[0],
            );

            if ($selected === 'Same as orchestrator' && isset($roles['orchestrator'])) {
                $roles[$roleName] = $roles['orchestrator'];
            } else {
                // Resolve back to full model ID
                $flipped = array_flip($modelChoices);
                $roles[$roleName] = is_string($selected) ? ($flipped[$selected] ?? (string) array_key_first($this->availableModels)) : (string) array_key_first($this->availableModels);
            }
        }

        return $roles;
    }

    /**
     * Step 4: Select the primary model.
     *
     * @param array<string, string> $roles
     */
    private function selectPrimaryModel(array $roles): string
    {
        $this->io->section('Step 4: Primary Model');

        $orchestratorModel = $roles['orchestrator'] ?? (string) array_key_first($this->availableModels);
        $this->io->text("The primary model is used as the default for any unassigned roles.");

        if ($this->io->confirm("Use the orchestrator model ({$orchestratorModel}) as primary?", true)) {
            return $orchestratorModel;
        }

        $modelChoices = [];
        foreach ($this->availableModels as $fullId => $name) {
            $modelChoices[$fullId] = "{$name} ({$fullId})";
        }

        $selected = $this->io->choice('Select primary model', array_values($modelChoices));
        $flipped = array_flip($modelChoices);

        return is_string($selected) ? ($flipped[$selected] ?? $orchestratorModel) : $orchestratorModel;
    }

    /**
     * Step 5: Configure the workspace directory.
     */
    private function configureWorkspace(): string
    {
        $this->io->section('Step 5: Workspace');

        $default = $this->defaults->defaultWorkspace();
        $this->io->text('The workspace is a sandboxed directory where Coqui reads and writes files.');

        $workspace = $this->io->ask('Workspace directory', $default);

        return is_string($workspace) ? $workspace : $default;
    }

    /**
     * Build the final openclaw.json config array.
     *
     * @param array<string, string> $roles
     * @return array<string, mixed>
     */
    private function buildConfig(string $primaryModel, array $roles, string $workspace): array
    {
        // Build model alias map
        $aliases = [];
        $modelDefinitions = [];

        foreach ($this->configuredProviders as $providerName => $providerConfig) {
            $providerModels = [];

            foreach ($this->availableModels as $fullId => $name) {
                [$p, $modelId] = explode('/', $fullId, 2);
                if ($p !== $providerName) {
                    continue;
                }

                $providerModels[] = [
                    'id' => $modelId,
                    'name' => $name,
                    'reasoning' => false,
                    'input' => ['text'],
                    'cost' => ['input' => 0, 'output' => 0, 'cacheRead' => 0, 'cacheWrite' => 0],
                    'contextWindow' => 128000,
                    'maxTokens' => 8192,
                ];
            }

            if (!empty($providerModels)) {
                $config = [
                    'baseUrl' => $providerConfig['baseUrl'],
                    'api' => $providerConfig['api'],
                    'models' => $providerModels,
                ];

                // Only include apiKey if it was provided
                $apiKey = $providerConfig['apiKey'] ?? '';
                if ($apiKey !== '') {
                    if (str_starts_with($apiKey, 'env:')) {
                        // Reference env var — store variable name for documentation
                        $config['apiKey'] = '$' . substr($apiKey, 4);
                    } else {
                        $config['apiKey'] = $apiKey;
                    }
                }

                $modelDefinitions[$providerName] = $config;
            }
        }

        return [
            'agents' => [
                'defaults' => [
                    'workspace' => $workspace,
                    'model' => [
                        'primary' => $primaryModel,
                    ],
                    'roles' => $roles,
                ],
            ],
            'models' => [
                'mode' => 'merge',
                'providers' => $modelDefinitions,
            ],
        ];
    }

    /**
     * Resolve an API key that may be an env: reference.
     */
    private function resolveApiKey(string $apiKey): string
    {
        if (str_starts_with($apiKey, 'env:')) {
            $envVar = substr($apiKey, 4);
            return getenv($envVar) ?: '';
        }

        return $apiKey;
    }
}
