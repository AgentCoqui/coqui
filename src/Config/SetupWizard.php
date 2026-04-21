<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Repl\InterruptiblePrompt;
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

    /** @var array<string, array<string, mixed>> Full model metadata keyed by "provider/model" */
    private array $modelMetadata = [];

    private readonly InterruptiblePrompt $prompt;
    private readonly ModelMetadataResolver $modelMetadataResolver;

    public function __construct(
        private readonly SymfonyStyle $io,
        private readonly DefaultsLoader $defaults,
        private readonly ?CredentialResolver $credentialResolver = null,
        ?ModelMetadataResolver $modelMetadataResolver = null,
    ) {
        $this->prompt = new InterruptiblePrompt($io);
        $this->modelMetadataResolver = $modelMetadataResolver
            ?? new ModelMetadataResolver($defaults, new ModelFamilyResolver($defaults->familyNames()));
    }

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

        // Step 5: Image generation defaults
        $imageModelConfig = $this->configureImageGeneration();

        // Step 6: Child background tasks
        $childBackgroundTasks = $this->configureChildBackgroundTasks();

        // Step 7: Memory extraction behavior
        $memoryAutoExtract = $this->configureMemoryExtraction();

        // Step 8: Summarization behavior
        $summarizationConfig = $this->configureSummarization();

        // Step 9: Configure workspace
        $workspace = $this->configureWorkspace();

        // Step 10: Default profile
        $defaultProfile = $this->configureDefaultProfile($workspace);

        // Step 11: Update preferences (ENV-based, not in openclaw.json)
        $this->configureUpdatePreferences();

        // Step 12: Generate API key for HTTP API server
        $this->configureApiKey();

        // Step 13: Configure directory mounts
        $mounts = $this->configureMounts();

        // Build and preview
        $config = $this->buildConfig($primaryModel, $roles, $workspace, $mounts, $childBackgroundTasks, $memoryAutoExtract, $summarizationConfig, $defaultProfile, $imageModelConfig);

        $this->io->section('Configuration Preview');
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $this->io->writeln($json);
        $this->io->newLine();

        if (!$this->confirm('Save this configuration?', true)) {
            $this->io->warning('Configuration not saved.');
            return null;
        }

        return $config;
    }

    /**
     * Run the wizard and save the result to a file.
     *
     * When an existing config is available, presents a section menu
     * instead of running the full linear wizard.
     *
     * @param array<string, mixed>|null $existingConfig Existing openclaw.json data for section-based editing.
     */
    public function runAndSave(string $outputPath, ?array $existingConfig = null): bool
    {
        $config = ($existingConfig !== null && $existingConfig !== [])
            ? $this->runEdit($existingConfig)
            : $this->run();

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
     * Run section-based editing against an existing configuration.
     *
     * Presents a menu of editable sections. Only the selected sections
     * run their interactive configure methods; unselected sections
     * retain their existing values in the output config.
     *
     * @param array<string, mixed> $existingConfig The current openclaw.json data.
     * @return array<string, mixed>|null Returns null if the user aborts.
     */
    public function runEdit(array $existingConfig): ?array
    {
        $this->io->title('Coqui Configuration Editor');
        $this->io->text([
            'Select which sections to reconfigure. Unselected sections keep their current values.',
            '',
        ]);

        $sections = [
            'providers' => 'Providers & Models (providers, model discovery, role assignments, primary model)',
            'image_model' => 'Image Generation Defaults (/image and image toolkits)',
            'child_bg'  => 'Child Background Tasks (allow child agents to spawn background tasks)',
            'summarization' => 'Summarization (auto-summarization mode and thresholds)',
            'workspace' => 'Workspace Directory',
            'profile'   => 'Default Profile',
            'updates'   => 'Update Preferences (check/auto-update on startup)',
            'api_key'   => 'API Server Key',
            'mounts'    => 'Directory Mounts',
        ];

        $selected = $this->choice(
            'Which sections do you want to edit? (comma-separated for multiple)',
            array_values($sections),
            null,
        );

        $selectedValues = [$selected];
        $selectedKeys = [];
        $flipped = array_flip($sections);
        foreach ($selectedValues as $val) {
            if (isset($flipped[$val])) {
                $selectedKeys[] = $flipped[$val];
            }
        }

        if ($selectedKeys === []) {
            $this->io->info('No sections selected. Configuration unchanged.');
            return null;
        }

        $defaults = $existingConfig['agents']['defaults'] ?? [];
        $currentImageModelConfig = $this->extractExistingImageConfig($existingConfig);

        // --- Providers & Models ---
        if (in_array('providers', $selectedKeys, true)) {
            $selectedProviders = $this->selectProviders();
            if (empty($selectedProviders)) {
                $this->io->warning('No providers selected. Aborting.');
                return null;
            }

            foreach ($selectedProviders as $provider) {
                $this->configureProvider($provider);
            }

            if (empty($this->availableModels)) {
                $this->io->warning('No models available. Check your provider configuration.');
                return null;
            }

            $roles = $this->assignRoles();
            $primaryModel = $this->selectPrimaryModel($roles);
        } else {
            $roles = is_array($defaults['roles'] ?? null) ? $defaults['roles'] : [];
            $primaryModel = is_string($defaults['model']['primary'] ?? null) ? $defaults['model']['primary'] : '';
        }

        // --- Image Generation Defaults ---
        if (in_array('image_model', $selectedKeys, true) || in_array('providers', $selectedKeys, true)) {
            $imageModelConfig = $this->configureImageGeneration($currentImageModelConfig);
        } else {
            $imageModelConfig = $currentImageModelConfig;
        }

        // --- Child Background Tasks ---
        if (in_array('child_bg', $selectedKeys, true)) {
            $childBackgroundTasks = $this->configureChildBackgroundTasks();
        } else {
            $childBackgroundTasks = !empty($defaults['childBackgroundTasks']);
        }

        // --- Summarization ---
        if (in_array('summarization', $selectedKeys, true)) {
            $summarizationConfig = $this->configureSummarization();
        } else {
            $summarizationConfig = is_array($defaults['context'] ?? null) ? $defaults['context'] : [];
        }

        // --- Workspace ---
        if (in_array('workspace', $selectedKeys, true)) {
            $workspace = $this->configureWorkspace();
        } else {
            $workspace = is_string($defaults['workspace'] ?? null) ? $defaults['workspace'] : $this->defaults->defaultWorkspace();
        }

        if (in_array('profile', $selectedKeys, true) || in_array('workspace', $selectedKeys, true)) {
            $currentDefaultProfile = is_string($defaults['profile'] ?? null) ? $defaults['profile'] : null;
            $defaultProfile = $this->configureDefaultProfile($workspace, $currentDefaultProfile);
        } else {
            $defaultProfile = is_string($defaults['profile'] ?? null) ? $defaults['profile'] : null;
        }

        // --- Updates (ENV-based, no config output) ---
        if (in_array('updates', $selectedKeys, true)) {
            $this->configureUpdatePreferences();
        }

        // --- API Key (ENV-based, no config output) ---
        if (in_array('api_key', $selectedKeys, true)) {
            $this->configureApiKey();
        }

        // --- Mounts ---
        if (in_array('mounts', $selectedKeys, true)) {
            $mounts = $this->configureMounts();
        } else {
            $mounts = is_array($defaults['mounts'] ?? null) ? $defaults['mounts'] : [];
        }

        // Build the new config, merging with existing for non-edited sections
        $config = $this->buildEditedConfig(
            $existingConfig,
            $primaryModel,
            $roles,
            $workspace,
            $mounts,
            $childBackgroundTasks,
            in_array('providers', $selectedKeys, true),
            $summarizationConfig,
            $defaultProfile,
            $imageModelConfig,
        );

        $this->io->section('Configuration Preview');
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            $json = '{}';
        }
        $this->io->writeln($json);
        $this->io->newLine();

        if (!$this->confirm('Save this configuration?', true)) {
            $this->io->warning('Configuration not saved.');
            return null;
        }

        return $config;
    }

    /**
     * Build a config array for section-based editing, preserving unedited sections.
     *
     * @param array<string, mixed> $existingConfig
     * @param array<string, string> $roles
     * @param array<int, array{path: string, alias: string, access: string, description?: string}> $mounts
    * @param array<string, mixed> $summarizationConfig
    * @param array<string, mixed> $imageModelConfig
     * @return array<string, mixed>
     */
    private function buildEditedConfig(
        array $existingConfig,
        string $primaryModel,
        array $roles,
        string $workspace,
        array $mounts,
        bool $childBackgroundTasks,
        bool $providersEdited,
        array $summarizationConfig = [],
        ?string $defaultProfile = null,
        array $imageModelConfig = [],
    ): array {
        $config = $existingConfig;

        // Update defaults
        $config['agents']['defaults']['workspace'] = $workspace;
        $config['agents']['defaults']['model']['primary'] = $primaryModel;
        $config['agents']['defaults']['roles'] = $roles;

        if ($defaultProfile !== null && trim($defaultProfile) !== '') {
            $config['agents']['defaults']['profile'] = strtolower(trim($defaultProfile));
        } else {
            unset($config['agents']['defaults']['profile']);
        }

        if ($childBackgroundTasks) {
            $config['agents']['defaults']['childBackgroundTasks'] = true;
        } else {
            unset($config['agents']['defaults']['childBackgroundTasks']);
        }

        if ($summarizationConfig !== []) {
            $config['agents']['defaults']['context'] = $summarizationConfig;
        }

        if ($imageModelConfig !== []) {
            $config['agents']['defaults']['model']['imageModel'] = $imageModelConfig['primary'] ?? null;
            $config['agents']['defaults']['model']['imageFallbacks'] = $imageModelConfig['fallbacks'] ?? [];

            $images = [
                'providers' => $imageModelConfig['providers'] ?? [],
            ];

            if (isset($imageModelConfig['ownerName']) && is_string($imageModelConfig['ownerName']) && trim($imageModelConfig['ownerName']) !== '') {
                $images['ownerName'] = trim($imageModelConfig['ownerName']);
            }

            $config['images'] = $images;
        } else {
            unset($config['agents']['defaults']['model']['imageModel']);
            unset($config['agents']['defaults']['model']['imageFallbacks']);
            unset($config['images']);
        }

        if ($mounts !== []) {
            $config['agents']['defaults']['mounts'] = $mounts;
        } else {
            unset($config['agents']['defaults']['mounts']);
        }

        // Only rebuild the models section when providers were re-configured
        if ($providersEdited) {
            $config['models'] = $this->buildConfig($primaryModel, $roles, $workspace, $mounts, $childBackgroundTasks, imageModelConfig: $imageModelConfig)['models'];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return array<string, mixed>
     */
    private function extractExistingImageConfig(array $existingConfig): array
    {
        $modelDefaults = $existingConfig['agents']['defaults']['model'] ?? [];
        $images = $existingConfig['images'] ?? [];

        if (!is_array($modelDefaults)) {
            $modelDefaults = [];
        }

        if (!is_array($images)) {
            $images = [];
        }

        $config = [
            'primary' => is_string($modelDefaults['imageModel'] ?? null) ? $modelDefaults['imageModel'] : null,
            'fallbacks' => is_array($modelDefaults['imageFallbacks'] ?? null) ? $modelDefaults['imageFallbacks'] : [],
            'providers' => is_array($images['providers'] ?? null) ? $images['providers'] : [],
        ];

        if (is_string($images['ownerName'] ?? null) && trim($images['ownerName']) !== '') {
            $config['ownerName'] = trim($images['ownerName']);
        }

        return array_filter(
            $config,
            static fn(mixed $value): bool => $value !== null,
        );
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return array<string, mixed>
     */
    private function configureImageGeneration(array $existingConfig = []): array
    {
        $this->io->section('Step 5: Image Generation');
        $this->io->text([
            'Image generation uses a separate default model from your chat and role models.',
            'These settings drive the <fg=cyan>/image</> REPL command and compatible image toolkits.',
            '',
        ]);

        $defaults = $this->defaults->defaultImageConfig();
        $choicesByVendor = $this->defaults->defaultImageChoices();
        $modelChoices = [];

        foreach ($choicesByVendor as $vendor => $choices) {
            foreach ($choices as $choice) {
                if (!is_string($choice['id'] ?? null) || trim($choice['id']) === '') {
                    continue;
                }

                $fullId = $vendor . '/' . trim($choice['id']);
                $label = is_string($choice['label'] ?? null) && trim($choice['label']) !== ''
                    ? trim($choice['label'])
                    : trim($choice['id']);
                $recommended = ($choice['recommended'] ?? false) === true ? ' (recommended)' : '';
                $modelChoices[$fullId] = ucfirst($vendor) . ' — ' . $label . $recommended;
            }
        }

        if ($modelChoices === []) {
            return $existingConfig !== [] ? $existingConfig : $defaults;
        }

        $defaultPrimary = is_string($existingConfig['primary'] ?? null) && $existingConfig['primary'] !== ''
            ? $existingConfig['primary']
            : (is_string($defaults['primary'] ?? null) && $defaults['primary'] !== ''
                ? $defaults['primary']
                : (string) array_key_first($modelChoices));

        $selectedLabel = $this->choice(
            'Default image model for `/image generate`',
            array_values($modelChoices),
            $modelChoices[$defaultPrimary] ?? reset($modelChoices),
        );

        $primary = array_search($selectedLabel, $modelChoices, true);
        if ($primary === false) {
            $primary = $defaultPrimary;
        }

        $openAiDefaults = is_array($defaults['providers']['openai'] ?? null) ? $defaults['providers']['openai'] : [];
        $existingOpenAi = is_array($existingConfig['providers']['openai'] ?? null) ? $existingConfig['providers']['openai'] : [];
        $openAiBaseUrl = (is_string($existingOpenAi['baseUrl'] ?? null) && $existingOpenAi['baseUrl'] !== '')
            ? $existingOpenAi['baseUrl']
            : (is_string($openAiDefaults['baseUrl'] ?? null) ? $openAiDefaults['baseUrl'] : 'https://api.openai.com/v1');
        $openAiSize = $this->choice(
            'OpenAI image size',
            ['1024x1024', '1792x1024', '1024x1792'],
            is_string($existingOpenAi['size'] ?? null) && $existingOpenAi['size'] !== ''
                ? $existingOpenAi['size']
                : (is_string($openAiDefaults['size'] ?? null) ? $openAiDefaults['size'] : '1024x1024'),
        );
        $openAiQuality = $this->choice(
            'OpenAI image quality',
            ['standard', 'hd'],
            is_string($existingOpenAi['quality'] ?? null) && $existingOpenAi['quality'] !== ''
                ? $existingOpenAi['quality']
                : (is_string($openAiDefaults['quality'] ?? null) ? $openAiDefaults['quality'] : 'standard'),
        );

        $ollamaDefaults = is_array($defaults['providers']['ollama'] ?? null) ? $defaults['providers']['ollama'] : [];
        $existingOllama = is_array($existingConfig['providers']['ollama'] ?? null) ? $existingConfig['providers']['ollama'] : [];
        $ollamaBase = (is_string($existingOllama['baseUrl'] ?? null) && $existingOllama['baseUrl'] !== '')
            ? $existingOllama['baseUrl']
            : (is_string($ollamaDefaults['baseUrl'] ?? null) ? $ollamaDefaults['baseUrl'] : 'http://localhost:11434');

        $fallbacks = array_values(array_filter(
            array_keys($modelChoices),
            static fn(string $model): bool => $model !== $primary,
        ));

        $config = [
            'primary' => $primary,
            'fallbacks' => $fallbacks,
            'providers' => [
                'openai' => [
                    'model' => 'gpt-image-1.5',
                    'baseUrl' => $openAiBaseUrl,
                    'size' => $openAiSize,
                    'quality' => $openAiQuality,
                ],
                'ollama' => [
                    'model' => str_starts_with($primary, 'ollama/')
                        ? substr($primary, strlen('ollama/'))
                        : (is_string($existingOllama['model'] ?? null) && $existingOllama['model'] !== ''
                            ? $existingOllama['model']
                            : (is_string($ollamaDefaults['model'] ?? null) ? $ollamaDefaults['model'] : 'jmorgan/z-image-turbo:fp8')),
                    'baseUrl' => $this->normalizeOllamaHost($ollamaBase),
                ],
            ],
        ];

        $ownerName = is_string($existingConfig['ownerName'] ?? null) && trim($existingConfig['ownerName']) !== ''
            ? trim($existingConfig['ownerName'])
            : (is_string($defaults['ownerName'] ?? null) && trim($defaults['ownerName']) !== '' ? trim($defaults['ownerName']) : null);
        if ($ownerName !== null) {
            $config['ownerName'] = $ownerName;
        }

        return $config;
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

        $selected = $this->choice(
            'Which providers do you want to configure? (comma-separated for multiple)',
            array_values($choices),
            $choices['ollama'] ?? null,
        );

        // Resolve display strings back to provider keys
        $flipped = array_flip($choices);

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
        $baseUrl = $this->ask('Base URL', $defaultUrl);
        $baseUrl = is_string($baseUrl) ? $baseUrl : $defaultUrl;

        // API Key
        $apiKey = '';
        if ($this->defaults->requiresApiKey($provider)) {
            $envVar = $this->defaults->apiKeyEnvVar($provider);
            $envValue = $envVar !== null ? (getenv($envVar) ?: '') : '';

            if ($envValue !== '') {
                $masked = substr($envValue, 0, 8) . str_repeat('*', max(0, strlen($envValue) - 12)) . substr($envValue, -4);
                $this->io->text("<fg=gray>Found API key in \${$envVar}:</> {$masked}");

                if ($this->confirm("Use the key from \${$envVar}?", true)) {
                    $apiKey = "env:{$envVar}";
                }
            }

            if ($apiKey === '') {
                $apiKey = $this->askHidden("API key for {$displayName} (or press Enter to skip)") ?? '';

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
            $this->modelMetadata[$fullId] = $model;
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
     * Returns model arrays enriched with any available ModelDefinition fields.
     * Curated metadata from defaults.json is merged in for known models.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchModelsFromProvider(string $provider, string $baseUrl, string $apiKey): array
    {
        $resolvedKey = $this->resolveApiKey($apiKey);
        $providerConfig = OpenClawConfig::fromArray([
            'models' => [
                'providers' => [
                    $provider => [
                        'baseUrl' => $baseUrl,
                        'apiKey' => $resolvedKey,
                        'api' => $this->configuredProviders[$provider]['api']
                            ?? ($this->defaults->provider($provider)['api'] ?? 'openai-completions'),
                    ],
                ],
            ],
        ]);

        $definitions = (new ProviderFactory($providerConfig))
            ->create($provider . '/__discovery__')
            ->models();

        return array_map(
            function (ModelDefinition $m) use ($provider): array {
                $resolved = $this->modelMetadataResolver->enrichDiscovered($provider, $m);
                $model = $resolved->toArray();

                if (($model['contextWindow'] ?? 0) <= CoquiDefaults::CONTEXT_WINDOW_RESERVED) {
                    $model['contextWindow'] = CoquiDefaults::CONTEXT_WINDOW_FALLBACK;
                }

                if (($model['maxTokens'] ?? 0) <= 0) {
                    $model['maxTokens'] = CoquiDefaults::CONTEXT_WINDOW_RESERVED;
                }

                return $model;
            },
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

        // Sort models alphabetically by name
        usort($models, fn(array $a, array $b): int =>
            strcasecmp($a['name'] ?? $a['id'], $b['name'] ?? $b['id'])
        );

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

            $selectedLabels = $this->choice(
                "Select models to include (comma-separated for multiple, or 'all')",
                ['All available models', ...$choices],
                'All available models',
            );

            if ($selectedLabels === 'All available models') {
                return $models;
            }

            // Filter to selected
            $selectedSet = [$selectedLabels];
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
        asort($modelChoices);

        $roles = [];
        $roleDefinitions = $this->defaults->roles();

        foreach ($roleDefinitions as $roleName => $roleDef) {
            $description = $roleDef['description'] ?? '';
            $required = $roleDef['required'] ?? false;

            if (!$required) {
                // Optional roles: "Same as orchestrator" at [0], models start at [1]
                $choices = ['Same as orchestrator', ...array_values($modelChoices)];
                $defaultChoice = $choices[0];
            } else {
                // Required roles: models start at [1] for consistent numbering
                $choices = array_combine(range(1, count($modelChoices)), array_values($modelChoices));
                $defaultChoice = !empty($choices) ? reset($choices) : '';
            }

            $selected = $this->choice(
                "<fg=cyan>{$roleName}</> — {$description}",
                $choices,
                $defaultChoice,
            );

            if ($selected === 'Same as orchestrator' && isset($roles['orchestrator'])) {
                $roles[$roleName] = $roles['orchestrator'];
            } else {
                // Resolve back to full model ID
                $flipped = array_flip($modelChoices);
                $roles[$roleName] = $flipped[$selected] ?? (string) array_key_first($this->availableModels);
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

        if ($this->confirm("Use the orchestrator model ({$orchestratorModel}) as primary?", true)) {
            return $orchestratorModel;
        }

        $modelChoices = [];
        foreach ($this->availableModels as $fullId => $name) {
            $modelChoices[$fullId] = "{$name} ({$fullId})";
        }
        asort($modelChoices);

        $indexedChoices = array_combine(range(1, count($modelChoices)), array_values($modelChoices));
        $defaultChoice = reset($indexedChoices);
        $selected = $this->choice('Select primary model', $indexedChoices, is_string($defaultChoice) ? $defaultChoice : null);
        $flipped = array_flip($modelChoices);

        return $flipped[$selected] ?? $orchestratorModel;
    }

    /**
    * Step 6: Configure child agent background task spawning.
     */
    private function configureChildBackgroundTasks(): bool
    {
        $this->io->section('Step 6: Child Background Tasks');

        $this->io->text([
            'Child agents (spawned via <fg=cyan>spawn_agent</>) can optionally create background tasks.',
            'This enables powerful autonomous workflows where delegated agents can kick off',
            'long-running work independently.',
            '',
            '<fg=yellow>Risks:</>',
            '  • A child agent could spawn background tasks that spawn more child agents',
            '  • Each background task is capped at <fg=cyan>' . CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS . ' iterations</> for safety',
            '  • Background tasks spawned from within a background task cannot spawn further tasks',
            '',
        ]);

        return $this->confirm('Allow child agents to spawn background tasks?', false);
    }

    /**
    * Step 7: Configure automatic memory extraction behavior.
     */
    private function configureMemoryExtraction(): bool
    {
        $this->io->section('Step 7: Memory Extraction');

        $this->io->text([
            'Coqui can automatically extract and save noteworthy memories (facts, preferences,',
            'solutions) from each conversation turn. This adds latency to every turn but builds',
            'a persistent knowledge base over time.',
            '',
            'When disabled, memories are still extracted:',
            '  • During conversation summarization (automatic or manual)',
            '  • When the agent explicitly calls the <fg=cyan>extract_memories</> tool',
            '',
        ]);

        return $this->confirm('Enable automatic memory extraction after every turn?', CoquiDefaults::MEMORY_AUTO_EXTRACT);
    }

    /**
    * Step 8: Configure auto-summarization behavior.
     *
     * @return array<string, mixed> Context config array for openclaw.json
     */
    private function configureSummarization(): array
    {
        $this->io->section('Step 8: Summarization');

        $this->io->text([
            'Coqui can automatically summarize older conversation messages to stay within the',
            'model\'s context window. You can control when auto-summarization triggers.',
            '',
            'Modes:',
            '  • <fg=cyan>token</> (recommended) — Summarize when token usage exceeds a percentage of the context window.',
            '    Prevents context overflow while preserving as much conversation as possible.',
            '  • <fg=cyan>turn</> — Summarize after a fixed number of user turns, regardless of token usage.',
            '  • <fg=cyan>manual</> — Never auto-summarize. Use <fg=cyan>/summarize</> to summarize on demand.',
            '    A safety net still prevents context window overflow during agent execution.',
            '',
        ]);

        $modeLabels = [
            'Token-based (summarize when nearing context limit)',
            'Turn-based (summarize after a fixed number of turns)',
            'Manual only (never auto-summarize)',
        ];
        $modeValues = ['token', 'turn', 'manual'];

        $selected = $this->choice('Auto-summarization mode', $modeLabels, $modeLabels[0]);
        $selectedIndex = array_search($selected, $modeLabels, true);
        $mode = $modeValues[$selectedIndex !== false ? $selectedIndex : 0];

        $config = ['autoSummarizeMode' => $mode];

        if ($mode === 'token') {
            $threshold = $this->ask(
                'Token usage threshold (percentage of context window)',
                (string) (int) CoquiDefaults::AUTO_SUMMARIZE_THRESHOLD,
            );
            $config['autoSummarizeThreshold'] = max(10, min(95, (int) $threshold));
        }

        if ($mode === 'turn') {
            $turns = $this->ask(
                'Summarize after how many user turns?',
                (string) CoquiDefaults::AUTO_SUMMARIZE_TURN_THRESHOLD,
            );
            $config['autoSummarizeTurnThreshold'] = max(5, (int) $turns);
        }

        return $config;
    }

    /**
    * Step 9: Configure the workspace directory.
     */
    private function configureWorkspace(): string
    {
        $this->io->section('Step 9: Workspace');

        $default = $this->defaults->defaultWorkspace();

        $this->io->text([
            'The workspace is a sandboxed directory where Coqui stores sessions, credentials, and files.',
            '',
            sprintf('Default: <fg=cyan>~/.coqui/.workspace</> (resolves to <fg=gray>%s/.coqui/.workspace</>)', $this->resolveHome()),
            'All sessions are stored in this single location regardless of where you run Coqui from.',
        ]);

        $workspace = $this->ask('Workspace directory', $default);

        return is_string($workspace) ? $workspace : $default;
    }

    /**
    * Step 10: Configure the default profile for startup.
     */
    private function configureDefaultProfile(string $workspace, ?string $currentDefaultProfile = null): ?string
    {
        $profileDiscovery = new ProfileDiscovery($workspace);
        $profiles = $profileDiscovery->availableProfiles();

        if ($profiles === []) {
            return null;
        }

        $normalizedCurrent = is_string($currentDefaultProfile) ? strtolower(trim($currentDefaultProfile)) : null;
        if ($normalizedCurrent !== null && !in_array($normalizedCurrent, $profiles, true)) {
            $normalizedCurrent = null;
        }

        if (count($profiles) < 2) {
            return $normalizedCurrent;
        }

        $this->io->section('Step 10: Default Profile');
        $this->io->text([
            'Multiple profiles were found in the workspace.',
            'Choose which profile Coqui should attach by default when starting a new attached session.',
        ]);

        $choices = ['No default profile'];
        foreach ($profiles as $profile) {
            $choices[] = $profile;
        }

        $defaultChoice = $normalizedCurrent ?? 'No default profile';
        $selected = $this->choice('Default startup profile', $choices, $defaultChoice);

        return $selected === 'No default profile' ? null : strtolower(trim($selected));
    }

    /**
     * Resolve the user's home directory for display purposes.
     */
    private function resolveHome(): string
    {
        $home = HomeDirectory::resolve();

        // HomeDirectory falls back to sys_get_temp_dir() — display ~ if that happened
        return $home !== sys_get_temp_dir() ? $home : '~';
    }

    /**
    * Step 10: Configure update preferences (stored as ENV vars, not in openclaw.json).
     */
    private function configureUpdatePreferences(): void
    {
        $this->io->section('Step 10: Updates');

        $this->io->text('Coqui can check for dependency updates on startup and optionally apply them automatically.');

        $checkUpdates = $this->confirm('Check for updates on startup?', true);
        $autoUpdate = false;

        if ($checkUpdates) {
            $autoUpdate = $this->confirm('Automatically apply updates on startup? (will restart Coqui)', false);
        }

        // Persist to workspace .env via CredentialResolver (reuses the same .env mechanism)
        if ($this->credentialResolver !== null) {
            $this->credentialResolver->set('COQUI_CHECK_UPDATES', $checkUpdates ? 'true' : 'false');
            $this->credentialResolver->set('COQUI_AUTO_UPDATE', $autoUpdate ? 'true' : 'false');
            $this->io->text('<fg=gray>Update preferences saved to workspace .env</>');
        } else {
            $this->io->text('<fg=gray>Set COQUI_CHECK_UPDATES and COQUI_AUTO_UPDATE in your .env to control update behavior.</>');
        }
    }

    /**
    * Step 11: Generate an API key for the HTTP API server.
     *
     * The key is stored in the workspace .env file via CredentialResolver.
     * Required for running `coqui api` — the server refuses to start
     * without a key unless running on localhost.
     */
    private function configureApiKey(): void
    {
        // Skip entirely if key already configured
        $existingKey = $this->credentialResolver?->get('COQUI_API_KEY');
        if ($existingKey !== null && $existingKey !== '') {
            $this->io->text('<fg=gray>API key already configured — skipping.</>');
            return;
        }

        $this->io->section('Step 11: API Server Key');

        $this->io->text([
            'The HTTP API server requires an API key for authentication.',
            'This key is used with <fg=cyan>Authorization: Bearer <key></> when calling the API.',
        ]);

        $generateKey = $this->confirm('Generate an API key now?', true);

        if (!$generateKey) {
            $this->io->text('<fg=gray>Skipped. Set COQUI_API_KEY manually. Localhost access works without a key.</>');
            return;
        }

        $apiKey = bin2hex(random_bytes(16));

        if ($this->credentialResolver !== null) {
            $this->credentialResolver->set('COQUI_API_KEY', $apiKey);
            $this->io->newLine();
            $this->io->text('<fg=green>API key generated and saved to workspace .env</>');
            $this->io->text(sprintf('Your API key: <fg=cyan>%s</>', $apiKey));
            $this->io->text('<fg=yellow>Save this key now — it will not be shown again.</>');
            $this->io->newLine();
        } else {
            $this->io->text(sprintf('Generated key: <fg=cyan>%s</>', $apiKey));
            $this->io->text('Set it manually: <fg=gray>COQUI_API_KEY=%s in your .env</>');
        }
    }

    /**
    * Step 12: Configure directory mounts for agent workspace access.
     *
     * Guides the user through adding local directories that the agent can
     * access via symlinks under workspace/mnt/. Supports adding multiple
     * mounts with an iterative add/review/edit/remove flow.
     *
     * @return array<int, array{path: string, alias: string, access: string, description?: string}>
     */
    private function configureMounts(): array
    {
        $this->io->section('Step 12: Directory Mounts');

        $this->io->text([
            'Mounts give the agent access to directories outside the workspace.',
            'Mounted directories appear under <fg=cyan>workspace/mnt/{alias}</>.',
            '',
            '<fg=yellow>Security tips:</>',
            '  • Only mount directories the agent actually needs',
            '  • Use <fg=cyan>read-only</> access unless the agent must write files',
            '  • Avoid mounting directories with sensitive credentials or secrets',
            '',
        ]);

        if (!$this->confirm('Would you like to mount local directories into the agent\'s workspace?', false)) {
            return [];
        }

        /** @var array<int, array{path: string, alias: string, access: string, description?: string}> */
        $mounts = [];

        // Entry loop — add at least one mount, then optionally more
        $mounts[] = $this->promptForMount($mounts);

        while ($this->confirm('Add another mount?', false)) {
            $mounts[] = $this->promptForMount($mounts);
        }

        // Review loop
        return $this->reviewMounts($mounts);
    }

    /**
     * Prompt the user for a single mount entry.
     *
     * @param array<int, array{path: string, alias: string, access: string, description?: string}> $existingMounts
     * @return array{path: string, alias: string, access: string, description?: string}
     */
    private function promptForMount(array $existingMounts, ?int $editIndex = null): array
    {
        $existingAliases = array_map(fn(array $m): string => $m['alias'], $existingMounts);
        $defaults = $editIndex !== null ? $existingMounts[$editIndex] : null;

        // If editing, remove the current alias from the uniqueness check
        if ($editIndex !== null && $defaults !== null) {
            $existingAliases = array_values(array_filter(
                $existingAliases,
                fn(string $a): bool => $a !== $defaults['alias'],
            ));
        }

        // Local path
        $defaultPath = $defaults['path'] ?? null;
        $path = '';
        while (true) {
            $input = $this->ask('Local directory path', $defaultPath);
            if (!is_string($input) || $input === '') {
                $this->io->error('A directory path is required.');
                continue;
            }

            $validated = $this->validateMountPath($input);
            if ($validated === null) {
                $this->io->error("Directory not found: {$input}");
                continue;
            }

            $path = $validated;
            break;
        }

        // Alias
        $suggestedAlias = $this->suggestAlias($path, $existingAliases);
        $defaultAlias = $defaults['alias'] ?? $suggestedAlias;
        $alias = '';
        while (true) {
            $input = $this->ask(
                sprintf('Mount alias (accessible at <fg=cyan>mnt/%s</>)', $defaultAlias),
                $defaultAlias,
            );
            $input = is_string($input) ? trim($input) : '';

            if ($input === '' || str_contains($input, '/') || str_contains($input, '\\')) {
                $this->io->error('Alias must be a non-empty name without path separators.');
                continue;
            }

            if (in_array($input, $existingAliases, true)) {
                $this->io->error("Alias \"{$input}\" is already in use. Choose a different name.");
                continue;
            }

            $alias = $input;
            break;
        }

        // Access level
        $defaultAccess = $defaults['access'] ?? 'ro';
        $accessChoices = ['Read-only (recommended)', 'Read-write'];
        $accessDefault = $defaultAccess === 'rw' ? $accessChoices[1] : $accessChoices[0];
        $accessSelected = $this->choice('Access level', $accessChoices, $accessDefault);
        $access = $accessSelected === 'Read-write' ? 'rw' : 'ro';

        // Description
        $defaultDesc = $defaults['description'] ?? null;
        $description = $this->ask('Description (optional)', $defaultDesc);

        $mount = [
            'path' => $path,
            'alias' => $alias,
            'access' => $access,
        ];

        if (is_string($description) && $description !== '') {
            $mount['description'] = $description;
        }

        return $mount;
    }

    /**
     * Display mount configuration for review and let the user accept, edit, remove, or add more.
     *
     * @param array<int, array{path: string, alias: string, access: string, description?: string}> $mounts
     * @return array<int, array{path: string, alias: string, access: string, description?: string}>
     */
    private function reviewMounts(array $mounts): array
    {
        while (true) {
            if ($mounts === []) {
                $this->io->text('<fg=gray>No mounts configured.</>');
                return [];
            }

            $this->io->newLine();
            $this->io->text('<fg=cyan>Configured mounts:</>');

            $rows = [];
            foreach (array_values($mounts) as $i => $mount) {
                $rows[] = [
                    $i + 1,
                    $mount['path'],
                    "mnt/{$mount['alias']}",
                    $mount['access'] === 'rw' ? 'read-write' : 'read-only',
                    $mount['description'] ?? '—',
                ];
            }

            $this->io->table(['#', 'Path', 'Mount Point', 'Access', 'Description'], $rows);

            $action = $this->choice('Mount configuration', [
                'Accept',
                'Add another mount',
                'Edit a mount',
                'Remove a mount',
                'Clear all and skip',
            ], 'Accept');

            switch ($action) {
                case 'Accept':
                    return array_values($mounts);

                case 'Add another mount':
                    $mounts[] = $this->promptForMount($mounts);
                    break;

                case 'Edit a mount':
                    $index = $this->selectMountIndex($mounts, 'Which mount to edit?');
                    if ($index !== null) {
                        $mounts[$index] = $this->promptForMount($mounts, $index);
                        $mounts = array_values($mounts);
                    }
                    break;

                case 'Remove a mount':
                    $index = $this->selectMountIndex($mounts, 'Which mount to remove?');
                    if ($index !== null) {
                        unset($mounts[$index]);
                        $mounts = array_values($mounts);
                        $this->io->text('<fg=gray>Mount removed.</>');
                    }
                    break;

                case 'Clear all and skip':
                    $this->io->text('<fg=gray>Mounts cleared — continuing without mounts.</>');
                    return [];
            }
        }
    }

    /**
     * Prompt the user to select a mount by number.
     *
     * @param array<int, array{path: string, alias: string, access: string, description?: string}> $mounts
     */
    private function selectMountIndex(array $mounts, string $question): ?int
    {
        $choices = [];
        foreach (array_values($mounts) as $i => $mount) {
            $choices[] = sprintf('%d: %s (mnt/%s)', $i + 1, $mount['path'], $mount['alias']);
        }

        $selected = $this->choice($question, $choices);
        if (preg_match('/^(\d+):/', $selected, $matches)) {
            return ((int) $matches[1]) - 1;
        }

        return null;
    }

    private function ask(string $question, ?string $default = null): ?string
    {
        return $this->prompt->ask($question, $default);
    }

    private function askHidden(string $question): ?string
    {
        return $this->prompt->askHidden($question);
    }

    private function confirm(string $question, bool $default = false): bool
    {
        return $this->prompt->confirm($question, $default);
    }

    /**
     * @param array<int|string, string> $choices
     */
    private function choice(string $question, array $choices, ?string $default = null): string
    {
        return $this->prompt->choice($question, $choices, $default);
    }

    /**
     * Build the final openclaw.json config array.
     *
     * Uses model metadata from discovery/curated data to preserve accurate
     * contextWindow, maxTokens, reasoning, vision, and cost information.
     *
     * @param array<string, string> $roles
     * @param array<int, array{path: string, alias: string, access: string, description?: string}> $mounts
     * @param array<string, mixed> $summarizationConfig
    * @param array<string, mixed> $imageModelConfig
     * @return array<string, mixed>
     */
    private function buildConfig(string $primaryModel, array $roles, string $workspace, array $mounts = [], bool $childBackgroundTasks = false, bool $memoryAutoExtract = false, array $summarizationConfig = [], ?string $defaultProfile = null, array $imageModelConfig = []): array
    {
        $modelDefinitions = [];

        foreach ($this->configuredProviders as $providerName => $providerConfig) {
            $providerModels = [];

            foreach ($this->availableModels as $fullId => $name) {
                [$p, $modelId] = explode('/', $fullId, 2);
                if ($p !== $providerName) {
                    continue;
                }

                $meta = $this->modelMetadata[$fullId] ?? [];

                // Build input capabilities from metadata
                $input = ['text'];
                if (!empty($meta['vision'])) {
                    $input[] = 'image';
                }

                // Build cost array from metadata (default: zeros)
                $rawCost = $meta['cost'] ?? [];
                $cost = [
                    'input' => $rawCost['input'] ?? 0,
                    'output' => $rawCost['output'] ?? 0,
                    'cacheRead' => $rawCost['cacheRead'] ?? 0,
                    'cacheWrite' => $rawCost['cacheWrite'] ?? 0,
                ];

                $modelEntry = [
                    'id' => $modelId,
                    'name' => $name,
                    'reasoning' => $meta['reasoning'] ?? false,
                    'input' => $input,
                    'cost' => $cost,
                    'contextWindow' => $meta['contextWindow'] ?? CoquiDefaults::CONTEXT_WINDOW_FALLBACK,
                    'maxTokens' => $meta['maxTokens'] ?? CoquiDefaults::CONTEXT_WINDOW_RESERVED,
                ];

                if (isset($meta['numCtx'])) {
                    $modelEntry['numCtx'] = (int) $meta['numCtx'];
                }

                if (isset($meta['family']) && is_string($meta['family']) && $meta['family'] !== '') {
                    $modelEntry['family'] = $meta['family'];
                }

                if (!empty($meta['toolCalls'])) {
                    $modelEntry['toolCalls'] = true;
                }

                if (!empty($meta['thinking'])) {
                    $modelEntry['thinking'] = true;
                }

                if (isset($meta['metadataSource']) && is_string($meta['metadataSource']) && $meta['metadataSource'] !== '') {
                    $modelEntry['metadataSource'] = $meta['metadataSource'];
                }

                if (isset($meta['fieldSources']) && is_array($meta['fieldSources']) && $meta['fieldSources'] !== []) {
                    $modelEntry['fieldSources'] = $meta['fieldSources'];
                }

                $providerModels[] = $modelEntry;
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

        $defaults = [
            'workspace' => $workspace,
            'model' => [
                'primary' => $primaryModel,
            ],
            'roles' => $roles,
        ];

        if ($defaultProfile !== null && trim($defaultProfile) !== '') {
            $defaults['profile'] = strtolower(trim($defaultProfile));
        }

        if ($childBackgroundTasks) {
            $defaults['childBackgroundTasks'] = true;
        }

        if ($memoryAutoExtract) {
            $defaults['memory'] = ['autoExtract' => true];
        }

        if ($summarizationConfig !== []) {
            $defaults['context'] = $summarizationConfig;
        }

        if ($imageModelConfig !== []) {
            $defaults['model']['imageModel'] = $imageModelConfig['primary'];
            $defaults['model']['imageFallbacks'] = $imageModelConfig['fallbacks'] ?? [];
        }

        if ($mounts !== []) {
            $defaults['mounts'] = $mounts;
        }

        $config = [
            'agents' => [
                'defaults' => $defaults,
            ],
            'models' => [
                'mode' => 'merge',
                'providers' => $modelDefinitions,
            ],
        ];

        if ($imageModelConfig !== []) {
            $images = [
                'providers' => $imageModelConfig['providers'] ?? [],
            ];

            if (isset($imageModelConfig['ownerName']) && is_string($imageModelConfig['ownerName']) && trim($imageModelConfig['ownerName']) !== '') {
                $images['ownerName'] = trim($imageModelConfig['ownerName']);
            }

            $config['images'] = $images;
        }

        return $config;
    }

    private function normalizeOllamaHost(string $value): string
    {
        $normalized = preg_replace('#/v1/?$#', '', trim($value));

        return is_string($normalized) ? rtrim($normalized, '/') : rtrim(trim($value), '/');
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

    /**
     * Validate and expand a mount path entered by the user.
     *
     * Expands ~ to the home directory, resolves the real path, and checks
     * that the result is an existing directory.
     *
     * @return string|null The expanded absolute path, or null if invalid.
     */
    private function validateMountPath(string $path): ?string
    {
        $expanded = $path;

        // Expand ~ to home directory
        if (str_starts_with($expanded, '~/') || $expanded === '~') {
            $home = HomeDirectory::resolve();
            $expanded = $home . substr($expanded, 1);
        }

        // Resolve to absolute path
        $real = realpath($expanded);
        if ($real === false || !is_dir($real)) {
            return null;
        }

        return $real;
    }

    /**
     * Suggest a unique alias derived from a directory path.
     *
     * Takes the basename, lowercases it, replaces non-alphanumeric characters
     * with hyphens, and appends a numeric suffix if the alias is already taken.
     *
     * @param string[] $existingAliases Aliases already in use
     */
    private function suggestAlias(string $path, array $existingAliases): string
    {
        $base = basename($path);
        $alias = strtolower((string) preg_replace('/[^a-zA-Z0-9-]/', '-', $base));
        $alias = trim($alias, '-');

        if ($alias === '') {
            $alias = 'mount';
        }

        if (!in_array($alias, $existingAliases, true)) {
            return $alias;
        }

        $counter = 2;
        while (in_array("{$alias}-{$counter}", $existingAliases, true)) {
            $counter++;
        }

        return "{$alias}-{$counter}";
    }
}
