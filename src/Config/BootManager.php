<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CarmeloSantana\PHPAgents\Contract\EmbeddingProviderInterface;
use CarmeloSantana\PHPAgents\Embedding\OllamaEmbeddingProvider;
use CarmeloSantana\PHPAgents\Embedding\OpenAIEmbeddingProvider;

use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CoquiBot\Coqui\Provider\ReactCliRuntime;
use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Config\OpenClawConfig as CoquiOpenClawConfig;
use CoquiBot\Coqui\Contract\MountDefinition;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\ToolkitCommandHandler;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Repl\ToolkitCommandCandidate;
use CoquiBot\ModManager\ModManagerToolkit;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\ToolUsageTracker;
use CoquiBot\Coqui\Exception\InteractionCancelledException;
use CoquiBot\Coqui\Exception\ShutdownRequestedException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Handles the Coqui boot sequence: config loading, workspace initialization,
 * credential resolution, and toolkit discovery.
 *
 * Extracted from RunCommand to enforce single-responsibility.
 */
final class BootManager
{
    private CoquiOpenClawConfig $config;
    private string $configPath = '';
    private string $workspacePath;
    private CredentialResolver $credentialResolver;
    private ToolkitDiscovery $discovery;
    private ?ChannelDiscovery $channelDiscovery = null;
    private ToolkitVisibilityRegistry $visibilityRegistry;
    private SkillDiscovery $skillDiscovery;
    private RoleDiscovery $roleDiscovery;
    private ProfileDiscovery $profileDiscovery;
    private RoleResolver $roleResolver;
    private RoleUpdateTracker $roleUpdateTracker;
    /** @var list<RoleUpdateInfo> */
    private array $pendingRoleUpdates = [];
    private CatastrophicBlacklist $blacklist;
    private DefaultsLoader $defaultsLoader;
    private MountManager $mountManager;
    private MemoryStore $memoryStore;
    private MemorySummarizer $memorySummarizer;
    private ConfigManager $configManager;
    private ?ArtifactStore $artifactStore = null;
    private ?ProjectStore $projectStore = null;
    private ?ModManagerToolkit $modsToolkit = null;
    private ?LoopStore $loopStore = null;
    private ?LoopDiscovery $loopDiscovery = null;
    private ?LoopUpdateTracker $loopUpdateTracker = null;
    /** @var list<LoopUpdateInfo> */
    private array $pendingLoopUpdates = [];
    private ?NotificationStore $notificationStore = null;
    private ?ToolUsageTracker $usageTracker = null;
    private ?ToolkitLoadingRegistry $loadingRegistry = null;
    private ?ProviderFactory $providerFactory = null;

    public function __construct(
        private readonly string $workDir,
        private readonly ?string $workspaceOverride = null,
    ) {
        $this->defaultsLoader = new DefaultsLoader();
    }

    /**
     * Run the full boot sequence.
     *
     * @param OutputInterface|SymfonyStyle|null $io  Pass SymfonyStyle for interactive mode,
     *                                               OutputInterface for verbose logging,
     *                                               or null for headless/API mode.
     * @return bool True if boot succeeded, false if it should abort.
     */
    public function boot(
        OutputInterface|SymfonyStyle|null $io = null,
        ?string $configPath = null,
        bool $skipMaintenance = false,
    ): bool {
        try {
            $this->loadConfig($io, $configPath);
        } catch (ShutdownRequestedException) {
            return false;
        }

        $this->blacklist = CatastrophicBlacklist::fromConfig($this->config);
        $this->initializeWorkspace();
        $this->initializeMounts();
        $this->discoverRoles();
        $this->roleResolver = new RoleResolver($this->config, $this->defaultsLoader, $this->roleDiscovery, $this->profileDiscovery);
        $this->initializeCredentials();
        $this->initializeMemory();
        $this->initializeArtifacts($skipMaintenance);
        $this->discoverLoops();
        $this->discoverToolkits($io);
        $this->discoverChannels($io);
        $this->seedPackageContent();
        $this->discoverSkills();
        $this->initializeMods();

        return true;
    }

