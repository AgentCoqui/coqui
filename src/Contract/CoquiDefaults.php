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

    /** Default recent turns preserved during on-demand summarization (config: agents.defaults.context.keepRecentTurns). */
    public const int KEEP_RECENT_TURNS = 24;

    /** Default recent turns preserved during auto-summarization (config: agents.defaults.context.autoSummarizeKeepRecent). */
    public const int AUTO_SUMMARIZE_KEEP_RECENT = 32;

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

    /** Whether automated code review is enabled globally (config: agents.defaults.codeReview.enabled). */
    public const bool CODE_REVIEW_ENABLED = true;

    /** Maximum review→iterate rounds for spawned coder agents (config: agents.defaults.codeReview.maxRounds). */
    public const int CODE_REVIEW_MAX_ROUNDS = 2;

    /** Whether spawned coder agents auto-iterate on NEEDS_CHANGES (config: agents.defaults.codeReview.autoIterate). */
    public const bool CODE_REVIEW_AUTO_ITERATE = true;

    /** Whether automatic memory extraction runs after every turn (config: agents.defaults.memory.autoExtract). */
    public const bool MEMORY_AUTO_EXTRACT = false;

    /** Default edit history retention in days for prune operations (config: agents.defaults.editHistory.retentionDays). */
    public const int EDIT_HISTORY_RETENTION_DAYS = 7;

    /** Safety cap on recursive copy/move operations to prevent runaway traversals. */
    public const int MAX_RECURSIVE_ITEMS = 10_000;

    /** Maximum file size in bytes for surgical edit operations (10 MB). */
    public const int MAX_EDIT_FILE_SIZE = 10_485_760;

    /** Default max execution time in seconds for background tasks (config: agents.defaults.backgroundTaskMaxExecutionSeconds). */
    public const int BACKGROUND_TASK_MAX_EXECUTION_SECONDS = 3600;

    /**
     * Tool schema token budget threshold for deferred loading.
     *
     * When total tool schema tokens for non-system toolkits exceed this threshold,
     * non-system toolkits are deferred (wrapped as StubToolkit) and discoverable
     * via tool_search. When under budget, all toolkits load eagerly.
     *
     * Config: agents.defaults.toolkitTokenBudget
     */
    public const int TOOLKIT_TOKEN_BUDGET = 10_000;

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
        'SkillToolkit',
        'ComposerToolkit',
        'PackagistToolkit',
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
        'toolkit_list',
    ];
}
