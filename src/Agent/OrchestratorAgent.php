<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Provider\FallbackProvider;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\SummarizePruningStrategy;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\CoquiSpace\SpaceToolkit;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CarmeloSantana\PHPAgents\Memory\MemoryEntry;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;
use CoquiBot\Coqui\Toolkit\ProjectSourceToolkit;
use CoquiBot\Coqui\Toolkit\SkillToolkit;
use CoquiBot\Coqui\Toolkit\StubToolkit;
use CoquiBot\Coqui\Toolkit\ToolkitGeneratorToolkit;
use CoquiBot\Coqui\Tool\ConfigTool;
use CoquiBot\Coqui\Tool\CredentialGuardToolkit;
use CoquiBot\Coqui\Tool\CredentialTool;
use CoquiBot\Coqui\Tool\PackageInfoTool;
use CoquiBot\Coqui\Tool\PhpExecuteTool;
use CoquiBot\Coqui\Tool\RestartTool;
use CoquiBot\Coqui\Tool\SpawnAgentTool;
use CoquiBot\Coqui\Tool\StubTool;
use CoquiBot\Coqui\Tool\SummarizeConversationTool;
use CoquiBot\Coqui\Tool\ToolRegistry;
use CoquiBot\Coqui\Tool\ToolSearchTool;
use CoquiBot\Coqui\Tool\VisionTool;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Contract\ContextWindowInterface;
use CarmeloSantana\PHPAgents\Contract\TokenCounterInterface;
use CarmeloSantana\PHPAgents\Prompt\SystemPrompt;

use SplObserver;

/**
 * The top-level orchestrator agent that receives user input.
 *
 * Runs on a cheap local model (Ollama) and delegates specialized tasks
 * to child agents via the spawn_agent tool.
 *
 * File I/O is sandboxed to the workspace directory. Read access to the
 * project root is available through shell commands (cat, grep, find).
 */
final class OrchestratorAgent extends AbstractAgent
{
    private SpawnAgentTool $spawnTool;
    private CredentialTool $credentialTool;
    private PackageInfoTool $packageInfoTool;
    private PhpExecuteTool $phpExecuteTool;
    private ?RestartTool $restartTool = null;
    private ?ConfigTool $configTool = null;
    private VisionTool $visionTool;
    private ?SummarizeConversationTool $summarizeTool = null;
    private ToolRegistry $toolRegistry;
    private ToolSearchTool $toolSearchTool;
    private ?ContextWindowInterface $contextWindowInstance = null;

    /** @var ToolkitInterface[] Toolkits added to parent — mirrors AbstractAgent's private $toolkits */
    private array $ownToolkits = [];