    /**
     * Lightweight boot for the setup wizard: config + workspace + credentials only.
     *
     * Skips blacklist, mounts, roles, memory, toolkit discovery, and skills
     * so the wizard can run without heavyweight initialization.
     */
    public function bootForWizard(OutputInterface|SymfonyStyle|null $io = null, ?string $configPath = null): bool
    {
        $this->loadConfig($io, $configPath);
        $this->initializeWorkspace();
        $this->initializeCredentials();

        return true;
    }

    public function config(): CoquiOpenClawConfig
    {
        return $this->config;
    }

    /**
     * Shared ProviderFactory instance.
     *
     * Lazily created on first call. Pass an HttpClientInterface on the first
     * call to wire it into the factory (e.g. ReactHttpClientAdapter for REPL
     * or API contexts). Subsequent calls return the cached instance.
     */
    public function providerFactory(?HttpClientInterface $httpClient = null): ProviderFactory
    {
        if ($this->providerFactory === null) {
            $this->providerFactory = new ProviderFactory(
                config: $this->config,
                httpClient: $httpClient,
                cliRuntime: new ReactCliRuntime(),
            );
        }

        return $this->providerFactory;
    }

    /**
     * Resolved absolute path to the active openclaw.json (empty if using defaults).
     */
    public function configPath(): string
    {
        return $this->configPath;
    }

    /**
     * The ConfigManager instance managing the workspace config lifecycle.
     */
    public function configManager(): ConfigManager
    {
        return $this->configManager;
    }

    public function workspacePath(): string
    {
        return $this->workspacePath;
    }

    public function credentialResolver(): CredentialResolver
    {
        return $this->credentialResolver;
    }

    public function discovery(): ToolkitDiscovery
    {
        return $this->discovery;
    }

    public function channelDiscovery(): ChannelDiscovery
    {
        if ($this->channelDiscovery === null) {
            throw new \LogicException('Channel discovery is not available before boot completes.');
        }

        return $this->channelDiscovery;
    }

    /**
     * Discover and return REPL command handlers from enabled toolkits.
     *
     * @param array<string, mixed> $context Runtime context passed to toolkit factories
     * @return list<ToolkitCommandHandler>
     */
    public function commandHandlers(array $context = []): array
    {
        return $this->discovery->commandHandlers($context);
    }

    /**
     * Discover and return package-aware REPL command registration candidates.
     *
     * @param array<string, mixed> $context Runtime context passed to toolkit factories
     * @return list<ToolkitCommandCandidate>
     */
    public function commandHandlerCandidates(array $context = []): array
    {
        return $this->discovery->commandHandlerCandidates($context);
    }

    public function visibilityRegistry(): ToolkitVisibilityRegistry
    {
        return $this->visibilityRegistry;
    }

    public function roleResolver(): RoleResolver
    {
        return $this->roleResolver;
    }

    public function blacklist(): CatastrophicBlacklist
    {
        return $this->blacklist;
    }

    public function defaultsLoader(): DefaultsLoader
    {
        return $this->defaultsLoader;
    }

    public function mountManager(): MountManager
    {
        return $this->mountManager;
    }

    public function skillDiscovery(): SkillDiscovery
    {
        return $this->skillDiscovery;
    }

    public function roleDiscovery(): RoleDiscovery
    {
        return $this->roleDiscovery;
    }

    public function profileDiscovery(): ProfileDiscovery
    {
        return $this->profileDiscovery;
    }

    public function roleUpdateTracker(): RoleUpdateTracker
    {
        return $this->roleUpdateTracker;
    }

    /**
     * Get roles with pending updates that need user review.
     *
     * @return list<RoleUpdateInfo>
     */
    public function pendingRoleUpdates(): array
    {
        return $this->pendingRoleUpdates;
    }

    public function memoryStore(): MemoryStore
    {
        return $this->memoryStore;
    }

    public function memorySummarizer(): MemorySummarizer
    {
        return $this->memorySummarizer;
    }

    public function modsToolkit(): ?ModManagerToolkit
    {
        return $this->modsToolkit;
    }

    public function artifactStore(): ?ArtifactStore
    {
        return $this->artifactStore;
    }

    public function projectStore(): ?ProjectStore
    {
        return $this->projectStore;
    }

    public function loopStore(): ?LoopStore
    {
        return $this->loopStore;
    }

