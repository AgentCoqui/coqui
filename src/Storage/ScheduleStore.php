<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Exception\RequestBodyException;
use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use Cron\CronExpression;
use PDO;
use stdClass;

/**
 * SQLite-backed persistence for scheduled tasks.
 *
 * Stores cron-style schedule definitions that the ScheduleManager
 * evaluates on each tick. Supports one-shot (@once) and recurring
 * schedules with circuit-breaker logic for consecutive failures.
 *
 * Schedules have two possible sources:
 * - `system` (default) — created via agent tools, REPL, or API; fully mutable.
 * - `filesystem` — synced from workspace/schedules/*.json; read-only from app.
 */
final class ScheduleStore
{
    /** Schedule created via agent tools, REPL, or API. */
    public const string SOURCE_SYSTEM = 'system';

    /** Schedule synced from a workspace JSON file. */
    public const string SOURCE_FILESYSTEM = 'filesystem';

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
                cron TEXT NOT NULL,
                persona_id TEXT,
                action_kind TEXT NOT NULL DEFAULT 'turn',
                prompt TEXT,
                definition_name TEXT,
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
                source TEXT NOT NULL DEFAULT 'system',
                source_path TEXT,
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
     *
     * `$action` is the kind-discriminated union that fires each tick:
     *   - `{kind: 'turn', prompt}`            — dispatch a one-shot turn.
     *   - `{kind: 'loop', definition_name}`   — dispatch a loop definition.
     * It is validated at this boundary; an invalid union is a 422 rejection.
     *
     * @param array<string, mixed> $action
     * @throws RequestBodyException on an invalid action union.
     */
    public function create(
        string $name,
        string $scheduleExpression,
        array $action,
        ?string $personaId = null,
        string $role = 'orchestrator',
        int $maxIterations = 48,
        ?string $description = null,
        ?string $createdBy = null,
        string $timezone = 'UTC',
        int $maxFailures = 3,
        ?string $metadata = null,
    ): string {
        [$actionKind, $prompt, $definitionName] = $this->normalizeAction($action);

        $id = IdGenerator::hex();
        $now = Clock::nowUtc();

        $nextRunAt = $this->computeNextRun($scheduleExpression, $timezone);

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO scheduled_tasks
                (id, name, description, cron, persona_id, action_kind, prompt, definition_name,
                 role, max_iterations, enabled, created_by, timezone, next_run_at, max_failures,
                 metadata, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $id,
            $name,
            $description,
            $scheduleExpression,
            $personaId,
            $actionKind,
            $prompt,
            $definitionName,
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
     * Validate and decompose an `action` union into its persisted columns.
     *
     * The kind set is closed: `{turn, loop}`. A `turn` action MUST carry a
     * non-empty `prompt`; a `loop` action MUST carry a `definition_name`.
     * Anything else is a 422 `validation_error`.
     *
     * @param array<string, mixed> $action
     * @return array{0: string, 1: ?string, 2: ?string} [action_kind, prompt, definition_name]
     * @throws RequestBodyException
     */
    private function normalizeAction(array $action): array
    {
        $kind = $action['kind'] ?? null;

        if ($kind === 'turn') {
            $prompt = $action['prompt'] ?? null;
            if (!is_string($prompt) || trim($prompt) === '') {
                throw new RequestBodyException(
                    ApiErrorCode::VALIDATION_ERROR,
                    'A turn action requires a non-empty prompt.',
                    ['field' => 'action.prompt'],
                );
            }

            return ['turn', $prompt, null];
        }

        if ($kind === 'loop') {
            $definitionName = $action['definition_name'] ?? null;
            if (!is_string($definitionName) || trim($definitionName) === '') {
                throw new RequestBodyException(
                    ApiErrorCode::VALIDATION_ERROR,
                    'A loop action requires a definition_name.',
                    ['field' => 'action.definition_name'],
                );
            }

            return ['loop', null, $definitionName];
        }

        throw new RequestBodyException(
            ApiErrorCode::VALIDATION_ERROR,
            sprintf(
                'Unknown action kind "%s"; expected one of: turn, loop.',
                is_string($kind) ? $kind : gettype($kind),
            ),
            ['field' => 'action.kind'],
        );
    }

    /**
     * Create or update a filesystem-backed schedule.
     *
     * Only static definition fields are written. Runtime fields (last_run_at,
     * last_status, last_task_id, run_count, failure_count) are preserved on update.
     *
     * @return string The schedule ID
     */
    public function upsertFilesystem(
        string $name,
        string $sourcePath,
        string $scheduleExpression,
        string $prompt,
        string $role = 'orchestrator',
        int $maxIterations = 48,
        ?string $description = null,
        string $timezone = 'UTC',
        int $maxFailures = 3,
        bool $enabled = true,
        ?string $metadata = null,
    ): string {
        $existing = $this->getByName($name);
        $now = Clock::nowUtc();

        if ($existing !== null) {
            // Update only static definition columns, preserve runtime state
            $nextRun = $this->computeNextRun($scheduleExpression, $timezone);

            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE scheduled_tasks
                SET description = ?, cron = ?, prompt = ?, role = ?,
                    max_iterations = ?, timezone = ?, max_failures = ?, enabled = ?,
                    metadata = ?, source = ?, source_path = ?, next_run_at = ?, updated_at = ?
                WHERE id = ?
            SQL);
            $stmt->execute([
                $description,
                $scheduleExpression,
                $prompt,
                $role,
                $maxIterations,
                $timezone,
                $maxFailures,
                $enabled ? 1 : 0,
                $metadata,
                self::SOURCE_FILESYSTEM,
                $sourcePath,
                $nextRun?->format('Y-m-d\TH:i:s\Z'),
                $now,
                (string) $existing['id'],
            ]);

            return (string) $existing['id'];
        }

        // Create new filesystem schedule
        $id = IdGenerator::hex();
        $nextRunAt = $this->computeNextRun($scheduleExpression, $timezone);

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO scheduled_tasks
                (id, name, description, cron, prompt, role, max_iterations,
                 enabled, timezone, next_run_at, max_failures, metadata, source, source_path,
                 created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);

        $stmt->execute([
            $id,
            $name,
            $description,
            $scheduleExpression,
            $prompt,
            $role,
            $maxIterations,
            $enabled ? 1 : 0,
            $timezone,
            $nextRunAt?->format('Y-m-d\TH:i:s\Z'),
            $maxFailures,
            $metadata,
            self::SOURCE_FILESYSTEM,
            $sourcePath,
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * Delete all filesystem-backed schedules whose source_path is not in the given set.
     *
     * Used by the watcher to remove schedules for deleted files.
     *
     * @param list<string> $activePaths Source paths that still have files on disk
     * @return int Number of removed schedules
     */
    public function deleteRemovedFilesystemSchedules(array $activePaths): int
    {
        if ($activePaths === []) {
            // Remove all filesystem schedules
            $stmt = $this->db->prepare("DELETE FROM scheduled_tasks WHERE source = ?");
            $stmt->execute([self::SOURCE_FILESYSTEM]);

            return $stmt->rowCount();
        }

        $placeholders = implode(', ', array_fill(0, count($activePaths), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM scheduled_tasks WHERE source = ? AND source_path NOT IN ({$placeholders})"
        );
        $stmt->execute([self::SOURCE_FILESYSTEM, ...$activePaths]);

        return $stmt->rowCount();
    }

    /**
     * Check whether a schedule is filesystem-backed (read-only from app).
     */
    public function isFilesystemSchedule(string $id): bool
    {
        $stmt = $this->db->prepare('SELECT source FROM scheduled_tasks WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false && ($row['source'] ?? '') === self::SOURCE_FILESYSTEM;
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
     *
     * When `$action` is provided it is validated as the kind-discriminated
     * union and rewrites the `action_kind`/`prompt`/`definition_name` columns.
     *
     * @param array<string, mixed>|null $action
     * @throws RequestBodyException on an invalid action union.
     */
    public function update(
        string $id,
        ?string $name = null,
        ?string $description = null,
        ?string $scheduleExpression = null,
        ?array $action = null,
        ?string $personaId = null,
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
        $now = Clock::nowUtc();
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
            $sets[] = 'cron = ?';
            $params[] = $scheduleExpression;
        }

        if ($action !== null) {
            [$actionKind, $prompt, $definitionName] = $this->normalizeAction($action);
            $sets[] = 'action_kind = ?';
            $params[] = $actionKind;
            $sets[] = 'prompt = ?';
            $params[] = $prompt;
            $sets[] = 'definition_name = ?';
            $params[] = $definitionName;
        }

        if ($personaId !== null) {
            $sets[] = 'persona_id = ?';
            $params[] = $personaId;
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
        $expr = $scheduleExpression ?? $schedule['cron'];
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
     * Get schedules that have a last_task_id but no resolved last_status yet.
     *
     * Used by ScheduleManager to reconcile completed task statuses
     * back onto their parent schedule records.
     *
     * @return list<array<string, mixed>>
     */
    public function getSchedulesPendingReconciliation(): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM scheduled_tasks
            WHERE last_task_id IS NOT NULL
              AND last_status IS NULL
            ORDER BY last_run_at ASC
        SQL);

        $stmt->execute();

        return array_values($stmt->fetchAll(\PDO::FETCH_ASSOC));
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

        $now = Clock::nowUtc();
        $isOneShot = $schedule['cron'] === '@once';

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
                $schedule['cron'],
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
        $now = Clock::nowUtc();
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

        $now = Clock::nowUtc();
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

        $now = Clock::nowUtc();
        $nextRun = $this->computeNextRun($schedule['cron'], $schedule['timezone']);

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
        $now = Clock::nowUtc();
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
            SELECT id, name, description, cron, role, next_run_at, last_status
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
        $now = Clock::nowUtc();
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks SET next_run_at = ?, updated_at = ? WHERE id = ?
        SQL);
        $stmt->execute([$nextRunAt, $now, $id]);
    }

    /**
     * Delete all system schedules (excludes filesystem-backed).
     *
     * @return int Number of deleted schedules
     */
    public function deleteAll(): int
    {
        $stmt = $this->db->prepare("DELETE FROM scheduled_tasks WHERE source = ?");
        $stmt->execute([self::SOURCE_SYSTEM]);

        return $stmt->rowCount();
    }

    /**
     * Disable all enabled system schedules (excludes filesystem-backed).
     *
     * @return int Number of newly disabled schedules
     */
    public function disableAll(): int
    {
        $now = Clock::nowUtc();
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE scheduled_tasks SET enabled = 0, updated_at = ?
            WHERE enabled = 1 AND source = ?
        SQL);
        $stmt->execute([$now, self::SOURCE_SYSTEM]);

        return $stmt->rowCount();
    }

    /**
     * Enable all disabled system schedules with recomputed next_run_at.
     *
     * Loops per-row because each schedule has its own cron expression and timezone.
     * Excludes filesystem-backed schedules.
     *
     * @return int Number of newly enabled schedules
     */
    public function enableAll(): int
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM scheduled_tasks WHERE enabled = 0 AND source = ?'
        );
        $stmt->execute([self::SOURCE_SYSTEM]);
        $disabled = array_values($stmt->fetchAll(PDO::FETCH_ASSOC));

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
     * Project a persisted `scheduled_tasks` row onto the CAP 0.5.0
     * `scheduled-task.json` wire shape.
     *
     * `action` is a typed object discriminated by `kind`, built from the persisted
     * `action_kind` column: a `turn` action carries the stored `prompt`; a `loop`
     * action carries the stored `definition_name`. It is emitted as `stdClass` so an
     * object-typed schema slot never receives a JSON array. `status` is derived from
     * the `enabled` flag. Nullable timestamps pass through as their stored Z-suffixed
     * value or null. Only schema-declared properties are emitted
     * (`additionalProperties:false`-clean).
     *
     * A row with a null `persona_id` (runtime rows created before personas are
     * bound) intentionally serializes `persona_id:null`, which the schema rejects;
     * a schedule authored via the API always binds a persona.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $action = new stdClass();
        if (($row['action_kind'] ?? 'turn') === 'loop') {
            $action->kind = 'loop';
            $action->definition_name = (string) ($row['definition_name'] ?? '');
        } else {
            $action->kind = 'turn';
            $action->prompt = (string) ($row['prompt'] ?? '');
        }

        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'cron' => (string) $row['cron'],
            'persona_id' => $row['persona_id'] !== null ? (string) $row['persona_id'] : null,
            'action' => $action,
            'status' => ((int) $row['enabled']) === 1 ? 'enabled' : 'disabled',
            'last_run_at' => $row['last_run_at'] !== null ? (string) $row['last_run_at'] : null,
            'next_run_at' => $row['next_run_at'] !== null ? (string) $row['next_run_at'] : null,
            'created_at' => (string) $row['created_at'],
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
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