    public function __construct(
        ProviderInterface $provider,
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?SplObserver $observer = null,
        ?ToolkitDiscovery $discovery = null,
        int $maxIterations = AbstractAgent::DEFAULT_MAX_ITERATIONS,
        ?ToolExecutionPolicyInterface $executionPolicy = null,
        private readonly ?ScriptSanitizer $sanitizer = null,
        ?\Closure $onRestart = null,
        ?CredentialResolverInterface $credentialResolver = null,
        private readonly ?SkillDiscovery $skillDiscovery = null,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        ?CancellationTokenInterface $cancellationToken = null,
        ?PendingInputProviderInterface $pendingInputProvider = null,
        ?BackgroundTaskToolkit $backgroundTaskToolkit = null,
        private readonly ?MemoryStore $memoryStore = null,
        private readonly ?MemorySummarizer $memorySummarizer = null,
        private readonly ?MountManager $mountManager = null,
        ?ConfigManager $configManager = null,
        ?ConfigGuard $configGuard = null,
        private readonly ?ToolkitVisibilityRegistry $visibilityRegistry = null,
        private readonly ?SpaceToolkit $spaceToolkit = null,
        private readonly ?string $activeRole = null,
    ) {
        // Initialise the registry before parent::__construct() so that our
        // addToolkit() override can populate it immediately for every toolkit added.
        $this->toolRegistry = new ToolRegistry();

        // Wrap primary provider with FallbackProvider when fallback models are configured
        $effectiveProvider = $provider;
        if ($config instanceof OpenClawConfig) {
            $fallbacks = $config->getFallbacks();
            if (!empty($fallbacks)) {
                $factory = new ProviderFactory($config);
                $fallbackProviders = array_map(
                    fn(string $model) => $factory->create($model),
                    $fallbacks,
                );
                $effectiveProvider = new FallbackProvider($provider, $fallbackProviders);
            }
        }

        // Resolve context window from model definition when available
        $contextWindow = $this->resolveContextWindow($config, $this->roleResolver);
        $this->contextWindowInstance = $contextWindow;

        // Resolve a summarize-then-drop pruning strategy for context window overflow.
        // This is a safety net — autoSummarizeIfNeeded() runs pre-turn as the primary mechanism.
        $pruningStrategy = null;
        if ($this->storage !== null) {
            try {
                $utilityFactory = new ProviderFactory($config);
                $utilityModel = $this->roleResolver->resolveUtility();
                if ($utilityModel !== '') {
                    $utilityProvider = $utilityFactory->create($utilityModel);

                    $keepRecentCfg = $config->get('agents.defaults.context.keepRecentTurns');
                    $keepRecent = is_numeric($keepRecentCfg) ? max(1, min(20, (int) $keepRecentCfg)) : CoquiDefaults::KEEP_RECENT_TURNS;

                    $pruningStrategy = new SummarizePruningStrategy(
                        provider: $utilityProvider,
                        storage: $this->storage,
                        memoryStore: $this->memoryStore,
                        keepRecentTurns: $keepRecent,
                    );
                }
            } catch (\Throwable) {
                // Fall through — use default pruning strategy
            }
        }

        parent::__construct($effectiveProvider, $maxIterations, $executionPolicy, $cancellationToken, $pendingInputProvider, $contextWindow, $pruningStrategy);

        // Use injected resolver or create one (backward compat for standalone use)
        $credentialResolver ??= new \CoquiBot\Coqui\Config\CredentialResolver(workspacePath: $this->workspacePath);
        $credentialResolver->loadIntoProcessEnv();

        // Resolve the effective access level for toolkit filtering.
        // When activeRole is set (via /role command), use the role's declared access_level.
        $effectiveAccessLevel = 'full';
        if ($this->activeRole !== null && $this->activeRole !== 'orchestrator' && $this->roleDiscovery !== null) {
            try {
                $roleProps = $this->roleDiscovery->getRole($this->activeRole);
                $effectiveAccessLevel = $roleProps->accessLevel;
            } catch (\Throwable) {
                // Role not found — fall through with 'full' access
            }
        }

        // Filesystem toolkit — access level determines read/write vs read-only
        if ($effectiveAccessLevel !== 'minimal') {
            $this->addToolkit(new FilesystemToolkit(
                rootPath: $this->workspacePath,
                allowedPaths: $this->mountManager?->allowedPaths() ?? [],
                readOnly: $effectiveAccessLevel === 'readonly',
            ));
        }

        // Shell toolkit — only available for 'full' access roles
        $shellAllowed = $this->resolveShellAllowedCommands();
        if ($effectiveAccessLevel === 'full') {
            $this->addToolkit(new ShellToolkit(
                workDir: $this->projectRoot,
                allowedCommands: $shellAllowed,
                timeout: 60,
            ));
        }

        // Memory toolkit — SQLite-backed with optional vector search
        if ($this->memoryStore !== null) {
            $this->addToolkit(new MemoryToolkit($this->memoryStore));
        }

        // Artifact toolkit — versioned output tracking (shares database with session storage)
        if ($this->storage !== null && $this->sessionId !== null) {
            $artifactStore = new \CoquiBot\Coqui\Storage\ArtifactStore($this->storage->getPdo());
            $this->addToolkit(new ArtifactToolkit($artifactStore, $this->sessionId));
        }

        // Project source toolkit — read-only access to the Coqui project codebase
        $this->addToolkit(new ProjectSourceToolkit(projectRoot: $this->projectRoot));

        // Toolkit generator — scaffold new toolkit packages
        $this->addToolkit(new ToolkitGeneratorToolkit(workspacePath: $this->workspacePath));

        // Skill toolkit — discover and use Agent Skills
        if ($this->skillDiscovery !== null) {
            $this->addToolkit(new SkillToolkit($this->skillDiscovery));
        }

        // Coqui Space toolkit — marketplace integration
        if ($this->spaceToolkit !== null) {
            $this->addToolkit($this->spaceToolkit);
        }

        // Register any auto-discovered toolkits from installed packages with visibility applied
        if ($discovery !== null) {
            foreach ($discovery->instantiateRegisteredGrouped() as $entry) {
                $packageName = $entry['package'];
                $toolkit = $entry['toolkit'];
                $vis = $this->visibilityRegistry?->getPackageVisibility($packageName)
                    ?? ToolkitVisibility::Enabled;

                if ($vis === ToolkitVisibility::Stub) {
                    $this->addToolkit(new StubToolkit($toolkit));
                } else {
                    $this->addToolkit($toolkit);
                }
            }
        }

        // Create spawn tool with workspace isolation
        $this->spawnTool = new SpawnAgentTool(
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            roleDiscovery: $this->roleDiscovery,
            storage: $this->storage,
            sessionId: $this->sessionId,
            observer: $this->observer,
            mountManager: $this->mountManager,
            shellAllowedCommands: $shellAllowed,
        );

        // Create credential tool for API key management
        $this->credentialTool = new CredentialTool(
            resolver: $credentialResolver,
        );

        // Create package info tool for SDK introspection
        $this->packageInfoTool = new PackageInfoTool(
            projectRoot: $this->projectRoot,
        );

        // Create PHP execution tool for running SDK code
        $this->phpExecuteTool = new PhpExecuteTool(
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            sanitizer: $this->sanitizer,
            mountManager: $this->mountManager,
        );

        // Create restart tool if callback provided
        if ($onRestart !== null) {
            $this->restartTool = new RestartTool(onRestart: $onRestart);
        }

        // Create vision tool for image analysis
        $this->visionTool = new VisionTool(
            analyzer: new VisionAnalyzer(
                roleResolver: $this->roleResolver,
                config: $this->config,
                roleDiscovery: $this->roleDiscovery,
                providerFactory: new ProviderFactory($this->config),
            ),
        );

        // Config tool — agent-facing config read/modify
        if ($configManager !== null) {
            $this->configTool = new ConfigTool(
                configManager: $configManager,
                configGuard: $configGuard ?? new ConfigGuard(),
            );
        }

        // Summarize conversation tool — agent can compress history to save context space
        if ($this->storage !== null && $this->sessionId !== null) {
            $conversationSummarizer = new ConversationSummarizer(
                storage: $this->storage,
                memoryStore: $this->memoryStore,
            );
            $this->summarizeTool = new SummarizeConversationTool(
                summarizer: $conversationSummarizer,
                roleResolver: $this->roleResolver,
                config: $this->config,
                sessionId: $this->sessionId,
            );
        }

        // Background task toolkit — only in API mode
        if ($backgroundTaskToolkit !== null) {
            $this->addToolkit($backgroundTaskToolkit);
        }

        // Register standalone tools in the registry now that they're all created.
        // Toolkit tools are already registered via addToolkit() override above.
        foreach ([$this->spawnTool, $this->credentialTool, $this->packageInfoTool, $this->phpExecuteTool] as $tool) {
            $this->toolRegistry->register($tool);
        }

        $this->toolRegistry->register($this->visionTool);

        if ($this->restartTool !== null) {
            $this->toolRegistry->register($this->restartTool);
        }

        if ($this->configTool !== null) {
            $this->toolRegistry->register($this->configTool);
        }

        if ($this->summarizeTool !== null) {
            $this->toolRegistry->register($this->summarizeTool);
        }

        // Create the tool search tool — always-loaded, not subject to maxTools cap.
        // Gives the agent on-demand access to the full tool library via BM25 search.
        $this->toolSearchTool = new ToolSearchTool($this->toolRegistry);

        // Apply maxTools cap from config (0 = unlimited).
        // tool_search is placed first in tools() so it is always within the cap.
        $maxToolsCfg = $this->config->get('agents.defaults.maxTools');
        if (is_int($maxToolsCfg) || is_string($maxToolsCfg)) {
            $cap = (int) $maxToolsCfg;
            if ($cap > 0) {
                $this->setMaxTools($cap);
            }
        }

        // Set up FallbackProvider notifications via the agent's observer system
        if ($effectiveProvider instanceof FallbackProvider) {
            $effectiveProvider->setOnNotify(fn(string $msg) => $this->notify('agent.warning', $msg));
        }
    }

