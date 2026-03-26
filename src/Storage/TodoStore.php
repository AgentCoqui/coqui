<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed todo persistence.
 *
 * Todos are session-scoped task items that agents create and manage
 * during planning and implementation workflows. Each todo can optionally
 * link to an artifact (plan → checklist traceability) and supports
 * single-level subtask hierarchy via parent_id.
 */
final class TodoStore
{
    private PDO $db;

    /**
     * @param PDO $db Shared PDO connection (from SessionStorage::getPdo())
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS todos (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                artifact_id TEXT,
                parent_id TEXT,
                title TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                priority TEXT NOT NULL DEFAULT 'medium',
                created_by TEXT,
                completed_by TEXT,
                notes TEXT,
                sort_order INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                completed_at TEXT,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                FOREIGN KEY (artifact_id) REFERENCES artifacts(id) ON DELETE SET NULL,
                FOREIGN KEY (parent_id) REFERENCES todos(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_todos_session ON todos(session_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_todos_artifact ON todos(artifact_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_todos_status ON todos(session_id, status)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_todos_parent ON todos(parent_id)
        SQL);

        // Harness column — added to existing installations via migration
        $this->migrateAddColumn('todos', 'sprint_id', 'TEXT');
    }

    private function migrateAddColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->db->query("PRAGMA table_info({$table})");

        if ($stmt === false) {
            return;
        }

        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $exists = array_any($columns, fn(array $col): bool => $col['name'] === $column);

        if (!$exists) {
            $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    /**
     * Create a new todo item.
     */
    public function create(
        string $sessionId,
        string $title,
        string $priority = 'medium',
        ?string $artifactId = null,
        ?string $parentId = null,
        ?string $createdBy = null,
        ?string $notes = null,
        ?int $sortOrder = null,
        ?string $sprintId = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        // Auto-assign sort order if not specified
        if ($sortOrder === null) {
            $sortOrder = $this->nextSortOrder($sessionId, $artifactId);
        }

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO todos (id, session_id, artifact_id, parent_id, title, status, priority, created_by, notes, sort_order, sprint_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $sessionId,
            $artifactId,
            $parentId,
            $title,
            $priority,
            $createdBy,
            $notes,
            $sortOrder,
            $sprintId,
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * Get a single todo by ID.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM todos WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Update a todo's fields. Only non-null parameters are applied.
     */
    public function update(
        string $id,
        ?string $title = null,
        ?string $priority = null,
        ?string $notes = null,
        ?string $status = null,
    ): bool {
        $todo = $this->get($id);
        if ($todo === null) {
            return false;
        }

        $sets = ['updated_at = ?'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $params = [$now];

        if ($title !== null) {
            $sets[] = 'title = ?';
            $params[] = $title;
        }

        if ($priority !== null) {
            $sets[] = 'priority = ?';
            $params[] = $priority;
        }

        if ($notes !== null) {
            $sets[] = 'notes = ?';
            $params[] = $notes;
        }

        if ($status !== null) {
            $sets[] = 'status = ?';
            $params[] = $status;

            if ($status === 'completed' && $todo['status'] !== 'completed') {
                $sets[] = 'completed_at = ?';
                $params[] = $now;
            } elseif ($status !== 'completed' && $todo['status'] === 'completed') {
                // Reverting from completed — clear completion metadata
                $sets[] = 'completed_at = NULL';
                $sets[] = 'completed_by = NULL';
            }
        }

        $params[] = $id;
        $sql = 'UPDATE todos SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    /**
     * Mark a todo as completed.
     */
    public function complete(string $id, ?string $completedBy = null, ?string $notes = null): bool
    {
        $todo = $this->get($id);
        if ($todo === null) {
            return false;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');

        $sets = ["status = 'completed'", 'completed_at = ?', 'updated_at = ?'];
        $params = [$now, $now];

        if ($completedBy !== null) {
            $sets[] = 'completed_by = ?';
            $params[] = $completedBy;
        }

        if ($notes !== null) {
            $sets[] = 'notes = ?';
            $params[] = $notes;
        }

        $params[] = $id;
        $sql = 'UPDATE todos SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    /**
     * Delete a todo and its subtasks (via CASCADE).
     */
    public function delete(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM todos WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * List todos for a session with optional filters.
     *
     * @return list<array<string, mixed>>
     */
    public function list(
        string $sessionId,
        ?string $artifactId = null,
        ?string $status = null,
        ?string $priority = null,
        ?string $parentId = null,
        bool $includeCompleted = true,
        int $limit = 100,
    ): array {
        $where = ['session_id = ?'];
        $params = [$sessionId];

        if ($artifactId !== null) {
            $where[] = 'artifact_id = ?';
            $params[] = $artifactId;
        }

        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        if ($priority !== null) {
            $where[] = 'priority = ?';
            $params[] = $priority;
        }

        if ($parentId !== null) {
            $where[] = 'parent_id = ?';
            $params[] = $parentId;
        } else {
            // By default, list only top-level todos
            $where[] = 'parent_id IS NULL';
        }

        if (!$includeCompleted && $status === null) {
            $where[] = "status != 'completed'";
        }

        $params[] = $limit;
        $sql = 'SELECT * FROM todos WHERE ' . implode(' AND ', $where)
            . ' ORDER BY sort_order ASC, created_at ASC LIMIT ?';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List all todos linked to a specific artifact.
     *
     * @return list<array<string, mixed>>
     */
    public function listByArtifact(string $artifactId, bool $includeSubtasks = true): array
    {
        if ($includeSubtasks) {
            $stmt = $this->db->prepare(
                'SELECT * FROM todos WHERE artifact_id = ? ORDER BY sort_order ASC, created_at ASC',
            );
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM todos WHERE artifact_id = ? AND parent_id IS NULL ORDER BY sort_order ASC, created_at ASC',
            );
        }

        $stmt->execute([$artifactId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List all todos linked to a specific sprint.
     *
     * @return list<array<string, mixed>>
     */
    public function listBySprint(string $sprintId, bool $includeSubtasks = true): array
    {
        if ($includeSubtasks) {
            $stmt = $this->db->prepare(
                'SELECT * FROM todos WHERE sprint_id = ? ORDER BY sort_order ASC, created_at ASC',
            );
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM todos WHERE sprint_id = ? AND parent_id IS NULL ORDER BY sort_order ASC, created_at ASC',
            );
        }

        $stmt->execute([$sprintId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get progress stats for a sprint's todos.
     *
     * @return array{total: int, pending: int, in_progress: int, completed: int, cancelled: int}
     */
    public function getSprintStats(string $sprintId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM todos WHERE sprint_id = ?
        SQL);
        $stmt->execute([$sprintId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'in_progress' => (int) ($row['in_progress'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
        ];
    }

    /**
     * Get subtasks for a parent todo.
     *
     * @return list<array<string, mixed>>
     */
    public function getSubtasks(string $parentId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM todos WHERE parent_id = ? ORDER BY sort_order ASC, created_at ASC',
        );
        $stmt->execute([$parentId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get progress stats for a session or artifact.
     *
     * @return array{total: int, pending: int, in_progress: int, completed: int, cancelled: int}
     */
    public function getStats(string $sessionId, ?string $artifactId = null): array
    {
        $where = ['session_id = ?'];
        $params = [$sessionId];

        if ($artifactId !== null) {
            $where[] = 'artifact_id = ?';
            $params[] = $artifactId;
        }

        $whereClause = implode(' AND ', $where);
        $stmt = $this->db->prepare(<<<SQL
            SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM todos WHERE {$whereClause}
        SQL);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'in_progress' => (int) ($row['in_progress'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
        ];
    }

    /**
     * Reorder todos within a session/artifact scope.
     *
     * @param array<string, int> $ordering Map of todo ID → new sort_order
     */
    public function reorder(array $ordering): void
    {
        $stmt = $this->db->prepare('UPDATE todos SET sort_order = ?, updated_at = ? WHERE id = ?');
        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($ordering as $id => $order) {
            $stmt->execute([$order, $now, $id]);
        }
    }

    /**
     * Bulk-create multiple todos in a single transaction.
     *
     * @param list<array{title: string, priority?: string, notes?: string}> $items
     * @return list<string> Created todo IDs
     */
    public function bulkCreate(
        string $sessionId,
        array $items,
        ?string $createdBy = null,
        ?string $artifactId = null,
        ?string $sprintId = null,
    ): array {
        $ids = [];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $baseSortOrder = $this->nextSortOrder($sessionId, $artifactId);

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(<<<'SQL'
                INSERT INTO todos (id, session_id, artifact_id, parent_id, title, status, priority, created_by, notes, sort_order, sprint_id, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?)
            SQL);

            foreach ($items as $i => $item) {
                $id = bin2hex(random_bytes(16));
                $stmt->execute([
                    $id,
                    $sessionId,
                    $artifactId,
                    null,
                    $item['title'],
                    $item['priority'] ?? 'medium',
                    $createdBy,
                    $item['notes'] ?? null,
                    $baseSortOrder + $i,
                    $sprintId,
                    $now,
                    $now,
                ]);
                $ids[] = $id;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return $ids;
    }

    /**
     * Bulk-update multiple todos in a single transaction.
     *
     * @param list<array{id: string, title?: string, status?: string, priority?: string, notes?: string}> $updates
     * @return int Number of successfully updated todos
     */
    public function bulkUpdate(array $updates): int
    {
        $count = 0;
        $this->db->beginTransaction();

        try {
            foreach ($updates as $update) {
                $id = $update['id'];
                if ($id === '') {
                    continue;
                }

                $updated = $this->update(
                    id: $id,
                    title: $update['title'] ?? null,
                    priority: $update['priority'] ?? null,
                    notes: $update['notes'] ?? null,
                    status: $update['status'] ?? null,
                );

                if ($updated) {
                    $count++;
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return $count;
    }

    /**
     * Delete todos whose session no longer exists in the sessions table.
     *
     * Handles orphaned todos that survive session deletion when FK CASCADE
     * is not enforced (e.g. across different PDO connections or after
     * database restoration).
     *
     * @return int Number of orphaned todos deleted
     */
    public function cleanupOrphaned(): int
    {
        $stmt = $this->db->query(<<<'SQL'
            DELETE FROM todos
            WHERE session_id NOT IN (SELECT id FROM sessions)
        SQL);

        return $stmt !== false ? $stmt->rowCount() : 0;
    }

    /**
     * Delete stale completed/cancelled todos from inactive sessions.
     *
     * Removes todos that are:
     * - completed or cancelled
     * - completed more than $staleDays ago
     * - in sessions not updated within $inactiveDays
     *
     * Active sessions (recently updated) are never touched.
     *
     * @return int Number of stale todos deleted
     */
    public function cleanupStale(int $staleDays = 30, int $inactiveDays = 7): int
    {
        $stmt = $this->db->prepare(<<<'SQL'
            DELETE FROM todos
            WHERE status IN ('completed', 'cancelled')
              AND completed_at IS NOT NULL
              AND completed_at < datetime('now', :stale_offset)
              AND session_id NOT IN (
                  SELECT id FROM sessions
                  WHERE updated_at > datetime('now', :inactive_offset)
              )
        SQL);
        $stmt->execute([
            ':stale_offset' => "-{$staleDays} days",
            ':inactive_offset' => "-{$inactiveDays} days",
        ]);

        return $stmt->rowCount();
    }

    /**
     * Delete all todos for a session.
     *
     * @return int Number of todos deleted
     */
    public function deleteBySession(string $sessionId): int
    {
        $stmt = $this->db->prepare('DELETE FROM todos WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        return $stmt->rowCount();
    }

    /**
     * Delete all completed and cancelled todos for a session.
     *
     * @return int Number of todos deleted
     */
    public function deleteCompletedBySession(string $sessionId): int
    {
        $stmt = $this->db->prepare(
            "DELETE FROM todos WHERE session_id = ? AND status IN ('completed', 'cancelled')",
        );
        $stmt->execute([$sessionId]);

        return $stmt->rowCount();
    }

    /**
     * Mark all pending and in_progress todos as completed for a session.
     *
     * @return int Number of todos completed
     */
    public function completeAllBySession(string $sessionId, ?string $completedBy = null): int
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE todos
            SET status = 'completed', completed_by = ?, completed_at = ?, updated_at = ?
            WHERE session_id = ? AND status IN ('pending', 'in_progress')
        SQL);
        $stmt->execute([$completedBy, $now, $now, $sessionId]);

        return $stmt->rowCount();
    }

    /**
     * Find a todo by ID prefix within a session.
     *
     * Returns the todo if exactly one match is found, null otherwise.
     * Use for REPL partial-ID matching.
     *
     * @return array{todo: array<string, mixed>, ambiguous: bool, candidates: list<array<string, mixed>>}|null
     */
    public function findByPrefix(string $sessionId, string $prefix): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM todos WHERE session_id = ? AND id LIKE ? ORDER BY sort_order ASC',
        );
        $stmt->execute([$sessionId, $prefix . '%']);
        /** @var list<array<string, mixed>> $candidates */
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) === 1) {
            return ['todo' => $candidates[0], 'ambiguous' => false, 'candidates' => $candidates];
        }

        // Check for exact match first
        foreach ($candidates as $candidate) {
            if ($candidate['id'] === $prefix) {
                return ['todo' => $candidate, 'ambiguous' => false, 'candidates' => $candidates];
            }
        }

        return ['todo' => $candidates[0], 'ambiguous' => true, 'candidates' => $candidates];
    }

    /**
     * Calculate the next sort order value for a new todo.
     */
    private function nextSortOrder(string $sessionId, ?string $artifactId): int
    {
        if ($artifactId !== null) {
            $stmt = $this->db->prepare(
                'SELECT MAX(sort_order) FROM todos WHERE session_id = ? AND artifact_id = ?',
            );
            $stmt->execute([$sessionId, $artifactId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT MAX(sort_order) FROM todos WHERE session_id = ? AND artifact_id IS NULL',
            );
            $stmt->execute([$sessionId]);
        }

        $max = $stmt->fetchColumn();

        return $max !== false && $max !== null ? ((int) $max) + 1 : 0;
    }
}
