<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use CoquiBot\Coqui\Support\SchemaHelper;
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
        // CAP 0.5.0 loops shape: circuit-breaker + dispatch diagnostics are
        // first-class columns (rework_attempts/dispatch_state/last_dispatch_error,
        // CORE-16), origin/persona_id are typed columns, and the protocol's Project
        // removal (D3) drops project_id. persona_id is intentionally NOT a foreign
        // key: the personas table is not runtime-populated, so under
        // PRAGMA foreign_keys=ON a RESTRICT reference would reject every insert.
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS loops (
                id TEXT PRIMARY KEY,
                definition_name TEXT NOT NULL,
                persona_id TEXT,
                session_id TEXT,
                goal TEXT NOT NULL,
                status TEXT NOT NULL DEFAULT 'running',
                current_iteration INTEGER NOT NULL DEFAULT 0,
                current_stage INTEGER NOT NULL DEFAULT 0,
                max_iterations INTEGER,
                deadline TEXT,
                termination_criteria TEXT,
                configuration TEXT,
                origin TEXT NOT NULL DEFAULT 'conversation',
                started_at TEXT NOT NULL,
                completed_at TEXT,
                last_activity_at TEXT,
                rework_attempts INTEGER NOT NULL DEFAULT 0,
                dispatch_state TEXT NOT NULL DEFAULT 'pending',
                last_dispatch_error TEXT,
                metadata TEXT,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS loop_iterations (
                id TEXT PRIMARY KEY,
                loop_id TEXT NOT NULL,
                iteration_number INTEGER NOT NULL,
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
        $this->migrateAddColumn('loop_stages', 'verdict', 'TEXT DEFAULT NULL');
    }

    private function migrateAddColumn(string $table, string $column, string $definition): void
    {
        SchemaHelper::addColumnIfMissing($this->db, $table, $column, $definition);
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
        ?string $personaId = null,
        ?int $maxIterations = null,
        ?string $deadline = null,
        ?string $terminationCriteria = null,
        ?array $metadata = null,
        string $origin = 'conversation',
    ): string {
        $id = IdGenerator::hex();
        $now = Clock::nowUtc();

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO loops (id, definition_name, persona_id, session_id, goal, status, current_iteration, current_stage, max_iterations, deadline, termination_criteria, configuration, origin, started_at, last_activity_at, metadata)
            VALUES (?, ?, ?, ?, ?, 'running', 0, 0, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $definitionName,
            $personaId,
            $sessionId,
            $goal,
            $maxIterations,
            $deadline,
            $terminationCriteria,
            json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $origin,
            $now,
            $now,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
        ]);

        return $id;
    }

    /**
     * Set the circuit-breaker rework counter (CORE-16). Authoritative column;
     * no longer stored in the metadata blob.
     */
    public function setReworkAttempts(string $id, int $attempts): void
    {
        $stmt = $this->db->prepare('UPDATE loops SET rework_attempts = ?, last_activity_at = ? WHERE id = ?');
        $stmt->execute([max(0, $attempts), Clock::nowUtc(), $id]);
    }

    /**
     * Set the dispatch diagnostic columns (CORE-16). `state` is the closed set
     * pending|dispatched; `error` is the last dispatch failure (null when healthy).
     * A failed dispatch is recorded as state='pending' with the error captured, so
     * a stuck loop is diagnosable while the enum stays closed.
     */
    public function setDispatchState(string $id, string $state, ?string $error = null): void
    {
        $normalized = $state === 'dispatched' ? 'dispatched' : 'pending';
        $stmt = $this->db->prepare('UPDATE loops SET dispatch_state = ?, last_dispatch_error = ?, last_activity_at = ? WHERE id = ?');
        $stmt->execute([$normalized, $error, Clock::nowUtc(), $id]);
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
     * List every loop bound to a session, in the same raw-row shape as getLoop.
     *
     * Used by SessionStorage::deleteSession to cascade-stop non-terminal loops
     * before their session row is removed (CORE-17).
     *
     * @return list<array<string, mixed>>
     */
    public function getLoopsBySession(string $sessionId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM loops WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        return array_values($stmt->fetchAll(PDO::FETCH_ASSOC));
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
        $now = Clock::nowUtc();
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
        $now = Clock::nowUtc();

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
        $now = Clock::nowUtc();

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
        $params = [Clock::nowUtc()];

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
    ): string {
        $id = IdGenerator::hex();

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO loop_iterations (id, loop_id, iteration_number, status)
            VALUES (?, ?, ?, 'pending')
        SQL);
        $stmt->execute([$id, $loopId, $iterationNumber]);

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
        $now = Clock::nowUtc();

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

    /**
     * Reset an iteration so it can be retried.
     */
    public function resetIterationForRetry(string $id): void
    {
        $now = Clock::nowUtc();

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE loop_iterations
            SET status = 'running', outcome_summary = NULL, started_at = ?, completed_at = NULL
            WHERE id = ?
        SQL);
        $stmt->execute([$now, $id]);
    }

    /**
     * Reopen an iteration without resetting its stage history.
     */
    public function reopenIteration(string $id): void
    {
        $now = Clock::nowUtc();

        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE loop_iterations
            SET status = 'running', outcome_summary = NULL, completed_at = NULL, started_at = COALESCE(started_at, ?)
            WHERE id = ?
        SQL);
        $stmt->execute([$now, $id]);
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
        $id = IdGenerator::hex();

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
     * The highest task_events.id produced across all of a loop's stage tasks,
     * or null when the loop has produced no events yet. Cheap activity cursor
     * for the loop events stream (single aggregate query, same PDO).
     */
    public function latestActivityId(string $loopId): ?int
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT MAX(te.id) AS max_id
            FROM loop_iterations li
            JOIN loop_stages ls ON ls.iteration_id = li.id
            JOIN task_events te ON te.task_id = ls.task_id
            WHERE li.loop_id = :loop_id
        SQL);
        $stmt->execute(['loop_id' => $loopId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (int) $value;
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
        $now = Clock::nowUtc();
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

    /**
     * Clear a stage's task link so it can be re-dispatched after orphan recovery.
     *
     * Uses a direct UPDATE because updateStage() COALESCEs task_id and therefore
     * cannot null it back out.
     */
    public function clearStageTask(string $stageId): void
    {
        $stmt = $this->db->prepare('UPDATE loop_stages SET task_id = NULL WHERE id = ?');
        $stmt->execute([$stageId]);
    }

    /**
     * Persist a stage's machine-readable verdict JSON without touching its status.
     */
    public function recordStageVerdict(string $stageId, string $verdictJson): void
    {
        $stmt = $this->db->prepare('UPDATE loop_stages SET verdict = ? WHERE id = ?');
        $stmt->execute([$verdictJson, $stageId]);
    }

    /**
     * Reset all stages for an iteration back to pending.
     */
    public function resetStagesForIteration(string $iterationId): void
    {
        $stmt = $this->db->prepare(<<<'SQL'
            UPDATE loop_stages
            SET task_id = NULL,
                artifact_id = NULL,
                metadata = NULL,
                status = 'pending',
                result_summary = NULL,
                verdict = NULL,
                started_at = NULL,
                completed_at = NULL
            WHERE iteration_id = ?
        SQL);
        $stmt->execute([$iterationId]);
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
     * Get timing data for each iteration of a loop (for API timing consumers).
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

    // ──────────────────────────────────────────────
    //  Conformance producer
    // ──────────────────────────────────────────────

    /**
     * Produce a strict CAP 0.5.0 `loop.json` wire object from a loop row (as
     * returned by {@see getLoop()}). Emits exactly the schema's property set so it
     * is `additionalProperties:false`-clean.
     *
     * The coqui column `started_at` maps to the wire field `created_at`. The
     * circuit-breaker + dispatch diagnostics come from their real columns
     * (rework_attempts/dispatch_state/last_dispatch_error), not the metadata blob.
     * Object fields (termination_criteria/configuration/metadata) emit as JSON
     * objects or null — never as a bare `[]`, which JSON would encode as an array.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $personaId = $row['persona_id'] ?? null;
        $sessionId = $row['session_id'] ?? null;
        $lastError = $row['last_dispatch_error'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'definition_name' => (string) ($row['definition_name'] ?? ''),
            'persona_id' => is_string($personaId) && $personaId !== '' ? $personaId : null,
            'session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
            'goal' => (string) ($row['goal'] ?? ''),
            'status' => (string) ($row['status'] ?? 'running'),
            'current_iteration' => (int) ($row['current_iteration'] ?? 0),
            'current_stage' => (int) ($row['current_stage'] ?? 0),
            'max_iterations' => isset($row['max_iterations'])
                ? (int) $row['max_iterations']
                : null,
            'deadline' => self::wireTimestamp($row['deadline'] ?? null),
            'termination_criteria' => self::wireObject($row['termination_criteria'] ?? null, 'criteria'),
            'configuration' => self::wireObject($row['configuration'] ?? null),
            'origin' => (string) ($row['origin'] ?? 'conversation'),
            'created_at' => self::wireTimestamp($row['started_at'] ?? null) ?? Clock::nowUtc(),
            'completed_at' => self::wireTimestamp($row['completed_at'] ?? null),
            'last_activity_at' => self::wireTimestamp($row['last_activity_at'] ?? null),
            'rework_attempts' => max(0, (int) ($row['rework_attempts'] ?? 0)),
            'dispatch_state' => ((string) ($row['dispatch_state'] ?? 'pending')) === 'dispatched' ? 'dispatched' : 'pending',
            'last_dispatch_error' => is_string($lastError) && $lastError !== '' ? $lastError : null,
            'metadata' => self::wireObject($row['metadata'] ?? null),
        ];
    }

    /**
     * Normalize a stored timestamp to RFC-3339 UTC (Z), or null when absent.
     */
    private static function wireTimestamp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * Coerce a stored value into a JSON object (stdClass) or null for a wire
     * field typed `object|null`. A JSON string decoding to an object is emitted
     * verbatim; a bare non-JSON string is wrapped under $wrapKey when given (so a
     * plain termination-criteria string still satisfies the object shape).
     */
    private static function wireObject(mixed $value, ?string $wrapKey = null): ?\stdClass
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return (object) $value;
        }

        if ($value instanceof \stdClass) {
            return $value;
        }

        if (is_string($value)) {
            $decoded = json_decode($value, false);
            if ($decoded instanceof \stdClass) {
                return $decoded;
            }

            if ($wrapKey !== null) {
                $wrapped = new \stdClass();
                $wrapped->{$wrapKey} = $value;

                return $wrapped;
            }
        }

        return null;
    }
}