    public function loopDiscovery(): ?LoopDiscovery
    {
        return $this->loopDiscovery;
    }

    public function loopUpdateTracker(): ?LoopUpdateTracker
    {
        return $this->loopUpdateTracker;
    }

    /**
     * Get loop definitions with pending updates that need user review.
     *
     * @return list<LoopUpdateInfo>
     */
    public function pendingLoopUpdates(): array
    {
        return $this->pendingLoopUpdates;
    }

    public function usageTracker(): ?ToolUsageTracker
    {
        return $this->usageTracker;
    }

    public function loadingRegistry(): ?ToolkitLoadingRegistry
    {
        return $this->loadingRegistry;
    }

    public function notificationStore(): ?NotificationStore
    {
        return $this->notificationStore;
    }

    private function loadConfig(OutputInterface|SymfonyStyle|null $io, ?string $configPath): void
    {
        // Two-phase load: we need workspace path first for ConfigManager,
        // but workspace resolution needs config. Break the cycle by doing
        // a preliminary workspace resolve, then using ConfigManager.

        // Phase 1: Preliminary workspace resolution.
        // If an explicit config path is given or one exists in workDir, use it
        // temporarily to resolve the workspace. Otherwise use defaults.
        $prelimConfig = $this->loadPreliminaryConfig($configPath);
        $workspaceResolver = new WorkspaceResolver($prelimConfig, $this->workDir, $this->workspaceOverride);
        $prelimWorkspace = $workspaceResolver->resolve();

        // Phase 2: Create ConfigManager with resolved workspace path.
        $validator = new ConfigValidator();
        $this->configManager = new ConfigManager(
            workspacePath: $prelimWorkspace,
            projectRoot: $this->workDir,
            defaultsLoader: $this->defaultsLoader,
            validator: $validator,
        );

        // If explicit CLI --config flag, pass it through
        $explicitPath = null;
        if ($configPath !== null && $configPath !== '' && file_exists($configPath)) {
            $explicitPath = $configPath;
        }

        // Gate first-time users behind setup wizard before ConfigManager can silently
        // create a broken default config (it never throws; it always succeeds with defaults).
        if ($io instanceof SymfonyStyle && $explicitPath === null) {
            $workspaceConfigExists = file_exists($this->configManager->path());
            $projectConfigExists = file_exists(
                PathHelper::trimTrailingSlash($this->workDir) . '/openclaw.json',
            );

            if (!$workspaceConfigExists && !$projectConfigExists) {
                $this->runFirstTimeSetup($io);
                // If we reach here the wizard saved a config — fall through to load it.
            }
        }

        // Load config (guaranteed to exist when the interactive wizard ran successfully).
        try {
            $this->config = $this->configManager->load($explicitPath);
            $this->configPath = $this->configManager->path();

            if ($io instanceof SymfonyStyle && $this->wasSeededFromProjectRoot($explicitPath)) {
                $io->text('<fg=gray>Config seeded from project root to workspace.</>');
            }

            return;
        } catch (\Throwable $e) {
            // Config file exists but is malformed or unreadable — show error and fall back.
            if ($io instanceof SymfonyStyle) {
                $io->warning('Failed to load config: ' . $e->getMessage());
            }
        }

        // Malformed config recovery: overwrite with defaults so the next boot succeeds.
        $this->config = $this->buildDefaultConfig();
        $this->configManager->save([
            'agents' => $this->config->get('agents') ?? [],
        ]);
        $this->configPath = $this->configManager->path();
    }

    /**
     * Load a preliminary config for workspace resolution before ConfigManager exists.
     */
    private function loadPreliminaryConfig(?string $configPath): CoquiOpenClawConfig
    {
        // Explicit path
        if ($configPath !== null && file_exists($configPath)) {
            return OpenClawConfig::fromFile($configPath);
        }

        // Project root
        $workDirConfig = $this->workDir . '/openclaw.json';
        if (file_exists($workDirConfig)) {
            return OpenClawConfig::fromFile($workDirConfig);
        }

        // Bundled default
        $bundledConfig = dirname(__DIR__, 2) . '/openclaw.json';
        if (file_exists($bundledConfig)) {
            return OpenClawConfig::fromFile($bundledConfig);
        }

        // Minimal defaults
        return $this->buildDefaultConfig();
    }

