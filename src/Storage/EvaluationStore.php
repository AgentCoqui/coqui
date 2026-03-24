<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed evaluation report persistence.
 *
 * Stores structured evaluation reports for completed sessions, including
 * numeric scores for completion, hallucination detection, and tool efficiency.
 * Provides efficient queries for finding unevaluated sessions via LEFT JOIN.
 */
final class EvaluationStore
{
    public function __construct(
        private readonly PDO $db,
    ) {
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS evaluations (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                evaluator_task_id TEXT,
                overall_grade TEXT NOT NULL,
                score_completion REAL NOT NULL,
                score_hallucination REAL NOT NULL,
                score_efficiency REAL NOT NULL,
                overall_score REAL NOT NULL,
                report TEXT NOT NULL,
                model TEXT,
                metadata TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_evaluations_session ON evaluations(session_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_evaluations_grade ON evaluations(overall_grade)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_evaluations_created ON evaluations(created_at)
        SQL);
    }

    /**
     * Create a new evaluation report.
     */
    public function create(
        string $sessionId,
        string $overallGrade,
        float $scoreCompletion,
        float $scoreHallucination,
        float $scoreEfficiency,
        float $overallScore,
        string $report,
        ?string $model = null,
        ?string $evaluatorTaskId = null,
        ?array $metadata = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO evaluations (id, session_id, evaluator_task_id, overall_grade, score_completion, score_hallucination, score_efficiency, overall_score, report, model, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $sessionId,
            $evaluatorTaskId,
            $overallGrade,
            $scoreCompletion,
            $scoreHallucination,
            $scoreEfficiency,
            $overallScore,
            $report,
            $model,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            $now,
        ]);

        return $id;
    }

    /**
     * Get a single evaluation by ID.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM evaluations WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Get evaluation(s) for a specific session.
     *
     * @return list<array<string, mixed>>
     */
    public function getBySessionId(string $sessionId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM evaluations WHERE session_id = ? ORDER BY created_at DESC',
        );
        $stmt->execute([$sessionId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Find sessions that have not been evaluated and are eligible for evaluation.
     *
     * Criteria:
     * - No existing evaluation record (LEFT JOIN)
     * - Not a background task session (no entry in background_tasks with matching session_id)
     * - Last activity older than inactivity threshold (session is "done")
     * - Within the lookback window (don't scan ancient history)
     * - Minimum turn count (skip trivial sessions)
     *
     * @return list<array<string, mixed>>
     */
    public function getUnevaluatedSessions(
        int $lookbackHours = 24,
        int $inactivityHours = 3,
        int $minTurns = 2,
        int $limit = 20,
    ): array {
        $lookbackCutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($lookbackHours * 3600));
        $inactivityCutoff = gmdate('Y-m-d\TH:i:s\Z', time() - ($inactivityHours * 3600));

        $stmt = $this->db->prepare(<<<'SQL'
            SELECT
                s.id,
                s.title,
                s.model_role,
                s.model,
                s.token_count,
                s.created_at,
                s.updated_at,
                (SELECT COUNT(*) FROM turns WHERE turns.session_id = s.id) AS turn_count
            FROM sessions s
            LEFT JOIN evaluations e ON e.session_id = s.id
            LEFT JOIN background_tasks bt ON bt.session_id = s.id
            WHERE e.id IS NULL
              AND bt.id IS NULL
              AND s.updated_at < ?
              AND s.created_at > ?
              AND (SELECT COUNT(*) FROM turns WHERE turns.session_id = s.id) >= ?
            ORDER BY s.updated_at DESC
            LIMIT ?
        SQL);
        $stmt->execute([
            $inactivityCutoff,
            $lookbackCutoff,
            $minTurns,
            $limit,
        ]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List recent evaluations with optional grade filter.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $grade = null, int $limit = 50): array
    {
        if ($grade !== null) {
            $stmt = $this->db->prepare(<<<'SQL'
                SELECT e.*, s.title AS session_title
                FROM evaluations e
                LEFT JOIN sessions s ON s.id = e.session_id
                WHERE e.overall_grade = ?
                ORDER BY e.created_at DESC
                LIMIT ?
            SQL);
            $stmt->execute([$grade, $limit]);
        } else {
            $stmt = $this->db->prepare(<<<'SQL'
                SELECT e.*, s.title AS session_title
                FROM evaluations e
                LEFT JOIN sessions s ON s.id = e.session_id
                ORDER BY e.created_at DESC
                LIMIT ?
            SQL);
            $stmt->execute([$limit]);
        }

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get aggregate statistics for evaluations.
     *
     * @return array{total: int, avg_completion: float, avg_hallucination: float, avg_efficiency: float, avg_overall: float, grade_distribution: array<string, int>}
     */
    public function getStats(): array
    {
        $stmt = $this->db->query(<<<'SQL'
            SELECT
                COUNT(*) AS total,
                COALESCE(AVG(score_completion), 0) AS avg_completion,
                COALESCE(AVG(score_hallucination), 0) AS avg_hallucination,
                COALESCE(AVG(score_efficiency), 0) AS avg_efficiency,
                COALESCE(AVG(overall_score), 0) AS avg_overall,
                SUM(CASE WHEN overall_grade = 'A' THEN 1 ELSE 0 END) AS grade_a,
                SUM(CASE WHEN overall_grade = 'B' THEN 1 ELSE 0 END) AS grade_b,
                SUM(CASE WHEN overall_grade = 'C' THEN 1 ELSE 0 END) AS grade_c,
                SUM(CASE WHEN overall_grade = 'D' THEN 1 ELSE 0 END) AS grade_d,
                SUM(CASE WHEN overall_grade = 'F' THEN 1 ELSE 0 END) AS grade_f
            FROM evaluations
        SQL);

        $row = $stmt !== false ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if ($row === false) {
            return [
                'total' => 0,
                'avg_completion' => 0.0,
                'avg_hallucination' => 0.0,
                'avg_efficiency' => 0.0,
                'avg_overall' => 0.0,
                'grade_distribution' => ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0],
            ];
        }

        return [
            'total' => (int) $row['total'],
            'avg_completion' => round((float) $row['avg_completion'], 3),
            'avg_hallucination' => round((float) $row['avg_hallucination'], 3),
            'avg_efficiency' => round((float) $row['avg_efficiency'], 3),
            'avg_overall' => round((float) $row['avg_overall'], 3),
            'grade_distribution' => [
                'A' => (int) ($row['grade_a'] ?? 0),
                'B' => (int) ($row['grade_b'] ?? 0),
                'C' => (int) ($row['grade_c'] ?? 0),
                'D' => (int) ($row['grade_d'] ?? 0),
                'F' => (int) ($row['grade_f'] ?? 0),
            ],
        ];
    }

    /**
     * Remove orphaned evaluations whose sessions no longer exist.
     *
     * Defensive cleanup — CASCADE should handle this, but belt+suspenders.
     */
    public function cleanupOrphaned(): int
    {
        $result = $this->db->exec(<<<'SQL'
            DELETE FROM evaluations
            WHERE session_id NOT IN (SELECT id FROM sessions)
        SQL);

        return $result !== false ? $result : 0;
    }
}
