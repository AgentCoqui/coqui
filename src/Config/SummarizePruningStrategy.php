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
 */
final class SummarizePruningStrategy implements BudgetPruningStrategyInterface
{
    private readonly DefaultBudgetPruningStrategy $fallback;

    public function __construct(
        private readonly ProviderInterface $provider,
        private readonly SessionStorage $storage,
        private readonly ?MemoryStore $memoryStore = null,
        private readonly int $keepRecentTurns = CoquiDefaults::KEEP_RECENT_TURNS,
    ) {
        $this->fallback = new DefaultBudgetPruningStrategy();
    }

    public function prune(Conversation $conversation, int $budgetTokens): Conversation
    {
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
                return $result->conversation;
            }

            // Summarization helped but still over budget — apply default pruning on top
            if ($result->wasSummarized()) {
                return $this->fallback->prune($result->conversation, $budgetTokens);
            }
        } catch (\Throwable) {
            // Summarization failed — fall through to default strategy
        }

        // Fall back to default drop-oldest strategy
        return $this->fallback->prune($conversation, $budgetTokens);
    }
}