    /**
     * Override addToolkit() to also register every tool in the BM25 registry.
     *
     * When the toolkit is a StubToolkit, registers the REAL tool descriptions
     * (via realTools()) so tool_search returns full details, while the stub
     * schemas are what actually get sent to the LLM via the parent's tool list.
     *
     * Tracks all added toolkits locally for getSystemPromptText() reconstruction.
     */
    #[\Override]
    public function addToolkit(ToolkitInterface $toolkit): static
    {
        // Register real tool descriptions in BM25 registry for tool_search
        $toolsForRegistry = ($toolkit instanceof StubToolkit)
            ? $toolkit->realTools()
            : $toolkit->tools();

        foreach ($toolsForRegistry as $tool) {
            $this->toolRegistry->register($tool);
        }

        parent::addToolkit($toolkit);
        $this->ownToolkits[] = $toolkit;

        return $this;
    }

    public function instructions(): string
    {
        // When a non-orchestrator role is active, use the role's instructions
        // instead of the full orchestrator prompt. This enables /role switching
        // while preserving memory injection and context awareness.
        if ($this->activeRole !== null && $this->activeRole !== 'orchestrator' && $this->roleDiscovery !== null) {
            try {
                $roleInstructions = $this->roleDiscovery->readInstructions($this->activeRole);
                $rendered = $roleInstructions;
            } catch (\Throwable) {
                // Role instructions not found — fall through to orchestrator prompt
                $rendered = $this->renderOrchestratorPrompt();
            }
        } else {
            $rendered = $this->renderOrchestratorPrompt();
        }

        // Lost-in-middle mitigation: inject memories at START (high attention)
        // and recapitulation at END (recency attention) of the instructions block.
        $rendered = $this->injectMemoryContext($rendered);

        return $rendered;
    }

