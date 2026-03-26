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
    public const int MAX_ITERATIONS = 48;

    /** Maximum iterations for background tasks (unattended, capped for safety). */
    public const int BACKGROUND_TASK_MAX_ITERATIONS = 100;

    /** Default concurrent background tasks (config: api.tasks.maxConcurrent). */
    public const int MAX_CONCURRENT_TASKS = 6;

    /** Default recent turns preserved during on-demand summarization (config: agents.defaults.context.keepRecentTurns). */
    public const int KEEP_RECENT_TURNS = 10;

    /** Default recent turns preserved during auto-summarization (config: agents.defaults.context.autoSummarizeKeepRecent). */
    public const int AUTO_SUMMARIZE_KEEP_RECENT = 15;

    /** Token usage percentage threshold that triggers auto-summarization (config: agents.defaults.context.autoSummarizeThreshold). */
    public const float AUTO_SUMMARIZE_THRESHOLD = 70.0;

    /** User turn count that triggers auto-summarization regardless of token usage (config: agents.defaults.context.autoSummarizeTurnThreshold). */
    public const int AUTO_SUMMARIZE_TURN_THRESHOLD = 20;

    /** Fallback context window size in tokens when no model definition is available. */
    public const int CONTEXT_WINDOW_FALLBACK = 128_000;

    /** Fallback reserved tokens for completion when no model definition is available. */
    public const int CONTEXT_WINDOW_RESERVED = 4_096;

    /** Safety margin percentage applied by fitWithinBudget to account for token estimation inaccuracy (config: agents.defaults.context.budgetSafetyMarginPercent). */
    public const int BUDGET_SAFETY_MARGIN_PERCENT = 20;
}
