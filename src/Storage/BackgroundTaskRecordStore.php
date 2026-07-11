<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\CoquiProcessChecker;
use CoquiBot\Coqui\Support\IdGenerator;
use PDO;

/**
 * Persistence for background-task records, events, and inputs.
 *
 * Extracted from SessionStorage to keep that class focused on session,
 * message, and turn state. Shares the same PDO connection (and process
 * checker) so the public SessionStorage API can delegate to it without
 * any change to callers.
 */
final class BackgroundTaskRecordStore
{
    public function __construct(
        private readonly PDO $db,
        private readonly CoquiProcessChecker $processChecker,
    ) {}

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function createTask(
        string $sessionId,
        string $prompt,
        string $role = 'orchestrator',
        ?string $parentSessionId = null,
        ?string $title = null,
        int $maxIterations = 25,
        ?string $toolName = null,
        ?string $toolArguments = null,
        ?string $scheduleId = null,
        int $maxExecutionSeconds = 3600,
        ?string $projectId = null,
        ?array $metadata = null,
    ): string {
        $id = IdGenerator::hex();
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO background_tasks (id, session_id, parent_session_id, status, title, prompt, role, metadata, max_iterations, tool_name, tool_arguments, schedule_id, max_execution_seconds, project_id, created_at)
            VALUES (:id, :session_id, :parent_session_id, 'pending', :title, :prompt, :role, :metadata, :max_iterations, :tool_name, :tool_arguments, :schedule_id, :max_execution_seconds, :project_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'parent_session_id' => $parentSessionId,
            'title' => $title,
            'prompt' => $prompt,
            'role' => $role,
            'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            'max_iterations' => $maxIterations,
            'tool_name' => $toolName,
            'tool_arguments' => $toolArguments,
            'schedule_id' => $scheduleId,
            'max_execution_seconds' => $maxExecutionSeconds,
            'project_id' => $projectId,
            'created_at' => $now,
        ]);

