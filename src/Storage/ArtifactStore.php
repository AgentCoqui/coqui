<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed artifact persistence.
 *
 * Artifacts represent structured outputs created by agents: code files,
 * documents, configurations, etc. Each artifact tracks lineage (which
 * session/turn created it), supports staging (draft → review → final),
 * and maintains a version history.
 */
final class ArtifactStore
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
            CREATE TABLE IF NOT EXISTS artifacts (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                turn_id TEXT,
                title TEXT NOT NULL,
                type TEXT NOT NULL DEFAULT 'code',
                content TEXT NOT NULL DEFAULT '',
                language TEXT,
                filepath TEXT,
                stage TEXT NOT NULL DEFAULT 'draft',
                version INTEGER NOT NULL DEFAULT 1,
                metadata TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS artifact_versions (
                id TEXT PRIMARY KEY,
                artifact_id TEXT NOT NULL,
                version INTEGER NOT NULL,
                content TEXT NOT NULL,
                change_summary TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (artifact_id) REFERENCES artifacts(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_artifacts_session ON artifacts(session_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_artifact_versions_artifact ON artifact_versions(artifact_id)
        SQL);

        // Harness columns — added to existing installations via migration
        $this->migrateAddColumn('artifacts', 'project_id', "TEXT");
        $this->migrateAddColumn('artifacts', 'sprint_id', "TEXT");
        $this->migrateAddColumn('artifacts', 'persistent', 'INTEGER NOT NULL DEFAULT 0');
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
     * Create a new artifact and save its first version.
     *
     * @param array<string, mixed> $metadata Optional structured metadata.
     */
    public function create(
        string $sessionId,
        string $title,
        string $content,
        string $type = 'code',
        ?string $language = null,
        ?string $filepath = null,
        string $stage = 'draft',
        ?string $turnId = null,
        ?array $metadata = null,
        ?string $projectId = null,
        ?string $sprintId = null,
        bool $persistent = false,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO artifacts (id, session_id, turn_id, title, type, content, language, filepath, stage, version, metadata, project_id, sprint_id, persistent, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $sessionId,
            $turnId,
            $title,
            $type,
            $content,
            $language,
            $filepath,
            $stage,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            $projectId,
            $sprintId,
            $persistent ? 1 : 0,
            $now,
            $now,
        ]);

        // Save initial version
        $this->saveVersion($id, 1, $content, 'Initial version');

        return $id;
    }

    /**
     * Update an artifact's content, bumping the version.
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function update(
        string $id,
        string $content,
        ?string $changeSummary = null,
        ?string $title = null,
        ?string $stage = null,
        ?string $sessionId = null,
    ): bool {
        $artifact = $this->get($id, $sessionId);
        if ($artifact === null) {
            return false;
        }

        $newVersion = (int) $artifact['version'] + 1;
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $sets = ['content = ?', 'version = ?', 'updated_at = ?'];
        $params = [$content, $newVersion, $now];

        if ($title !== null) {
            $sets[] = 'title = ?';
            $params[] = $title;
        }

        if ($stage !== null) {
            $sets[] = 'stage = ?';
            $params[] = $stage;
        }

        $params[] = $id;
        $sql = 'UPDATE artifacts SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        // Save version snapshot
        $this->saveVersion($id, $newVersion, $content, $changeSummary);

        return true;
    }

    /**
     * Get a single artifact by ID.
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     * @return array<string, mixed>|null
     */
    public function get(string $id, ?string $sessionId = null): ?array
    {
        if ($sessionId !== null) {
            $stmt = $this->db->prepare('SELECT * FROM artifacts WHERE id = ? AND session_id = ?');
            $stmt->execute([$id, $sessionId]);
        } else {
            $stmt = $this->db->prepare('SELECT * FROM artifacts WHERE id = ?');
            $stmt->execute([$id]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List artifacts for a session, optionally filtered by type or stage.
     *
     * @return list<array<string, mixed>>
     */
    public function list(
        string $sessionId,
        ?string $type = null,
        ?string $stage = null,
        int $limit = 50,
        ?string $projectId = null,
        ?string $sprintId = null,
    ): array {
        $where = ['session_id = ?'];
        $params = [$sessionId];

        if ($type !== null) {
            $where[] = 'type = ?';
            $params[] = $type;
        }

        if ($stage !== null) {
            $where[] = 'stage = ?';
            $params[] = $stage;
        }

        if ($projectId !== null) {
            $where[] = 'project_id = ?';
            $params[] = $projectId;
        }

        if ($sprintId !== null) {
            $where[] = 'sprint_id = ?';
            $params[] = $sprintId;
        }

        $params[] = $limit;
        $sql = 'SELECT * FROM artifacts WHERE ' . implode(' AND ', $where)
            . ' ORDER BY updated_at DESC LIMIT ?';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete an artifact and all its versions.
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function delete(string $id, ?string $sessionId = null): bool
    {
        if ($sessionId !== null) {
            $stmt = $this->db->prepare('DELETE FROM artifacts WHERE id = ? AND session_id = ?');
            $stmt->execute([$id, $sessionId]);
        } else {
            $stmt = $this->db->prepare('DELETE FROM artifacts WHERE id = ?');
            $stmt->execute([$id]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Get version history for an artifact.
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     * @return list<array<string, mixed>>
     */
    public function getVersions(string $artifactId, ?string $sessionId = null): array
    {
        if ($sessionId !== null && $this->get($artifactId, $sessionId) === null) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT * FROM artifact_versions WHERE artifact_id = ? ORDER BY version DESC',
        );
        $stmt->execute([$artifactId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a specific version of an artifact.
     *
     * @return array<string, mixed>|null
     */
    public function getVersion(string $artifactId, int $version): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM artifact_versions WHERE artifact_id = ? AND version = ?',
        );
        $stmt->execute([$artifactId, $version]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Update only the stage of an artifact (e.g. draft → review → final).
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function updateStage(string $id, string $stage, ?string $sessionId = null): bool
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');

        if ($sessionId !== null) {
            $stmt = $this->db->prepare(
                'UPDATE artifacts SET stage = ?, updated_at = ? WHERE id = ? AND session_id = ?',
            );
            $stmt->execute([$stage, $now, $id, $sessionId]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE artifacts SET stage = ?, updated_at = ? WHERE id = ?',
            );
            $stmt->execute([$stage, $now, $id]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete non-persistent artifacts in 'final' stage (they have been consumed by coders).
     *
     * Draft and review artifacts are preserved across sessions so in-progress
     * planning work survives restarts. Version history is cascade-deleted by FK.
     * Persistent artifacts (linked to projects) are never cleaned up.
     *
     * @return int Number of artifacts deleted.
     */
    public function cleanupFinalized(): int
    {
        $stmt = $this->db->prepare("DELETE FROM artifacts WHERE stage = 'final' AND persistent = 0");
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Batch update stage for multiple artifacts in a session.
     *
     * @param list<string> $ids Artifact IDs to transition
     * @return int Number of artifacts updated
     */
    public function bulkUpdateStage(array $ids, string $stage, string $sessionId): int
    {
        if ($ids === []) {
            return 0;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $count = 0;

        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(
                'UPDATE artifacts SET stage = ?, updated_at = ? WHERE id = ? AND session_id = ?',
            );

            foreach ($ids as $id) {
                $stmt->execute([$stage, $now, $id, $sessionId]);
                $count += $stmt->rowCount();
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();

            throw $e;
        }

        return $count;
    }

    /**
     * Check if a session has any persistent (project-linked) artifacts.
     */
    public function hasPersistentArtifacts(string $sessionId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM artifacts WHERE session_id = ? AND persistent = 1',
        );
        $stmt->execute([$sessionId]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    private function saveVersion(
        string $artifactId,
        int $version,
        string $content,
        ?string $changeSummary,
    ): void {
        $versionId = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO artifact_versions (id, artifact_id, version, content, change_summary, created_at)
            VALUES (?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([$versionId, $artifactId, $version, $content, $changeSummary, $now]);
    }
}
