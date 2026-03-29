<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Memory;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CoquiBot\Coqui\Memory\MemoryEntry;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Summarizes conversation history to reduce token usage while preserving context.
 *
 * Takes a Conversation and compresses it into a structured summary using a cheap
 * LLM provider. The summary replaces older messages, keeping the most recent turns
 * intact. Summaries can optionally be stored as session-scoped memories.
 */
final class ConversationSummarizer
{
    public function __construct(
        private readonly SessionStorage $storage,
        private readonly ?MemoryStore $memoryStore = null,
    ) {}

    /**
     * Summarize a conversation using an LLM provider.
     *
     * @param Conversation $conversation The full conversation to summarize.
     * @param ProviderInterface $provider A cheap provider for summarization.
     * @param int $keepRecentTurns Number of most recent user turns to preserve unsummarized.
     * @param string|null $focus Optional focus topic to emphasize in the summary.
     * @return ConversationSummaryResult The summary result with metrics.
     */
    public function summarize(
        Conversation $conversation,
        ProviderInterface $provider,
        int $keepRecentTurns = CoquiDefaults::KEEP_RECENT_TURNS,
        ?string $focus = null,
        ?string $workflowContext = null,
    ): ConversationSummaryResult {
        $messages = $conversation->messages();
        $tokensBefore = $conversation->estimateTokens();

        if (count($messages) <= $keepRecentTurns * 2) {
            return new ConversationSummaryResult(
                summary: '',
                messagesSummarized: 0,
                tokensBefore: $tokensBefore,
                tokensAfter: $tokensBefore,
                conversation: $conversation,
            );
        }

        // Split into messages to summarize and messages to keep
        [$toSummarize, $toKeep] = $this->splitConversation($conversation, $keepRecentTurns);

        if (empty($toSummarize)) {
            return new ConversationSummaryResult(
                summary: '',
                messagesSummarized: 0,
                tokensBefore: $tokensBefore,
                tokensAfter: $tokensBefore,
                conversation: $conversation,
            );
        }

        // Build summary text from messages to summarize
        $conversationText = $this->formatMessagesForSummary($toSummarize);
        $summaryText = $this->compressWithLlm($provider, $conversationText, $focus, $workflowContext);

        if ($summaryText === '') {
            return new ConversationSummaryResult(
                summary: '',
                messagesSummarized: 0,
                tokensBefore: $tokensBefore,
                tokensAfter: $tokensBefore,
                conversation: $conversation,
            );
        }

        // Build new conversation: system messages + summary + kept messages
        $summarized = $this->buildSummarizedConversation($conversation, $summaryText, $toKeep);
        $tokensAfter = $summarized->estimateTokens();

        return new ConversationSummaryResult(
            summary: $summaryText,
            messagesSummarized: count($toSummarize),
            tokensBefore: $tokensBefore,
            tokensAfter: $tokensAfter,
            conversation: $summarized,
        );
    }

