<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Memory;

use CarmeloSantana\PHPAgents\Message\Conversation;

/**
 * Value object containing the result of a conversation summarization.
 */
final readonly class ConversationSummaryResult
{
    public function __construct(
        public string $summary,
        public int $messagesSummarized,
        public int $tokensBefore,
        public int $tokensAfter,
        public Conversation $conversation,
    ) {}

    /**
     * Number of tokens saved by the summarization.
     */
    public function tokensSaved(): int
    {
        return max(0, $this->tokensBefore - $this->tokensAfter);
    }

    /**
     * Whether the summarization actually produced a result.
     */
    public function wasSummarized(): bool
    {
        return $this->messagesSummarized > 0 && $this->summary !== '';
    }
}
