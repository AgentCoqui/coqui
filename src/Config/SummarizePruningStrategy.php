<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\BudgetPruningStrategyInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\DefaultBudgetPruningStrategy;
use CoquiBot\Coqui\Memory\ConversationSummarizer;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Summarize-then-drop pruning strategy.
 *
 * Attempts LLM-powered conversation summarization before falling back to
 * the default drop-oldest strategy. This is a safety net — Coqui's pre-turn
 * autoSummarizeIfNeeded() is the primary mechanism. This strategy only fires
 * if token usage still exceeds the budget after pre-turn summarization
 * (edge case: large tool results within a single turn).
 *
 * When summarization fires mid-loop, changes are NOT persisted immediately
 * (mid-loop persistence would corrupt turn message offsets). Instead, a
 * deferred flag is set and AgentRunner checks it after agent->run() returns.
 */
final class SummarizePruningStrategy implements BudgetPruningStrategyInterface
{
    private readonly DefaultBudgetPruningStrategy $fallback;

    /** Whether summarization was applied during the current run. */
    private bool $summarizationApplied = false;

    /** Whether to skip the next prune call (set after auto-summarize fires). */
    private bool $skipNext = false;

    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly SessionStorage $storage,
        private readonly ?MemoryStore $memoryStore = null,
        private readonly int $keepRecentTurns = CoquiDefaults::KEEP_RECENT_TURNS,
        private readonly ?string $sessionId = null,
    ) {
        $this->fallback = new DefaultBudgetPruningStrategy();
    }

    public function prune(Conversation $conversation, int $budgetTokens): Conversation
    {
        // Skip if auto-summarization already ran this turn
        if ($this->skipNext) {
            $this->skipNext = false;

            return $conversation;
        }

        // Only attempt summarization if the conversation is significantly over budget
        if ($conversation->estimateTokens() <= $budgetTokens) {
            return $conversation;
        }

        try {
            $summarizer = new ConversationSummarizer(
                storage: $this->storage,
                memoryStore: $this->memoryStore,
            );

            $result = $summarizer->summarize(
                conversation: $conversation,
                provider: $this->provider,
                keepRecentTurns: $this->keepRecentTurns,
            );

            // If summarization produced a result and fits within budget, use it
            if ($result->wasSummarized() && $result->conversation->estimateTokens() <= $budgetTokens) {
                $this->summarizationApplied = true;

                return $result->conversation;
            }

            // Summarization helped but still over budget — apply default pruning on top
            if ($result->wasSummarized()) {
                $this->summarizationApplied = true;

                return $this->fallback->prune($result->conversation, $budgetTokens);
            }
        } catch (\Throwable) {
            // Summarization failed — fall through to default strategy
        }

        // Fall back to default drop-oldest strategy
        return $this->fallback->prune($conversation, $budgetTokens);
    }

    /**
     * Whether in-loop summarization was applied during the current run.
     *
     * AgentRunner checks this after agent->run() returns. If true, it triggers
     * a post-turn summarizeAndPersist() to sync the DB with the pruned state.
     */
    public function wasSummarizationApplied(): bool
    {
        return $this->summarizationApplied;
    }

    /**
     * Skip the next prune() call. Called after autoSummarizeIfNeeded() fires
     * to prevent double-summarization in the same turn.
     */
    public function skipNextPrune(): void
    {
        $this->skipNext = true;
    }

    /**
     * Reset deferred state between turns.
     */
    public function reset(): void
    {
        $this->summarizationApplied = false;
        $this->skipNext = false;
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }
}
