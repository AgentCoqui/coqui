<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Agent\Output;
use CarmeloSantana\PHPAgents\Context\TokenCounterFactory;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Provider\Usage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CoquiBot\Coqui\Config\CatastrophicBlacklist;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\AgentTurnResult;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\CoquiSpace\SpaceToolkit;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\MemoryExtractor;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;
use SplObserver;

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
    ): AgentTurnResult {
        // Load prior conversation history from database
        $history = $this->storage->loadConversation($sessionId);

        // Resolve the model string for turn tracking (use task role if provided)
        $effectiveRole = $role ?? 'orchestrator';
        $modelString = $this->roleResolver->resolve($effectiveRole);

        // Create turn record before execution
        $turnId = $this->storage->createTurn($sessionId, $prompt, $modelString);
        $startTime = hrtime(true);

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
        );

        if ($observer !== null) {
            $agent->attach($observer);
        }

        try {
            // Auto-summarize when conversation history is nearing the context limit.
            // This preserves important context via LLM summarization instead of
            // silently dropping oldest turns via fitWithinBudget().
            $history = $this->autoSummarizeIfNeeded($agent, $history, $sessionId, $observer);

            // Per-iteration pruning is handled by AbstractAgent using the
            // model-aware ContextWindow passed to OrchestratorAgent.

            $output = $agent->run($this->buildUserMessage($prompt, $filePaths), $history);

            // Resolve usage: prefer provider-reported tokens, fall back to local estimation
            $usage = ($output->usage !== null && $output->usage->totalTokens > 0)
                ? $output->usage
                : $this->estimateUsage($output, $modelString);

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
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $toolsUsed = $this->extractToolsUsed($output->conversation, $history->count());
            $childAgentCount = $agent->getSpawnTool()->getChildRunCount();

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

            // Automatic memory extraction from completed turn
            $this->autoExtractMemories($output->conversation ?? $history, $sessionId);

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
    ): OrchestratorAgent {
        $modelString = $this->roleResolver->resolve($role);
        $factory = $this->providerFactory ?? new ProviderFactory($this->config);
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
                ? new BackgroundTaskToolkit($this->storage, $sessionId, $this->roleResolver)
                : null,
            memoryStore: $this->memoryStore,
            memorySummarizer: $this->memorySummarizer,
            mountManager: $this->mountManager,
            configManager: $this->configManager,
            configGuard: $this->configGuard,
            visibilityRegistry: $this->visibilityRegistry,
            spaceToolkit: $this->spaceToolkit,
            activeRole: $role !== 'orchestrator' ? $role : null,
        );
    }

    /**
     * Build a preview agent (no session, no storage side-effects) and return
     * its system prompt text, tool/toolkit counts, and token estimates.
     *
     * Used by the /prompt REPL command and GET /api/v1/server/prompt endpoint.
     *
     * @return array{prompt: string, tool_count: int, toolkit_count: int, prompt_tokens: int, tool_tokens: int, total_tokens: int, toolkit_breakdown: array<int, array{name: string, class: string, guidelines_tokens: int, tools_tokens: int, total_tokens: int}>}
     */
    public function buildPromptPreview(): array
    {
        $modelString = $this->roleResolver->resolve('orchestrator');
        $factory = $this->providerFactory ?? new ProviderFactory($this->config);
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
        );

        $counter = TokenCounterFactory::forModel($modelString);
        $promptText = $agent->getSystemPromptText();
        $promptTokens = $counter->count($promptText);
        $toolkitBreakdown = $agent->getToolkitTokenBreakdown($counter);
        $standaloneToolTokens = $agent->getStandaloneToolTokens($counter);

        $toolkitToolTokens = array_sum(array_column($toolkitBreakdown, 'tools_tokens'));
        $toolTokens = $standaloneToolTokens + $toolkitToolTokens;

        return [
            'prompt'            => $promptText,
            'tool_count'        => $agent->getToolCount(),
            'toolkit_count'     => $agent->getOwnToolkitCount(),
            'prompt_tokens'     => $promptTokens,
            'tool_tokens'       => $toolTokens,
            'total_tokens'      => $promptTokens + $toolTokens,
            'toolkit_breakdown' => $toolkitBreakdown,
        ];
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
                match ($role) {
                    Role::Assistant => $this->storage->addMessage(
                        $sessionId,
                        'assistant',
                        $this->sanitizeContent($msg->content()),
                        !empty($msg->toolCalls()) ? json_encode(
                            array_map(fn(ToolCall $tc) => [
                                'id' => $tc->id,
                                'name' => $tc->name,
                                'arguments' => $tc->arguments,
                            ], $msg->toolCalls()),
                            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                        ) : null,
                        turnId: $turnId,
                    ),

                    Role::Tool => $this->storage->addMessage(
                        $sessionId,
                        'tool',
                        $this->sanitizeContent($msg->content()),
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
     * Auto-summarize conversation history when it approaches the context window limit.
     *
     * Checks the estimated token usage against the model's context window.
     * At 75% usage, triggers automatic summarization to preserve context
     * via LLM compression instead of losing information through hard pruning.
     *
     * Thresholds are configurable via openclaw.json:
     *   agents.defaults.context.autoSummarizeThreshold (default: 75)
     */
    private function autoSummarizeIfNeeded(
        OrchestratorAgent $agent,
        Conversation $history,
        string $sessionId,
        ?SplObserver $observer = null,
    ): Conversation {
        if ($history->count() === 0) {
            return $history;
        }

        $contextWindow = $agent->getContextWindow();
        if ($contextWindow === null) {
            return $history;
        }

        // Estimate current conversation token usage
        $estimatedTokens = $history->estimateTokens();
        $maxTokens = $contextWindow->maxTokens();
        $reserved = $contextWindow->reservedTokens();
        $effectiveMax = $maxTokens - $reserved;

        if ($effectiveMax <= 0) {
            return $history;
        }

        $usagePercent = ($estimatedTokens / $effectiveMax) * 100;

        // Read threshold from config (default: 75%)
        $threshold = $this->config->get('agents.defaults.context.autoSummarizeThreshold');
        $threshold = is_numeric($threshold) ? (float) $threshold : 75.0;

        if ($usagePercent < $threshold) {
            return $history;
        }

        // Trigger auto-summarization
        $summarizer = new ConversationSummarizer(
            storage: $this->storage,
            memoryStore: $this->memoryStore,
        );

        // Resolve a cheap provider for summarization via utility model chain
        $factory = $this->providerFactory ?? new ProviderFactory($this->config);
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
        $keepRecent = is_numeric($keepRecentCfg) ? (int) $keepRecentCfg : 5;
        $keepRecent = max(1, min(20, $keepRecent));

        $result = $summarizer->summarizeAndPersist(
            sessionId: $sessionId,
            provider: $provider,
            keepRecentTurns: $keepRecent,
        );

        if (!$result->wasSummarized()) {
            return $history;
        }

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
     * Run automatic memory extraction from a completed conversation turn.
     *
     * Uses a cheap LLM call to identify noteworthy facts in recent turns
     * and saves them as memories. Respects cooldown and config toggle.
     */
    private function autoExtractMemories(Conversation $conversation, string $sessionId): void
    {
        if ($this->memoryStore === null) {
            return;
        }

        // Check config toggle (default: true)
        $autoExtract = $this->config->get('agents.defaults.memory.autoExtract');
        if ($autoExtract === false || $autoExtract === 'false') {
            return;
        }

        try {
            $factory = $this->providerFactory ?? new ProviderFactory($this->config);
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
            $extractor->extractFromConversation($conversation, $provider);
        } catch (\Throwable) {
            // Extraction failure is non-fatal — never interrupt the user flow
        }
    }
}
