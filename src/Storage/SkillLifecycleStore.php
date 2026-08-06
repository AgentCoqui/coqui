<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use PDO;
use stdClass;

final class SkillLifecycleStore
{
    public function __construct(
        private readonly PDO $db,
    ) {
        $this->createTables();
    }

    private function createTables(): void
    {
        // Skill catalog (CAP 0.5.0 `skill.json`). One row per discovered skill.
        // `origin` and `execution` are stored as JSON TEXT objects; `metadata` is
        // a nullable JSON TEXT object. `status` is server-owned (available|disabled).
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS skills (
                name        TEXT PRIMARY KEY,
                description TEXT,
                metadata    TEXT,
                source      TEXT,
                status      TEXT NOT NULL,
                origin      TEXT,
                execution   TEXT,
                created_at  TEXT NOT NULL,
                updated_at  TEXT NOT NULL
            )
        SQL);

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
     * Upsert a skill into the catalog and return its CAP `skill.json` wire object.
     *
     * `origin` and `execution` are typed objects: origin carries a closed-set
     * `kind` (builtin|local|imported) plus optional publisher/signature (CORE-26);
     * execution carries a closed-set `kind` (instruction|script) plus `requires`,
     * an array of capability names defaulting to `[]` (CORE-27). Both are stored
     * as normalized JSON so a re-projection stays `additionalProperties:false`-clean.
     *
     * A first insert stamps `created_at`; a re-upsert of the same name preserves the
     * original `created_at` and advances `updated_at`.
     *
     * @param array{kind: string, publisher?: string|null, signature?: string|null} $origin
     * @param array{kind: string, requires?: list<string>} $execution
     * @param array<string, mixed>|null $metadata
     * @return array<string, mixed>
     */
    public function upsertSkill(
        string $name,
        string $description,
        string $status,
        array $origin,
        array $execution,
        ?array $metadata = null,
        ?string $source = null,
    ): array {
        $now = Clock::nowUtc();

        $originJson = json_encode(self::normalizeOrigin($origin), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $executionJson = json_encode(self::normalizeExecution($execution), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $metadataJson = $metadata !== null
            ? json_encode((object) $metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            : null;

        $existing = $this->db->prepare('SELECT created_at FROM skills WHERE name = :name');
        $existing->execute(['name' => $name]);
        $current = $existing->fetch(PDO::FETCH_ASSOC);
        $createdAt = $current !== false ? (string) $current['created_at'] : $now;

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO skills (name, description, metadata, source, status, origin, execution, created_at, updated_at)
            VALUES (:name, :description, :metadata, :source, :status, :origin, :execution, :created_at, :updated_at)
            ON CONFLICT(name) DO UPDATE SET
                description = excluded.description,
                metadata    = excluded.metadata,
                source      = excluded.source,
                status      = excluded.status,
                origin      = excluded.origin,
                execution   = excluded.execution,
                updated_at  = excluded.updated_at
        SQL);
        $stmt->execute([
            'name' => $name,
            'description' => $description,
            'metadata' => $metadataJson,
            'source' => $source,
            'status' => $status,
            'origin' => $originJson,
            'execution' => $executionJson,
            'created_at' => $createdAt,
            'updated_at' => $now,
        ]);

        return self::toWire([
            'name' => $name,
            'description' => $description,
            'metadata' => $metadataJson,
            'source' => $source,
            'status' => $status,
            'origin' => $originJson,
            'execution' => $executionJson,
            'created_at' => $createdAt,
            'updated_at' => $now,
        ]);
    }

    /**
     * Project a persisted `skills` row onto the CAP 0.5.0 `skill.json` wire shape.
     *
     * `origin`/`execution` decode into `stdClass` objects — never associative
     * arrays — so an object-typed schema slot never receives a JSON array. Empty
     * or malformed object columns still emit as objects; `execution.requires`
     * defaults to `[]`. Only schema-declared properties are emitted.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        return [
            'name' => (string) $row['name'],
            'description' => (string) $row['description'],
            'metadata' => $row['metadata'] !== null
                ? self::decodeObject((string) $row['metadata'])
                : null,
            'source' => $row['source'] !== null ? (string) $row['source'] : null,
            'status' => (string) $row['status'],
            'origin' => self::originToObject((string) $row['origin']),
            'execution' => self::executionToObject((string) $row['execution']),
            'created_at' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'updated_at' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * Normalize an authoring `origin` array to the closed schema shape, dropping
     * any undeclared keys. `publisher`/`signature` are emitted only when supplied.
     *
     * @param array<string, mixed> $origin
     */
    private static function normalizeOrigin(array $origin): stdClass
    {
        $out = new stdClass();
        $out->kind = (string) ($origin['kind'] ?? '');
        if (array_key_exists('publisher', $origin)) {
            $out->publisher = $origin['publisher'] !== null ? (string) $origin['publisher'] : null;
        }
        if (array_key_exists('signature', $origin)) {
            $out->signature = $origin['signature'] !== null ? (string) $origin['signature'] : null;
        }

        return $out;
    }

    /**
     * Normalize an authoring `execution` array to the closed schema shape.
     * `requires` always materializes as a list of strings, defaulting to `[]`.
     *
     * @param array<string, mixed> $execution
     */
    private static function normalizeExecution(array $execution): stdClass
    {
        $out = new stdClass();
        $out->kind = (string) ($execution['kind'] ?? '');
        $requires = $execution['requires'] ?? [];
        $out->requires = is_array($requires)
            ? array_values(array_map('strval', $requires))
            : [];

        return $out;
    }

    /**
     * Decode a stored `origin` JSON column into a schema-clean object, keeping
     * only `kind`, `publisher`, and `signature`.
     */
    private static function originToObject(string $json): stdClass
    {
        $decoded = json_decode($json, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $out = new stdClass();
        $out->kind = isset($decoded['kind']) ? (string) $decoded['kind'] : '';
        if (array_key_exists('publisher', $decoded)) {
            $out->publisher = $decoded['publisher'] !== null ? (string) $decoded['publisher'] : null;
        }
        if (array_key_exists('signature', $decoded)) {
            $out->signature = $decoded['signature'] !== null ? (string) $decoded['signature'] : null;
        }

        return $out;
    }

    /**
     * Decode a stored `execution` JSON column into a schema-clean object with
     * `kind` and a `requires` list that defaults to `[]`.
     */
    private static function executionToObject(string $json): stdClass
    {
        $decoded = json_decode($json, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $out = new stdClass();
        $out->kind = isset($decoded['kind']) ? (string) $decoded['kind'] : '';
        $requires = $decoded['requires'] ?? [];
        $out->requires = is_array($requires)
            ? array_values(array_map('strval', $requires))
            : [];

        return $out;
    }

    /**
     * Decode a stored JSON object column as `stdClass`. Empty or non-object JSON
     * normalizes to an empty object so it never serializes back to a JSON array
     * under an `object`-typed schema.
     */
    private static function decodeObject(string $json): stdClass
    {
        $decoded = json_decode($json, false);

        return $decoded instanceof stdClass ? $decoded : new stdClass();
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