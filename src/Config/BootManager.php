<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use CarmeloSantana\PHPAgents\Contract\EmbeddingProviderInterface;
use CarmeloSantana\PHPAgents\Embedding\OllamaEmbeddingProvider;
use CarmeloSantana\PHPAgents\Embedding\OpenAIEmbeddingProvider;

use CoquiBot\Coqui\Contract\MountDefinition;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
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
    private OpenClawConfig $config;
    private string $configPath = '';
    private string $workspacePath;
    private CredentialResolver $credentialResolver;
    private ToolkitDiscovery $discovery;
    private SkillDiscovery $skillDiscovery;
    private RoleDiscovery $roleDiscovery;
    private RoleResolver $roleResolver;
    private CatastrophicBlacklist $blacklist;
    private DefaultsLoader $defaultsLoader;
    private MountManager $mountManager;
    private MemoryStore $memoryStore;
    private MemorySummarizer $memorySummarizer;

    public function __construct(
        private readonly string $workDir,
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
    public function boot(OutputInterface|SymfonyStyle|null $io = null, ?string $configPath = null): bool
    {
        $this->loadConfig($io, $configPath);
        $this->blacklist = CatastrophicBlacklist::fromConfig($this->config);
        $this->initializeWorkspace();
        $this->initializeMounts();
        $this->discoverRoles();
        $this->roleResolver = new RoleResolver($this->config, $this->defaultsLoader, $this->roleDiscovery);
        $this->initializeCredentials();
        $this->initializeMemory();
        $this->discoverToolkits($io);
        $this->discoverSkills();

        return true;
    }

    public function config(): OpenClawConfig
    {
        return $this->config;
    }

    /**
     * Resolved absolute path to the active openclaw.json (empty if using defaults).
     */
    public function configPath(): string
    {
        return $this->configPath;
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

    public function memoryStore(): MemoryStore
    {
        return $this->memoryStore;
    }

    public function memorySummarizer(): MemorySummarizer
    {
        return $this->memorySummarizer;
    }

    /**
     * Reload config from disk — updates resolver, workspace, blacklist, and mounts.
     *
     * Called after the setup wizard and when external config changes are detected.
     * Rebuilds all config-derived state so the next agent turn uses fresh values.
     */
    public function reloadConfig(string $configPath): void
    {
        $this->config = OpenClawConfig::fromFile($configPath);
        $this->blacklist = CatastrophicBlacklist::fromConfig($this->config);
        $this->roleDiscovery->invalidateCache();
        $this->roleResolver = new RoleResolver($this->config, $this->defaultsLoader, $this->roleDiscovery);

        $workspaceResolver = new WorkspaceResolver($this->config, $this->workDir);
        $this->workspacePath = $workspaceResolver->resolve();

        $this->initializeMounts();
    }

    private function loadConfig(OutputInterface|SymfonyStyle|null $io, ?string $configPath): void
    {
        $configPath ??= $this->workDir . '/openclaw.json';

        if (!file_exists($configPath)) {
            $configPath = dirname(__DIR__, 2) . '/openclaw.json';
        }

        if (file_exists($configPath)) {
            $this->configPath = realpath($configPath) ?: $configPath;
            $this->config = OpenClawConfig::fromFile($configPath);
            return;
        }

        // Interactive setup wizard — only available with SymfonyStyle
        if ($io instanceof SymfonyStyle) {
            $io->warning('No openclaw.json configuration found.');
            $io->text([
                'Coqui needs an openclaw.json file to know which AI providers and models to use.',
                'Without it, you may see connection errors like "404 Not Found".',
                '',
            ]);

            if ($io->confirm('Would you like to run the setup wizard now?', true)) {
                $outputPath = $this->workDir . '/openclaw.json';
                $wizard = new SetupWizard($io, $this->defaultsLoader);
                $saved = $wizard->runAndSave($outputPath);

                if ($saved && file_exists($outputPath)) {
                    $this->configPath = realpath($outputPath) ?: $outputPath;
                    $this->config = OpenClawConfig::fromFile($outputPath);
                    return;
                }
            }

            $defaultModel = $this->defaultsLoader->defaultModel();
            $io->text("<fg=gray>Using defaults (model: {$defaultModel}). Run <fg=cyan>coqui setup</> to configure.</>");
        }

        $this->config = $this->buildDefaultConfig();
    }

    private function initializeWorkspace(): void
    {
        $workspaceResolver = new WorkspaceResolver($this->config, $this->workDir);
        $this->workspacePath = $workspaceResolver->resolve();

        $workspaceComposer = new WorkspaceComposerManager($this->workspacePath);
        $workspaceComposer->initialize();
        $workspaceComposer->loadAutoloader();
    }

    /**
     * Read mount declarations from config and initialize the MountManager.
     *
     * Reads `agents.defaults.mounts` from openclaw.json — an array of mount
     * objects with path, alias, access (ro|rw), and optional description.
     * Creates symlinks under workspace/mnt/ for agent discoverability.
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

    private function discoverRoles(): void
    {
        $this->roleDiscovery = new RoleDiscovery($this->workspacePath, $this->workDir);
        $this->roleDiscovery->seedBuiltinRoles();
    }

    private function initializeMemory(): void
    {
        $dbPath = $this->workspacePath . '/data/memory.db';
        $embeddingProvider = $this->resolveEmbeddingProvider();

        $this->memoryStore = new MemoryStore($dbPath, $embeddingProvider);
        $this->memorySummarizer = new MemorySummarizer($this->memoryStore);
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
        $this->discovery = new ToolkitDiscovery($this->workDir, $this->workspacePath, $this->credentialResolver);
        $newToolkits = $this->discovery->discoverAll();

        if (!empty($newToolkits) && $io !== null && $io->isVerbose()) {
            $io->writeln('Discovered new toolkits: ' . implode(', ', $newToolkits));
        }
    }

    private function buildDefaultConfig(): OpenClawConfig
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
