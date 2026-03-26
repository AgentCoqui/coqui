<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Memory;

use CarmeloSantana\PHPAgents\Contract\MessageInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Memory\MemoryEntry;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;

/**
 * Automatic memory extraction from conversations via LLM.
 *
 * Analyzes recent conversation turns and extracts noteworthy facts,
 * preferences, solutions, and context into structured memory entries.
 * Deduplicates against existing memories to avoid redundancy.
 *
 * Triggered post-turn (on done tool) and pre-summarization.
 */
final class MemoryExtractor
{
    /** Minimum interval between extractions in seconds. */
    private const EXTRACTION_COOLDOWN = 300;

    /** Similarity threshold above which a candidate is considered a duplicate. */
    private const DEDUP_THRESHOLD = 0.85;

    /** Maximum memories to extract per invocation. */
    private const MAX_EXTRACTIONS = 8;

    public function __construct(
        private readonly MemoryStore $memoryStore,
    ) {}

    /**
     * Extract memories from the most recent conversation turns.
     *
     * Returns the number of new memories saved.
     */
    public function extractFromConversation(
        Conversation $conversation,
        ProviderInterface $provider,
        int $recentTurns = 5,
        bool $bypassCooldown = false,
    ): int {
        if (!$bypassCooldown && !$this->shouldExtract()) {
            return 0;
        }

        $recentMessages = $this->getRecentTurns($conversation, $recentTurns);

        if ($recentMessages === []) {
            return 0;
        }

        $transcript = $this->formatTranscript($recentMessages);

        if (mb_strlen($transcript) < 100) {
            return 0;
        }

        $candidates = $this->extractCandidates($provider, $transcript);

        if ($candidates === []) {
            return 0;
        }

        $saved = 0;

        foreach ($candidates as $candidate) {
            if ($this->isDuplicate($candidate['content'])) {
                continue;
            }

            $importance = max(0.0, min(1.0, (float) ($candidate['importance'])));
            $area = $this->validateArea($candidate['area']);
            $tags = $candidate['tags'];

            $this->memoryStore->save(new MemoryEntry(
                content: $candidate['content'],
                area: $area,
                metadata: [
                    'tags' => $tags,
                    'importance' => $importance,
                    'source' => 'auto_extraction',
                ],
            ));

            $saved++;
        }

        if ($saved > 0) {
            $this->recordExtractionTime();
        }

        return $saved;
    }

