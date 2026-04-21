<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Immutable snapshot of active background tasks for footer rendering.
 *
 * Pre-separates agent tasks (full LLM loop) from tool tasks (direct execution)
 * so the renderer can display them on distinct lines. Built from a lightweight
 * SessionStorage query after each agent turn.
 */
final readonly class BackgroundTaskSummary
{
    /**
     * @param array<int, array{id: string, status: string, title: ?string, role: string, started_at: ?string, created_at: string}> $agents Agent tasks (tool_name IS NULL).
     * @param array<int, array{id: string, status: string, title: ?string, tool_name: string, started_at: ?string, created_at: string}> $tools  Tool tasks (tool_name IS NOT NULL).
     */
    public function __construct(
        public array $agents = [],
        public array $tools = [],
    ) {}

    /**
     * Build from raw database rows, separating agents from tools.
     *
     * @param array<int, array<string, mixed>> $rows Rows from getActiveBackgroundSummary().
     */
    public static function fromRows(array $rows): self
    {
        $agents = [];
        $tools = [];

        foreach ($rows as $row) {
            $toolName = $row['tool_name'] ?? null;

            if (is_string($toolName) && $toolName !== '') {
                $tools[] = [
                    'id' => (string) $row['id'],
                    'status' => (string) $row['status'],
                    'title' => isset($row['title']) ? (string) $row['title'] : null,
                    'tool_name' => $toolName,
                    'started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
                    'created_at' => (string) $row['created_at'],
                ];
            } else {
                $agents[] = [
                    'id' => (string) $row['id'],
                    'status' => (string) $row['status'],
                    'title' => isset($row['title']) ? (string) $row['title'] : null,
                    'role' => (string) ($row['role'] ?? 'orchestrator'),
                    'started_at' => isset($row['started_at']) ? (string) $row['started_at'] : null,
                    'created_at' => (string) $row['created_at'],
                ];
            }
        }

        return new self($agents, $tools);
    }

    public function isEmpty(): bool
    {
        return $this->agents === [] && $this->tools === [];
    }

    public function totalCount(): int
    {
        return count($this->agents) + count($this->tools);
    }

    public function agentCount(): int
    {
        return count($this->agents);
    }

    public function toolCount(): int
    {
        return count($this->tools);
    }

    /**
     * Format elapsed duration for a task as a human-readable string.
     *
     * Running tasks show elapsed time from started_at. Pending tasks show "pending".
     *
     * @param array{status: string, started_at: ?string, created_at: string} $task
     */
    public static function formatDuration(array $task): string
    {
        if ($task['status'] === 'pending') {
            return 'pending';
        }

        $reference = $task['started_at'] ?? $task['created_at'];

        try {
            $start = new \DateTimeImmutable($reference);
        } catch (\Throwable) {
            return 'unknown';
        }

        $now = new \DateTimeImmutable();
        $seconds = max(0, $now->getTimestamp() - $start->getTimestamp());

        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            $minutes = (int) floor($seconds / 60);
            $secs = $seconds % 60;
            return $secs > 0 ? "{$minutes}m {$secs}s" : "{$minutes}m";
        }

        $hours = (int) floor($seconds / 3600);
        $minutes = (int) floor(($seconds % 3600) / 60);
        return $minutes > 0 ? "{$hours}h {$minutes}m" : "{$hours}h";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'agents' => array_map(fn(array $a): array => [
                'id' => $a['id'],
                'status' => $a['status'],
                'title' => $a['title'],
                'role' => $a['role'],
                'started_at' => $a['started_at'],
                'created_at' => $a['created_at'],
            ], $this->agents),
            'tools' => array_map(fn(array $t): array => [
                'id' => $t['id'],
                'status' => $t['status'],
                'title' => $t['title'],
                'tool_name' => $t['tool_name'],
                'started_at' => $t['started_at'],
                'created_at' => $t['created_at'],
            ], $this->tools),
            'total_count' => $this->totalCount(),
        ];
    }
}
