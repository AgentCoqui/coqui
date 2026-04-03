<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Context\TokenCounterFactory;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\DefaultsLoader;
use CoquiBot\Coqui\Config\ModelFamilyResolver;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\BackgroundTaskSummary;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Contract\DeferredWorkQueue;
use CoquiBot\Coqui\CoquiSpace\SpaceToolkit;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\MemoryExtractor;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\EditHistory;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;
use SplObserver;
use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Renderer\ContextUsageBar;
use CoquiBot\Coqui\Storage\ToolUsageTracker;

/**
 * Handles agent creation, execution, and turn message persistence.
 *
 * Extracted from RunCommand to isolate agent orchestration from
 * the REPL loop and session management concerns.
 */
final class AgentRunner
{
    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly SessionStorage $storage,
        private readonly ?SplObserver $observer,
        private readonly ToolkitDiscovery $discovery,
        private readonly CatastrophicBlacklist $blacklist,
        private readonly CredentialResolverInterface $credentialResolver,
        private readonly ?SkillDiscovery $skillDiscovery = null,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        private readonly bool $unsafeMode = false,
        private readonly ?ProviderFactory $providerFactory = null,
        private readonly bool $backgroundTasksEnabled = false,
        private readonly ?MemoryStore $memoryStore = null,
        private readonly ?MemorySummarizer $memorySummarizer = null,
        private readonly ?MountManager $mountManager = null,
        private readonly ?ConfigManager $configManager = null,
        private readonly ?ConfigGuard $configGuard = null,
        private readonly ?ToolkitVisibilityRegistry $visibilityRegistry = null,
        private readonly ?SpaceToolkit $spaceToolkit = null,
        private readonly ?TodoStore $todoStore = null,
        private readonly ?ArtifactStore $artifactStore = null,
        private readonly ?ProjectStore $projectStore = null,
        private readonly ?DefaultsLoader $defaultsLoader = null,
        private readonly ?TickCallbackInterface $tickCallback = null,
        private readonly ?ToolExecutorInterface $toolExecutor = null,
        private readonly ?HttpClientInterface $httpClient = null,
        private readonly ?ToolkitLoadingRegistry $loadingRegistry = null,
        private readonly ?ToolUsageTracker $usageTracker = null,
    ) {}

    /**
     * Run a single agent turn with a per-turn observer override.
     *
     * Used by the API server where each request gets its own SseObserver.
     * Falls through to run() after temporarily overriding the observer.
     *
     * @param string[]|null $filePaths  Optional file paths to attach as context.
     */
    public function runWithObserver(
        string $prompt,
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        SplObserver $observer,
        ?array $filePaths = null,
        ?string $role = null,
    ): AgentTurnResult {
        return $this->doRun($prompt, $sessionId, $executionPolicy, $observer, filePaths: $filePaths, role: $role);
    }

    /**
     * Run an agent turn for a background task with cancellation and input injection.
     *
     * Used by TaskRunCommand where the agent runs in a separate process
     * and needs cooperative cancellation and mid-run input injection.
     */
    public function runForTask(
        string $prompt,
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        SplObserver $observer,
        CancellationTokenInterface $cancellationToken,
        PendingInputProviderInterface $pendingInputProvider,
        ?string $role = null,
        ?int $maxIterations = null,
        ?string $workScopeSessionId = null,
    ): AgentTurnResult {
        return $this->doRun(
            $prompt,
            $sessionId,
            $executionPolicy,
            $observer,
            $cancellationToken,
            $pendingInputProvider,
            enableBackgroundTasks: false,
            role: $role,
            maxIterations: $maxIterations,
            workScopeSessionId: $workScopeSessionId,
        );
    }

    /**
     * Run a single agent turn: create agent, execute, persist messages.
     *
     * Returns a result DTO — rendering is the caller's responsibility.
     */
    public function run(
        string $prompt,
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        ?CancellationTokenInterface $cancellationToken = null,
        ?string $role = null,
    ): AgentTurnResult {
        return $this->doRun($prompt, $sessionId, $executionPolicy, $this->observer, $cancellationToken, role: $role);
    }

    /**
     * Internal implementation shared by run(), runWithObserver(), and runForTask().
     *
     * @param string[]|null $filePaths
     */
    private function doRun(
        string $prompt,
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        ?SplObserver $observer = null,
        ?CancellationTokenInterface $cancellationToken = null,
        ?PendingInputProviderInterface $pendingInputProvider = null,
        bool $enableBackgroundTasks = true,
        ?string $role = null,
        ?int $maxIterations = null,
        ?array $filePaths = null,
        ?string $workScopeSessionId = null,
    ): AgentTurnResult {
        // Load prior conversation history from database
        $history = $this->storage->loadConversation($sessionId);

        // Resolve the model string for turn tracking (use task role if provided)
        $effectiveRole = $role ?? 'orchestrator';
        $modelString = $this->roleResolver->resolve($effectiveRole);

        // Create turn record before execution
        $turnId = $this->storage->createTurn($sessionId, $prompt, $modelString);
        $startTime = hrtime(true);
        $turnStartedAt = (new \DateTimeImmutable())->format('c');

        // Save user message to database before running agent
        $this->storage->addMessage($sessionId, 'user', $prompt, turnId: $turnId);

        // Build sanitizer for PHP execution
        $sanitizer = new ScriptSanitizer(
            unsafe: $this->unsafeMode,
            blacklist: $this->blacklist,
        );

        // Track restart request via closure
        $restartRequested = false;

        $agent = $this->createAgent(
            sessionId: $sessionId,
            executionPolicy: $executionPolicy,
            sanitizer: $sanitizer,
            onRestart: function () use (&$restartRequested): void {
                $restartRequested = true;
            },
            observer: $observer,
            cancellationToken: $cancellationToken,
            pendingInputProvider: $pendingInputProvider,
            enableBackgroundTasks: $enableBackgroundTasks,
            role: $effectiveRole,
            maxIterations: $maxIterations,
            workScopeSessionId: $workScopeSessionId,
        );

        if ($observer !== null) {
            $agent->attach($observer);
        }

        try {
            // Pre-summarize when the role requests it (e.g. plan role switching
            // to coder needs a compressed context). Reuses existing summarization
            // infrastructure — the only new logic is the trigger condition.
            if ($this->roleDiscovery !== null && $history->count() > 20) {
                try {
                    $roleProps = $this->roleDiscovery->getRole($effectiveRole);
                    if ($roleProps->preSummarize) {
                        $history = $this->autoSummarizeIfNeeded($agent, $history, $sessionId, $prompt, $observer);
                    }
                } catch (\Throwable) {
                    // Role not found or summarization failure — non-fatal
                }
            }

            // Auto-summarize when conversation history is nearing the context limit.
            // This preserves important context via LLM summarization instead of
            // silently dropping oldest turns via fitWithinBudget().
            $history = $this->autoSummarizeIfNeeded($agent, $history, $sessionId, $prompt, $observer);

            // Per-iteration pruning is handled by AbstractAgent using the
            // model-aware ContextWindow passed to OrchestratorAgent.

            $output = $agent->run($this->buildUserMessage($prompt, $filePaths), $history);

            // Resolve usage: prefer provider-reported tokens, fall back to local estimation
            $usage = ($output->usage !== null && $output->usage->totalTokens > 0)
                ? $output->usage
                : $this->estimateUsage($output, $modelString);

            // Detect whether the agent exhausted its iteration budget
            $resolvedMaxIterations = $maxIterations ?? $this->roleResolver->resolveMaxIterations($effectiveRole);
            $iterationLimitReached = $resolvedMaxIterations > 0 && $output->iterations >= $resolvedMaxIterations;

            // Batch all post-turn DB writes in a single transaction to reduce fsync overhead
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $toolsUsed = $this->extractToolsUsed($output->conversation, $history->count());
            $childAgentCount = $agent->getSpawnTool()->getChildRunCount();

            $pdo = $this->storage->getPdo();
            $pdo->beginTransaction();
            try {
                // Persist intermediate messages from this turn (tool calls + results)
                if ($output->conversation !== null) {
                    $this->persistTurnMessages($output->conversation, $history->count(), $sessionId, $turnId);
                }

                // Save final assistant response if not already persisted
                if ($output->conversation === null) {
                    $this->storage->addMessage($sessionId, 'assistant', $output->content, turnId: $turnId);
                }

                // Update session token count
                $this->storage->updateTokenCount($sessionId, $usage->totalTokens);

                // Complete turn with metadata
                $this->storage->completeTurn(
                    turnId: $turnId,
                    responseText: $output->content,
                    promptTokens: $usage->promptTokens,
                    completionTokens: $usage->completionTokens,
                    totalTokens: $usage->totalTokens,
                    iterations: $output->iterations,
                    durationMs: $durationMs,
                    toolsUsed: json_encode($toolsUsed, JSON_UNESCAPED_SLASHES) ?: '[]',
                    childAgentCount: $childAgentCount,
                );

                $pdo->commit();
            } catch (\Throwable $dbError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $dbError;
            }

            // If the in-loop pruning strategy applied summarization during agent->run(),
            // persist it now (mid-loop persistence would corrupt turn message offsets).
            $pruningStrategy = $agent->getPruningStrategy();
            if ($pruningStrategy !== null && $pruningStrategy->wasSummarizationApplied()) {
                try {
                    $summarizer = new ConversationSummarizer(
                        storage: $this->storage,
                        memoryStore: $this->memoryStore,
                    );
                    $factory = $this->providerFactory ?? new ProviderFactory($this->config, $this->httpClient);
                    $utilityModel = $this->roleResolver->resolveUtility();
                    if ($utilityModel !== '') {
                        $utilityProvider = $factory->create($utilityModel);
                        $summarizer->summarizeAndPersist(
                            sessionId: $sessionId,
                            provider: $utilityProvider,
                            keepRecentTurns: $pruningStrategy->sessionId() !== null
                                ? CoquiDefaults::KEEP_RECENT_TURNS
                                : CoquiDefaults::KEEP_RECENT_TURNS,
                            workflowContext: $this->buildWorkflowContext($sessionId),
                            onExtraction: function (int $saved, string $source) use ($agent): void {
                                $agent->notify('agent.memory_extraction', [
                                    'memories_saved' => $saved,
                                    'source' => $source,
                                    'auto' => true,
                                ]);
                            },
                        );
                    }
                } catch (\Throwable) {
                    // Deferred persistence failure is non-fatal
                }
                $pruningStrategy->reset();
            }

            // Enqueue memory extraction and usage tracking refresh as deferred work
            $deferredWork = new DeferredWorkQueue();

            if ($this->usageTracker !== null) {
                $deferredWork->enqueue(fn() => $this->usageTracker->refresh());
            }

            $conversationForExtraction = $output->conversation ?? $history;
            $deferredWork->enqueue(fn() => $this->autoExtractMemories(
                $conversationForExtraction,
                $sessionId,
                fn(string $event, mixed $data) => $agent->notify($event, $data),
            ));

            // Build context usage snapshot for progress bar rendering
            $contextUsage = null;
            try {
                $finalConversation = $output->conversation ?? $history;
                $contextUsage = ContextUsageBar::buildSnapshot(
                    $finalConversation,
                    $agent->getContextWindow(),
                );
            } catch (\Throwable) {
                // Non-fatal — progress bar is optional
            }

            // Collect file edits for post-turn summary
            $fileEdits = $this->collectFileEdits($turnStartedAt);

            // Post-turn automated code review for coder role interactive sessions.
            // Single pass only — feedback is shown to user, no auto-iterate.
            $reviewFeedback = null;
            $reviewApproved = null;
            if ($this->shouldPostTurnReview($effectiveRole, $fileEdits)) {
                $reviewResult = $this->runPostTurnReview(
                    coderOutput: $output->content,
                    originalTask: $prompt,
                    observer: $observer,
                );
                if ($reviewResult !== null) {
                    $reviewFeedback = $reviewResult->reviewFeedback;
                    $reviewApproved = $reviewResult->approved;
                }
            }

            // Build active background tasks snapshot for footer rendering
            $backgroundTasks = null;
            try {
                $showBg = (bool) $this->config->get('agents.defaults.footer.backgroundTasks', true);
                if ($showBg) {
                    $rows = $this->storage->getActiveBackgroundSummary();
                    if ($rows !== []) {
                        $backgroundTasks = BackgroundTaskSummary::fromRows($rows);
                    }
                }
            } catch (\Throwable) {
                // Non-fatal — background task summary is optional
            }

            return new AgentTurnResult(
                content: $output->content,
                iterations: $output->iterations,
                promptTokens: $usage->promptTokens,
                completionTokens: $usage->completionTokens,
                totalTokens: $usage->totalTokens,
                durationMs: $durationMs,
                toolsUsed: $toolsUsed,
                childAgentCount: $childAgentCount,
                restartRequested: $restartRequested,
                iterationLimitReached: $iterationLimitReached,
                contextUsage: $contextUsage,
                fileEdits: $fileEdits,
                reviewFeedback: $reviewFeedback,
                reviewApproved: $reviewApproved,
                deferredWork: $deferredWork,
                backgroundTasks: $backgroundTasks,
            );
        } catch (\Throwable $e) {
            // Complete turn even on error so duration/state is tracked
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $this->storage->completeTurn(
                turnId: $turnId,
                responseText: "Error: {$e->getMessage()}",
                promptTokens: 0,
                completionTokens: 0,
                totalTokens: 0,
                iterations: 0,
                durationMs: $durationMs,
                toolsUsed: '[]',
                childAgentCount: 0,
            );

            return new AgentTurnResult(
                content: '',
                iterations: 0,
                promptTokens: 0,
                completionTokens: 0,
                totalTokens: 0,
                durationMs: $durationMs,
                toolsUsed: [],
                childAgentCount: 0,
                restartRequested: $restartRequested,
                error: $e->getMessage(),
            );
        }
    }

    private function createAgent(
        string $sessionId,
        ToolExecutionPolicyInterface $executionPolicy,
        ScriptSanitizer $sanitizer,
        \Closure $onRestart,
        ?SplObserver $observer = null,
        ?CancellationTokenInterface $cancellationToken = null,
        ?PendingInputProviderInterface $pendingInputProvider = null,
        bool $enableBackgroundTasks = true,
        string $role = 'orchestrator',
        ?int $maxIterations = null,
        ?string $workScopeSessionId = null,
    ): OrchestratorAgent {
        $modelString = $this->roleResolver->resolve($role);
        $factory = $this->providerFactory ?? new ProviderFactory($this->config, $this->httpClient);
        $provider = $factory->create($modelString);

        return new OrchestratorAgent(
            provider: $provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            storage: $this->storage,
            sessionId: $sessionId,
            observer: $observer,
            discovery: $this->discovery,
            maxIterations: $maxIterations ?? $this->roleResolver->resolveMaxIterations($role),
            executionPolicy: $executionPolicy,
            sanitizer: $sanitizer,
            onRestart: $onRestart,
            credentialResolver: $this->credentialResolver,
            skillDiscovery: $this->skillDiscovery,
            roleDiscovery: $this->roleDiscovery,
            cancellationToken: $cancellationToken,
            pendingInputProvider: $pendingInputProvider,
            backgroundTaskToolkit: ($enableBackgroundTasks && $this->backgroundTasksEnabled)
                ? new BackgroundTaskToolkit(
                    storage: $this->storage,
                    parentSessionId: $sessionId,
                    roleResolver: $this->roleResolver,
                    maxIterationsCap: $this->config instanceof \CoquiBot\Coqui\Config\OpenClawConfig
                        ? $this->config->getBackgroundTaskMaxIterations()
                        : CoquiDefaults::BACKGROUND_TASK_MAX_ITERATIONS,
                )
                : null,
            memoryStore: $this->memoryStore,
            memorySummarizer: $this->memorySummarizer,
            mountManager: $this->mountManager,
            configManager: $this->configManager,
            configGuard: $this->configGuard,
            visibilityRegistry: $this->visibilityRegistry,
            spaceToolkit: $this->spaceToolkit,
            activeRole: $role !== 'orchestrator' ? $role : null,
            projectStore: $this->projectStore,
            defaultsLoader: $this->defaultsLoader,
            familyResolver: $this->defaultsLoader !== null
                ? new ModelFamilyResolver($this->defaultsLoader->familyNames())
                : null,
            unsafeMode: $this->unsafeMode,
            toolExecutor: $this->toolExecutor,
            tickCallback: $this->tickCallback,
            httpClient: $this->httpClient,
            loadingRegistry: $this->loadingRegistry,
            usageTracker: $this->usageTracker,
            workScopeSessionId: $workScopeSessionId,
        );
    }

    /**
     * Build a preview agent (no session, no storage side-effects) and return
     * its system prompt text, tool/toolkit counts, and token estimates.
     *
     * Used by the /prompt REPL command and GET /api/v1/server/prompt endpoint.
     *
     * @return array{prompt: string, tool_count: int, toolkit_count: int, prompt_tokens: int, tool_tokens: int, total_tokens: int, toolkit_breakdown: array<int, array{name: string, class: string, guidelines_tokens: int, tools_tokens: int, total_tokens: int}>, tool_schemas: list<array{type: string, function: array{name: string, description: string, parameters: array}}>}
     */
    public function buildPromptPreview(?string $role = null): array
    {
        $effectiveRole = $role ?? 'orchestrator';
        $modelString = $this->roleResolver->resolve($effectiveRole);
        $factory = $this->providerFactory ?? new ProviderFactory($this->config, $this->httpClient);
        $provider = $factory->create($modelString);

        $sanitizer = new ScriptSanitizer(unsafe: false, blacklist: $this->blacklist);

        $agent = new OrchestratorAgent(
            provider: $provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            discovery: $this->discovery,
            sanitizer: $sanitizer,
            credentialResolver: $this->credentialResolver,
            skillDiscovery: $this->skillDiscovery,
            roleDiscovery: $this->roleDiscovery,
            memoryStore: $this->memoryStore,
            memorySummarizer: $this->memorySummarizer,
            mountManager: $this->mountManager,
            configManager: $this->configManager,
            configGuard: $this->configGuard,
            visibilityRegistry: $this->visibilityRegistry,
            spaceToolkit: $this->spaceToolkit,
            activeRole: $effectiveRole !== 'orchestrator' ? $effectiveRole : null,
            projectStore: $this->projectStore,
            defaultsLoader: $this->defaultsLoader,
            familyResolver: $this->defaultsLoader !== null
                ? new ModelFamilyResolver($this->defaultsLoader->familyNames())
                : null,
            loadingRegistry: $this->loadingRegistry,
            usageTracker: $this->usageTracker,
        );

        $counter = TokenCounterFactory::forModel($modelString);
        $promptText = $agent->getSystemPromptText();
        $promptTokens = $counter->count($promptText);
        $toolkitBreakdown = $agent->getToolkitTokenBreakdown($counter);
        $standaloneToolTokens = $agent->getStandaloneToolTokens($counter);

        $toolkitToolTokens = array_sum(array_column($toolkitBreakdown, 'tools_tokens'));
        $toolTokens = $standaloneToolTokens + $toolkitToolTokens;

        $toolSchemas = array_map(
            fn($tool) => $tool->toFunctionSchema(),
            $agent->tools(),
        );

        return [
            'prompt'                => $promptText,
            'tool_count'            => $agent->getToolCount(),
            'toolkit_count'         => $agent->getOwnToolkitCount(),
            'prompt_tokens'         => $promptTokens,
            'tool_tokens'           => $toolTokens,
            'total_tokens'          => $promptTokens + $toolTokens,
            'toolkit_breakdown'     => $toolkitBreakdown,
            'tool_schemas'          => $toolSchemas,
            'applied_loading_modes' => $agent->getAppliedLoadingModes(),
        ];
    }

    /**
     * Export the system prompt and tool schemas to a human-readable file.
     *
     * @return string Absolute path to the exported file.
     */
    public function exportPromptToFile(?string $role = null): string
    {
        $preview = $this->buildPromptPreview($role);
        $effectiveRole = $role ?? 'orchestrator';
        $timestamp = date('Y-m-d_H-i-s');

        $lines = [];
        $lines[] = '# Coqui System Prompt Export';
        $lines[] = '# Generated: ' . date('c');
        $lines[] = '# Role: ' . $effectiveRole;
        $lines[] = '# Tools: ' . $preview['tool_count'] . '  |  Toolkits: ' . $preview['toolkit_count'];
        $lines[] = '# Prompt tokens: ' . number_format($preview['prompt_tokens'])
            . '  |  Tool schema tokens: ' . number_format($preview['tool_tokens'])
            . '  |  Total: ' . number_format($preview['total_tokens']);
        $lines[] = '';
        $lines[] = str_repeat('=', 80);
        $lines[] = 'SYSTEM PROMPT';
        $lines[] = str_repeat('=', 80);
        $lines[] = '';
        $lines[] = $preview['prompt'];
        $lines[] = '';
        $lines[] = str_repeat('=', 80);
        $lines[] = 'TOOLKIT TOKEN BREAKDOWN';
        $lines[] = str_repeat('=', 80);
        $lines[] = '';

        foreach ($preview['toolkit_breakdown'] as $entry) {
            $lines[] = sprintf(
                '%s: %s guidelines + %s tools = %s total',
                $entry['name'],
                number_format($entry['guidelines_tokens']),
                number_format($entry['tools_tokens']),
                number_format($entry['total_tokens']),
            );
        }

        $lines[] = '';
        $lines[] = str_repeat('=', 80);
        $lines[] = 'TOOL SCHEMAS (' . $preview['tool_count'] . ' tools)';
        $lines[] = str_repeat('=', 80);

        foreach ($preview['tool_schemas'] as $schema) {
            $fn = $schema['function'] ?? [];
            $name = $fn['name'] ?? 'unknown';
            $desc = $fn['description'] ?? '';
            $params = $fn['parameters'] ?? [];

            $lines[] = '';
            $lines[] = '## ' . $name;
            $lines[] = $desc;

            $properties = $params['properties'] ?? [];
            $required = $params['required'] ?? [];

            if ($properties instanceof \stdClass) {
                $properties = (array) $properties;
            }

            if (!empty($properties)) {
                $lines[] = '';
                $lines[] = 'Parameters:';

                foreach ($properties as $pName => $pSchema) {
                    $type = $pSchema['type'] ?? 'mixed';
                    if (isset($pSchema['enum'])) {
                        $type = 'enum(' . implode('|', $pSchema['enum']) . ')';
                    }
                    $isRequired = in_array($pName, $required, true);
                    $pDesc = $pSchema['description'] ?? '';
                    $lines[] = sprintf(
                        '  %s (%s%s)%s',
                        $pName,
                        $type,
                        $isRequired ? ', required' : '',
                        $pDesc !== '' ? ' — ' . $pDesc : '',
                    );
                }
            }

            $lines[] = '';
            $lines[] = str_repeat('-', 40);
        }

        $lines[] = '';

        $content = implode("\n", $lines);
        $filePath = rtrim($this->workspacePath, '/') . '/Prompt-' . $timestamp . '.txt';
        file_put_contents($filePath, $content);

        return $filePath;
    }

    /**
     * Persist the new messages generated during a single agent turn.
     *
     * The conversation from AbstractAgent::run() contains:
     * [SystemMessage, ...history..., UserMessage, ...new turn messages...]
     *
     * Skip the system message and history (already persisted), and the
     * new user message (already saved before run()). Persist only the
     * assistant and tool messages from this turn's loop.
     *
     * Content is sanitized for valid UTF-8 before storage to prevent
     * malformed bytes (e.g., from web scraping) from poisoning the
     * conversation on reload.
     */
    private function persistTurnMessages(
        Conversation $conversation,
        int $historyCount,
        string $sessionId,
        ?string $turnId = null,
    ): void {
        $messages = $conversation->messages();

        // Offset = 1 (system prompt) + historyCount + 1 (user message from this turn)
        $newMessageStart = 1 + $historyCount + 1;

        for ($i = $newMessageStart; $i < count($messages); $i++) {
            $msg = $messages[$i];
            $role = $msg->role();

            try {
                $sanitizedContent = $this->sanitizeContent($msg->content());

                if ($role === Role::Assistant && trim($sanitizedContent) === '' && $msg->toolCalls() === []) {
                    continue;
                }

                match ($role) {
                    Role::Assistant => $this->storage->addMessage(
                        $sessionId,
                        'assistant',
                        $sanitizedContent,
                        !empty($msg->toolCalls()) ? json_encode(
                            array_map(fn(ToolCall $tc) => [
                                'id' => $tc->id,
                                'name' => $tc->name,
                                'arguments' => $tc->arguments,
                                ...($tc->metadata !== [] ? ['metadata' => $tc->metadata] : []),
                            ], $msg->toolCalls()),
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        ) : null,
                        turnId: $turnId,
                    ),

                    Role::Tool => $this->storage->addMessage(
                        $sessionId,
                        'tool',
                        $sanitizedContent,
                        null,
                        $msg->toolCallId(),
                        turnId: $turnId,
                    ),

                    // User and System messages mid-turn are unexpected but harmless — skip
                    default => null,
                };
            } catch (\Throwable) {
                // Skip messages that fail to serialize — prevents partial persistence
                continue;
            }
        }
    }

    /**
     * Sanitize message content for safe UTF-8 storage.
     */
    private function sanitizeContent(mixed $content): string
    {
        if (!is_string($content)) {
            return json_encode($content, JSON_THROW_ON_ERROR) ?: '';
        }

        if (!mb_check_encoding($content, 'UTF-8')) {
            return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
        }

        return $content;
    }

    /**
     * Build a UserMessage, optionally with attached files/images.
     *
     * Image files (JPEG, PNG, GIF, WebP) are sent to the LLM as vision
     * content via UserMessage::withImages(). Non-image files (text, code,
     * JSON, etc.) are read and injected as context blocks in the prompt.
     *
     * @param string[]|null $filePaths  Optional file paths to attach.
     */
    private function buildUserMessage(string $prompt, ?array $filePaths): UserMessage
    {
        if ($filePaths === null || $filePaths === []) {
            return new UserMessage($prompt);
        }

        $imagePaths = [];
        $textContext = '';

        foreach ($filePaths as $filePath) {
            if (!file_exists($filePath)) {
                continue;
            }

            $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

            if ($this->isImageMimeType($mimeType)) {
                $imagePaths[] = $filePath;
            } else {
                // Read text/document files and inject as context
                $contents = file_get_contents($filePath);
                if ($contents === false) {
                    continue;
                }

                $filename = basename($filePath);
                $textContext .= "\n\n--- Attached file: {$filename} ---\n{$contents}\n--- End of {$filename} ---";
            }
        }

        $enrichedPrompt = $textContext !== '' ? $prompt . $textContext : $prompt;

        if ($imagePaths !== []) {
            return UserMessage::withImages($enrichedPrompt, $imagePaths);
        }

        return new UserMessage($enrichedPrompt);
    }

    /**
     * Check if a MIME type represents an image.
     */
    private function isImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ], true);
    }

    /**
     * Extract unique tool names from tool calls made during this turn only.
     *
     * Uses the same offset formula as persistTurnMessages() to skip
     * history messages and only scan new assistant messages.
     *
     * @return string[]
     */
    private function extractToolsUsed(?Conversation $conversation, int $historyCount = 0): array
    {
        if ($conversation === null) {
            return [];
        }

        $tools = [];
        $messages = $conversation->messages();

        // Offset: 1 (system prompt) + historyCount (prior messages) + 1 (user input)
        $newMessageStart = 1 + $historyCount + 1;

        for ($i = $newMessageStart; $i < count($messages); $i++) {
            $msg = $messages[$i];
            if ($msg->role() === Role::Assistant && !empty($msg->toolCalls())) {
                foreach ($msg->toolCalls() as $tc) {
                    if ($tc->name !== 'done') {
                        $tools[$tc->name] = true;
                    }
                }
            }
        }

        return array_keys($tools);
    }

    /**
     * Estimate token usage from the conversation when the provider returns no usage data.
     *
     * Uses TokenCounterFactory to select the appropriate counter for the model
     * (tiktoken for OpenAI, heuristic for others) and counts tokens by role:
     * non-assistant messages → prompt tokens, assistant messages → completion tokens.
     */
    private function estimateUsage(Output $output, string $modelString): Usage
    {
        if ($output->conversation === null) {
            return new Usage();
        }

        $counter = TokenCounterFactory::forModel($modelString);
        $promptTokens = 0;
        $completionTokens = 0;

        foreach ($output->conversation->messages() as $msg) {
            $content = $msg->content();
            $tokens = is_string($content) ? $counter->count($content) : 0;

            if ($msg->role() === Role::Assistant) {
                $completionTokens += $tokens;
            } else {
                $promptTokens += $tokens;
            }
        }

        return new Usage(
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $promptTokens + $completionTokens,
        );
    }

    /**
     * Auto-summarize conversation history when it approaches the context window limit
     * or the conversation has grown too many turns.
     *
     * Two independent triggers (whichever fires first):
     *   1. Token usage exceeds threshold % of context window (default: 50%)
     *   2. User turn count exceeds turn threshold (default: 20 turns)
     *
     * Thresholds are configurable via openclaw.json:
     *   agents.defaults.context.autoSummarizeMode            (default: 'token')
     *   agents.defaults.context.autoSummarizeThreshold       (default: 70)
     *   agents.defaults.context.autoSummarizeTurnThreshold   (default: 20)
     */
    private function autoSummarizeIfNeeded(
        OrchestratorAgent $agent,
        Conversation $history,
        string $sessionId,
        string $prompt = '',
        ?SplObserver $observer = null,
    ): Conversation {
        if ($history->count() === 0) {
            return $history;
        }

        // Read summarization mode from config (default: 'token')
        $modeCfg = $this->config->get('agents.defaults.context.autoSummarizeMode');
        $mode = is_string($modeCfg) && in_array($modeCfg, ['token', 'turn', 'manual'], true)
            ? $modeCfg
            : CoquiDefaults::AUTO_SUMMARIZE_MODE;

        // Manual mode disables pre-turn auto-summarization entirely.
        // The SummarizePruningStrategy safety net still fires per-iteration
        // to prevent context window overflow.
        if ($mode === 'manual') {
            return $history;
        }

        $shouldSummarize = false;

        if ($mode === 'turn') {
            // Turn-based trigger: summarize when user turn count reaches threshold
            $userTurnCount = count($history->filter(Role::User));
            $turnThresholdCfg = $this->config->get('agents.defaults.context.autoSummarizeTurnThreshold');
            $turnThreshold = is_numeric($turnThresholdCfg) ? (int) $turnThresholdCfg : CoquiDefaults::AUTO_SUMMARIZE_TURN_THRESHOLD;
            $shouldSummarize = $userTurnCount >= $turnThreshold;
        } elseif ($mode === 'token') {
            // Token-based trigger: summarize when estimated token usage exceeds threshold
            $contextWindow = $agent->getContextWindow();

            if ($contextWindow !== null) {
                $historyTokens = $history->estimateTokens();
                $systemPromptTokens = (int) (strlen($agent->getSystemPromptText()) / 4);
                $userPromptTokens = (int) (strlen($prompt) / 4);
                $estimatedTokens = (int) (($historyTokens + $systemPromptTokens + $userPromptTokens) * 1.15);

                $maxTokens = $contextWindow->maxTokens();
                $reserved = $contextWindow->reservedTokens();
                $effectiveMax = $maxTokens - $reserved;

                if ($effectiveMax > 0) {
                    $usagePercent = ($estimatedTokens / $effectiveMax) * 100;

                    // Read threshold from config (default: 70%)
                    $threshold = $this->config->get('agents.defaults.context.autoSummarizeThreshold');
                    $threshold = is_numeric($threshold) ? (float) $threshold : CoquiDefaults::AUTO_SUMMARIZE_THRESHOLD;

                    // Normalize: accept both ratio (0.0–1.0) and percentage (1–100)
                    if ($threshold > 0.0 && $threshold <= 1.0) {
                        $threshold *= 100;
                    }

                    $shouldSummarize = $usagePercent >= $threshold;
                }
            }
        }

        if (!$shouldSummarize) {
            return $history;
        }

        // Trigger auto-summarization
        $summarizer = new ConversationSummarizer(
            storage: $this->storage,
            memoryStore: $this->memoryStore,
        );

        // Resolve a cheap provider for summarization via utility model chain
        $factory = $this->providerFactory ?? new ProviderFactory($this->config, $this->httpClient);
        $provider = null;

        try {
            $utilityModel = $this->roleResolver->resolveUtility();
            if ($utilityModel !== '') {
                $provider = $factory->create($utilityModel);
            }
        } catch (\Throwable) {
            // Fall through
        }

        if ($provider === null) {
            try {
                $orchestratorModel = $this->roleResolver->resolve('orchestrator');
                $provider = $factory->create($orchestratorModel);
            } catch (\Throwable) {
                return $history;
            }
        }

        // Read configurable keepRecentTurns for auto-summarization
        $keepRecentCfg = $this->config->get('agents.defaults.context.autoSummarizeKeepRecent');
        $keepRecent = is_numeric($keepRecentCfg) ? (int) $keepRecentCfg : CoquiDefaults::AUTO_SUMMARIZE_KEEP_RECENT;
        $keepRecent = max(1, min(20, $keepRecent));

        $result = $summarizer->summarizeAndPersist(
            sessionId: $sessionId,
            provider: $provider,
            keepRecentTurns: $keepRecent,
            workflowContext: $this->buildWorkflowContext($sessionId),
            onExtraction: function (int $saved, string $source) use ($agent): void {
                $agent->notify('agent.memory_extraction', [
                    'memories_saved' => $saved,
                    'source' => $source,
                    'auto' => true,
                ]);
            },
        );

        if (!$result->wasSummarized()) {
            return $history;
        }

        // Tell the pruning strategy to skip its next prune() call —
        // we just summarized, so the per-iteration safety net should not
        // double-summarize on the first iteration of this turn.
        $agent->getPruningStrategy()?->skipNextPrune();

        // Notify observer about auto-summarization
        $agent->notify(
            'agent.summary',
            [
                'messages_summarized' => $result->messagesSummarized,
                'tokens_before' => $result->tokensBefore,
                'tokens_after' => $result->tokensAfter,
                'tokens_saved' => $result->tokensSaved(),
                'auto' => true,
            ],
        );

        return $result->conversation;
    }

    /**
     * Build a workflow context string summarizing active todos and artifacts.
     *
     * Injected into the summarization prompt so the LLM preserves
     * structured workflow state when compressing conversation history.
     */
    private function buildWorkflowContext(string $sessionId): ?string
    {
        $sections = [];

        if ($this->todoStore !== null) {
            try {
                $stats = $this->todoStore->getStats($sessionId);
                $total = $stats['total'];

                if ($total > 0) {
                    $lines = ["Todos: {$stats['completed']}/{$total} completed"];

                    $activeTodos = $this->todoStore->list($sessionId, 'in_progress');
                    foreach ($activeTodos as $todo) {
                        $lines[] = "  - [in_progress] {$todo['title']}";
                    }

                    $pendingTodos = $this->todoStore->list($sessionId, 'pending');
                    foreach (array_slice($pendingTodos, 0, 5) as $todo) {
                        $lines[] = "  - [pending] {$todo['title']}";
                    }
                    if (count($pendingTodos) > 5) {
                        $lines[] = '  - ... and ' . (count($pendingTodos) - 5) . ' more pending';
                    }

                    $sections[] = implode("\n", $lines);
                }
            } catch (\Throwable) {
                // Non-critical — skip if store errors
            }
        }

        if ($this->artifactStore !== null) {
            try {
                $artifacts = $this->artifactStore->list($sessionId);
                if ($artifacts !== []) {
                    $lines = ['Artifacts:'];
                    foreach (array_slice($artifacts, 0, 5) as $artifact) {
                        $type = $artifact['type'] ?? 'unknown';
                        $stage = $artifact['stage'] ?? 'draft';
                        $title = $artifact['title'] ?? 'Untitled';
                        $lines[] = "  - [{$type}/{$stage}] {$title}";
                    }
                    if (count($artifacts) > 5) {
                        $lines[] = '  - ... and ' . (count($artifacts) - 5) . ' more';
                    }
                    $sections[] = implode("\n", $lines);
                }
            } catch (\Throwable) {
                // Non-critical
            }
        }
        $this->appendSprintContext($sessionId, $sections);
        return $sections !== [] ? implode("\n", $sections) : null;
    }

    /**
     * Append active sprint context to workflow sections.
     *
     * @param string[] $sections Mutable reference to sections array.
     */
    private function appendSprintContext(string $sessionId, array &$sections): void
    {
        if ($this->projectStore === null) {
            return;
        }

        try {
            $sprints = $this->projectStore->getActiveSprintsForSession($sessionId);
            if ($sprints === []) {
                return;
            }

            $lines = ['Active sprints:'];
            foreach (array_slice($sprints, 0, 3) as $sprint) {
                $title = $sprint['title'];
                $number = $sprint['sprint_number'];
                $status = $sprint['status'];
                $round = $sprint['review_round'] ?? 0;
                $maxRounds = $sprint['max_review_rounds'] ?? 3;
                $progress = '';
                if ($this->todoStore !== null) {
                    $stats = $this->projectStore->getSprintProgress($sprint['id'], $this->todoStore, $sessionId);
                    $progress = " — {$stats['percent']}% complete";
                }
                $lines[] = "  - Sprint #{$number} '{$title}' ({$status}{$progress}, round {$round}/{$maxRounds})";
            }
            $sections[] = implode("\n", $lines);
        } catch (\Throwable) {
            // Non-critical
        }
    }

    /**
     * Collect file edits that occurred during the current turn.
     *
     * @return ?array<int, array{file_path: string, operation: string}>
     */
    private function collectFileEdits(string $turnStartedAt): ?array
    {
        try {
            $history = new EditHistory($this->workspacePath . '/data/edit-history');
            $edits = $history->getEditsSince($turnStartedAt);

            if ($edits === []) {
                return null;
            }

            return array_map(
                fn(array $edit) => [
                    'file_path' => $edit['file_path'],
                    'operation' => $edit['operation'],
                ],
                $edits,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Determine if the current turn should receive a post-turn code review.
     *
     * @param ?array<int, array{file_path: string, operation: string}> $fileEdits
     */
    private function shouldPostTurnReview(string $role, ?array $fileEdits): bool
    {
        // Only review when there are actual file changes
        if ($fileEdits === null || $fileEdits === []) {
            return false;
        }

        // Check global config
        if ($this->config instanceof \CoquiBot\Coqui\Config\OpenClawConfig) {
            $reviewConfig = $this->config->getCodeReviewConfig();
            if (!$reviewConfig['enabled']) {
                return false;
            }
        }

        // Check role-level auto_review flag
        if ($this->roleDiscovery !== null) {
            try {
                $properties = $this->roleDiscovery->getRole($role);
                return $properties->autoReview;
            } catch (\Throwable) {
                // Fall through
            }
        }

        // Default: only coder role
        return $role === 'coder';
    }

    /**
     * Run a single-pass code review for interactive coder sessions.
     *
     * Returns the review result for display, but does not auto-iterate.
     */
    private function runPostTurnReview(
        string $coderOutput,
        string $originalTask,
        ?SplObserver $observer,
    ): ?\CoquiBot\Coqui\Contract\CodeReviewResult {
        try {
            $cycle = new CodeReviewCycle(
                roleResolver: $this->roleResolver,
                config: $this->config,
                roleDiscovery: $this->roleDiscovery,
                observer: $observer,
            );

            // Build reviewer toolkits: read-only filesystem + shell search
            $mountPaths = $this->mountManager?->allowedPathsReadOnly() ?? [];
            $reviewerToolkits = [
                new \CoquiBot\Coqui\Toolkit\FileSystemToolkit(
                    workspacePath: $this->workspacePath,
                    readOnly: true,
                    allowedPaths: $mountPaths,
                ),
                new \CoquiBot\Coqui\Toolkit\ShellToolkit(
                    workDir: $this->projectRoot,
                    allowedCommands: \CoquiBot\Coqui\Config\ShellConfigResolver::READ_ONLY_SHELL_COMMANDS,
                    timeout: 60,
                ),
                new \CoquiBot\Coqui\Toolkit\CoquiSourceToolkit(projectRoot: $this->projectRoot),
            ];

            return $cycle->run(
                coderOutput: $coderOutput,
                originalTask: $originalTask,
                reviewerToolkits: $reviewerToolkits,
                maxRounds: 1,
                autoIterate: false,
            );
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Uses a cheap LLM call to identify noteworthy facts in recent turns
     * and saves them as memories. Respects cooldown and config toggle.
     *
     * @param ?\Closure(string, mixed): void $notify  Optional observer callback for transparency events
     */
    private function autoExtractMemories(
        Conversation $conversation,
        string $sessionId,
        ?\Closure $notify = null,
    ): void {
        if ($this->memoryStore === null) {
            return;
        }

        // Check config toggle — defaults to disabled (CoquiDefaults::MEMORY_AUTO_EXTRACT = false)
        $autoExtract = $this->config->get('agents.defaults.memory.autoExtract');
        if ($autoExtract === null) {
            $autoExtract = CoquiDefaults::MEMORY_AUTO_EXTRACT;
        }
        if ($autoExtract === false || $autoExtract === 'false' || $autoExtract === '0') {
            return;
        }

        try {
            $factory = $this->providerFactory ?? new ProviderFactory($this->config, $this->httpClient);
            $provider = null;

            // Resolve a cheap utility provider
            $utilityModel = $this->roleResolver->resolveUtility();
            if ($utilityModel !== '') {
                $provider = $factory->create($utilityModel);
            }

            if ($provider === null) {
                $orchestratorModel = $this->roleResolver->resolve('orchestrator');
                $provider = $factory->create($orchestratorModel);
            }

            $extractor = new MemoryExtractor($this->memoryStore);
            $saved = $extractor->extractFromConversation($conversation, $provider);

            if ($saved > 0 && $notify !== null) {
                $notify('agent.memory_extraction', [
                    'memories_saved' => $saved,
                    'source' => 'auto_turn',
                    'auto' => true,
                ]);
            }
        } catch (\Throwable) {
            // Extraction failure is non-fatal — never interrupt the user flow
        }
    }
}