    /**
     * Summarize and persist: replace old messages in the database with a summary message.
     *
     * Deletes the summarized messages from the database and inserts a single
     * summary message in their place. The summary is stored as a user message
     * (not system) because AbstractAgent skips system messages from history.
     *
     * @return ConversationSummaryResult
     */
    public function summarizeAndPersist(
        string $sessionId,
        ProviderInterface $provider,
        int $keepRecentTurns = CoquiDefaults::KEEP_RECENT_TURNS,
        ?string $focus = null,
        ?string $workflowContext = null,
        ?\Closure $onExtraction = null,
    ): ConversationSummaryResult {
        // Load raw message rows (with DB IDs) for cleanup after summarization
        $rawMessages = $this->storage->getMessages($sessionId);
        $conversation = $this->storage->loadConversation($sessionId);

        // Extract memories before summarization marks older messages
        if ($this->memoryStore !== null) {
            try {
                $extractor = new MemoryExtractor($this->memoryStore);
                $saved = $extractor->extractFromConversation($conversation, $provider, bypassCooldown: true);

                if ($saved > 0 && $onExtraction !== null) {
                    $onExtraction($saved, 'summarization');
                }
            } catch (\Throwable) {
                // Extraction failure should never block summarization
            }
        }

        $result = $this->summarize($conversation, $provider, $keepRecentTurns, $focus, $workflowContext);

        if ($result->messagesSummarized === 0) {
            return $result;
        }

        // Mark summarized messages as soft-deleted in the database.
        // The summarize() method splits by user turn index — we mirror
        // that logic on the raw rows to identify which DB IDs to mark.
        $idsToMark = $this->identifySummarizedMessageIds($rawMessages, $keepRecentTurns);
        if ($idsToMark !== []) {
            $this->storage->markMessagesAsSummarized($idsToMark);
        }

        // Store summary as a session-scoped memory if memory store is available
        if ($this->memoryStore !== null && $result->summary !== '') {
            $this->memoryStore->save(new MemoryEntry(
                content: $result->summary,
                area: 'session_summary',
                metadata: [
                    'tags' => "session:{$sessionId},auto_summary",
                    'session_id' => $sessionId,
                    'messages_summarized' => $result->messagesSummarized,
                    'tokens_before' => $result->tokensBefore,
                    'tokens_after' => $result->tokensAfter,
                    'created_at' => date('c'),
                ],
            ));
        }

        // Persist summary as a user message (not system — AbstractAgent
        // skips system messages from history, so system summaries are lost).
        $this->storage->addMessage(
            $sessionId,
            'user',
            $this->formatSummaryMessage($result->summary, $result->messagesSummarized),
        );

        return $result;
    }

    /**
     * Split conversation into messages to summarize and messages to keep.
     *
     * System messages are always kept. The last $keepRecentTurns user turns
     * and their associated responses are kept intact.
     *
     * @return array{0: list<\CarmeloSantana\PHPAgents\Contract\MessageInterface>, 1: list<\CarmeloSantana\PHPAgents\Contract\MessageInterface>}
     */
    private function splitConversation(Conversation $conversation, int $keepRecentTurns): array
    {
        $messages = $conversation->messages();

        // Find user message indices
        $userIndices = [];
        foreach ($messages as $i => $msg) {
            if ($msg->role() === Role::User) {
                $userIndices[] = $i;
            }
        }

        if (count($userIndices) <= $keepRecentTurns) {
            return [[], array_values($messages)];
        }

        // Cut point: keep from the Nth-from-last user message onward
        $cutIndex = $userIndices[count($userIndices) - $keepRecentTurns];

        $toSummarize = [];
        $toKeep = [];

        foreach ($messages as $i => $msg) {
            // System messages always go to keep
            if ($msg->role() === Role::System) {
                $toKeep[] = $msg;
                continue;
            }

            if ($i < $cutIndex) {
                $toSummarize[] = $msg;
            } else {
                $toKeep[] = $msg;
            }
        }

        return [$toSummarize, $toKeep];
    }

    /**
     * Format messages into a text representation for the LLM summarizer.
     *
     * @param list<\CarmeloSantana\PHPAgents\Contract\MessageInterface> $messages
     */
    private function formatMessagesForSummary(array $messages): string
    {
        $lines = [];

        foreach ($messages as $msg) {
            $role = strtoupper($msg->role()->value);
            $content = $msg->content();
            $text = is_string($content) ? $content : (json_encode($content) ?: '');

            // Truncate very long individual messages (e.g. tool results)
            if (strlen($text) > 2000) {
                $text = substr($text, 0, 1000) . "\n[... truncated ...]\n" . substr($text, -500);
            }

            $lines[] = "[{$role}]: {$text}";

            // Include tool call names for context
            if (!empty($msg->toolCalls())) {
                $toolNames = array_map(fn($tc) => $tc->name, $msg->toolCalls());
                $lines[] = "  (tools used: " . implode(', ', $toolNames) . ")";
            }
        }

        return implode("\n\n", $lines);
    }

