<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Config\ModelDefinition;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use CoquiBot\Coqui\Storage\EditHistory;
use CoquiBot\Coqui\Toolkit\FileSystemToolkit;
use CoquiBot\Coqui\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Toolkit\WebToolkit;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\ModelFamilyResolver;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Provider\FallbackProvider;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\RoleToolkitResolver;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\ShellConfigResolver;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\SummarizePruningStrategy;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;
use CoquiBot\Coqui\CoquiSpace\SpaceToolkit;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Memory\MemoryEntry;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\ToolUsageTracker;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;
use CoquiBot\Coqui\Toolkit\LearningToolkit;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;
use CoquiBot\Coqui\Toolkit\CoquiSourceToolkit;
use CoquiBot\Coqui\Toolkit\SkillToolkit;
use CoquiBot\Coqui\Toolkit\StubToolkit;
use CoquiBot\Coqui\Toolkit\TodoToolkit;
use CoquiBot\Coqui\Toolkit\ToolkitGeneratorToolkit;
use CoquiBot\Coqui\Tool\ConfigTool;
use CoquiBot\Coqui\Tool\CredentialGuardToolkit;
use CoquiBot\Coqui\Tool\CredentialTool;
use CoquiBot\Coqui\Tool\PackageInfoTool;
use CoquiBot\Coqui\Tool\PhpExecuteTool;
use CoquiBot\Coqui\Tool\RestartTool;
use CoquiBot\Coqui\Tool\SpawnAgentTool;
use CoquiBot\Coqui\Tool\StubTool;
use CoquiBot\Coqui\Tool\ExtractMemoriesTool;
use CoquiBot\Coqui\Tool\SummarizeConversationTool;
use CoquiBot\Coqui\Tool\ToolkitListTool;
use CoquiBot\Coqui\Tool\ToolRegistry;
use CoquiBot\Coqui\Tool\ToolSearchTool;
use CoquiBot\Coqui\Tool\VisionTool;
use CoquiBot\Coqui\Toolkit\SessionEvaluationToolkit;
use CarmeloSantana\PHPAgents\Context\ContextWindow;
use CarmeloSantana\PHPAgents\Context\HeuristicCounter;
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
    private ?ExtractMemoriesTool $extractMemoriesTool = null;
    private ToolkitListTool $toolkitListTool;
    private ToolRegistry $toolRegistry;
    private ToolSearchTool $toolSearchTool;
    private ?ContextWindowInterface $contextWindowInstance = null;
    private ?SummarizePruningStrategy $pruningStrategyInstance = null;

    /** @var ToolkitInterface[] Toolkits added to parent — mirrors AbstractAgent's private $toolkits */
    private array $ownToolkits = [];

    /** @var array<int, array{name: string, description: string, package: string}> Deferred toolkit info for prompt injection */
    private array $deferredToolkitInfo = [];

    /** @var array<string, ToolkitLoadingMode> Applied loading modes for REPL display (toolkit basename => mode) */
    private array $appliedLoadingModes = [];

    // Prompt cache — avoids rebuilding from disk (glob + file reads) on every iteration
    private ?string $cachedInstructions = null;
    private ?string $cachedInstructionsRole = null;
    private ?string $cachedMemoryHash = null;
    private ?string $cachedProjectId = null;

    private readonly RoleToolkitResolver $roleToolkitResolver;

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
        private readonly ?\CoquiBot\Coqui\Storage\ProjectStore $projectStore = null,
        private readonly ?DefaultsLoader $defaultsLoader = null,
        private readonly ?ModelFamilyResolver $familyResolver = null,
        private readonly bool $unsafeMode = false,
        ?ToolExecutorInterface $toolExecutor = null,
        ?TickCallbackInterface $tickCallback = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?ToolkitLoadingRegistry $loadingRegistry = null,
        private readonly ?ToolUsageTracker $usageTracker = null,
        private readonly ?string $workScopeSessionId = null,
    ) {
        // Initialise the registry before parent::__construct() so that our
        // addToolkit() override can populate it immediately for every toolkit added.
        $this->toolRegistry = new ToolRegistry();

        // Build role toolkit resolver from the active role's frontmatter
        $this->roleToolkitResolver = $this->buildRoleToolkitResolver($this->activeRole, $this->roleDiscovery);

        // Wrap primary provider with FallbackProvider when fallback models are configured
        $effectiveProvider = $provider;
        if ($config instanceof OpenClawConfig) {
            $fallbacks = $config->getFallbacks();
            if (!empty($fallbacks)) {
                $factory = new ProviderFactory($config, $this->httpClient);
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
                $utilityFactory = new ProviderFactory($config, $this->httpClient);
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
                        sessionId: $this->sessionId,
                    );
                }
            } catch (\Throwable) {
                // Fall through — use default pruning strategy
            }
        }

        $this->pruningStrategyInstance = $pruningStrategy;

        // Resolve budget safety margin from config (default: 20%)
        $safetyMarginCfg = $config->get('agents.defaults.context.budgetSafetyMarginPercent');
        $safetyMarginPercent = is_numeric($safetyMarginCfg) ? max(0, min(50, (int) $safetyMarginCfg)) : CoquiDefaults::BUDGET_SAFETY_MARGIN_PERCENT;

        parent::__construct($effectiveProvider, $maxIterations, $executionPolicy, $cancellationToken, $pendingInputProvider, $contextWindow, $pruningStrategy, $safetyMarginPercent, $toolExecutor, $tickCallback);

        // Use injected resolver or create one (backward compat for standalone use)
        $credentialResolver ??= new \CoquiBot\Coqui\Config\CredentialResolver(workspacePath: $this->workspacePath);
        $credentialResolver->loadIntoProcessEnv();

        // Resolve the effective access level for toolkit filtering.
        // When activeRole is set (via /role command), use the role's declared access_level.
        $effectiveAccessLevel = 'full';
        if ($this->roleDiscovery !== null) {
            $effectiveRole = ($this->activeRole === null || $this->activeRole === 'orchestrator')
                ? 'orchestrator'
                : $this->activeRole;
            try {
                $roleProps = $this->roleDiscovery->getRole($effectiveRole);
                $effectiveAccessLevel = $roleProps->accessLevel;
            } catch (\Throwable) {
                // Role not found — fall through with 'full' access
            }
        }

        // Filesystem toolkit — access level determines read/write vs read-only
        if ($effectiveAccessLevel !== 'minimal') {
            $isReadOnly = in_array($effectiveAccessLevel, ['readonly', 'readonly-shell'], true);
            $editHistory = $isReadOnly ? null : new EditHistory($this->workspacePath . '/data/edit-history');
            $retentionDaysCfg = $this->config->get('agents.defaults.editHistory.retentionDays');
            $retentionDays = is_int($retentionDaysCfg) && $retentionDaysCfg >= 1
                ? $retentionDaysCfg
                : CoquiDefaults::EDIT_HISTORY_RETENTION_DAYS;
            $this->addToolkit(new FileSystemToolkit(
                workspacePath: $this->workspacePath,
                readOnly: $isReadOnly,
                allowedPaths: $this->mountManager?->allowedPaths() ?? [],
                history: $editHistory,
                retentionDays: $retentionDays,
            ));
        }

        // Shell toolkit — available for 'full' and 'readonly-shell' access roles.
        // In unsafe mode, all command restrictions are bypassed (only catastrophic
        // blacklist at the execution policy layer remains as the safety net).
        $shellAllowed = $this->unsafeMode ? [] : ShellConfigResolver::resolveAllowed($this->config);
        $shellDenied = $this->unsafeMode ? [] : ShellConfigResolver::resolveDenied($this->config);
        if ($effectiveAccessLevel === 'full') {
            $this->addToolkit(new ShellToolkit(
                workDir: $this->projectRoot,
                allowedCommands: $shellAllowed,
                deniedCommands: $shellDenied,
                timeout: 60,
                unsafe: $this->unsafeMode,
            ));
        } elseif ($effectiveAccessLevel === 'readonly-shell') {
            $this->addToolkit(new ShellToolkit(
                workDir: $this->projectRoot,
                allowedCommands: ShellConfigResolver::READ_ONLY_SHELL_COMMANDS,
                timeout: 60,
            ));
        }

        // Web toolkit — HTTP requests with SSRF protection
        if ($effectiveAccessLevel === 'full') {
            $this->addToolkit(new WebToolkit());
        }

        // Memory toolkit — SQLite-backed with optional vector search
        if ($this->memoryStore !== null) {
            $this->addToolkit(new MemoryToolkit($this->memoryStore));
        }

        // Artifact toolkit — versioned output tracking (shares database with session storage)
        // When workScopeSessionId is set (loop stage tasks), toolkits that scope data
        // by session (artifacts, todos, sprints) use the work-scope session instead of
        // the execution session. This allows cross-stage data sharing within a loop.
        $toolkitSessionId = $this->workScopeSessionId ?? $this->sessionId;

        if ($this->storage !== null && $toolkitSessionId !== null) {
            $artifactStore = new \CoquiBot\Coqui\Storage\ArtifactStore($this->storage->getPdo());
            $todoStore = new \CoquiBot\Coqui\Storage\TodoStore($this->storage->getPdo());

            $planTodoGenerator = new PlanTodoGenerator(
                roleResolver: $this->roleResolver,
                config: $this->config,
                todoStore: $todoStore,
                roleDiscovery: $this->roleDiscovery,
            );

            $this->addToolkit(new ArtifactToolkit(
                $artifactStore,
                $toolkitSessionId,
                planTodoGenerator: $planTodoGenerator,
                todoStore: $todoStore,
            ));
        }

        // Todo toolkit — session-scoped task tracking for planning and implementation
        if ($this->storage !== null && $toolkitSessionId !== null) {
            $todoStore ??= new \CoquiBot\Coqui\Storage\TodoStore($this->storage->getPdo());
            $activeRoleName = $this->activeRole ?? 'orchestrator';
            $this->addToolkit(new TodoToolkit(
                $todoStore,
                $toolkitSessionId,
                $activeRoleName,
                $effectiveAccessLevel,
                $artifactStore ?? null,
            ));
        }

        // Sprint toolkit — project and sprint management across sessions
        if ($this->projectStore !== null) {
            $todoStore ??= $this->storage !== null
                ? new \CoquiBot\Coqui\Storage\TodoStore($this->storage->getPdo())
                : null;
            if ($todoStore !== null) {
                $this->addToolkit(new \CoquiBot\Coqui\Toolkit\SprintToolkit(
                    $this->projectStore,
                    $todoStore,
                    $toolkitSessionId,
                    $this->workspacePath,
                    $this->resolveActiveProjectId(),
                    $this->storage,
                ));
            }
        }

        // Project source toolkit — read-only access to the Coqui project codebase
        $this->addToolkit(new CoquiSourceToolkit(projectRoot: $this->projectRoot));

        // --- Candidate toolkits: collected first, then budget-gated ---
        // Non-system toolkits may be deferred (wrapped as StubToolkit) when the
        // total tool schema token count exceeds the configured budget. Frequency
        // data from ToolUsageTracker determines which candidates earn eager loading.

        /** @var array<int, array{toolkit: ToolkitInterface, package: string, description: string}> */
        $candidateToolkits = [];

        // Toolkit generator — scaffold new toolkit packages
        if ($this->roleToolkitResolver->isToolkitAllowed(ToolkitGeneratorToolkit::class)) {
            $candidateToolkits[] = [
                'toolkit' => new ToolkitGeneratorToolkit(workspacePath: $this->workspacePath),
                'package' => '',
                'description' => 'scaffold new toolkit packages',
            ];
        }

        // Coqui Space toolkit — marketplace integration
        if ($this->spaceToolkit !== null) {
            $candidateToolkits[] = [
                'toolkit' => $this->spaceToolkit,
                'package' => '',
                'description' => 'marketplace integration',
            ];
        }

        // Auto-discovered toolkits from installed packages with visibility applied
        if ($discovery !== null) {
            foreach ($discovery->instantiateRegisteredGrouped() as $entry) {
                $packageName = $entry['package'];
                $toolkit = $entry['toolkit'];
                $vis = $this->visibilityRegistry?->getPackageVisibility($packageName)
                    ?? ToolkitVisibility::Enabled;

                // Disabled packages are invisible — skip entirely
                if ($vis === ToolkitVisibility::Disabled) {
                    continue;
                }

                // User-explicit Stub visibility always wins — bypass budget gate
                if ($vis === ToolkitVisibility::Stub) {
                    $this->addToolkit(new StubToolkit($toolkit), $packageName);
                    $this->deferredToolkitInfo[] = [
                        'name' => self::toolkitBasename($toolkit),
                        'description' => $this->extractToolkitDescription($toolkit),
                        'package' => $packageName,
                    ];
                    continue;
                }

                $candidateToolkits[] = [
                    'toolkit' => $toolkit,
                    'package' => $packageName,
                    'description' => $this->extractToolkitDescription($toolkit),
                ];
            }
        }

        // Background task toolkit — only in API mode, never in loop stages
        if ($backgroundTaskToolkit !== null && $this->workScopeSessionId === null) {
            $candidateToolkits[] = [
                'toolkit' => $backgroundTaskToolkit,
                'package' => '',
                'description' => 'background task management',
            ];
        }

        // Schedule, webhook, and loop toolkits — only for top-level agents, never in loop stages.
        // Loop stages must not spawn background tasks, create schedules, manage webhooks, or
        // start nested loops — this prevents infinite recursion and uncontrolled spawning.
        if ($this->storage !== null && $effectiveAccessLevel === 'full' && $this->workScopeSessionId === null) {
            if ($this->roleToolkitResolver->isToolkitAllowed(\CoquiBot\Coqui\Toolkit\ScheduleToolkit::class)) {
                $scheduleStore = new \CoquiBot\Coqui\Storage\ScheduleStore($this->storage->getPdo());
                $candidateToolkits[] = [
                    'toolkit' => new \CoquiBot\Coqui\Toolkit\ScheduleToolkit($scheduleStore),
                    'package' => '',
                    'description' => 'cron-style task scheduling',
                ];
            }

            if ($this->roleToolkitResolver->isToolkitAllowed(\CoquiBot\Coqui\Toolkit\WebhookToolkit::class)) {
                $webhookStore = new \CoquiBot\Coqui\Storage\WebhookStore($this->storage->getPdo());
                $candidateToolkits[] = [
                    'toolkit' => new \CoquiBot\Coqui\Toolkit\WebhookToolkit($webhookStore),
                    'package' => '',
                    'description' => 'webhook subscription management',
                ];
            }

            // Loop toolkit — manages automated multi-role loop workflows
            if ($this->roleToolkitResolver->isToolkitAllowed(\CoquiBot\Coqui\Toolkit\LoopToolkit::class)) {
                $loopStore = new \CoquiBot\Coqui\Storage\LoopStore($this->storage->getPdo());
                $loopDiscovery = new \CoquiBot\Coqui\Config\LoopDiscovery(
                    $this->workspacePath,
                    $this->projectRoot !== '' ? $this->projectRoot : null,
                );
                $loopExecutor = ($this->projectStore !== null && isset($artifactStore) && $this->roleDiscovery !== null)
                    ? new \CoquiBot\Coqui\Agent\LoopExecutor(
                        loopStore: $loopStore,
                        projectStore: $this->projectStore,
                    )
                    : null;
                $candidateToolkits[] = [
                    'toolkit' => new \CoquiBot\Coqui\Toolkit\LoopToolkit($loopStore, $loopDiscovery, $loopExecutor, $this->sessionId),
                    'package' => '',
                    'description' => 'automated multi-role loop workflows',
                ];
            }
        }

        // Session evaluation toolkit
        if ($this->roleToolkitResolver->isToolkitAllowed(SessionEvaluationToolkit::class) && $this->storage !== null) {
            $evaluationStore = new \CoquiBot\Coqui\Storage\EvaluationStore($this->storage->getPdo());
            $lookbackHours = (int) ($this->config->get('agents.defaults.evaluation.lookbackHours') ?? 24);
            $inactivityHours = (int) ($this->config->get('agents.defaults.evaluation.inactivityHours') ?? 3);
            $candidateToolkits[] = [
                'toolkit' => new SessionEvaluationToolkit(
                    evaluationStore: $evaluationStore,
                    storage: $this->storage,
                    defaultLookbackHours: $lookbackHours,
                    defaultInactivityHours: $inactivityHours,
                ),
                'package' => '',
                'description' => 'session evaluation and grading',
            ];
        }

        // Learning toolkit
        if ($this->roleToolkitResolver->isToolkitAllowed(LearningToolkit::class) && $this->storage !== null) {
            $learnerEvalStore = new \CoquiBot\Coqui\Storage\EvaluationStore($this->storage->getPdo());
            $candidateToolkits[] = [
                'toolkit' => new LearningToolkit(
                    evaluationStore: $learnerEvalStore,
                ),
                'package' => '',
                'description' => 'autonomous learning from evaluations',
            ];
        }

        // --- Budget gate: decide which candidates load eagerly vs deferred ---
        $this->applyToolkitBudgetGate($candidateToolkits);

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
            projectStore: $this->projectStore,
            discovery: $discovery,
            memoryStore: $this->memoryStore,
            skillDiscovery: $this->skillDiscovery,
            sanitizer: $this->sanitizer,
            visibilityRegistry: $this->visibilityRegistry,
            shellDeniedCommands: $shellDenied,
            unsafeMode: $this->unsafeMode,
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

        // Create vision analyzer — shared with spawn tool for child agent image analysis
        $visionAnalyzer = new VisionAnalyzer(
            roleResolver: $this->roleResolver,
            config: $this->config,
            roleDiscovery: $this->roleDiscovery,
            providerFactory: new ProviderFactory($this->config, $this->httpClient),
        );

        // Create vision tool for image analysis
        $this->visionTool = new VisionTool(
            analyzer: $visionAnalyzer,
        );

        // Wire vision analyzer into spawn tool for child agent access
        $this->spawnTool->setVisionAnalyzer($visionAnalyzer);

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
                todoStore: $todoStore ?? null,
                artifactStore: $artifactStore ?? null,
            );
        }

        // Extract memories tool — agent can explicitly trigger memory extraction
        if ($this->memoryStore !== null && $this->storage !== null && $this->sessionId !== null) {
            $this->extractMemoriesTool = new ExtractMemoriesTool(
                memoryStore: $this->memoryStore,
                storage: $this->storage,
                sessionId: $this->sessionId,
                roleResolver: $this->roleResolver,
                config: $this->config,
            );
        }

        // Toolkit list tool — always available system tool for package discovery
        $this->toolkitListTool = new ToolkitListTool(workspacePath: $this->workspacePath);

        // Register standalone tools in the registry now that they're all created.
        // Toolkit tools are already registered via addToolkit() override above.
        foreach ([$this->spawnTool, $this->credentialTool, $this->packageInfoTool, $this->phpExecuteTool] as $tool) {
            $this->toolRegistry->register($tool);
        }

        $this->toolRegistry->register($this->toolkitListTool->tool());

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

        if ($this->extractMemoriesTool !== null) {
            $this->toolRegistry->register($this->extractMemoriesTool);
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
    public function addToolkit(ToolkitInterface $toolkit, string $packageName = ''): static
    {
        // Role-based toolkit filtering via declarative frontmatter patterns.
        // Check toolkit class basename against the role's toolkits rules.
        if ($this->roleToolkitResolver->hasRules()) {
            $className = $toolkit::class;
            // Unwrap StubToolkit to check the real toolkit class
            if ($toolkit instanceof StubToolkit) {
                $className = $toolkit->innerClass();
            }
            if (!$this->roleToolkitResolver->isToolkitAllowed($className)) {
                return $this;
            }
        }

        // Register real tool descriptions in BM25 registry for tool_search
        $toolsForRegistry = ($toolkit instanceof StubToolkit)
            ? $toolkit->realTools()
            : $toolkit->tools();

        foreach ($toolsForRegistry as $tool) {
            $this->toolRegistry->register($tool, $packageName);
        }

        parent::addToolkit($toolkit);
        $this->ownToolkits[] = $toolkit;

        return $this;
    }

    public function instructions(): string
    {
        // Cache key: active role + memory summary hash + active project ID.
        // The prompt is rebuilt from disk (glob + file reads) each time, which
        // is expensive in a loop-heavy agent. Cache it and invalidate only when
        // the role changes, memory content is updated, or active project changes.
        $currentRole = $this->activeRole ?? 'orchestrator';
        $currentMemoryHash = $this->computeMemoryHash();
        $currentProjectId = $this->resolveActiveProjectId();

        if (
            $this->cachedInstructions !== null
            && $this->cachedInstructionsRole === $currentRole
            && $this->cachedMemoryHash === $currentMemoryHash
            && $this->cachedProjectId === $currentProjectId
        ) {
            return $this->cachedInstructions;
        }

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

        // Inject deferred toolkit discovery hints when toolkits have been deferred
        $rendered = $this->injectDeferredToolkitHint($rendered);

        // Lost-in-middle mitigation: inject memories at START (high attention)
        // and recapitulation at END (recency attention) of the instructions block.
        $rendered = $this->injectMemoryContext($rendered);

        // Inject active project context after memory context
        $rendered = $this->injectProjectContext($rendered);

        $this->cachedInstructions = $rendered;
        $this->cachedInstructionsRole = $currentRole;
        $this->cachedMemoryHash = $currentMemoryHash;
        $this->cachedProjectId = $currentProjectId;

        return $rendered;
    }

    /**
     * Compute a lightweight hash of the memory state for cache invalidation.
     *
     * Uses the memory count (same key MemorySummarizer uses for its own cache)
     * so the prompt cache invalidates whenever memories change.
     */
    private function computeMemoryHash(): string
    {
        if ($this->memoryStore === null) {
            return '';
        }

        return (string) $this->memoryStore->count();
    }

    private function renderOrchestratorPrompt(): string
    {
        $roles = implode(', ', $this->roleResolver->availableRoles());
        $skillsSummary = $this->skillDiscovery?->buildPromptSummary() ?? 'No skills installed.';
        $storageMap = $this->mountManager?->storageMap() ?? '';

        $timeSinceLastMessage = 'New session';
        if ($this->storage !== null && $this->sessionId !== null) {
            $session = $this->storage->getSession($this->sessionId);
            $timeSinceLastMessage = $this->formatTimeSince($session['updated_at'] ?? null);
        }

        $prompt = new OrchestratorPrompt(
            workspacePath: $this->workspacePath,
            projectRoot: $this->projectRoot,
            availableRoles: $roles,
            availableSkills: $skillsSummary,
            storageMap: $storageMap,
            timeSinceLastMessage: $timeSinceLastMessage,
        );

        return $prompt->render();
    }

    /**
     * Format an ISO 8601 timestamp as a human-readable elapsed duration.
     */
    private function formatTimeSince(?string $isoTimestamp): string
    {
        if ($isoTimestamp === null) {
            return 'New session';
        }

        try {
            $then = new \DateTimeImmutable($isoTimestamp);
            $now = new \DateTimeImmutable();
            $diff = $now->diff($then);
        } catch (\Throwable) {
            return 'Unknown';
        }

        if ($diff->days >= 1) {
            return $diff->days === 1 ? '1 day' : $diff->days . ' days';
        }
        if ($diff->h >= 1) {
            return $diff->h === 1 ? '1 hour' : $diff->h . ' hours';
        }
        if ($diff->i >= 1) {
            return $diff->i === 1 ? '1 minute' : $diff->i . ' minutes';
        }

        return 'Just now';
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
     * Resolve the active project ID from the current session.
     */
    private function resolveActiveProjectId(): ?string
    {
        if ($this->storage === null || $this->sessionId === null) {
            return null;
        }

        return $this->storage->getActiveProjectId($this->sessionId);
    }

    /**
     * Inject active project context into the system prompt.
     *
     * Appends a # ACTIVE PROJECT section with project metadata, sprint roster,
     * and project directory path. Placed after memory context for high visibility.
     */
    private function injectProjectContext(string $rendered): string
    {
        $projectId = $this->resolveActiveProjectId();
        if ($projectId === null || $this->projectStore === null) {
            return $rendered;
        }

        try {
            $todoStore = null;
            if ($this->storage !== null) {
                $todoStore = new \CoquiBot\Coqui\Storage\TodoStore($this->storage->getPdo());
            }

            $context = $this->projectStore->getProjectContext(
                $projectId,
                $todoStore,
                $this->sessionId,
            );
        } catch (\Throwable) {
            return $rendered;
        }

        $project = $context['project'];
        $lines = [
            '# ACTIVE PROJECT',
            '',
            sprintf('**%s** (`%s`) — %s', $project['title'], $project['slug'], $project['status']),
        ];

        if (!empty($project['description'])) {
            $lines[] = $project['description'];
        }

        $lines[] = sprintf('Project directory: `projects/%s/`', $context['directory']);

        // Sprint roster
        if ($context['sprints'] !== []) {
            $lines[] = '';
            $lines[] = '**Sprints:**';
            foreach ($context['sprints'] as $sprint) {
                $progress = '';
                if (isset($sprint['progress']['percent'])) {
                    $progress = sprintf(
                        ' %d%% (%d/%d)',
                        $sprint['progress']['percent'],
                        $sprint['progress']['completed'],
                        $sprint['progress']['total'],
                    );
                }
                $lines[] = sprintf(
                    '- #%d %s [%s]%s',
                    $sprint['sprint_number'],
                    $sprint['title'],
                    $sprint['status'],
                    $progress,
                );
            }
        }

        $lines[] = '';
        $lines[] = 'All work in this session is scoped to this project. Use `project_switch` or `/projects clear` to change.';

        $rendered .= "\n\n" . implode("\n", $lines);

        return $rendered;
    }

    /**
     * Inject a # DEFERRED TOOLKITS section when toolkits have been deferred.
     *
     * Tells the LLM that additional toolkits are available via tool_search,
     * following Anthropic's recommendation to describe available tool categories.
     */
    private function injectDeferredToolkitHint(string $rendered): string
    {
        if (empty($this->deferredToolkitInfo)) {
            return $rendered;
        }

        $lines = [
            '# DEFERRED TOOLKITS',
            '',
            'Additional toolkits are available but not loaded in context. Use `tool_search` to discover their tools:',
        ];

        foreach ($this->deferredToolkitInfo as $info) {
            $label = $info['package'] !== '' ? $info['package'] : $info['name'];
            $desc = $info['description'] !== '' ? " — {$info['description']}" : '';
            $lines[] = "- {$label}{$desc}";
        }

        $lines[] = '';
        $lines[] = 'Use `tool_search("keyword")` to find specific tools, or `toolkit_list` for a full inventory.';

        return $rendered . "\n\n" . implode("\n", $lines);
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

        // Visibility-aware standalone tools (by tool name => instance)
        /** @var array<string, ToolInterface> */
        $visibilityManaged = [
            'spawn_agent'    => $this->spawnTool,
            'package_info'   => $this->packageInfoTool,
            'php_execute'    => $this->phpExecuteTool,
            'vision_analyze' => $this->visionTool,
        ];

        if ($this->summarizeTool !== null) {
            $visibilityManaged['summarize_conversation'] = $this->summarizeTool;
        }

        if ($this->extractMemoriesTool !== null) {
            $visibilityManaged['extract_memories'] = $this->extractMemoriesTool;
        }

        if ($this->restartTool !== null) {
            $visibilityManaged['restart_coqui'] = $this->restartTool;
        }

        if ($this->configTool !== null) {
            $visibilityManaged['config'] = $this->configTool;
        }

        $visibilityManaged['toolkit_list'] = $this->toolkitListTool->tool();

        $tools = $alwaysEnabled;

        foreach ($visibilityManaged as $name => $tool) {
            // Role-based filtering for standalone tools
            if (!$this->roleToolkitResolver->isToolAllowed($name)) {
                continue;
            }

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



    /**
     * Resolve a ContextWindow from the model definition in config.
     *
     * Uses a 4-layer resolution chain:
     * 1. User-configured model definition (openclaw.json)
     * 2. Curated model from defaults.json
     * 3. Family-level defaults from defaults.json
     * 4. Conservative hardcoded fallback (128K/4K)
     */
    private function resolveContextWindow(ConfigInterface $config, RoleResolver $roleResolver): ContextWindowInterface
    {
        if ($config instanceof OpenClawConfig) {
            $modelString = $roleResolver->resolve('orchestrator');
            $parts = explode('/', $modelString, 2);
            $provider = $parts[0];
            $modelId = $parts[1] ?? $modelString;

            // Layer 1: User-configured model definition (openclaw.json)
            $modelDef = $config->getModelDefinition($modelId)
                ?? $config->getModelDefinition($modelString);

            // Only use the model definition if it carries a meaningful context window.
            // A value at or below CONTEXT_WINDOW_RESERVED indicates a placeholder default
            // written by an older setup wizard run — fall through to Layer 2/3/4 instead.
            if ($modelDef !== null && $modelDef->contextWindow > CoquiDefaults::CONTEXT_WINDOW_RESERVED) {
                return ContextWindow::fromModel($modelDef);
            }

            // Layer 2: Curated model from defaults.json
            if ($this->defaultsLoader !== null && $provider !== '') {
                $curated = $this->defaultsLoader->curatedModel($provider, $modelId);
                if ($curated !== null) {
                    $def = ModelDefinition::fromOpenClaw($provider, $curated);

                    return ContextWindow::fromModel($def);
                }
            }

            // Layer 3: Family-level defaults
            if ($this->defaultsLoader !== null && $this->familyResolver !== null) {
                $family = $this->familyResolver->resolveFamily($modelId);
                if ($family !== null) {
                    $familyDefaults = $this->defaultsLoader->familyDefaults($family);
                    if ($familyDefaults !== null) {
                        return new ContextWindow(
                            maxTok: $familyDefaults['contextWindow'],
                            reservedTok: $familyDefaults['maxTokens'],
                        );
                    }
                }
            }
        }

        // Layer 4: Conservative hardcoded fallback
        return new ContextWindow(maxTok: CoquiDefaults::CONTEXT_WINDOW_FALLBACK, reservedTok: CoquiDefaults::CONTEXT_WINDOW_RESERVED);
    }

    /**
     * Get the context window tracker for this agent.
     */
    public function getContextWindow(): ?ContextWindowInterface
    {
        return $this->contextWindowInstance;
    }

    public function getPruningStrategy(): ?SummarizePruningStrategy
    {
        return $this->pruningStrategyInstance;
    }

    /**
     * Build a RoleToolkitResolver from the active role's frontmatter.
     */
    private function buildRoleToolkitResolver(?string $activeRole, ?RoleDiscovery $roleDiscovery): RoleToolkitResolver
    {
        if ($roleDiscovery === null) {
            return new RoleToolkitResolver(null);
        }

        $effectiveRole = ($activeRole === null || $activeRole === 'orchestrator') ? 'orchestrator' : $activeRole;

        try {
            $roleProps = $roleDiscovery->getRole($effectiveRole);

            return new RoleToolkitResolver($roleProps->toolkits);
        } catch (\Throwable) {
            return new RoleToolkitResolver(null);
        }
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
            $factory = new ProviderFactory($this->config, $this->httpClient);
            $utilityModel = $this->roleResolver->resolveUtility();

            if ($utilityModel !== '') {
                return $factory->create($utilityModel);
            }
        } catch (\Throwable) {
            // Fall through — utility provider is best-effort
        }

        return null;
    }

    /**
     * Apply the token budget gate to candidate toolkits.
     *
     * Three-phase algorithm respecting explicit user overrides:
     *
     * 1. Explicit Eager  → always loaded with full schema, bypasses budget
     * 2. Explicit Deferred → always wrapped as StubToolkit, bypasses budget
     * 3. Auto candidates  → ranked by usage frequency, promoted until the
     *    promotion budget (percentage of total budget) is exhausted, rest deferred
     *
     * When ALL candidates are Auto and total tokens fit within the full budget,
     * everything loads eagerly (no deferral).
     *
     * @param array<int, array{toolkit: ToolkitInterface, package: string, description: string}> $candidates
     */
    private function applyToolkitBudgetGate(array $candidates): void
    {
        if (empty($candidates)) {
            return;
        }

        // Resolve token budget from config (default: CoquiDefaults::TOOLKIT_TOKEN_BUDGET)
        $budgetCfg = $this->config->get('agents.defaults.toolkitTokenBudget');
        $budget = is_numeric($budgetCfg) ? (int) $budgetCfg : CoquiDefaults::TOOLKIT_TOKEN_BUDGET;

        // Resolve promotion budget percentage (what fraction of budget Auto candidates can fill)
        $promotionPercentCfg = $this->config->get('agents.defaults.toolkitPromotionBudgetPercent');
        $promotionPercent = is_numeric($promotionPercentCfg)
            ? max(0, min(100, (int) $promotionPercentCfg))
            : CoquiDefaults::TOOLKIT_PROMOTION_BUDGET_PERCENT;

        $counter = new HeuristicCounter();

        // Estimate tokens and resolve loading mode for each candidate
        $candidateTokens = [];
        $candidateModes = [];
        $autoCandidateIndices = [];

        foreach ($candidates as $idx => $entry) {
            $toolkit = $entry['toolkit'];
            $basename = self::toolkitBasename($toolkit);
            $tokens = $counter->count($toolkit->guidelines()) + $counter->countTools($toolkit->tools());
            $candidateTokens[$idx] = $tokens;

            $mode = $this->loadingRegistry?->getMode($basename) ?? ToolkitLoadingMode::Auto;
            $candidateModes[$idx] = $mode;

            if ($mode === ToolkitLoadingMode::Auto) {
                $autoCandidateIndices[] = $idx;
            }
        }

        // Phase 1 & 2: Handle explicit overrides (bypass budget entirely)
        foreach ($candidates as $idx => $entry) {
            $mode = $candidateModes[$idx];
            $toolkit = $entry['toolkit'];
            $package = $entry['package'];
            $basename = self::toolkitBasename($toolkit);

            if ($mode === ToolkitLoadingMode::Eager) {
                $this->addToolkit($toolkit, $package);
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Eager;
            } elseif ($mode === ToolkitLoadingMode::Deferred) {
                $this->addToolkit(new StubToolkit($toolkit), $package);
                $this->deferredToolkitInfo[] = [
                    'name' => $basename,
                    'description' => $entry['description'],
                    'package' => $package,
                ];
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Deferred;
            }
        }

        // Phase 3: Auto candidates — budget-gated with frequency ranking
        if (empty($autoCandidateIndices)) {
            return;
        }

        // Collect Auto candidates
        $autoCandidates = [];
        foreach ($autoCandidateIndices as $idx) {
            $autoCandidates[] = $candidates[$idx] + ['_budget_idx' => $idx];
        }

        $totalAutoTokens = 0;
        foreach ($autoCandidateIndices as $idx) {
            $totalAutoTokens += $candidateTokens[$idx];
        }

        // Under budget: load all Auto candidates eagerly
        if ($totalAutoTokens <= $budget) {
            foreach ($autoCandidates as $entry) {
                $basename = self::toolkitBasename($entry['toolkit']);
                $this->addToolkit($entry['toolkit'], $entry['package']);
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Eager;
            }
            return;
        }

        // Over budget: rank by frequency, promote until promotion budget exhausted
        $promotionBudget = (int) ($budget * $promotionPercent / 100);
        $rankedAuto = $this->rankCandidatesByFrequency($autoCandidates);

        $usedBudget = 0;
        foreach ($rankedAuto as $entry) {
            $budgetIdx = $entry['_budget_idx'];
            $tokens = $candidateTokens[$budgetIdx];
            $toolkit = $entry['toolkit'];
            $package = $entry['package'];
            $basename = self::toolkitBasename($toolkit);

            if ($usedBudget + $tokens <= $promotionBudget) {
                $this->addToolkit($toolkit, $package);
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Eager;
                $usedBudget += $tokens;
            } else {
                $this->addToolkit(new StubToolkit($toolkit), $package);
                $this->deferredToolkitInfo[] = [
                    'name' => $basename,
                    'description' => $entry['description'],
                    'package' => $package,
                ];
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Deferred;
            }
        }
    }

    /**
     * Rank candidate toolkits by aggregate usage frequency (descending).
     *
     * Candidates with higher historical usage are promoted to load eagerly.
     * When no usage data is available, candidates retain their original order.
     *
     * @param array<int, array{toolkit: ToolkitInterface, package: string, description: string}> $candidates
     * @return array<int, array{toolkit: ToolkitInterface, package: string, description: string, original_index: int, frequency: int}>
     */
    private function rankCandidatesByFrequency(array $candidates): array
    {
        if ($this->usageTracker === null) {
            // No usage data — preserve original order
            return array_map(
                fn(int $idx, array $entry) => $entry + ['original_index' => $idx, 'frequency' => 0],
                array_keys($candidates),
                $candidates,
            );
        }

        // Build a map of toolkit basename → tool names for frequency aggregation
        $toolkitToolMap = [];
        foreach ($candidates as $idx => $entry) {
            $basename = self::toolkitBasename($entry['toolkit']);
            $toolNames = array_map(
                fn(ToolInterface $tool) => $tool->name(),
                $entry['toolkit']->tools(),
            );
            $toolkitToolMap[$idx] = $toolNames;
        }

        // Aggregate frequency per candidate (by index, not basename, to handle duplicates)
        $frequencyMap = $this->usageTracker->getFrequencyMap();
        $ranked = [];
        foreach ($candidates as $idx => $entry) {
            $freq = 0;
            foreach ($toolkitToolMap[$idx] as $toolName) {
                $freq += $frequencyMap[$toolName] ?? 0;
            }
            $ranked[] = $entry + ['original_index' => $idx, 'frequency' => $freq];
        }

        // Sort by frequency descending (stable sort preserves order for equal frequencies)
        usort($ranked, fn(array $a, array $b) => $b['frequency'] <=> $a['frequency']);

        return $ranked;
    }

    /**
     * Extract a class basename from a toolkit instance, unwrapping decorators.
     */
    private static function toolkitBasename(ToolkitInterface $toolkit): string
    {
        $class = $toolkit::class;

        if ($toolkit instanceof StubToolkit) {
            $class = $toolkit->innerClass();
        } elseif ($toolkit instanceof CredentialGuardToolkit) {
            $class = $toolkit->innerClass();
        }

        $parts = explode('\\', $class);

        return end($parts);
    }

    /**
     * Extract a brief description from a toolkit's guidelines (first non-empty line).
     */
    private function extractToolkitDescription(ToolkitInterface $toolkit): string
    {
        $guidelines = $toolkit->guidelines();
        if ($guidelines === '') {
            return '';
        }

        // Take the first meaningful line (skip headers, blanks)
        foreach (explode("\n", $guidelines) as $line) {
            $line = trim($line);
            if ($line !== '' && !str_starts_with($line, '#') && strlen($line) > 10) {
                return strlen($line) > 100 ? substr($line, 0, 97) . '...' : $line;
            }
        }

        return '';
    }

    /**
     * Get the list of deferred toolkit info for prompt injection and REPL display.
     *
     * @return array<int, array{name: string, description: string, package: string}>
     */
    public function getDeferredToolkitInfo(): array
    {
        return $this->deferredToolkitInfo;
    }

    /**
     * Get the applied loading modes for REPL display.
     *
     * Maps toolkit basename → ToolkitLoadingMode as actually applied at runtime
     * (not just what's configured in the registry). This reflects budget gate decisions.
     *
     * @return array<string, ToolkitLoadingMode>
     */
    public function getAppliedLoadingModes(): array
    {
        return $this->appliedLoadingModes;
    }

    /**
     * Get the ToolUsageTracker instance (if available).
     */
    public function getUsageTracker(): ?ToolUsageTracker
    {
        return $this->usageTracker;
    }

    /**
     * Get the ToolkitLoadingRegistry instance (if available).
     */
    public function getLoadingRegistry(): ?ToolkitLoadingRegistry
    {
        return $this->loadingRegistry;
    }
}
