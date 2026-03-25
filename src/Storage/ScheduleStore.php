<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use Cron\CronExpression;
use PDO;

/**
 * SQLite-backed persistence for scheduled tasks.
 *
 * Stores cron-style schedule definitions that the ScheduleManager
 * evaluates on each tick. Supports one-shot (@once) and recurring
 * schedules with circuit-breaker logic for consecutive failures.
 */
final class ScheduleStore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS scheduled_tasks (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                schedule_expression TEXT NOT NULL,
                prompt TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'orchestrator',
                max_iterations INTEGER NOT NULL DEFAULT 48,
                enabled INTEGER NOT NULL DEFAULT 1,
                created_by TEXT,
                timezone TEXT NOT NULL DEFAULT 'UTC',
                next_run_at TEXT,
                last_run_at TEXT,
                last_task_id TEXT,
                last_status TEXT,
                run_count INTEGER NOT NULL DEFAULT 0,
                failure_count INTEGER NOT NULL DEFAULT 0,
                max_failures INTEGER NOT NULL DEFAULT 3,
                metadata TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_scheduled_tasks_enabled_next
                ON scheduled_tasks(enabled, next_run_at)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_scheduled_tasks_name
                ON scheduled_tasks(name)
        SQL);
    }

    /**
     * Create a new schedule.
     */
    public function create(
        string $name,
        string $scheduleExpression,
        string $prompt,
        string $role = 'orchestrator',
        int $maxIterations = 48,
        ?string $description = null,
        ?string $createdBy = null,
        string $timezone = 'UTC',
        int $maxFailures = 3,
        ?string $metadata = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $nextRunAt = $this->computeNextRun($scheduleExpression, $timezone);

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO scheduled_tasks
                (id, name, description, schedule_expression, prompt, role, max_iterations,
                 enabled, created_by, timezone, next_run_at, max_failures, metadata, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $id,
            $name,
            $description,
            $scheduleExpression,
            $prompt,
            $role,
            $maxIterations,
            $createdBy,
            $timezone,
            $nextRunAt?->format('Y-m-d\TH:i:s\Z'),
            $maxFailures,
            $metadata,
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * Get a single schedule by ID.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scheduled_tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Get a schedule by name.
     *
     * @return array<string, mixed>|null
     */
    public function getByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM scheduled_tasks WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Update a schedule's fields. Only non-null parameters are applied.
     */
    public function update(
        string $id,
        ?string $name = null,
        ?string $description = null,
        ?string $scheduleExpression = null,
        ?string $prompt = null,
        ?string $role = null,
        ?int $maxIterations = null,
        ?bool $enabled = null,
        ?string $timezone = null,
        ?int $maxFailures = null,
        ?string $metadata = null,
    ): bool {
        $schedule = $this->get($id);
        if ($schedule === null) {
            return false;
        }

        $sets = ['updated_at = ?'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $params = [$now];

        if ($name !== null) {
            $sets[] = 'name = ?';
            $params[] = $name;
        }

        if ($description !== null) {
            $sets[] = 'description = ?';
            $params[] = $description;
        }

        if ($scheduleExpression !== null) {
            $sets[] = 'schedule_expression = ?';
            $params[] = $scheduleExpression;
        }

        if ($prompt !== null) {
            $sets[] = 'prompt = ?';
            $params[] = $prompt;
        }

        if ($role !== null) {
            $sets[] = 'role = ?';
            $params[] = $role;
        }

        if ($maxIterations !== null) {
            $sets[] = 'max_iterations = ?';
            $params[] = $maxIterations;
        }

        if ($enabled !== null) {
            $sets[] = 'enabled = ?';
            $params[] = $enabled ? 1 : 0;
        }

        if ($timezone !== null) {
            $sets[] = 'timezone = ?';
            $params[] = $timezone;
        }

        if ($maxFailures !== null) {
            $sets[] = 'max_failures = ?';
            $params[] = $maxFailures;
        }

        if ($metadata !== null) {
            $sets[] = 'metadata = ?';
            $params[] = $metadata;
        }

        // Recompute next_run_at if expression or timezone changed
        $expr = $scheduleExpression ?? $schedule['schedule_expression'];
        $tz = $timezone ?? $schedule['timezone'];
        $nextRun = $this->computeNextRun($expr, $tz);
        if ($nextRun !== null) {
            $sets[] = 'next_run_at = ?';
            $params[] = $nextRun->format('Y-m-d\TH:i:s\Z');
        }

        $params[] = $id;
        $sql = 'UPDATE scheduled_tasks SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    /**
     * Delete a schedule.
     */
    public function delete(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM scheduled_tasks WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * List schedules with optional filters.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * @return list<array<string, mixed>>
     */
    public function list(
        ?bool $enabled = null,
        ?string $createdBy = null,
        int $limit = 100,
    ): array {
        $where = [];
        $params = [];

        if ($enabled !== null) {
            $where[] = 'enabled = ?';
            $params[] = $enabled ? 1 : 0;
        }

        if ($createdBy !== null) {
            $where[] = 'created_by = ?';
            $params[] = $createdBy;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM scheduled_tasks
            {$whereClause}
            ORDER BY next_run_at ASC NULLS LAST, created_at ASC
            LIMIT ?
        SQL);

        $params[] = $limit;
        $stmt->execute($params);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get all enabled schedules that are due for execution.
     *
     * @return list<array<string, mixed>>
     */
    public function getReadySchedules(\DateTimeImmutable $now): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM scheduled_tasks
            WHERE enabled = 1
              AND next_run_at IS NOT NULL
              AND next_run_at <= ?
            ORDER BY next_run_at ASC
        SQL);

        $stmt->execute([$now->format('Y-m-d\TH:i:s\Z')]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Mark a schedule as executed and compute its next run time.
     *
     * For one-shot schedules (@once), the schedule is disabled after execution.
     */
    public function markExecuted(string $id, string $taskId): void
    {
        $schedule = $this->get($id);
        if ($schedule === null) {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $isOneShot = $schedule['schedule_expression'] === '@once';

        if ($isOneShot) {
            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE scheduled_tasks
                SET last_run_at = ?, last_task_id = ?, run_count = run_count + 1,
                    last_status = NULL, enabled = 0, next_run_at = NULL, updated_at = ?
                WHERE id = ?
            SQL);
            $stmt->execute([$now, $taskId, $now, $id]);
        } else {
            $nextRun = $this->computeNextRun(
                $schedule['schedule_expression'],
                $schedule['timezone'],
            );

            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE scheduled_tasks
                SET last_run_at = ?, last_task_id = ?, run_count = run_count + 1,
                    last_status = NULL, next_run_at = ?, updated_at = ?
                WHERE id = ?
            SQL);
            $stmt->execute([
                $now,
                $taskId,
                $nextRun?->format('Y-m-d\TH:i:s\Z'),
                $now,
                $id,
            ]);
        }
    }

    /**
     * Record a successful execution — reset failure counter.
     */
    public function markSuccess(string $id): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks
            SET failure_count = 0, last_status = 'completed', updated_at = ?
            WHERE id = ?
        SQL);
        $stmt->execute([$now, $id]);
    }

    /**
     * Record a failed execution. If consecutive failures exceed max_failures,
     * the schedule is auto-disabled (circuit breaker).
     *
     * @return bool True if the schedule was auto-disabled
     */
    public function markFailed(string $id): bool
    {
        $schedule = $this->get($id);
        if ($schedule === null) {
            return false;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $newFailureCount = (int) $schedule['failure_count'] + 1;
        $shouldDisable = $newFailureCount >= (int) $schedule['max_failures'];

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks
            SET failure_count = ?, last_status = 'failed',
                enabled = ?, updated_at = ?
            WHERE id = ?
        SQL);
        $stmt->execute([
            $newFailureCount,
            $shouldDisable ? 0 : 1,
            $now,
            $id,
        ]);

        return $shouldDisable;
    }

    /**
     * Enable a schedule.
     */
    public function enable(string $id): bool
    {
        $schedule = $this->get($id);
        if ($schedule === null) {
            return false;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $nextRun = $this->computeNextRun($schedule['schedule_expression'], $schedule['timezone']);

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks
            SET enabled = 1, failure_count = 0, next_run_at = ?, updated_at = ?
            WHERE id = ?
        SQL);
        $stmt->execute([$nextRun?->format('Y-m-d\TH:i:s\Z'), $now, $id]);

        return true;
    }

    /**
     * Disable a schedule.
     */
    public function disable(string $id): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks
            SET enabled = 0, updated_at = ?
            WHERE id = ?
        SQL);
        $stmt->execute([$now, $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get aggregate stats for schedules.
     *
     * @return array{total: int, enabled: int, disabled: int, total_runs: int}
     */
    public function getStats(): array
    {
        $stmt = $this->db->query(<<<'SQL'
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN enabled = 1 THEN 1 ELSE 0 END) AS enabled,
                SUM(CASE WHEN enabled = 0 THEN 1 ELSE 0 END) AS disabled,
                SUM(run_count) AS total_runs
            FROM scheduled_tasks
        SQL);

        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return [
            'total' => (int) ($row['total'] ?? 0),
            'enabled' => (int) ($row['enabled'] ?? 0),
            'disabled' => (int) ($row['disabled'] ?? 0),
            'total_runs' => (int) ($row['total_runs'] ?? 0),
        ];
    }

    /**
     * Get schedules that are due in the next N hours (for guidelines display).
     *
     * @return list<array<string, mixed>>
     */
    public function getUpcoming(int $hours = 24): array
    {
        $future = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$hours} hours");

        $stmt = $this->db->prepare(<<<'SQL'
            SELECT id, name, description, schedule_expression, role, next_run_at, last_status
            FROM scheduled_tasks
            WHERE enabled = 1
              AND next_run_at IS NOT NULL
              AND next_run_at <= ?
            ORDER BY next_run_at ASC
            LIMIT 20
        SQL);

        $stmt->execute([$future->format('Y-m-d\TH:i:s\Z')]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Force the next_run_at to a specific time (used for manual triggers).
     */
    public function forceNextRun(string $id, string $nextRunAt): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks SET next_run_at = ?, updated_at = ? WHERE id = ?
        SQL);
        $stmt->execute([$nextRunAt, $now, $id]);
    }

    /**
     * Delete all schedules.
     *
     * @return int Number of deleted schedules
     */
    public function deleteAll(): int
    {
        $count = $this->db->exec('DELETE FROM scheduled_tasks');

        return $count !== false ? $count : 0;
    }

    /**
     * Disable all enabled schedules.
     *
     * @return int Number of newly disabled schedules
     */
    public function disableAll(): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks SET enabled = 0, updated_at = ? WHERE enabled = 1
        SQL);
        $stmt->execute([$now]);

        return $stmt->rowCount();
    }

    /**
     * Enable all disabled schedules with recomputed next_run_at.
     *
     * Loops per-row because each schedule has its own cron expression and timezone.
     *
     * @return int Number of newly enabled schedules
     */
    public function enableAll(): int
    {
        $disabled = $this->list(enabled: false);
        $count = 0;

        foreach ($disabled as $schedule) {
            $this->enable((string) $schedule['id']);
            $count++;
        }

        return $count;
    }

    /**
     * Compute the next run time for a cron expression.
     *
     * Returns null for invalid expressions or @once (which has no next run).
     */
    public function computeNextRun(string $expression, string $timezone = 'UTC'): ?\DateTimeImmutable
    {
        if ($expression === '@once') {
            // One-shot: run immediately (next_run_at = now)
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        if (!CronExpression::isValidExpression($expression)) {
            return null;
        }

        try {
            $tz = new \DateTimeZone($timezone);
            $cron = new CronExpression($expression);
            $next = $cron->getNextRunDate('now', 0, false, $tz->getName());

            // Convert to UTC for storage
            return \DateTimeImmutable::createFromMutable($next)
                ->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Validate a cron expression.
     */
    public static function isValidExpression(string $expression): bool
    {
        if ($expression === '@once') {
            return true;
        }

        return CronExpression::isValidExpression($expression);
    }
}