    private function renderOrchestratorPrompt(): string
    {
        $roles = implode(', ', $this->roleResolver->availableRoles());
        $skillsSummary = $this->skillDiscovery?->buildPromptSummary() ?? 'No skills installed.';
        $storageMap = $this->mountManager?->storageMap() ?? '';

        $prompt = new OrchestratorPrompt(
            workspacePath: $this->workspacePath,
            projectRoot: $this->projectRoot,
            availableRoles: $roles,
            availableSkills: $skillsSummary,
            storageMap: $storageMap,
        );

        return $prompt->render();
    }

    private function injectMemoryContext(string $rendered): string
    {
        if ($this->memorySummarizer !== null) {
            $utilityProvider = $this->resolveUtilityProvider();
            $memorySummary = $this->memorySummarizer->getSummary($utilityProvider);

            if ($memorySummary !== '') {
                $rendered = "# CORE MEMORIES\n\n" . $memorySummary . "\n\n" . $rendered;

                if ($this->memoryStore !== null) {
                    $topMemories = $this->memoryStore->getTopImportantMemories(5);
                    if ($topMemories !== []) {
                        $bullets = array_map(
                            static fn(MemoryEntry $e) => '- ' . $e->content,
                            $topMemories,
                        );
                        $rendered .= "\n\n# KEY CONTEXT REMINDER\n\nCritical user context (refer to CORE MEMORIES for full details):\n" . implode("\n", $bullets);
                    }
                }
            }
        }

        return $rendered;
    }