    /**
     * Check cooldown to avoid extracting too frequently.
     */
    private function shouldExtract(): bool
    {
        try {
            $db = $this->getDb();
            $stmt = $db->query('SELECT last_extraction_at FROM memory_summary WHERE id = 1');

            if ($stmt === false) {
                return true;
            }

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false || $row['last_extraction_at'] === null) {
                return true;
            }

            $lastExtraction = new \DateTimeImmutable($row['last_extraction_at']);
            $elapsed = time() - $lastExtraction->getTimestamp();

            return $elapsed >= self::EXTRACTION_COOLDOWN;
        } catch (\Throwable) {
            return true;
        }
    }

    private function recordExtractionTime(): void
    {
        try {
            $db = $this->getDb();
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s');

            // Ensure row exists, then update
            $db->exec("INSERT OR IGNORE INTO memory_summary (id, summary, memory_count, generated_at) VALUES (1, '', 0, '{$now}')");
            $db->prepare('UPDATE memory_summary SET last_extraction_at = :ts WHERE id = 1')
                ->execute([':ts' => $now]);
        } catch (\Throwable) {
            // Non-fatal
        }
    }

    /**
     * Get recent user/assistant turns from conversation.
     *
     * @return MessageInterface[]
     */
    private function getRecentTurns(Conversation $conversation, int $turns): array
    {
        $messages = $conversation->messages();
        $relevant = [];

        // Walk backwards to find the last N user turns
        $turnCount = 0;

        for ($i = count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];

            if ($msg->role() === Role::User) {
                $turnCount++;
            }

            if ($turnCount > $turns) {
                break;
            }

            if ($msg->role() === Role::User || $msg->role() === Role::Assistant) {
                $relevant[] = $msg;
            }
        }

        return array_reverse($relevant);
    }

    /**
     * Format messages into a readable transcript.
     *
     * @param MessageInterface[] $messages
     */
    private function formatTranscript(array $messages): string
    {
        $lines = [];

        foreach ($messages as $msg) {
            $role = $msg->role() === Role::User ? 'USER' : 'ASSISTANT';
            $content = $msg->content();

            if (is_array($content)) {
                // Multimodal — extract text parts only
                $textParts = [];
                foreach ($content as $part) {
                    if (isset($part['text'])) {
                        $textParts[] = $part['text'];
                    }
                }
                $content = implode("\n", $textParts);
            }

            // Truncate very long messages to avoid blowing the extraction prompt
            if (mb_strlen($content) > 2000) {
                $content = mb_substr($content, 0, 2000) . '... [truncated]';
            }

            $lines[] = "{$role}: {$content}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Use LLM to extract structured memory candidates from transcript.
     *
     * @return list<array{content: string, area: string, importance: float, tags: string}>
     */
    private function extractCandidates(ProviderInterface $provider, string $transcript): array
    {
        $maxExtractions = self::MAX_EXTRACTIONS;

        $systemPrompt = <<<PROMPT
        You are a memory extraction system. Analyze the conversation and extract noteworthy facts worth remembering long-term.

        Extract ONLY information that would be useful in future conversations:
        - User preferences (tools, languages, workflows, communication style)
        - Key facts about the user or their projects
        - Solutions to problems that were discovered
        - Important technical context or decisions

        Do NOT extract:
        - Transient task details (file names being edited right now, current errors being debugged)
        - Information the assistant already knows (general programming knowledge)
        - Greetings, thanks, or small talk

        Return a JSON array (max {$maxExtractions} items). Each item:
        {
            "content": "Concise fact in one or two sentences",
            "area": "preferences|facts|solutions|context",
            "importance": 0.0-1.0,
            "tags": "comma,separated,tags"
        }

        Importance guide: 0.9+ = critical user preference or identity, 0.7-0.8 = important project fact, 0.5-0.6 = useful context, 0.3-0.4 = minor detail.

        If nothing is worth extracting, return an empty array: []
        Return ONLY valid JSON — no commentary, no markdown fences.
        PROMPT;

        try {
            $response = $provider->chat(
                messages: [
                    new SystemMessage($systemPrompt),
                    new UserMessage("Extract memories from this conversation:\n\n{$transcript}"),
                ],
                tools: [],
            );

            $content = trim($response->content);

            // Strip markdown fences if present
            if (str_starts_with($content, '```')) {
                $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
                $content = preg_replace('/\s*```$/', '', $content) ?? $content;
            }

            $decoded = json_decode($content, true, 8, JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return [];
            }

            // Validate and filter
            $valid = [];
            foreach ($decoded as $item) {
                if (!is_array($item) || !isset($item['content']) || !is_string($item['content'])) {
                    continue;
                }
                if (mb_strlen($item['content']) < 10 || mb_strlen($item['content']) > 500) {
                    continue;
                }
                $valid[] = [
                    'content' => $item['content'],
                    'area' => is_string($item['area'] ?? null) ? $item['area'] : 'context',
                    'importance' => is_numeric($item['importance'] ?? null) ? (float) $item['importance'] : 0.5,
                    'tags' => is_string($item['tags'] ?? null) ? $item['tags'] : '',
                ];
            }

            return array_slice($valid, 0, self::MAX_EXTRACTIONS);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Check if a candidate memory is too similar to an existing one.
     */
    private function isDuplicate(string $content): bool
    {
        try {
            $existing = $this->memoryStore->search($content, limit: 3, threshold: 0.0);

            foreach ($existing as $entry) {
                if ($entry->score >= self::DEDUP_THRESHOLD) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function validateArea(string $area): string
    {
        $allowed = ['preferences', 'facts', 'solutions', 'context'];

        return in_array($area, $allowed, true) ? $area : 'context';
    }

    /**
     * Access the same DB as MemoryStore via reflection.
     */
    private function getDb(): \PDO
    {
        $reflection = new \ReflectionClass($this->memoryStore);
        $property = $reflection->getProperty('db');

        return $property->getValue($this->memoryStore);
    }
}
