<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Centralized default values for Coqui configuration.
 *
 * This is the single source of truth for all default limits and thresholds.
 * Every fallback in the codebase should reference these constants instead of
 * hardcoding values. Users can still override any value via openclaw.json —
 * these constants only govern the defaults when no config is provided.
 */
final class CoquiDefaults
{
    /** Default maximum agent loop iterations (config: agents.defaults.maxIterations). */
    public const int MAX_ITERATIONS = 256;

    /** Maximum iterations for background tasks (unattended, capped for safety). */
    public const int BACKGROUND_TASK_MAX_ITERATIONS = 512;

    /** Default concurrent background tasks (config: api.tasks.maxConcurrent). */
    public const int MAX_CONCURRENT_TASKS = 32;

    /** Default policy for unknown identities arriving through channels (config: channels.defaults.unknownUserPolicy). */
    public const string CHANNEL_UNKNOWN_USER_POLICY = 'deny';

    /** Default execution policy for channel-origin turns (config: channels.defaults.executionPolicy). */
    public const string CHANNEL_EXECUTION_POLICY = 'channel_untrusted';

    /** Default per-instance inbound rate limit for channels (config: channels.defaults.inboundRateLimit). */
    public const int CHANNEL_INBOUND_RATE_LIMIT = 60;

    /** Default outbound worker concurrency for channels (config: channels.defaults.outboundConcurrency). */
    public const int CHANNEL_OUTBOUND_CONCURRENCY = 4;

    /** Default cadence for channel health reconciliation (config: channels.defaults.healthCheckIntervalSeconds). */
    public const int CHANNEL_HEALTH_CHECK_INTERVAL_SECONDS = 30;

    /** Default recent turns preserved during on-demand summarization (config: agents.defaults.context.keepRecentTurns). */
    public const int KEEP_RECENT_TURNS = 24;

    /** Default recent turns preserved during auto-summarization (config: agents.defaults.context.autoSummarizeKeepRecent). */
    public const int AUTO_SUMMARIZE_KEEP_RECENT = 15;

    /** Token usage percentage threshold that triggers auto-summarization (config: agents.defaults.context.autoSummarizeThreshold). */
    public const float AUTO_SUMMARIZE_THRESHOLD = 64.0;

    /** User turn count that triggers auto-summarization when mode is 'turn' (config: agents.defaults.context.autoSummarizeTurnThreshold). */
    public const int AUTO_SUMMARIZE_TURN_THRESHOLD = 32;

    /** Auto-summarization trigger mode: 'token' (default), 'turn', or 'manual' (config: agents.defaults.context.autoSummarizeMode). */
    public const string AUTO_SUMMARIZE_MODE = 'token';

    /** Fallback context window size in tokens when no model definition is available. */
    public const int CONTEXT_WINDOW_FALLBACK = 128_000;

    /** Fallback reserved tokens for completion when no model definition is available. */
    public const int CONTEXT_WINDOW_RESERVED = 4_096;

    /** Safety margin percentage applied by fitWithinBudget to account for token estimation inaccuracy (config: agents.defaults.context.budgetSafetyMarginPercent). */
    public const int BUDGET_SAFETY_MARGIN_PERCENT = 20;

    /** Whether prior history is rendered into a system-prompt section instead of replayed provider messages (config: agents.defaults.context.conversationHistoryInSystemPrompt). */
    public const bool CONVERSATION_HISTORY_IN_SYSTEM_PROMPT = false;

    /** Recent-history window that uses relative timestamps in the conversation-history prompt formatter. */
    public const int CONVERSATION_HISTORY_RELATIVE_TIME_WINDOW_HOURS = 12;

    /** Context window usage threshold (0.0–1.0) that triggers budget-based exit with wrap-up (config: agents.defaults.context.budgetExitThreshold). 0.0 = disabled. */
    public const float BUDGET_EXIT_THRESHOLD = 0.85;

    /** Number of iterations allowed after budget threshold for the agent to wrap up (config: agents.defaults.context.budgetExitWrapUpIterations). */
    public const int BUDGET_EXIT_WRAP_UP_ITERATIONS = 2;

    /** Policy when a model turn yields no content and no tool calls — one of ignore|nudge|nudge_then_fallback|fallback (config: agents.defaults.emptyResponse.handling). */
    public const string EMPTY_RESPONSE_HANDLING = 'nudge_then_fallback';

    /** Corrective retries before the empty-response policy gives up or falls back to reasoning (config: agents.defaults.emptyResponse.maxRetries). */
    public const int EMPTY_RESPONSE_MAX_RETRIES = 2;

    /** Whether automated code review is enabled globally (config: agents.defaults.codeReview.enabled). */
    public const bool CODE_REVIEW_ENABLED = true;

    /** Maximum review→iterate rounds for spawned coder agents (config: agents.defaults.codeReview.maxRounds). */
    public const int CODE_REVIEW_MAX_ROUNDS = 2;

    /** Whether spawned coder agents auto-iterate on NEEDS_CHANGES (config: agents.defaults.codeReview.autoIterate). */
    public const bool CODE_REVIEW_AUTO_ITERATE = true;

    /** Whether automatic memory extraction runs after every turn (config: agents.defaults.memory.autoExtract). */
    public const bool MEMORY_AUTO_EXTRACT = false;

    /** Maximum token budget for compressed core memory summary in system prompt (config: agents.defaults.memory.coreSummaryMaxTokens). */
    public const int MEMORY_CORE_SUMMARY_MAX_TOKENS = 500;

