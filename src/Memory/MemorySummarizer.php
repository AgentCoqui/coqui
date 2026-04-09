<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Memory;

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;

/**
 * Generates compressed summaries of core memories for system prompt injection.
 *
 * Uses a cheap LLM call to distill many memory entries into a compact paragraph
 * that fits within the system prompt. The summary is cached in SQLite and only
 * regenerated when the memory count changes.
 */
final class MemorySummarizer
{
    public function __construct(
        private readonly MemoryStore $memoryStore,
    ) {}

    /**
     * Get the core memory summary for system prompt injection.
     *
     * Returns cached summary if memory count hasn't changed, otherwise
     * generates a fresh one from the raw entries. If no provider is
     * available, returns the raw compact summary from MemoryStore.
     */
    public function getSummary(?ProviderInterface $provider = null, int $maxTokens = 500): string
    {
        $currentCount = $this->memoryStore->count();

        if ($currentCount === 0) {
            return '';
        }

        // Check cached summary
        $cached = $this->getCachedSummary();
        $currentVersion = $this->memoryStore->getCacheVersion();

        if ($cached !== null && $cached['memory_count'] === $currentCount && $cached['cache_version'] === $currentVersion) {
            return $cached['summary'];
        }

        // Generate new summary
        $rawSummary = $this->memoryStore->getCoreSummary(limit: 50);

        if ($rawSummary === '') {
            return '';
        }

        // If a provider is available, use LLM to compress the summary
        if ($provider !== null) {
            $compressed = $this->compressWithLlm($provider, $rawSummary, $maxTokens);

            if ($compressed !== '') {
                $this->cacheSummary($compressed, $currentCount, $currentVersion);
                return $compressed;
            }
        }

        // Fall back to the raw summary (no LLM compression)
        $this->cacheSummary($rawSummary, $currentCount, $currentVersion);

        return $rawSummary;
    }

    /**
     * Force regeneration of the cached summary.
     */
    public function invalidate(): void
    {
        $this->memoryStore->count(); // ensure tables exist
        $rawSummary = $this->memoryStore->getCoreSummary(limit: 50);
        $this->cacheSummary($rawSummary, $this->memoryStore->count(), $this->memoryStore->getCacheVersion());
    }

    /**
     * Use a cheap LLM to compress raw memory entries into a concise summary.
     */
    private function compressWithLlm(ProviderInterface $provider, string $rawSummary, int $maxTokens): string
    {
        try {
            $systemPrompt = <<<PROMPT
            You are a memory summarizer. Compress the following user memories into a concise, 
            information-dense summary. Prioritize high-importance items first — user preferences, 
            key facts, and critical project knowledge must appear early in the summary. Solutions 
            and context follow. Use bullet points grouped by category. Keep it under {$maxTokens} 
            tokens. Do not add commentary — output ONLY the summary.
            PROMPT;

            $response = $provider->chat(
                messages: [
                    new SystemMessage($systemPrompt),
                    new UserMessage("Summarize these memories:\n\n{$rawSummary}"),
                ],
                tools: [],
            );

            return trim($response->content);
        } catch (\Throwable) {
            return ''; // Fall back to raw summary
        }
    }

    /**
     * @return array{summary: string, memory_count: int, cache_version: int}|null
     */
    private function getCachedSummary(): ?array
    {
        try {
            $db = $this->getDb();
            $stmt = $db->query('SELECT summary, memory_count, cache_version FROM memory_summary WHERE id = 1');

            if ($stmt === false) {
                return null;
            }

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            return $row !== false ? [
                'summary' => $row['summary'],
                'memory_count' => (int) $row['memory_count'],
                'cache_version' => (int) ($row['cache_version'] ?? 0),
            ] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheSummary(string $summary, int $memoryCount, int $cacheVersion): void
    {
        try {
            $db = $this->getDb();
            $now = (new \DateTimeImmutable())->format('Y-m-d\TH:i:s');

            $db->prepare(<<<SQL
                INSERT OR REPLACE INTO memory_summary (id, summary, memory_count, cache_version, generated_at)
                VALUES (1, :summary, :count, :cache_version, :generated_at)
            SQL)->execute([
                ':summary' => $summary,
                ':count' => $memoryCount,
                ':cache_version' => $cacheVersion,
                ':generated_at' => $now,
            ]);
        } catch (\Throwable) {
            // Cache failure is non-fatal
        }
    }

    /**
     * Access the same DB as the MemoryStore via reflection.
     *
     * The MemoryStore owns the PDO connection and table creation.
     * We access it via the public methods, but for the summary cache
     * table (already created by MemoryStore::ensureTables), we need
     * a direct PDO reference.
     */
    private function getDb(): \PDO
    {
        // Use reflection to access the private PDO — keeps MemoryStore's API clean
        $reflection = new \ReflectionClass($this->memoryStore);
        $property = $reflection->getProperty('db');

        return $property->getValue($this->memoryStore);
    }
}