    /**
     * Detect whether the current load was a fresh seed from project root.
     */
    private function wasSeededFromProjectRoot(?string $explicitPath): bool
    {
        if ($explicitPath !== null) {
            return false;
        }

        $projectConfig = PathHelper::trimTrailingSlash($this->workDir) . '/openclaw.json';
        $workspaceConfig = $this->configManager->path();

        // If both exist and workspace was just created (within last 2 seconds), likely seeded
        if (file_exists($projectConfig) && file_exists($workspaceConfig)) {
            $wsMtime = filemtime($workspaceConfig);
            return $wsMtime !== false && (time() - $wsMtime) < 2;
        }

        return false;
    }

    private function initializeWorkspace(): void
    {
        $workspaceResolver = new WorkspaceResolver($this->config, $this->workDir, $this->workspaceOverride);
        $this->workspacePath = $workspaceResolver->resolve();

        // Publish workspace path so toolkit fromEnv() factories resolve correctly
        putenv("COQUI_WORKSPACE_PATH={$this->workspacePath}");

        $workspaceComposer = new WorkspaceComposerManager($this->workspacePath);
        $workspaceComposer->initialize();
        $workspaceComposer->loadAutoloader();

        $this->visibilityRegistry = new ToolkitVisibilityRegistry($this->workspacePath);
    }

    /**
     * Read mount declarations from config and initialize the MountManager.
     *
     * Reads `agents.defaults.mounts` from openclaw.json — an array of mount
     * objects with path, alias, access (ro|rw), and optional description.
     * Creates symlinks under .workspace/mnt/ for agent discoverability.
     */
    private function initializeMounts(): void
    {
        $mountsConfig = $this->config->get('agents.defaults.mounts');
        $mounts = [];

        if (is_array($mountsConfig)) {
            foreach ($mountsConfig as $entry) {
                if (!is_array($entry) || !isset($entry['path'], $entry['alias'])) {
                    continue;
                }

                try {
                    $mounts[] = MountDefinition::fromArray($entry);
                } catch (\InvalidArgumentException) {
                    // Skip invalid mount definitions silently
                    continue;
                }
            }
        }

        $this->mountManager = new MountManager($this->workspacePath, $mounts);
        $this->mountManager->initialize();
    }

    private function initializeCredentials(): void
    {
        $this->credentialResolver = new CredentialResolver(workspacePath: $this->workspacePath);
        $this->credentialResolver->loadIntoProcessEnv();
    }

    private function discoverLoops(): void
    {
        $builtinDir = ($this->workDir !== '' ? PathHelper::trimTrailingSlash($this->workDir) : dirname(__DIR__, 2)) . '/config/loops';

        $this->loopDiscovery = new LoopDiscovery($this->workspacePath, $this->workDir !== '' ? $this->workDir : null);
        $this->loopUpdateTracker = new LoopUpdateTracker($this->workspacePath, $builtinDir);

        // Seed built-in loops, recording hashes for newly seeded files
        $this->loopDiscovery->seedBuiltinLoops($this->loopUpdateTracker);

        // Auto-update unmodified loops and collect notifications for modified ones
        $this->pendingLoopUpdates = $this->loopUpdateTracker->autoUpdateAndNotify($this->loopDiscovery);
    }

    private function discoverRoles(): void
    {
        $builtinDir = ($this->workDir !== '' ? PathHelper::trimTrailingSlash($this->workDir) : dirname(__DIR__, 2)) . '/config/roles';

        $this->roleDiscovery = new RoleDiscovery($this->workspacePath, $this->workDir);
        $this->profileDiscovery = new ProfileDiscovery($this->workspacePath);
        $this->roleUpdateTracker = new RoleUpdateTracker($this->workspacePath, $builtinDir);

        // Seed built-in roles, recording hashes for newly seeded files
        $this->roleDiscovery->seedBuiltinRoles($this->roleUpdateTracker);

        // Auto-update unmodified roles and collect notifications for modified ones
        $this->pendingRoleUpdates = $this->roleUpdateTracker->autoUpdateAndNotify($this->roleDiscovery);
    }

