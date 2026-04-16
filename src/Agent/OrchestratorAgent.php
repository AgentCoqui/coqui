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
use CoquiBot\Coqui\Contract\PromptSection;
use CoquiBot\Coqui\Contract\PromptSectionPriority;
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
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Storage\ToolUsageTracker;
use CoquiBot\Coqui\Support\StringHelper;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;
use CoquiBot\Coqui\Toolkit\LearningToolkit;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;
use CoquiBot\Coqui\Toolkit\ComposerToolkit;
use CoquiBot\Coqui\Toolkit\CoquiSourceToolkit;
use CoquiBot\Coqui\Toolkit\PackagistToolkit;
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
use CoquiBot\Coqui\Tool\CoquiToolkitsTool;
use CoquiBot\Coqui\Tool\CoquiSkillsTool;
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
    private CoquiToolkitsTool $coquiToolkitsTool;
    private CoquiSkillsTool $coquiSkillsTool;
    private ToolRegistry $toolRegistry;
    private ToolSearchTool $toolSearchTool;
    private ?ContextWindowInterface $contextWindowInstance = null;
    private ?SummarizePruningStrategy $pruningStrategyInstance = null;
    private readonly ?ToolExecutorInterface $childToolExecutor;

    /** @var ToolkitInterface[] Toolkits added to parent — mirrors AbstractAgent's private $toolkits */
    private array $ownToolkits = [];

    /** @var array<int, array{name: string, description: string, package: string}> Deferred toolkit info for prompt injection */
    private array $deferredToolkitInfo = [];

    /**
     * Maps toolkit class basenames to their corresponding tool prompt file slugs.
     * When a toolkit is deferred, its prompt slug is excluded from system prompt injection.
     */
    private const array TOOLKIT_PROMPT_SLUG_MAP = [
        'LoopToolkit' => 'loops',
        'ScheduleToolkit' => 'schedules',
        'WebhookToolkit' => 'webhooks',
        'BackgroundTaskToolkit' => 'background-tasks',
    ];

    /** @var list<string> Tool prompt slugs excluded because their toolkit was deferred */
    private array $excludedToolPromptSlugs = [];

    /** @var array<string, ToolkitLoadingMode> Applied loading modes for REPL display (toolkit basename => mode) */
    private array $appliedLoadingModes = [];

    /** @var array<int, array{name: string, package: string, description: string, mode: string, configured_mode: string, reason: string, tokens: int, frequency: int|null, rank: int|null}> Toolkit budget decisions for prompt preview surfaces */
    private array $toolkitLoadingDecisions = [];

    /** @var array{effective_role: string, budget_tokens: int, budget_source: string, promotion_budget_percent: int, promotion_budget_source: string, promotion_budget_tokens: int, auto_candidate_count: int, auto_candidate_tokens: int, used_promotion_budget_tokens: int, within_budget: bool, deferred_count: int} Budget summary captured during toolkit gating */
    private array $toolkitBudgetSnapshot = [
        'effective_role' => 'orchestrator',
        'budget_tokens' => CoquiDefaults::TOOLKIT_TOKEN_BUDGET,
        'budget_source' => 'default',
        'promotion_budget_percent' => CoquiDefaults::TOOLKIT_PROMOTION_BUDGET_PERCENT,
        'promotion_budget_source' => 'default',
        'promotion_budget_tokens' => 0,
        'auto_candidate_count' => 0,
        'auto_candidate_tokens' => 0,
        'used_promotion_budget_tokens' => 0,
        'within_budget' => true,
        'deferred_count' => 0,
    ];

    // Prompt cache — avoids rebuilding from disk (glob + file reads) on every iteration
    private ?string $cachedInstructions = null;
    private ?string $cachedInstructionsRole = null;
    private ?string $cachedMemoryHash = null;
    private ?string $cachedProjectId = null;
    private ?string $cachedProfile = null;
    private ?string $notificationPromptSection = null;

    private readonly RoleToolkitResolver $roleToolkitResolver;

    public function __construct(
        ProviderInterface $provider,
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?string $currentTurnId = null,
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
        private readonly ?ProviderFactory $providerFactory = null,
        private readonly ?ToolUsageTracker $usageTracker = null,
        private readonly ?string $workScopeSessionId = null,
        private readonly ?string $defaultProjectId = null,
        private readonly ?string $defaultSprintId = null,
        private readonly float $budgetExitThreshold = 0.0,
        private readonly int $budgetExitWrapUpIterations = 2,
        private readonly ?string $activeProfile = null,
        private readonly ?string $activeProfilePath = null,
    ) {
        $this->childToolExecutor = $toolExecutor;

        // Initialise the registry before parent::__construct() so that our
        // addToolkit() override can populate it immediately for every toolkit added.
        $this->toolRegistry = new ToolRegistry();

        // Build role toolkit resolver from the active role's frontmatter
        $this->roleToolkitResolver = $this->buildRoleToolkitResolver($this->activeRole, $this->roleDiscovery);

        // Resolve the shared ProviderFactory — prefer injected, fall back to config+httpClient
        $sharedFactory = $this->providerFactory ?? new ProviderFactory($config, $this->httpClient);

        // Wrap primary provider with FallbackProvider when fallback models are configured
        $effectiveProvider = $provider;
        if ($config instanceof OpenClawConfig) {
            $fallbacks = $config->getFallbacks();
            if (!empty($fallbacks)) {
                $fallbackProviders = array_map(
                    fn(string $model) => $sharedFactory->create($model),
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
                $utilityModel = $this->roleResolver->resolveUtility();
                if ($utilityModel !== '') {
                    $utilityProvider = $sharedFactory->create($utilityModel);

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

        parent::__construct($effectiveProvider, $maxIterations, $executionPolicy, $cancellationToken, $pendingInputProvider, $contextWindow, $pruningStrategy, $safetyMarginPercent, $this->budgetExitThreshold, $this->budgetExitWrapUpIterations, $toolExecutor, $tickCallback);

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
                $roleProps = $this->roleDiscovery->getRole($effectiveRole, $this->activeProfilePath);
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
        $shellSandboxWrites = ShellConfigResolver::resolveSandboxWrites($this->config);
        $shellScrubEnv = ShellConfigResolver::resolveScrubEnvironment($this->config);
        if ($effectiveAccessLevel === 'full') {
            $this->addToolkit(new ShellToolkit(
                workDir: $this->workspacePath,
                allowedCommands: $shellAllowed,
                deniedCommands: $shellDenied,
                timeout: 60,
                unsafe: $this->unsafeMode,
                cancellationToken: $cancellationToken instanceof \CoquiBot\Coqui\Api\ProcessCancellationToken ? $cancellationToken : null,
                rootPath: $this->workspacePath,
                allowedPaths: $this->mountManager?->allowedPaths() ?? [],
                sandboxWrites: $shellSandboxWrites,
                scrubEnvironment: $shellScrubEnv,
            ));
        } elseif ($effectiveAccessLevel === 'readonly-shell') {
            $this->addToolkit(new ShellToolkit(
                workDir: $this->workspacePath,
                allowedCommands: ShellConfigResolver::READ_ONLY_SHELL_COMMANDS,
                timeout: 60,
                cancellationToken: $cancellationToken instanceof \CoquiBot\Coqui\Api\ProcessCancellationToken ? $cancellationToken : null,
                rootPath: $this->workspacePath,
                allowedPaths: $this->mountManager?->allowedPathsReadOnly() ?? [],
                sandboxWrites: $shellSandboxWrites,
                scrubEnvironment: $shellScrubEnv,
            ));
        }

        // Web toolkit — HTTP requests with SSRF protection
        if ($effectiveAccessLevel === 'full') {
            $this->addToolkit(new WebToolkit(
                storage: $this->storage,
                parentSessionId: $this->sessionId,
                workspacePath: $this->workspacePath,
            ));
        }

        // Memory toolkit — SQLite-backed with optional vector search
        if ($this->memoryStore !== null) {
            $this->addToolkit(new MemoryToolkit($this->memoryStore, $this->workspacePath));
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
                providerFactory: $sharedFactory,
            );

            $this->addToolkit(new ArtifactToolkit(
                $artifactStore,
                $toolkitSessionId,
                planTodoGenerator: $planTodoGenerator,
                todoStore: $todoStore,
                defaultProjectId: $this->defaultProjectId,
                defaultSprintId: $this->defaultSprintId,
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

        // Composer & Packagist toolkits — workspace package management
        if ($effectiveAccessLevel === 'full') {
            $this->addToolkit(new ComposerToolkit(
                workspacePath: $this->workspacePath,
                listener: $discovery,
            ));
            $this->addToolkit(new PackagistToolkit());
        }

        // Schedule toolkit — cron-style task scheduling (top-level agents only)
        if ($this->storage !== null && $effectiveAccessLevel === 'full' && $this->workScopeSessionId === null) {
            if ($this->roleToolkitResolver->isToolkitAllowed(\CoquiBot\Coqui\Toolkit\ScheduleToolkit::class)) {
                $scheduleStore = new \CoquiBot\Coqui\Storage\ScheduleStore($this->storage->getPdo());
                $this->addToolkit(new \CoquiBot\Coqui\Toolkit\ScheduleToolkit($scheduleStore));
            }
        }

        // Loop toolkit — automated multi-role loop workflows (top-level agents only)
        if ($this->storage !== null && $effectiveAccessLevel === 'full' && $this->workScopeSessionId === null) {
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
                        sessionStorage: $this->storage,
                        todoStore: isset($todoStore) ? $todoStore : null,
                        artifactStore: $artifactStore,
                    )
                    : null;
                $this->addToolkit(new \CoquiBot\Coqui\Toolkit\LoopToolkit($loopStore, $loopDiscovery, $loopExecutor, $this->sessionId, null, $this->workspacePath));
            }
        }

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

        // Webhook toolkit — only for top-level agents, never in loop stages.
        // Loop stages must not spawn background tasks, create schedules, manage webhooks, or
        // start nested loops — this prevents infinite recursion and uncontrolled spawning.
        if ($this->storage !== null && $effectiveAccessLevel === 'full' && $this->workScopeSessionId === null) {
            if ($this->roleToolkitResolver->isToolkitAllowed(\CoquiBot\Coqui\Toolkit\WebhookToolkit::class)) {
                $webhookStore = new \CoquiBot\Coqui\Storage\WebhookStore($this->storage->getPdo());
                $candidateToolkits[] = [
                    'toolkit' => new \CoquiBot\Coqui\Toolkit\WebhookToolkit($webhookStore),
                    'package' => '',
                    'description' => 'webhook subscription management',
                ];
            }
        }

        // Session evaluation toolkit
        if ($this->roleToolkitResolver->isToolkitAllowed(SessionEvaluationToolkit::class) && $this->storage !== null) {
            $evaluationStore = new \CoquiBot\Coqui\Storage\EvaluationStore($this->storage->getPdo());
            $skillLifecycleStore = new SkillLifecycleStore($this->storage->getPdo());
            $lookbackHours = (int) ($this->config->get('agents.defaults.evaluation.lookbackHours') ?? 24);
            $inactivityHours = (int) ($this->config->get('agents.defaults.evaluation.inactivityHours') ?? 3);
            $qualityAutomation = new QualityAutomationCoordinator(
                config: $this->config,
                storage: $this->storage,
                evaluationStore: $evaluationStore,
            );
            $candidateToolkits[] = [
                'toolkit' => new SessionEvaluationToolkit(
                    evaluationStore: $evaluationStore,
                    storage: $this->storage,
                    defaultLookbackHours: $lookbackHours,
                    defaultInactivityHours: $inactivityHours,
                    qualityAutomation: $qualityAutomation,
                    artifactStore: $artifactStore ?? null,
                    skillLifecycleStore: $skillLifecycleStore,
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
                    skillLifecycleStore: new SkillLifecycleStore($this->storage->getPdo()),
                ),
                'package' => '',
                'description' => 'autonomous learning from evaluations',
            ];
        }

        // --- Budget gate: decide which candidates load eagerly vs deferred ---
        $this->applyToolkitBudgetGate($candidateToolkits);

        // Compute excluded tool prompt slugs based on deferred toolkits
        $deferredNames = array_column($this->deferredToolkitInfo, 'name');
        foreach (self::TOOLKIT_PROMPT_SLUG_MAP as $basename => $slug) {
            if (in_array($basename, $deferredNames, true)) {
                $this->excludedToolPromptSlugs[] = $slug;
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
            projectStore: $this->projectStore,
            discovery: $discovery,
            memoryStore: $this->memoryStore,
            skillDiscovery: $this->skillDiscovery,
            sanitizer: $this->sanitizer,
            visibilityRegistry: $this->visibilityRegistry,
            shellDeniedCommands: $shellDenied,
            unsafeMode: $this->unsafeMode,
            toolExecutor: $this->childToolExecutor,
            providerFactory: $sharedFactory,
            profileIdentityPreamble: $this->buildProfileIdentityPreamble(),
            activeProfile: $this->activeProfile,
            activeProfilePath: $this->activeProfilePath,
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
            providerFactory: $sharedFactory,
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
                providerFactory: $sharedFactory,
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
                providerFactory: $sharedFactory,
            );
        }

        // Coqui toolkits tool — always-loaded system tool for browsing/managing installed toolkits
        $this->coquiToolkitsTool = new CoquiToolkitsTool(
            workspacePath: $this->workspacePath,
            installer: $this->spaceToolkit?->toolkitInstaller(),
        );

        // Coqui skills tool — always-loaded system tool for browsing/managing local skills
        $this->coquiSkillsTool = new CoquiSkillsTool(
            discovery: $this->skillDiscovery ?? new SkillDiscovery($this->workspacePath),
            installer: $this->spaceToolkit?->skillInstaller(),
            lifecycleStore: $this->storage !== null ? new SkillLifecycleStore($this->storage->getPdo()) : null,
            sessionId: $this->sessionId,
            turnId: $this->currentTurnId,
            agentRole: $this->activeRole ?? 'orchestrator',
        );

        // Register standalone tools in the registry now that they're all created.
        // Toolkit tools are already registered via addToolkit() override above.
        foreach ([$this->spawnTool, $this->credentialTool, $this->packageInfoTool, $this->phpExecuteTool] as $tool) {
            $this->toolRegistry->register($tool);
        }

        $this->toolRegistry->register($this->coquiToolkitsTool->tool());
        $this->toolRegistry->register($this->coquiSkillsTool->tool());

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

    public function setNotificationPromptSection(?string $notificationPromptSection): void
    {
        $trimmed = $notificationPromptSection !== null ? trim($notificationPromptSection) : null;
        $this->notificationPromptSection = $trimmed !== '' ? $trimmed : null;
    }

    public function instructions(): string
    {
        // Cache key: active role + memory summary hash + active project ID + profile.
        // The prompt is rebuilt from disk (glob + file reads) each time, which
        // is expensive in a loop-heavy agent. Cache it and invalidate only when
        // the role changes, memory content is updated, active project changes,
        // or active profile changes.
        $currentRole = $this->activeRole ?? 'orchestrator';
        $currentMemoryHash = $this->computeMemoryHash();
        $currentProjectId = $this->resolveActiveProjectId();
        $currentProfile = $this->activeProfile;

        if (
            $this->cachedInstructions !== null
            && $this->cachedInstructionsRole === $currentRole
            && $this->cachedMemoryHash === $currentMemoryHash
            && $this->cachedProjectId === $currentProjectId
            && $this->cachedProfile === $currentProfile
        ) {
            return $this->injectNotificationContext($this->cachedInstructions);
        }

        // The orchestrator prompt stack owns soul/base/tool/security/done.
        // Specialized roles replace that stack with role markdown instead of
        // layering on top of soul, which keeps role switching predictable.
        $rendered = $this->resolvePrimaryInstructionContent();

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
        $this->cachedProfile = $currentProfile;

        return $this->injectNotificationContext($rendered);
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
            availableRoles: $roles,
            availableSkills: $skillsSummary,
            storageMap: $storageMap,
            timeSinceLastMessage: $timeSinceLastMessage,
            excludeToolPromptSlugs: $this->excludedToolPromptSlugs,
            profilePath: $this->activeProfilePath,
        );

        return $prompt->render();
    }

    private function resolvePrimaryInstructionContent(): string
    {
        $roleInstructions = $this->resolveActiveRoleInstructions();

        if ($roleInstructions !== null) {
            // When both profile and role are active, prepend the profile's
            // identity preamble so the agent retains its personality even
            // when operating under a specialized role.
            $preamble = $this->buildProfileIdentityPreamble();
            if ($preamble !== null) {
                return $preamble . "\n\n" . $roleInstructions;
            }

            return $roleInstructions;
        }

        return $this->renderOrchestratorPrompt();
    }

    /**
     * Build a short identity preamble from the active profile's soul.md.
     *
     * When a profile is active and a specialized role replaces the orchestrator
     * prompt stack, this preamble keeps the core personality present.
     */
    private function buildProfileIdentityPreamble(): ?string
    {
        if ($this->activeProfilePath === null) {
            return null;
        }

        $soulPath = rtrim($this->activeProfilePath, '/') . '/soul.md';
        if (!is_file($soulPath)) {
            return null;
        }

        $content = (new \CoquiBot\Coqui\Config\ProfileParser())->readFile($soulPath)['body'];
        if (trim($content) === '') {
            return null;
        }

        return "<!-- Profile Identity -->\n" . trim($content);
    }

    private function resolveActiveRoleInstructions(): ?string
    {
        if ($this->activeRole === null || $this->activeRole === 'orchestrator' || $this->roleDiscovery === null) {
            return null;
        }

        try {
            return $this->roleDiscovery->readInstructions($this->activeRole, $this->activeProfilePath);
        } catch (\Throwable) {
            return null;
        }
    }

    private function injectNotificationContext(string $rendered): string
    {
        if ($this->notificationPromptSection === null || $this->notificationPromptSection === '') {
            return $rendered;
        }

        return rtrim($rendered) . "\n\n" . $this->notificationPromptSection;
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
                $rendered = "# BACKGROUND KNOWLEDGE (Core Memories)\n\n"
                    . "The following memories provide background knowledge about the user and their projects. "
                    . "They are NOT active tasks or instructions — do NOT act on them unless the user explicitly references them in their current message.\n\n"
                    . $memorySummary . "\n\n" . $rendered;
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
        $lines[] = 'Use `tool_search("keyword")` to find specific tools, or `coqui_toolkits` for a full inventory.';

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

        $visibilityManaged['coqui_toolkits'] = $this->coquiToolkitsTool->tool();
        $visibilityManaged['coqui_skills'] = $this->coquiSkillsTool->tool();

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
     * Token breakdown for prompt sections with pinning and rationale metadata.
     *
     * @return array<int, array{id: string, title: string, group: string, priority: string, pinned: bool, deferrable: bool, included: bool, decision: string, rationale: string, source: string|null, tokens: int}>
     */
    public function getPromptSectionBreakdown(TokenCounterInterface $counter): array
    {
        $breakdown = [];

        foreach ($this->buildPromptSections() as $section) {
            $breakdown[] = $section->toTelemetryArray($counter->count($section->content));
        }

        usort(
            $breakdown,
            static fn(array $a, array $b) => $b['tokens'] <=> $a['tokens'],
        );

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
     * @return list<PromptSection>
     */
    private function buildPromptSections(): array
    {
        $sections = [];

        foreach ($this->buildMemoryPromptSections() as $section) {
            $sections[] = $section;
        }

        foreach ($this->buildInstructionPromptSections() as $section) {
            $sections[] = $section;
        }

        if (($deferred = $this->buildDeferredToolkitPromptSection()) !== null) {
            $sections[] = $deferred;
        }

        if (($project = $this->buildActiveProjectPromptSection()) !== null) {
            $sections[] = $project;
        }

        if (($notifications = $this->buildNotificationPromptSection()) !== null) {
            $sections[] = $notifications;
        }

        if (($iteration = $this->buildIterationBudgetPromptSection()) !== null) {
            $sections[] = $iteration;
        }

        foreach ($this->buildToolkitGuidelinePromptSections() as $section) {
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * @return list<PromptSection>
     */
    private function buildInstructionPromptSections(): array
    {
        $roleInstructions = $this->resolveActiveRoleInstructions();
        if ($roleInstructions !== null) {
            return [new PromptSection(
                id: 'role.' . $this->activeRole,
                title: ucfirst(str_replace('-', ' ', $this->activeRole)) . ' Instructions',
                content: $roleInstructions,
                priority: PromptSectionPriority::Critical,
                rationale: 'Specialized roles replace the orchestrator prompt stack for that turn, so their instructions must stay pinned.',
                decision: 'pinned_critical',
                group: 'identity',
            )];
        }

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
            availableRoles: $roles,
            availableSkills: $skillsSummary,
            storageMap: $storageMap,
            timeSinceLastMessage: $timeSinceLastMessage,
            excludeToolPromptSlugs: $this->excludedToolPromptSlugs,
            profilePath: $this->activeProfilePath,
        );

        $sections = [];
        foreach ($prompt->renderSections() as $entry) {
            $sections[] = $this->classifyInstructionPromptSection(
                id: $entry['id'],
                title: $entry['title'],
                content: $entry['content'],
                source: $entry['source'],
            );
        }

        return $sections;
    }

    private function classifyInstructionPromptSection(string $id, string $title, string $content, string $source): PromptSection
    {
        return match ($id) {
            'soul' => new PromptSection(
                id: 'prompt.soul',
                title: $title,
                content: $content,
                priority: PromptSectionPriority::Critical,
                rationale: 'The soul defines the bot\'s core identity, values, and personality — it must stay pinned at the highest priority.',
                decision: 'pinned_critical',
                group: 'identity',
                source: $source,
            ),
            'base' => new PromptSection(
                id: 'prompt.base',
                title: $title,
                content: $content,
                priority: PromptSectionPriority::Critical,
                rationale: 'The base prompt defines the orchestrator identity and default operating rules, so it stays pinned.',
                decision: 'pinned_critical',
                group: 'identity',
                source: $source,
            ),
            'security' => new PromptSection(
                id: 'prompt.security',
                title: $title,
                content: $content,
                priority: PromptSectionPriority::Critical,
                rationale: 'Security guardrails must stay pinned so safety decisions do not depend on recency.',
                decision: 'pinned_critical',
                group: 'identity',
                source: $source,
            ),
            'done' => new PromptSection(
                id: 'prompt.done',
                title: $title,
                content: $content,
                priority: PromptSectionPriority::Critical,
                rationale: 'Completion rules stay pinned so the agent knows when and how to finish tool-driven loops.',
                decision: 'pinned_critical',
                group: 'identity',
                source: $source,
            ),
            default => new PromptSection(
                id: 'prompt.' . $id,
                title: $title,
                content: $content,
                priority: PromptSectionPriority::Volatile,
                rationale: sprintf('%s provides tool-usage guidance that improves quality but is more deferrable than identity or workflow state.', $title),
                decision: 'included_volatile',
                group: 'tool_prompts',
                source: $source,
            ),
        };
    }

    /**
     * @return list<PromptSection>
     */
    private function buildMemoryPromptSections(): array
    {
        if ($this->memorySummarizer === null) {
            return [];
        }

        $utilityProvider = $this->resolveUtilityProvider();
        $memorySummary = $this->memorySummarizer->getSummary($utilityProvider);

        if ($memorySummary === '') {
            return [];
        }

        return [new PromptSection(
            id: 'context.core-memories',
            title: 'Core Memories',
            content: "# BACKGROUND KNOWLEDGE (Core Memories)\n\n"
                . "The following memories provide background knowledge about the user and their projects. "
                . "They are NOT active tasks or instructions — do NOT act on them unless the user explicitly references them in their current message.\n\n"
                . $memorySummary,
            priority: PromptSectionPriority::Workflow,
            rationale: 'Core memories preserve durable user and project knowledge, so they stay pinned as workflow context.',
            decision: 'pinned_workflow',
            group: 'memory',
        )];
    }

    private function buildActiveProjectPromptSection(): ?PromptSection
    {
        $projectId = $this->resolveActiveProjectId();
        if ($projectId === null || $this->projectStore === null) {
            return null;
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
            return null;
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

        return new PromptSection(
            id: 'context.active-project',
            title: 'Active Project',
            content: implode("\n", $lines),
            priority: PromptSectionPriority::Workflow,
            rationale: 'Active project state stays pinned so tasks, artifacts, and sprint work remain scoped correctly.',
            decision: 'pinned_workflow',
            group: 'project',
        );
    }

    private function buildDeferredToolkitPromptSection(): ?PromptSection
    {
        if ($this->deferredToolkitInfo === []) {
            return null;
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
        $lines[] = 'Use `tool_search("keyword")` to find specific tools, or `coqui_toolkits` for a full inventory.';

        return new PromptSection(
            id: 'context.deferred-toolkits',
            title: 'Deferred Toolkits',
            content: implode("\n", $lines),
            priority: PromptSectionPriority::Volatile,
            rationale: 'Deferred toolkit hints improve discoverability, but they are more deferrable than pinned identity and workflow state.',
            decision: 'included_volatile',
            group: 'tool_discovery',
        );
    }

    private function buildNotificationPromptSection(): ?PromptSection
    {
        if ($this->notificationPromptSection === null || $this->notificationPromptSection === '') {
            return null;
        }

        return new PromptSection(
            id: 'context.pending-notifications',
            title: 'Pending Notifications',
            content: $this->notificationPromptSection,
            priority: PromptSectionPriority::Workflow,
            rationale: 'Pending notifications are turn-scoped workflow context that can affect how the agent responds to completed background work.',
            decision: 'pinned_workflow',
            group: 'notifications',
        );
    }

    private function buildIterationBudgetPromptSection(): ?PromptSection
    {
        if ($this->maxIterations() === 0) {
            return null;
        }

        $content = sprintf(
            "# ITERATION BUDGET\n\nYou have **%d iterations** to complete this task. Each iteration is one round-trip with the provider — you send a message, receive a response, and optionally execute tool calls. When all iterations are consumed, execution stops.\n\n**Manage your budget wisely:**\n- Batch multiple independent tool calls in a single iteration when possible.\n- Prioritize the most impactful actions early.\n- If you are running low on iterations, summarize your progress and prepare questions or next steps for the user so work can continue in the next turn.",
            $this->maxIterations(),
        );

        return new PromptSection(
            id: 'system.iteration-budget',
            title: 'Iteration Budget',
            content: $content,
            priority: PromptSectionPriority::Critical,
            rationale: 'Iteration budget remains pinned so the agent can actively manage finite execution headroom.',
            decision: 'pinned_critical',
            group: 'iteration_budget',
        );
    }

    /**
     * @return list<PromptSection>
     */
    private function buildToolkitGuidelinePromptSections(): array
    {
        $sections = [];

        foreach ($this->ownToolkits as $toolkit) {
            $guidelines = $toolkit->guidelines();
            if ($guidelines === '') {
                continue;
            }

            if ($toolkit instanceof StubToolkit) {
                $class = $toolkit->innerClass();
                $displayName = basename(str_replace('\\', '/', $class)) . ' (stub)';
            } elseif ($toolkit instanceof CredentialGuardToolkit) {
                $class = $toolkit->innerClass();
                $displayName = basename(str_replace('\\', '/', $class));
            } else {
                $class = $toolkit::class;
                $displayName = basename(str_replace('\\', '/', $class));
            }

            $sections[] = new PromptSection(
                id: 'toolkit.' . StringHelper::slug($displayName),
                title: $displayName,
                content: $guidelines,
                priority: PromptSectionPriority::Volatile,
                rationale: sprintf('%s guidelines improve tool-use quality, but they are more deferrable than pinned identity or workflow context.', $displayName),
                decision: 'included_toolkit_guidance',
                group: 'toolkit_guidelines',
                source: $class,
            );
        }

        return $sections;
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
            $roleProps = $roleDiscovery->getRole($effectiveRole, $this->activeProfilePath);

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
            $factory = $this->providerFactory ?? new ProviderFactory($this->config, $this->httpClient);
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
        $effectiveRole = $this->effectiveRoleName();
        $budgetConfig = $this->resolveRoleScopedIntConfig(
            roleKey: 'toolkitTokenBudget',
            globalKey: 'agents.defaults.toolkitTokenBudget',
            default: CoquiDefaults::TOOLKIT_TOKEN_BUDGET,
        );
        $promotionPercentConfig = $this->resolveRoleScopedIntConfig(
            roleKey: 'toolkitPromotionBudgetPercent',
            globalKey: 'agents.defaults.toolkitPromotionBudgetPercent',
            default: CoquiDefaults::TOOLKIT_PROMOTION_BUDGET_PERCENT,
            min: 0,
            max: 100,
        );

        $budget = $budgetConfig['value'];
        $promotionPercent = $promotionPercentConfig['value'];
        $promotionBudget = (int) ($budget * $promotionPercent / 100);

        $this->toolkitLoadingDecisions = [];
        $this->toolkitBudgetSnapshot = [
            'effective_role' => $effectiveRole,
            'budget_tokens' => $budget,
            'budget_source' => $budgetConfig['source'],
            'promotion_budget_percent' => $promotionPercent,
            'promotion_budget_source' => $promotionPercentConfig['source'],
            'promotion_budget_tokens' => $promotionBudget,
            'auto_candidate_count' => 0,
            'auto_candidate_tokens' => 0,
            'used_promotion_budget_tokens' => 0,
            'within_budget' => true,
            'deferred_count' => 0,
        ];

        if (empty($candidates)) {
            return;
        }

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
                $this->recordToolkitLoadingDecision(
                    name: $basename,
                    package: $package,
                    description: $entry['description'],
                    mode: ToolkitLoadingMode::Eager,
                    configuredMode: $mode,
                    reason: 'explicit_eager',
                    tokens: $candidateTokens[$idx],
                );
            } elseif ($mode === ToolkitLoadingMode::Deferred) {
                $this->addToolkit(new StubToolkit($toolkit), $package);
                $this->deferredToolkitInfo[] = [
                    'name' => $basename,
                    'description' => $entry['description'],
                    'package' => $package,
                ];
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Deferred;
                $this->recordToolkitLoadingDecision(
                    name: $basename,
                    package: $package,
                    description: $entry['description'],
                    mode: ToolkitLoadingMode::Deferred,
                    configuredMode: $mode,
                    reason: 'explicit_deferred',
                    tokens: $candidateTokens[$idx],
                );
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

        $this->toolkitBudgetSnapshot['auto_candidate_count'] = count($autoCandidateIndices);
        $this->toolkitBudgetSnapshot['auto_candidate_tokens'] = $totalAutoTokens;
        $this->toolkitBudgetSnapshot['within_budget'] = $totalAutoTokens <= $budget;

        // Under budget: load all Auto candidates eagerly
        if ($totalAutoTokens <= $budget) {
            foreach ($autoCandidates as $entry) {
                $basename = self::toolkitBasename($entry['toolkit']);
                $this->addToolkit($entry['toolkit'], $entry['package']);
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Eager;
                $this->recordToolkitLoadingDecision(
                    name: $basename,
                    package: $entry['package'],
                    description: $entry['description'],
                    mode: ToolkitLoadingMode::Eager,
                    configuredMode: ToolkitLoadingMode::Auto,
                    reason: 'auto_within_budget',
                    tokens: $candidateTokens[$entry['_budget_idx']],
                );
            }

            $this->toolkitBudgetSnapshot['deferred_count'] = count($this->deferredToolkitInfo);
            return;
        }

        // Over budget: rank by frequency, promote until promotion budget exhausted
        $rankedAuto = $this->rankCandidatesByFrequency($autoCandidates);

        $usedBudget = 0;
        foreach ($rankedAuto as $rank => $entry) {
            $budgetIdx = $entry['_budget_idx'];
            $tokens = $candidateTokens[$budgetIdx];
            $toolkit = $entry['toolkit'];
            $package = $entry['package'];
            $basename = self::toolkitBasename($toolkit);

            if ($usedBudget + $tokens <= $promotionBudget) {
                $this->addToolkit($toolkit, $package);
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Eager;
                $usedBudget += $tokens;
                $this->recordToolkitLoadingDecision(
                    name: $basename,
                    package: $package,
                    description: $entry['description'],
                    mode: ToolkitLoadingMode::Eager,
                    configuredMode: ToolkitLoadingMode::Auto,
                    reason: 'auto_promoted_by_frequency',
                    tokens: $tokens,
                    frequency: $entry['frequency'],
                    rank: $rank + 1,
                );
            } else {
                $this->addToolkit(new StubToolkit($toolkit), $package);
                $this->deferredToolkitInfo[] = [
                    'name' => $basename,
                    'description' => $entry['description'],
                    'package' => $package,
                ];
                $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Deferred;
                $this->recordToolkitLoadingDecision(
                    name: $basename,
                    package: $package,
                    description: $entry['description'],
                    mode: ToolkitLoadingMode::Deferred,
                    configuredMode: ToolkitLoadingMode::Auto,
                    reason: 'auto_deferred_budget',
                    tokens: $tokens,
                    frequency: $entry['frequency'],
                    rank: $rank + 1,
                );
            }
        }

        $this->toolkitBudgetSnapshot['used_promotion_budget_tokens'] = $usedBudget;
        $this->toolkitBudgetSnapshot['deferred_count'] = count($this->deferredToolkitInfo);
    }

    /**
     * Rank candidate toolkits by aggregate usage frequency (descending).
     *
     * Candidates with higher historical usage are promoted to load eagerly.
     * When no usage data is available, candidates retain their original order.
     *
     * @param array<int, array{toolkit: ToolkitInterface, package: string, description: string, _budget_idx: int}> $candidates
     * @return array<int, array{toolkit: ToolkitInterface, package: string, description: string, _budget_idx: int, original_index: int, frequency: int}>
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
     * @return array<int, array{name: string, package: string, description: string, mode: string, configured_mode: string, reason: string, tokens: int, frequency: int|null, rank: int|null}>
     */
    public function getToolkitLoadingDecisions(): array
    {
        return $this->toolkitLoadingDecisions;
    }

    /**
     * @return array{effective_role: string, budget_tokens: int, budget_source: string, promotion_budget_percent: int, promotion_budget_source: string, promotion_budget_tokens: int, auto_candidate_count: int, auto_candidate_tokens: int, used_promotion_budget_tokens: int, within_budget: bool, deferred_count: int}
     */
    public function getToolkitBudgetSnapshot(): array
    {
        return $this->toolkitBudgetSnapshot;
    }

    /**
     * @return array{value: int, source: string}
     */
    private function resolveRoleScopedIntConfig(string $roleKey, string $globalKey, int $default, ?int $min = null, ?int $max = null): array
    {
        $effectiveRole = $this->effectiveRoleName();
        $rolePath = sprintf('agents.defaults.roles.%s.%s', $effectiveRole, $roleKey);
        $roleValue = $this->config->get($rolePath);

        if (is_numeric($roleValue)) {
            return [
                'value' => $this->clampInt((int) $roleValue, $min, $max),
                'source' => $rolePath,
            ];
        }

        $globalValue = $this->config->get($globalKey);
        if (is_numeric($globalValue)) {
            return [
                'value' => $this->clampInt((int) $globalValue, $min, $max),
                'source' => $globalKey,
            ];
        }

        return [
            'value' => $this->clampInt($default, $min, $max),
            'source' => 'default',
        ];
    }

    private function effectiveRoleName(): string
    {
        return ($this->activeRole === null || $this->activeRole === '') ? 'orchestrator' : $this->activeRole;
    }

    private function clampInt(int $value, ?int $min = null, ?int $max = null): int
    {
        if ($min !== null && $value < $min) {
            $value = $min;
        }

        if ($max !== null && $value > $max) {
            $value = $max;
        }

        return $value;
    }

    private function recordToolkitLoadingDecision(
        string $name,
        string $package,
        string $description,
        ToolkitLoadingMode $mode,
        ToolkitLoadingMode $configuredMode,
        string $reason,
        int $tokens,
        ?int $frequency = null,
        ?int $rank = null,
    ): void {
        $this->toolkitLoadingDecisions[] = [
            'name' => $name,
            'package' => $package,
            'description' => $description,
            'mode' => $mode->value,
            'configured_mode' => $configuredMode->value,
            'reason' => $reason,
            'tokens' => $tokens,
            'frequency' => $frequency,
            'rank' => $rank,
        ];
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