    /** Maximum number of memory entries fetched for core summary generation (config: agents.defaults.memory.coreSummaryEntryLimit). */
    public const int MEMORY_CORE_SUMMARY_ENTRY_LIMIT = 50;

    /** Default edit history retention in days for prune operations (config: agents.defaults.editHistory.retentionDays). */
    public const int EDIT_HISTORY_RETENTION_DAYS = 7;

    /** Whether the notification system is enabled (config: agents.defaults.notifications.enabled). */
    public const bool NOTIFICATION_ENABLED = true;

    /** Maximum notifications to display in REPL idle rendering (config: agents.defaults.notifications.replDisplayLimit). */
    public const int NOTIFICATION_REPL_DISPLAY_LIMIT = 5;

    /** Maximum notifications to inject into agent turn context (config: agents.defaults.notifications.promptInjectionLimit). */
    public const int NOTIFICATION_PROMPT_INJECTION_LIMIT = 10;

    /** Retention hours for informational notifications before auto-prune (config: agents.defaults.notifications.retentionHours.informational). */
    public const int NOTIFICATION_RETENTION_INFORMATIONAL_HOURS = 24;

    /** Retention hours for actionable notifications before auto-prune (config: agents.defaults.notifications.retentionHours.actionable). */
    public const int NOTIFICATION_RETENTION_ACTIONABLE_HOURS = 72;

    /** Whether actionable notification automation is enabled in API mode (config: agents.defaults.notifications.automation.enabled). */
    public const bool NOTIFICATION_AUTOMATION_ENABLED = true;

    /** Seconds between actionable notification processing ticks (config: agents.defaults.notifications.automation.processTickSeconds). */
    public const int NOTIFICATION_AUTOMATION_PROCESS_TICK_SECONDS = 10;

    /** Seconds between actionable notification reclaim ticks (config: agents.defaults.notifications.automation.reclaimTickSeconds). */
    public const int NOTIFICATION_AUTOMATION_RECLAIM_TICK_SECONDS = 30;

    /** Lease duration for claimed actionable notifications (config: agents.defaults.notifications.automation.leaseSeconds). */
    public const int NOTIFICATION_AUTOMATION_LEASE_SECONDS = 300;

    /** Max actionable notifications to process per tick (config: agents.defaults.notifications.automation.batchSize). */
    public const int NOTIFICATION_AUTOMATION_BATCH_SIZE = 5;

    /** Max automation retry attempts before terminal failure (config: agents.defaults.notifications.automation.maxAttempts). */
    public const int NOTIFICATION_AUTOMATION_MAX_ATTEMPTS = 3;

    /** Retry delay after a recoverable automation failure or reclaimed lease (config: agents.defaults.notifications.automation.retryDelaySeconds). */
    public const int NOTIFICATION_AUTOMATION_RETRY_DELAY_SECONDS = 60;

    /** Safety cap on recursive copy/move operations to prevent runaway traversals. */
    public const int MAX_RECURSIVE_ITEMS = 10_000;

    /** Maximum file size in bytes for surgical edit operations (10 MB). */
    public const int MAX_EDIT_FILE_SIZE = 10_485_760;

    /** Default max execution time in seconds for background tasks (config: agents.defaults.backgroundTaskMaxExecutionSeconds). */
    public const int BACKGROUND_TASK_MAX_EXECUTION_SECONDS = 3600;

    /** Whether filesystem-backed artifacts are enabled (config: agents.defaults.artifacts.filesystemBacked). */
    public const bool ARTIFACT_FILESYSTEM_BACKED = false;

    /**
     * Tool schema token budget threshold for deferred loading.
     *
     * When total tool schema tokens for non-system toolkits exceed this threshold,
     * non-system toolkits are deferred (wrapped as StubToolkit) and discoverable
     * via tool_search. When under budget, all toolkits load eagerly.
     *
     * Config: agents.defaults.toolkitTokenBudget
     */
    public const int TOOLKIT_TOKEN_BUDGET = 20_000;

    /**
     * Percentage of the token budget allocated for promoting Auto-mode toolkits.
     *
     * When Auto candidates exceed the full budget, only this percentage is used
     * for frequency-ranked eager promotion. The rest are deferred.
     *
     * Config: agents.defaults.toolkitPromotionBudgetPercent
     */
    public const int TOOLKIT_PROMOTION_BUDGET_PERCENT = 60;

    /**
     * Toolkit class basenames that are always loaded (never deferred).
     *
     * These are the core toolkits that every agent session needs. They are
     * always registered with full schemas regardless of the token budget.
     *
     * @var list<string>
     */
    public const array SYSTEM_TOOLKITS = [
        'FileSystemToolkit',
        'ShellToolkit',
        'WebToolkit',
        'MemoryToolkit',
        'ArtifactToolkit',
        'TodoToolkit',
        'SprintToolkit',
        'CoquiSourceToolkit',
        'ComposerToolkit',
        'PackagistToolkit',
        'McpToolkit',
        'LoopToolkit',
        'ScheduleToolkit',
    ];

    /**
     * Standalone tool names that are always loaded (never deferred).
     *
     * @var list<string>
     */
    public const array SYSTEM_TOOLS = [
        'tool_search',
        'credentials',
        'spawn_agent',
        'vision_analyze',
        'restart_coqui',
        'summarize_conversation',
        'extract_memories',
        'config',
        'coqui_toolkits',
        'coqui_skills',
    ];
}