    private function initializeMemory(): void
    {
        $dbPath = $this->workspacePath . '/data/memory.db';
        $embeddingProvider = $this->resolveEmbeddingProvider();

        $this->memoryStore = new MemoryStore($dbPath, $embeddingProvider);
        $this->memoryStore->deleteArea('session_summary');

        $coreSummaryMaxTokens = $this->config->get('agents.defaults.memory.coreSummaryMaxTokens');
        $coreSummaryEntryLimit = $this->config->get('agents.defaults.memory.coreSummaryEntryLimit');

        $this->memorySummarizer = new MemorySummarizer(
            memoryStore: $this->memoryStore,
            maxTokens: is_int($coreSummaryMaxTokens) ? $coreSummaryMaxTokens : CoquiDefaults::MEMORY_CORE_SUMMARY_MAX_TOKENS,
            entryLimit: is_int($coreSummaryEntryLimit) ? $coreSummaryEntryLimit : CoquiDefaults::MEMORY_CORE_SUMMARY_ENTRY_LIMIT,
        );

        // Run boot-time decay sweep — archives stale, low-value memories
        $this->memoryStore->decayAndArchive();
    }

    /**
     * Initialize artifact store and optionally clean up finalized artifacts.
     *
     * Maintenance cleanup (cleanupFinalized, cleanupOrphaned, cleanupStale, cleanupUnlinked)
     * runs only in long-lived processes (REPL, API server). Ephemeral background task
     * processes skip cleanup to avoid deleting artifacts that sibling tasks need to read.
     */
    private function initializeArtifacts(bool $skipMaintenance = false): void
    {
        $dbPath = $this->workspacePath . '/data/coqui.db';

        $storage = new SessionStorage($dbPath);
        $pdo = $storage->getPdo();

        $this->projectStore = new ProjectStore($pdo);

        $fileService = null;
        if ($this->config->isArtifactFilesystemBacked()) {
            $fileService = new ArtifactFileService($this->workspacePath);
        }
        $this->artifactStore = new ArtifactStore($pdo, $fileService, $this->projectStore);
        $this->loopStore = new LoopStore($pdo);
        $this->notificationStore = new NotificationStore($pdo);
        $this->usageTracker = new ToolUsageTracker($pdo);

        $toolProfileResolver = new ToolProfileResolver($this->config);
        $this->loadingRegistry = new ToolkitLoadingRegistry($this->workspacePath, $toolProfileResolver->coreToolkits());

        if (!$skipMaintenance) {
            $this->artifactStore->cleanupFinalized();

            $notifConfig = $this->config->getNotificationConfig();
            $this->notificationStore->prune(
                $notifConfig['retentionHours']['informational'],
                $notifConfig['retentionHours']['actionable'],
            );
        }
    }

    /**
     * Resolve the embedding provider from config and available credentials.
     *
     * Priority: explicit config > OpenAI key detected > Ollama available > null (FTS5 only).
     */
    private function resolveEmbeddingProvider(): ?EmbeddingProviderInterface
    {
        $embeddingModel = $this->config->get('agents.defaults.memory.embeddingModel');

        // Explicit config: "ollama/nomic-embed-text" or "openai/text-embedding-3-small"
        if (is_string($embeddingModel) && $embeddingModel !== '') {
            $parts = explode('/', $embeddingModel, 2);
            $provider = $parts[0];
            $model = $parts[1] ?? $embeddingModel;

            if ($provider === 'openai') {
                $apiKey = $this->credentialResolver->get('OPENAI_API_KEY') ?? '';
                if ($apiKey !== '') {
                    return new OpenAIEmbeddingProvider(modelName: $model, apiKey: $apiKey);
                }
            }

            if ($provider === 'ollama') {
                $baseUrl = $this->credentialResolver->get('OLLAMA_HOST') ?? 'http://localhost:11434';
                return new OllamaEmbeddingProvider(modelName: $model, baseUrl: $baseUrl);
            }
        }

        // Auto-detect: try Ollama first (free, local), then OpenAI
        $ollamaHost = $this->credentialResolver->get('OLLAMA_HOST') ?? 'http://localhost:11434';
        $openaiKey = $this->credentialResolver->get('OPENAI_API_KEY') ?? '';

        // Check if memory is explicitly disabled
        $memoryEnabled = $this->config->get('agents.defaults.memory.enabled');
        if ($memoryEnabled === false) {
            return null;
        }

        // Default to Ollama if available (non-blocking check skipped — let it fail gracefully)
        // Only auto-enable embeddings if a key or host is explicitly set
        if ($openaiKey !== '') {
            return new OpenAIEmbeddingProvider(apiKey: $openaiKey);
        }

        // Return null — FTS5 keyword search is the baseline
        return null;
    }

