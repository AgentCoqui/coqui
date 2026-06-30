<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
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
        $id = IdGenerator::hex();
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
            Clock::nowUtc(),
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