    /**
     * Return the active role name (null means orchestrator default).
     */
    public function getActiveRole(): ?string
    {
        return $this->activeRole;
    }

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        // ALWAYS_ENABLED tools come first and are never filtered.
        // tool_search must be first so it is always within the maxTools cap.
        $alwaysEnabled = [
            $this->toolSearchTool,
            $this->credentialTool,
        ];

        // Summarize tool is always available when a session exists
        if ($this->summarizeTool !== null) {
            $alwaysEnabled[] = $this->summarizeTool;
        }

        // Visibility-aware standalone tools (by tool name => instance)
        /** @var array<string, ToolInterface> */
        $visibilityManaged = [
            'spawn_agent'    => $this->spawnTool,
            'package_info'   => $this->packageInfoTool,
            'php_execute'    => $this->phpExecuteTool,
            'vision_analyze' => $this->visionTool,
        ];

        if ($this->restartTool !== null) {
            $visibilityManaged['restart_coqui'] = $this->restartTool;
        }

        if ($this->configTool !== null) {
            $visibilityManaged['config'] = $this->configTool;
        }

        $tools = $alwaysEnabled;

        foreach ($visibilityManaged as $name => $tool) {
            $vis = $this->visibilityRegistry?->getToolVisibility($name)
                ?? ToolkitVisibility::Enabled;

            if ($vis === ToolkitVisibility::Disabled) {
                continue;
            }

            $tools[] = $vis === ToolkitVisibility::Stub ? new StubTool($tool) : $tool;
        }

