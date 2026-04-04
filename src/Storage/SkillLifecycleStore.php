<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

final class SkillLifecycleStore
{
    public function __construct(
        private readonly PDO $db,
    ) {
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS skill_usage_events (
                id TEXT PRIMARY KEY,
                skill_name TEXT NOT NULL,
                action TEXT NOT NULL,
                source_tool TEXT NOT NULL,
                session_id TEXT,
                turn_id TEXT,
                agent_role TEXT,
                metadata TEXT,
                created_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_skill_usage_skill ON skill_usage_events(skill_name)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_skill_usage_session ON skill_usage_events(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_skill_usage_turn ON skill_usage_events(turn_id)');

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS evaluation_evidence_links (
                id TEXT PRIMARY KEY,
                evaluation_id TEXT NOT NULL,
                evidence_type TEXT NOT NULL,
                evidence_id TEXT,
                label TEXT NOT NULL,
                metadata TEXT,
                created_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_evaluation_evidence_links_eval ON evaluation_evidence_links(evaluation_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_evaluation_evidence_links_type ON evaluation_evidence_links(evidence_type)');

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS skill_provenance_events (
                id TEXT PRIMARY KEY,
                skill_name TEXT NOT NULL,
                action TEXT NOT NULL,
                evaluation_id TEXT NOT NULL,
                learner_task_id TEXT NOT NULL,
                metadata TEXT,
                created_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_skill_provenance_skill ON skill_provenance_events(skill_name)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_skill_provenance_evaluation ON skill_provenance_events(evaluation_id)');
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function recordSkillUsage(
        string $skillName,
        string $action,
        string $sourceTool,
        ?string $sessionId = null,
        ?string $turnId = null,
        ?string $agentRole = null,
        ?array $metadata = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO skill_usage_events (id, skill_name, action, source_tool, session_id, turn_id, agent_role, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $skillName,
            $action,
            $sourceTool,
            $sessionId,
            $turnId,
            $agentRole,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSkillUsage(?string $skillName = null, ?string $sessionId = null, int $limit = 100): array
    {
        $where = [];
        $params = [];

        if ($skillName !== null && $skillName !== '') {
            $where[] = 'skill_name = ?';
            $params[] = $skillName;
        }

        if ($sessionId !== null && $sessionId !== '') {
            $where[] = 'session_id = ?';
            $params[] = $sessionId;
        }

        $sql = 'SELECT * FROM skill_usage_events';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = max(1, $limit);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row): array => $this->decodeJsonField($row, 'metadata'), $rows);
    }

    /**
     * @param list<array{type: string, evidence_id?: string|null, label: string, metadata?: array<string, mixed>|null}> $links
     */
    public function replaceEvaluationEvidenceLinks(string $evaluationId, array $links): void
    {
        $this->db->beginTransaction();

        try {
            $delete = $this->db->prepare('DELETE FROM evaluation_evidence_links WHERE evaluation_id = ?');
            $delete->execute([$evaluationId]);

            if ($links !== []) {
                $insert = $this->db->prepare(<<<'SQL'
                    INSERT INTO evaluation_evidence_links (id, evaluation_id, evidence_type, evidence_id, label, metadata, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                SQL);

                $now = gmdate('Y-m-d\TH:i:s\Z');
                foreach ($links as $link) {
                    $insert->execute([
                        bin2hex(random_bytes(16)),
                        $evaluationId,
                        $link['type'],
                        $link['evidence_id'] ?? null,
                        $link['label'],
                        isset($link['metadata']) && is_array($link['metadata'])
                            ? json_encode($link['metadata'], JSON_UNESCAPED_SLASHES)
                            : null,
                        $now,
                    ]);
                }
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEvaluationEvidenceLinks(string $evaluationId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM evaluation_evidence_links WHERE evaluation_id = ? ORDER BY created_at ASC, label ASC
        SQL);
        $stmt->execute([$evaluationId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row): array => $this->decodeJsonField($row, 'metadata'), $rows);
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function recordSkillProvenance(
        string $skillName,
        string $action,
        string $evaluationId,
        string $learnerTaskId,
        ?array $metadata = null,
    ): string {
        $existing = $this->db->prepare(<<<'SQL'
            SELECT id FROM skill_provenance_events
            WHERE skill_name = ? AND action = ? AND evaluation_id = ? AND learner_task_id = ?
            LIMIT 1
        SQL);
        $existing->execute([$skillName, $action, $evaluationId, $learnerTaskId]);
        $existingId = $existing->fetchColumn();

        if (is_string($existingId) && $existingId !== '') {
            return $existingId;
        }

        $id = bin2hex(random_bytes(16));
        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO skill_provenance_events (id, skill_name, action, evaluation_id, learner_task_id, metadata, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $skillName,
            $action,
            $evaluationId,
            $learnerTaskId,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            gmdate('Y-m-d\TH:i:s\Z'),
        ]);

        return $id;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSkillProvenance(?string $skillName = null, ?string $evaluationId = null, int $limit = 100): array
    {
        $where = [];
        $params = [];

        if ($skillName !== null && $skillName !== '') {
            $where[] = 'skill_name = ?';
            $params[] = $skillName;
        }

        if ($evaluationId !== null && $evaluationId !== '') {
            $where[] = 'evaluation_id = ?';
            $params[] = $evaluationId;
        }

        $sql = 'SELECT * FROM skill_provenance_events';
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT ?';
        $params[] = max(1, $limit);

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row): array => $this->decodeJsonField($row, 'metadata'), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeJsonField(array $row, string $field): array
    {
        $value = $row[$field] ?? null;
        if (!is_string($value) || trim($value) === '') {
            $row[$field] = null;
            return $row;
        }

        $decoded = json_decode($value, true);
        $row[$field] = is_array($decoded) ? $decoded : null;

        return $row;
    }
}