    private function discoverSkills(): void
    {
        $packageSkillDirs = $this->discovery->discoverPackageSkillPaths();
        $this->skillDiscovery = new SkillDiscovery($this->workspacePath, $packageSkillDirs);
        $this->skillDiscovery->ensureSkillsDir();
    }

    private function discoverToolkits(OutputInterface|SymfonyStyle|null $io): void
    {
        $this->discovery = new ToolkitDiscovery(
            $this->workDir,
            $this->workspacePath,
            $this->credentialResolver,
            $this->visibilityRegistry,
        );
        $newToolkits = $this->discovery->discoverAll();

        if (!empty($newToolkits) && $io !== null && $io->isVerbose()) {
            $io->writeln('Discovered new toolkits: ' . implode(', ', $newToolkits));
        }
    }

    private function discoverChannels(OutputInterface|SymfonyStyle|null $io): void
    {
        $this->channelDiscovery = new ChannelDiscovery(
            $this->workDir,
            $this->workspacePath,
            $this->credentialResolver,
        );
        $newDrivers = $this->channelDiscovery->discoverAll();

        if (!empty($newDrivers) && $io !== null && $io->isVerbose()) {
            $io->writeln('Discovered new channel drivers: ' . implode(', ', $newDrivers));
        }
    }

    /**
     * Seed roles and loop definitions from discovered toolkit packages.
     *
     * Runs after discoverToolkits() so ToolkitDiscovery is available.
     * Package roles/loops use copy-if-not-exists semantics — workspace files always win.
     */
    private function seedPackageContent(): void
    {
        $packageRolePaths = $this->discovery->discoverPackageRolePaths();
        if (!empty($packageRolePaths)) {
            $this->roleDiscovery->seedPackageRoles($packageRolePaths);
        }

        $packageLoopPaths = $this->discovery->discoverPackageLoopPaths();
        if ($this->loopDiscovery !== null && !empty($packageLoopPaths)) {
            $this->loopDiscovery->seedPackageLoops($packageLoopPaths);
        }
    }

    private function initializeMods(): void
    {
        $this->modsToolkit = ModManagerToolkit::fromEnv($this->workspacePath);
    }

    /**
     * Gate first-time boot behind the setup wizard.
     *
     * Runs automatically when no openclaw.json is found. Throws
     * ShutdownRequestedException if the wizard is cancelled or the user
     * declines to save, preventing boot from continuing with broken defaults.
     */
    private function runFirstTimeSetup(SymfonyStyle $io): void
    {
        $io->warning('No openclaw.json configuration found.');
        $io->text([
            'Coqui needs an openclaw.json file to know which AI providers and models to use.',
            'Please complete the setup wizard to get started.',
            '',
        ]);

        try {
            $wizard = new SetupWizard($io, $this->defaultsLoader);
            $saved = $wizard->runAndSave($this->configManager->path());

            if ($saved) {
                return;
            }
        } catch (InteractionCancelledException) {
            // fall through to gating error below
        } catch (ShutdownRequestedException $e) {
            throw $e;
        }

        $io->newLine();
        $io->text('<fg=yellow>Coqui requires a valid configuration to start.</>');
        $io->text('Run <fg=cyan>coqui setup</> to configure your AI provider and models.');
        throw new ShutdownRequestedException('Configuration required');
    }

    private function buildDefaultConfig(): CoquiOpenClawConfig
    {
        $defaultModel = $this->defaultsLoader->defaultModel();

        return OpenClawConfig::fromArray([
            'agents' => [
                'defaults' => [
                    'model' => ['primary' => $defaultModel],
                    'roles' => ['orchestrator' => $defaultModel],
                ],
            ],
        ]);
    }
}