        return $tools;
    }

    /**
     * @return ModelCapability[]
     */
    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools];
    }

    public function getSpawnTool(): SpawnAgentTool
    {
        return $this->spawnTool;
    }

    /**
     * Build and return the system prompt text as the LLM would receive it.
     *
     * Used by the /prompt REPL command and GET /api/v1/server/prompt endpoint
     * to inspect what the agent sees at runtime.
     *
     * Returns the reconstructed system prompt (identity + iteration budget +
     * toolkit guidelines). Tool function schemas are separate API parameters
     * and are not part of the system prompt text.
     */
    public function getSystemPromptText(): string
    {
        $prompt = SystemPrompt::withIdentity($this->instructions());
        $prompt = SystemPrompt::withIterationBudget($this->maxIterations(), $prompt);

        if (!empty($this->ownToolkits)) {
            $prompt = SystemPrompt::withToolkits($this->ownToolkits, $prompt);
        }

        return SystemPrompt::render($prompt);
    }

    /**
     * Count tools that would be sent to the LLM (standalone + toolkit tools).
     */
    public function getToolCount(): int
    {
        $count = count($this->tools());

        foreach ($this->ownToolkits as $toolkit) {
            $count += count($toolkit->tools());
        }

        return $count;
    }

    /**
     * Number of toolkits registered with this agent instance.
     */
    public function getOwnToolkitCount(): int
    {
        return count($this->ownToolkits);
    }

    /**
     * Token breakdown per registered toolkit (guidelines + tool schemas).
     *
     * @return array<int, array{name: string, class: string, guidelines_tokens: int, tools_tokens: int, total_tokens: int}>
     */
    public function getToolkitTokenBreakdown(TokenCounterInterface $counter): array
    {
        $breakdown = [];

        foreach ($this->ownToolkits as $toolkit) {
            if ($toolkit instanceof StubToolkit) {
                $class = $toolkit->innerClass();
                $parts = explode('\\', $class);
                $name = end($parts) . ' (stub)';
            } elseif ($toolkit instanceof CredentialGuardToolkit) {
                $class = $toolkit->innerClass();
                $parts = explode('\\', $class);
                $name = end($parts);
            } else {
                $class = $toolkit::class;
                $parts = explode('\\', $class);
                $name = end($parts);
            }

            $guidelinesTokens = $counter->count($toolkit->guidelines());
            $toolsTokens = $counter->countTools($toolkit->tools());

            $breakdown[] = [
                'name'              => $name,
                'class'             => $class,
                'guidelines_tokens' => $guidelinesTokens,
                'tools_tokens'      => $toolsTokens,
                'total_tokens'      => $guidelinesTokens + $toolsTokens,
            ];
        }

        return $breakdown;
    }

    /**
     * Token count for standalone tools (not part of any toolkit).
     */
    public function getStandaloneToolTokens(TokenCounterInterface $counter): int
    {
        return $counter->countTools($this->tools());
    }

    /** Default shell commands available to the orchestrator. */
    private const array DEFAULT_SHELL_COMMANDS = [
        'php', 'git', 'grep', 'find', 'cat', 'head', 'tail', 'wc', 'ls',
        'curl', 'wget', 'make', 'sort', 'uniq', 'sed', 'awk', 'diff',
    ];

    /**
     * Resolve shell allowed commands from config or defaults.
     *
     * Reads `agents.defaults.shellAllowedCommands` from openclaw.json.
     * If not set, uses DEFAULT_SHELL_COMMANDS.
     *
     * @return string[]
     */
    private function resolveShellAllowedCommands(): array
    {
        $configured = $this->config->get('agents.defaults.shellAllowedCommands');

        if (is_array($configured) && !empty($configured)) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return self::DEFAULT_SHELL_COMMANDS;
    }

    /**
     * Resolve a ContextWindow from the model definition in config.
     *
     * Uses the orchestrator model's declared contextWindow and maxTokens
     * to create an accurate token budget. Falls back to a conservative
     * 128K window when no model definition is available.
     */
    private function resolveContextWindow(ConfigInterface $config, RoleResolver $roleResolver): ContextWindowInterface
    {
        if ($config instanceof OpenClawConfig) {
            $modelString = $roleResolver->resolve('orchestrator');
            $parts = explode('/', $modelString, 2);
            $modelId = $parts[1] ?? $modelString;

            $modelDef = $config->getModelDefinition($modelId)
                ?? $config->getModelDefinition($modelString);

            if ($modelDef !== null) {
                return ContextWindow::fromModel($modelDef);
            }
        }

        // Conservative fallback: 128K context window, 4K reserved for completion
        return new ContextWindow(maxTok: CoquiDefaults::CONTEXT_WINDOW_FALLBACK, reservedTok: CoquiDefaults::CONTEXT_WINDOW_RESERVED);
    }

    /**
     * Get the context window tracker for this agent.
     */
    public function getContextWindow(): ?ContextWindowInterface
    {
        return $this->contextWindowInstance;
    }

    /**
     * Resolve a utility provider for cheap single-shot tasks
     * (memory compression, titles, summarization).
     *
     * Returns null if no provider can be resolved — callers should
     * degrade gracefully (e.g. skip LLM compression).
     */
    private function resolveUtilityProvider(): ?\CarmeloSantana\PHPAgents\Contract\ProviderInterface
    {
        try {
            $factory = new ProviderFactory($this->config);
            $utilityModel = $this->roleResolver->resolveUtility();

            if ($utilityModel !== '') {
                return $factory->create($utilityModel);
            }
        } catch (\Throwable) {
            // Fall through — utility provider is best-effort
        }

        return null;
    }
}