        return $id;
    }

    /**
     * Update task status and optionally set result/error/PID fields.
     *
     * @param array<string, mixed> $extra Additional columns to update (result, error, pid)
     */
    public function updateTaskStatus(string $taskId, string $status, array $extra = []): void
    {
        $sets = ['status = :status'];
        $params = ['status' => $status, 'id' => $taskId];

        $now = date('c');

        // Auto-set timestamp columns based on status transition
        match ($status) {
            'running' => $sets[] = 'started_at = :started_at',
            'completed', 'failed' => $sets[] = 'completed_at = :completed_at',
            'cancelled' => $sets[] = 'cancelled_at = :cancelled_at',
            default => null,
        };

        if ($status === 'running') {
            $params['started_at'] = $now;
        } elseif ($status === 'completed' || $status === 'failed') {
            $params['completed_at'] = $now;
        } elseif ($status === 'cancelled') {
            $params['cancelled_at'] = $now;
        }

        foreach ($extra as $col => $val) {
            if (in_array($col, ['result', 'error', 'pid'], true)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $val;
            }
        }

        $setClause = implode(', ', $sets);

        $stmt = $this->db->prepare("UPDATE background_tasks SET {$setClause} WHERE id = :id");
        $stmt->execute($params);
    }

    /**
     * Conditionally update task status — only if current status matches expected.
     *
     * Prevents race condition where parent overwrites a status the child already committed.
     *
     * @param array<string, mixed> $extra Additional columns to update (result, error, pid)
     * @return bool True if a row was updated
     */
    public function updateTaskStatusConditional(string $taskId, string $newStatus, string $expectedCurrentStatus, array $extra = []): bool
    {
        $sets = ['status = :new_status'];
        $params = ['new_status' => $newStatus, 'expected_status' => $expectedCurrentStatus, 'id' => $taskId];

        $now = date('c');

        match ($newStatus) {
            'running' => $sets[] = 'started_at = :started_at',
            'completed', 'failed' => $sets[] = 'completed_at = :completed_at',
            'cancelled' => $sets[] = 'cancelled_at = :cancelled_at',
            default => null,
        };

        if ($newStatus === 'running') {
            $params['started_at'] = $now;
        } elseif ($newStatus === 'completed' || $newStatus === 'failed') {
            $params['completed_at'] = $now;
        } elseif ($newStatus === 'cancelled') {
            $params['cancelled_at'] = $now;
        }

        foreach ($extra as $col => $val) {
            if (in_array($col, ['result', 'error', 'pid'], true)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $val;
            }
        }

        $setClause = implode(', ', $sets);

        $stmt = $this->db->prepare("UPDATE background_tasks SET {$setClause} WHERE id = :id AND status = :expected_status");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Update the heartbeat timestamp for a running task.
     */
    public function updateTaskHeartbeat(string $taskId): void
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE background_tasks SET last_heartbeat_at = :now WHERE id = :id AND status = 'running'
        SQL);
        $stmt->execute(['now' => date('c'), 'id' => $taskId]);
    }

    /**
     * Get running tasks whose heartbeat has gone stale (>5 minutes since last heartbeat).
     *
     * Only returns tasks that have a heartbeat set (excludes tasks that never started heartbeating).
     *
     * @return array<array<string, mixed>>
     */
    public function getStaleRunningTasks(int $staleThresholdSeconds = 300): array
    {
        $cutoff = date('c', time() - $staleThresholdSeconds);

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, pid, started_at, last_heartbeat_at
            FROM background_tasks
            WHERE status = 'running'
              AND last_heartbeat_at IS NOT NULL
              AND last_heartbeat_at < :cutoff
        SQL);
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get running tasks that have exceeded their max execution time.
     *
     * @return array<array<string, mixed>>
     */
    public function getTimedOutRunningTasks(): array
    {
        $now = time();

        $stmt = $this->db->query(<<<SQL
            SELECT id, pid, started_at, max_execution_seconds
            FROM background_tasks
            WHERE status = 'running'
              AND started_at IS NOT NULL
              AND max_execution_seconds > 0
        SQL);

        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter in PHP since SQLite date arithmetic is limited
        return array_values(array_filter($rows, function (array $row) use ($now): bool {
            $started = strtotime($row['started_at']);

            return $started !== false && ($now - $started) > (int) $row['max_execution_seconds'];
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTask(string $id): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM background_tasks WHERE id = :id
        SQL);

        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Find the most recent task for a given schedule.
     *
     * @return array<string, mixed>|null
     */
    public function getTaskByScheduleId(string $scheduleId): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM background_tasks WHERE schedule_id = :schedule_id ORDER BY created_at DESC LIMIT 1
        SQL);

        $stmt->execute(['schedule_id' => $scheduleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * List background task runs for a schedule.
     *
     * @return array<array<string, mixed>>
     */
    public function listTasksForSchedule(string $scheduleId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, parent_session_id, pid, status, title, prompt, role,
                   metadata, result, error, max_iterations, schedule_id, project_id, sprint_id,
                   created_at, started_at, completed_at, cancelled_at
            FROM background_tasks
            WHERE schedule_id = :schedule_id
            ORDER BY created_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue('schedule_id', $scheduleId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find the most recent task created by a specific automation notification.
     *
     * @param list<string> $statuses
     * @return array<string, mixed>|null
     */
    public function findTaskByAutomationNotificationId(
        string $notificationId,
        array $statuses = ['pending', 'running', 'completed'],
    ): ?array {
        if ($statuses === []) {
            return null;
        }

        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare(<<<SQL
            SELECT *
            FROM background_tasks
            WHERE metadata IS NOT NULL
              AND json_extract(metadata, '$.automation.notification_id') = ?
              AND status IN ({$statusPlaceholders})
            ORDER BY created_at DESC
            LIMIT 1
        SQL);

        $params = [$notificationId, ...$statuses];
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Find the most recent task with a given title, optionally filtered by role and status.
     *
     * @param list<string> $statuses
     * @return array<string, mixed>|null
     */
    public function findRecentTaskByTitle(
        string $title,
        ?string $role = null,
        array $statuses = ['pending', 'running', 'completed'],
        int $lookbackHours = 24,
    ): ?array {
        if ($statuses === []) {
            return null;
        }

        $cutoff = date('c', time() - ($lookbackHours * 3600));
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
        $roleClause = $role !== null ? 'AND role = ?' : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT *
            FROM background_tasks
            WHERE title = ?
              {$roleClause}
              AND status IN ({$statusPlaceholders})
              AND created_at >= ?
            ORDER BY created_at DESC
            LIMIT 1
        SQL);

        $params = [$title];
        if ($role !== null) {
            $params[] = $role;
        }
        $params = [...$params, ...$statuses, $cutoff];

        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Get all tasks with a specific status.
     *
     * @return array<array<string, mixed>>
     */
    public function getTasksByStatus(string $status): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM background_tasks WHERE status = :status ORDER BY created_at ASC
        SQL);

        $stmt->execute(['status' => $status]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List background tasks with optional status filter.
     *
     * @return array<array<string, mixed>>
     */
    public function listTasks(?string $status = null, int $limit = 50): array
    {
        $where = '';
        $params = [];

        if ($status !== null && $status !== 'all') {
            $where = 'WHERE status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, parent_session_id, pid, status, title, prompt, role,
                   metadata, max_iterations, created_at, started_at, completed_at, cancelled_at
            FROM background_tasks
            {$where}
            ORDER BY created_at DESC
            LIMIT :limit
        SQL);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Append an event to the task event log.
     */
    public function appendTaskEvent(string $taskId, string $eventType, mixed $data = null): void
    {
        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO task_events (task_id, event_type, data, created_at)
            VALUES (:task_id, :event_type, :data, :created_at)
        SQL);

        $stmt->execute([
            'task_id' => $taskId,
            'event_type' => $eventType,
            'data' => json_encode($data ?? new \stdClass(), JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => date('c'),
        ]);
    }

    /**
     * Get task events, optionally starting after a given event ID.
     *
     * @return array<array<string, mixed>>
     */
    public function getTaskEvents(string $taskId, ?int $sinceId = null, int $limit = 100): array
    {
        $where = 'task_id = :task_id';
        $params = ['task_id' => $taskId];

        if ($sinceId !== null) {
            $where .= ' AND id > :since_id';
            $params['since_id'] = $sinceId;
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, event_type, data, created_at
            FROM task_events
            WHERE {$where}
            ORDER BY id ASC
            LIMIT :limit
        SQL);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get the most recent task events, newest-first.
     *
     * Unlike {@see getTaskEvents()} (oldest-first, cursor-friendly), this
     * returns the newest N events for read-models that surface current
     * activity regardless of how many events a task has produced.
     *
     * @return array<array<string, mixed>>
     */
    public function getRecentTaskEvents(string $taskId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, event_type, data, created_at
            FROM task_events
            WHERE task_id = :task_id
            ORDER BY id DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue('task_id', $taskId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add pending user input for a running task.
     */
    public function addTaskInput(string $taskId, string $content): string
    {
        $id = IdGenerator::hex();

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO task_inputs (id, task_id, content, consumed, created_at)
            VALUES (:id, :task_id, :content, 0, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'task_id' => $taskId,
            'content' => $content,
            'created_at' => date('c'),
        ]);

        return $id;
    }

    /**
     * Consume all unconsumed inputs for a task.
     *
     * Marks them as consumed atomically and returns the content strings.
     *
     * @return string[]
     */
    public function consumeTaskInputs(string $taskId): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(<<<SQL
                SELECT id, content FROM task_inputs
                WHERE task_id = :task_id AND consumed = 0
                ORDER BY created_at ASC
            SQL);
            $stmt->execute(['task_id' => $taskId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                $this->db->commit();
                return [];
            }

            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->db->prepare("UPDATE task_inputs SET consumed = 1 WHERE id IN ({$placeholders})");
            $update->execute($ids);

            $this->db->commit();

            return array_column($rows, 'content');
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark orphaned tasks as failed — only those whose process is actually dead.
     *
     * Checks each running/cancelling task's PID with posix_kill($pid, 0) before
     * marking it failed. Tasks with no PID or a dead PID are considered orphaned.
     */
    public function markOrphanedTasksFailed(string $error = 'Server restarted — task process was lost'): int
    {
        $stmt = $this->db->query(<<<SQL
            SELECT id, pid FROM background_tasks WHERE status IN ('running', 'cancelling')
        SQL);

        if ($stmt === false) {
            return 0;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        $now = date('c');

        $update = $this->db->prepare(<<<SQL
            UPDATE background_tasks SET status = 'failed', error = :error, completed_at = :now WHERE id = :id
        SQL);

        foreach ($rows as $row) {
            $pid = (int) ($row['pid'] ?? 0);

            // Only keep the task if the PID is alive AND still belongs to Coqui task:run.
            if ($this->processChecker->isExpectedCoquiProcessAlive($pid, 'task:run')) {
                continue;
            }

            $update->execute(['error' => $error, 'now' => $now, 'id' => $row['id']]);
            $count++;
        }

        return $count;
    }

    /**
     * Get pending tasks ordered by creation time (FIFO).
     *
     * @return array<array<string, mixed>>
     */
    public function getPendingTasks(int $limit = 10): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, prompt, role, max_iterations, title
            FROM background_tasks
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT :limit
        SQL);

        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a session belongs to a background task.
     */
    public function isTaskSession(string $sessionId): bool
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT COUNT(*) FROM background_tasks WHERE session_id = :session_id
        SQL);
        $stmt->execute(['session_id' => $sessionId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get the count of tasks by status.
     *
     * @return array<string, int>
     */
    public function getTaskCounts(): array
    {
        $stmt = $this->db->query(<<<SQL
            SELECT status, COUNT(*) as count FROM background_tasks GROUP BY status
        SQL);

        if ($stmt === false) {
            return [];
        }

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Purge old task events for tasks in terminal states.
     *
     * Deletes events older than the given number of days for tasks
     * that are completed, failed, or cancelled. Running/pending
     * task events are never purged.
     *
     * @return int Number of events deleted
     */
    public function purgeOldTaskEvents(int $maxAgeDays = 7): int
    {
        $cutoff = date('c', time() - ($maxAgeDays * 86400));

        $stmt = $this->db->prepare(<<<SQL
            DELETE FROM task_events
            WHERE task_id IN (
                SELECT id FROM background_tasks WHERE status IN ('completed', 'failed', 'cancelled')
            )
            AND created_at < :cutoff
        SQL);

        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Get active (running + pending) background tasks for footer rendering.
     *
     * Returns a lightweight result set with only the columns needed by
     * BackgroundTaskSummary. Ordered by creation time (oldest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveBackgroundSummary(): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, status, title, role, tool_name, started_at, created_at
            FROM background_tasks
            WHERE status IN ('running', 'pending')
            ORDER BY created_at ASC
        SQL);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
