<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed persistence for loop instances, iterations, and stages.
 *
 * Shares the same PDO instance as other stores (SessionStorage, ArtifactStore, etc.).
 * Three tables track the full lifecycle of automated loop workflows.
 */
final class LoopStore
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
            CREATE TABLE IF NOT EXISTS loops (
                id TEXT PRIMARY KEY,
                definition_name TEXT NOT NULL,
                session_id TEXT,
                project_id TEXT,
                goal TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'running',
                current_iteration INTEGER NOT NULL DEFAULT 0,
                current_stage INTEGER NOT NULL DEFAULT 0,
                max_iterations INTEGER,
                deadline TEXT,
                termination_criteria TEXT,
                configuration TEXT NOT NULL,
                started_at TEXT NOT NULL,
                completed_at TEXT,
                last_activity_at TEXT,
                metadata TEXT
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS loop_iterations (
                id TEXT PRIMARY KEY,
                loop_id TEXT NOT NULL,
                iteration_number INTEGER NOT NULL,
                sprint_id TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                outcome_summary TEXT,
                started_at TEXT,
                completed_at TEXT,
                FOREIGN KEY (loop_id) REFERENCES loops(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS loop_stages (
                id TEXT PRIMARY KEY,
                iteration_id TEXT NOT NULL,
                stage_index INTEGER NOT NULL,
                role TEXT NOT NULL,
                task_id TEXT,
                artifact_id TEXT,
                metadata TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                result_summary TEXT,
                started_at TEXT,
                completed_at TEXT,
                FOREIGN KEY (iteration_id) REFERENCES loop_iterations(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_loops_status ON loops(status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_loop_iterations_loop ON loop_iterations(loop_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_loop_stages_iteration ON loop_stages(iteration_id)');

        $this->migrateAddColumn('loop_stages', 'metadata', 'TEXT DEFAULT NULL');
    }

    private function migrateAddColumn(string $table, string $column, string $definition): void
    {
        $stmt = $this->db->query("PRAGMA table_info({$table})");
        if ($stmt === false) {
            return;
        }

        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $exists = array_any($columns, static fn(array $col): bool => $col['name'] === $column);
        if ($exists) {
            return;
        }

        $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }

    // ──────────────────────────────────────────────
    //  Loop CRUD
    // ──────────────────────────────────────────────

    /**
     * Create a new loop instance.
     *
     * @param array<string, mixed> $configuration Snapshot of the loop definition at creation time
     * @param array<string, mixed>|null $metadata  Optional extra metadata
     */
    public function createLoop(
        string $definitionName,
        string $goal,
        array $configuration,
        ?string $sessionId = null,
        ?string $projectId = null,
        ?int $maxIterations = null,
        ?string $deadline = null,
        ?string $terminationCriteria = null,
        ?array $metadata = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO loops (id, definition_name, session_id, project_id, goal, status, current_iteration, current_stage, max_iterations, deadline, termination_criteria, configuration, started_at, last_activity_at, metadata)
            VALUES (?, ?, ?, ?, ?, 'running', 0, 0, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $definitionName,
            $sessionId,
            $projectId,
            $goal,
            $maxIterations,
            $deadline,
            $terminationCriteria,
            json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $now,
            $now,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLoop(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM loops WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List loops, optionally filtered by status.
     *
     * @return list<array<string, mixed>>
     */
    public function listLoops(?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->db->prepare('SELECT * FROM loops WHERE status = ? ORDER BY started_at DESC');
            $stmt->execute([$status]);
        } else {
            $stmt = $this->db->query('SELECT * FROM loops ORDER BY started_at DESC');
            if ($stmt === false) {
                return [];
            }
        }

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Update a loop's status and optional completion timestamp.
     */
    public function updateLoopStatus(string $id, string $status): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $completedAt = in_array($status, ['completed', 'failed', 'cancelled'], true) ? $now : null;

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE loops SET status = ?, completed_at = COALESCE(?, completed_at), last_activity_at = ? WHERE id = ?
        SQL);
        $stmt->execute([$status, $completedAt, $now, $id]);
    }

    /**
     * Advance the loop's current iteration and stage counters.
     */
    public function updateLoopProgress(string $id, int $iteration, int $stage): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE loops SET current_iteration = ?, current_stage = ?, last_activity_at = ? WHERE id = ?
        SQL);
        $stmt->execute([$iteration, $stage, $now, $id]);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public function updateLoopMetadata(string $id, array $patch): void
    {
        $loop = $this->getLoop($id);
        if ($loop === null) {
            return;
        }

        $existing = [];
        if (is_string($loop['metadata'] ?? null) && $loop['metadata'] !== '') {
            $decoded = json_decode($loop['metadata'], true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        $merged = array_replace_recursive($existing, $patch);
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare('UPDATE loops SET metadata = ?, last_activity_at = ? WHERE id = ?');
        $stmt->execute([
            json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $now,
            $id,
        ]);
    }

    /**
     * Update first-class loop fields without replacing metadata.
     *
     * Supported keys: goal, max_iterations.
     *
     * @param array<string, mixed> $patch
     */
    public function updateLoop(string $id, array $patch): bool
    {
        $loop = $this->getLoop($id);
        if ($loop === null) {
            return false;
        }

        $sets = ['last_activity_at = ?'];
        $params = [gmdate('Y-m-d\TH:i:s\Z')];

        if (array_key_exists('goal', $patch)) {
            $sets[] = 'goal = ?';
            $params[] = $patch['goal'];
        }

        if (array_key_exists('max_iterations', $patch)) {
            $sets[] = 'max_iterations = ?';
            $params[] = $patch['max_iterations'];
        }

        if (count($sets) === 1) {
            return true;
        }

        $params[] = $id;

        $stmt = $this->db->prepare('UPDATE loops SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    // ──────────────────────────────────────────────
    //  Iteration CRUD
    // ──────────────────────────────────────────────

    /**
     * Create a new iteration record for a loop.
     */
    public function createIteration(
        string $loopId,
        int $iterationNumber,
        ?string $sprintId = null,
    ): string {
        $id = bin2hex(random_bytes(16));

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO loop_iterations (id, loop_id, iteration_number, sprint_id, status)
            VALUES (?, ?, ?, ?, 'pending')
        SQL);
        $stmt->execute([$id, $loopId, $iterationNumber, $sprintId]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getIteration(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM loop_iterations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List iterations for a loop, ordered by iteration number.
     *
     * @return list<array<string, mixed>>
     */
    public function listIterations(string $loopId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM loop_iterations WHERE loop_id = ? ORDER BY iteration_number ASC');
        $stmt->execute([$loopId]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Update iteration status with optional timestamps.
     */
    public function updateIterationStatus(string $id, string $status, ?string $outcomeSummary = null): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        if ($status === 'running') {
            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE loop_iterations SET status = ?, started_at = COALESCE(started_at, ?) WHERE id = ?
            SQL);
            $stmt->execute([$status, $now, $id]);
        } else {
            $completedAt = in_array($status, ['completed', 'failed', 'needs_rework'], true) ? $now : null;

            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE loop_iterations SET status = ?, outcome_summary = COALESCE(?, outcome_summary), completed_at = COALESCE(?, completed_at) WHERE id = ?
            SQL);
            $stmt->execute([$status, $outcomeSummary, $completedAt, $id]);
        }
    }

    // ──────────────────────────────────────────────
    //  Stage CRUD
    // ──────────────────────────────────────────────

    /**
     * Create a new stage record for an iteration.
     */
    public function createStage(
        string $iterationId,
        int $stageIndex,
        string $role,
    ): string {
        $id = bin2hex(random_bytes(16));

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO loop_stages (id, iteration_id, stage_index, role, status)
            VALUES (?, ?, ?, ?, 'pending')
        SQL);
        $stmt->execute([$id, $iterationId, $stageIndex, $role]);

        return $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getStage(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM loop_stages WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List stages for an iteration, ordered by stage index.
     *
     * @return list<array<string, mixed>>
     */
    public function listStages(string $iterationId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM loop_stages WHERE iteration_id = ? ORDER BY stage_index ASC');
        $stmt->execute([$iterationId]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Update stage status with optional result data.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function updateStage(
        string $id,
        string $status,
        ?string $taskId = null,
        ?string $artifactId = null,
        ?string $resultSummary = null,
        ?array $metadata = null,
    ): void {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $metadataJson = $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null;

        if ($status === 'running') {
            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE loop_stages SET status = ?, task_id = COALESCE(?, task_id), metadata = COALESCE(?, metadata), started_at = COALESCE(started_at, ?) WHERE id = ?
            SQL);
            $stmt->execute([$status, $taskId, $metadataJson, $now, $id]);
        } else {
            $completedAt = in_array($status, ['completed', 'failed'], true) ? $now : null;

            $stmt = $this->db->prepare(<<<'SQL'
                UPDATE loop_stages SET status = ?, task_id = COALESCE(?, task_id), artifact_id = COALESCE(?, artifact_id), metadata = COALESCE(?, metadata), result_summary = COALESCE(?, result_summary), completed_at = COALESCE(?, completed_at) WHERE id = ?
            SQL);
            $stmt->execute([$status, $taskId, $artifactId, $metadataJson, $resultSummary, $completedAt, $id]);
        }
    }

    // ──────────────────────────────────────────────
    //  Composite Queries
    // ──────────────────────────────────────────────

    /**
     * Get the current state of a loop: loop record + current iteration + its stages.
     *
     * @return array{loop: array<string, mixed>, iteration: array<string, mixed>|null, stages: list<array<string, mixed>>}|null
     */
    public function getCurrentState(string $loopId): ?array
    {
        $loop = $this->getLoop($loopId);
        if ($loop === null) {
            return null;
        }

        // Find the current (latest) iteration
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM loop_iterations WHERE loop_id = ? ORDER BY iteration_number DESC LIMIT 1
        SQL);
        $stmt->execute([$loopId]);
        $iteration = $stmt->fetch(PDO::FETCH_ASSOC);

        $stages = [];
        if ($iteration !== false) {
            $stages = $this->listStages($iteration['id']);
        }

        return [
            'loop' => $loop,
            'iteration' => $iteration !== false ? $iteration : null,
            'stages' => $stages,
        ];
    }

    /**
     * Get all completed stage results for an iteration (for context building).
     *
     * @return list<array<string, mixed>>
     */
    public function getCompletedStages(string $iterationId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM loop_stages WHERE iteration_id = ? AND status = 'completed' ORDER BY stage_index ASC
        SQL);
        $stmt->execute([$iterationId]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get outcome summaries from all previous iterations (for accumulated context).
     *
     * @return list<array{iteration_number: int, outcome_summary: string|null, status: string}>
     */
    public function getPreviousOutcomes(string $loopId, int $beforeIteration): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT iteration_number, outcome_summary, status
            FROM loop_iterations
            WHERE loop_id = ? AND iteration_number < ?
            ORDER BY iteration_number ASC
        SQL);
        $stmt->execute([$loopId, $beforeIteration]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get timing data for each iteration of a loop (for sparkline visualization).
     *
     * @return list<array{iteration: int, duration_seconds: float, stage_count: int, completed_stages: int}>
     */
    public function getIterationTimings(string $loopId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT
                i.iteration_number,
                i.started_at,
                i.completed_at,
                COUNT(s.id) AS stage_count,
                SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) AS completed_stages
            FROM loop_iterations i
            LEFT JOIN loop_stages s ON s.iteration_id = i.id
            WHERE i.loop_id = ?
            GROUP BY i.id
            ORDER BY i.iteration_number ASC
        SQL);
        $stmt->execute([$loopId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $timings = [];
        foreach ($rows as $row) {
            $duration = 0.0;
            if ($row['started_at'] !== null) {
                try {
                    $start = new \DateTimeImmutable($row['started_at']);
                    $end = $row['completed_at'] !== null
                        ? new \DateTimeImmutable($row['completed_at'])
                        : new \DateTimeImmutable('now');
                    $duration = max(0.0, (float) ($end->getTimestamp() - $start->getTimestamp()));
                } catch (\Throwable) {
                    // Invalid timestamp — leave duration at 0
                }
            }

            $timings[] = [
                'iteration' => (int) $row['iteration_number'],
                'duration_seconds' => $duration,
                'stage_count' => (int) $row['stage_count'],
                'completed_stages' => (int) $row['completed_stages'],
            ];
        }

        return $timings;
    }

    /**
     * Count active (running) loops.
     */
    public function countActive(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM loops WHERE status = 'running'");
        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Delete a loop and all its iterations/stages (CASCADE).
     */
    public function deleteLoop(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM loops WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }
}