    /**
     * Use an LLM to compress the conversation into a structured summary.
     */
    private function compressWithLlm(
        ProviderInterface $provider,
        string $conversationText,
        ?string $focus = null,
        ?string $workflowContext = null,
    ): string {
        $focusInstruction = $focus !== null
            ? "\n\nPay special attention to: {$focus}"
            : '';

        $workflowSection = '';
        if ($workflowContext !== null && $workflowContext !== '') {
            $workflowSection = "\n\nIMPORTANT — Current workflow state (preserve these details in the summary):\n{$workflowContext}";
        }

        $systemPrompt = <<<PROMPT
            You are a conversation summarizer. Compress the following conversation into a concise, structured summary.

            Include:
            - Key topics discussed
            - Important decisions made
            - Files modified or created
            - Tool actions taken and their outcomes
            - Unresolved questions or pending tasks
            - Current plan/todo status and next steps
            - User preferences expressed{$focusInstruction}{$workflowSection}

            Format as a compact paragraph with bullet points for key items. Keep it under 500 words.
            Do not add commentary — output ONLY the summary.
            PROMPT;

        try {
            $response = $provider->chat(
                messages: [
                    new SystemMessage($systemPrompt),
                    new UserMessage("Summarize this conversation:\n\n{$conversationText}"),
                ],
                tools: [],
            );

            return trim($response->content);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Build a new Conversation with the summary replacing old messages.
     *
     * The summary is injected as a UserMessage — not SystemMessage — because
     * AbstractAgent::run() skips all System messages from history when building
     * the conversation for the provider. A UserMessage with the
     * [CONVERSATION SUMMARY] marker is preserved and reaches the LLM.
     *
     * @param list<\CarmeloSantana\PHPAgents\Contract\MessageInterface> $keptMessages
     */
    private function buildSummarizedConversation(
        Conversation $original,
        string $summary,
        array $keptMessages,
    ): Conversation {
        $result = new Conversation();

        // Add system messages from original first
        foreach ($original->messages() as $msg) {
            if ($msg->role() === Role::System) {
                $result->add($msg);
                break; // Only the first system message (instructions)
            }
        }

        // Add summary as a user message so it survives history injection
        $result->add(new UserMessage($this->formatSummaryMessage($summary, 0)));

        // Add kept messages (skip system messages already added)
        foreach ($keptMessages as $msg) {
            if ($msg->role() !== Role::System) {
                $result->add($msg);
            }
        }

        return $result;
    }

    /**
     * Format a summary into a conversation marker message.
     */
    private function formatSummaryMessage(string $summary, int $messageCount): string
    {
        $date = date('Y-m-d H:i');
        $countNote = $messageCount > 0 ? " ({$messageCount} messages condensed)" : '';

        return "[CONVERSATION SUMMARY - {$date}]{$countNote}\n\n"
            . "{$summary}\n\n"
            . 'Focus on the most recent messages below for the user\'s current intent. '
            . 'This summary provides background context only.';
    }

    /**
     * Identify DB message IDs that correspond to summarized (older) messages.
     *
     * Mirrors the splitConversation() cut-point logic but operates on raw DB
     * rows instead of MessageInterface objects. System messages are never deleted.
     *
     * @param array<int, array<string, mixed>> $rawMessages
     * @return string[] DB message IDs to delete
     */
    private function identifySummarizedMessageIds(array $rawMessages, int $keepRecentTurns): array
    {
        $userIndices = [];
        foreach ($rawMessages as $i => $row) {
            if ($row['role'] === 'user') {
                $userIndices[] = $i;
            }
        }

        if (count($userIndices) <= $keepRecentTurns) {
            return [];
        }

        $cutIndex = $userIndices[count($userIndices) - $keepRecentTurns];

        $ids = [];
        foreach ($rawMessages as $i => $row) {
            // System messages are never deleted (they're skipped during history
            // injection anyway, and some may be prior summaries being replaced)
            if ($row['role'] === 'system') {
                continue;
            }

            if ($i < $cutIndex) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }
}
