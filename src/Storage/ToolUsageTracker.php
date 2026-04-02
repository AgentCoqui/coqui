<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

/**
 * Aggregates tool usage frequency from the turns.tools_used column.
 *
 * Provides in-memory cached frequency maps for loading priority decisions.
 * Uses SQLite json_each() to efficiently aggregate tool names from the
 * JSON-encoded tools_used arrays stored per turn.
 */
final class ToolUsageTracker
{
    private const int CACHE_TTL_SECONDS = 300;

    /** @var array<string, int> tool_name => usage_count, sorted descending */
    private array $frequencyMap = [];

    private ?float $cachedAt = null;

    public function __construct(
        private readonly \PDO $db,
    ) {}

    /**
     * Get the full frequency map: tool_name => usage_count, sorted descending.
     *
     * @return array<string, int>
     */
    public function getFrequencyMap(): array
    {
        $this->ensureFresh();

        return $this->frequencyMap;
    }

    /**
     * Get the N most frequently used tool names.
     *
     * @return list<string>
     */
    public function getTopTools(int $limit = 10): array
    {
        $this->ensureFresh();

        return array_slice(array_keys($this->frequencyMap), 0, $limit);
    }

    /**
     * Aggregate tool usage by toolkit.
     *
     * Accepts a map of toolkit class basename => list of tool names belonging
     * to that toolkit. Returns toolkit => aggregate usage count, sorted descending.
     *
     * @param array<string, list<string>> $toolkitToolMap toolkit basename => tool names
     * @return array<string, int> toolkit basename => aggregate usage count
     */
    public function getToolkitFrequency(array $toolkitToolMap): array
    {
        $this->ensureFresh();

        $result = [];
        foreach ($toolkitToolMap as $toolkit => $toolNames) {
            $total = 0;
            foreach ($toolNames as $name) {
                $total += $this->frequencyMap[$name] ?? 0;
            }
            $result[$toolkit] = $total;
        }

        arsort($result);

        return $result;
    }

    /**
     * Get usage count for a single tool.
     */
    public function getToolUsageCount(string $toolName): int
    {
        $this->ensureFresh();

        return $this->frequencyMap[$toolName] ?? 0;
    }

    /**
     * Force a cache refresh from the database.
     */
    public function refresh(): void
    {
        $this->cachedAt = null;
        $this->ensureFresh();
    }

    private function ensureFresh(): void
    {
        if ($this->cachedAt !== null && (microtime(true) - $this->cachedAt) < self::CACHE_TTL_SECONDS) {
            return;
        }

        $this->frequencyMap = $this->queryFrequencies();
        $this->cachedAt = microtime(true);
    }

    /**
     * @return array<string, int>
     */
    private function queryFrequencies(): array
    {
        try {
            $stmt = $this->db->query(<<<'SQL'
                SELECT json_each.value AS tool_name, COUNT(*) AS usage_count
                FROM turns
                CROSS JOIN json_each(turns.tools_used)
                WHERE tools_used IS NOT NULL AND tools_used != '[]'
                GROUP BY tool_name
                ORDER BY usage_count DESC
                SQL);

            if ($stmt === false) {
                return [];
            }

            $map = [];
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $map[(string) $row['tool_name']] = (int) $row['usage_count'];
            }

            return $map;
        } catch (\Throwable) {
            return [];
        }
    }
}
