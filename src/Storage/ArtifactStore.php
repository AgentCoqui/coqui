<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use CoquiBot\Coqui\Support\SchemaHelper;
use PDO;

/**
 * SQLite-backed artifact index.
 *
 * Artifacts represent structured outputs created by agents: code files,
 * documents, configurations, etc. Each artifact tracks lineage (which
 * session/turn created it) and supports staging (draft → review → final).
 *
 * For filesystem-backed types (plan, document, code, config) the canonical
 * content lives on disk under the project's artifacts/ directory and the DB
 * `content` column is left empty — the file is the source of truth and history
 * comes from the user's own VCS. Only DB-only types (loop_output, data, other)
 * store content inline. A monotonically increasing `version` counter is kept on
 * the row for display, but full per-version snapshots are not retained.
 */
final class ArtifactStore
{
    private PDO $db;
    private ?ArtifactFileService $fileService;
    private ?ProjectStore $projectStore;

    /**
     * @param PDO $db Shared PDO connection (from SessionStorage::getPdo())
     * @param ArtifactFileService|null $fileService When provided, eligible artifacts write canonical content to disk.
     * @param ProjectStore|null $projectStore When provided, resolves project directory for auto-generated paths.
     */
    public function __construct(PDO $db, ?ArtifactFileService $fileService = null, ?ProjectStore $projectStore = null)
    {
        $this->db = $db;
        $this->fileService = $fileService;
        $this->projectStore = $projectStore;
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

        // Legacy: drop the per-version snapshot table (artifacts now keep only a
        // version counter on the row; full content history lives in the user's VCS).
        $this->db->exec('DROP INDEX IF EXISTS idx_artifact_versions_artifact');
        $this->db->exec('DROP TABLE IF EXISTS artifact_versions');

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_artifacts_session ON artifacts(session_id)
        SQL);

        // Harness columns — added to existing installations via migration
        $this->migrateAddColumn('artifacts', 'project_id', "TEXT");
        // sprint_id is a dormant column retained from the removed sprint subsystem;
        // it is never written or read. Kept because SQLite cannot cheaply drop columns.
        $this->migrateAddColumn('artifacts', 'sprint_id', "TEXT");
        $this->migrateAddColumn('artifacts', 'persistent', 'INTEGER NOT NULL DEFAULT 0');

        // Hybrid filesystem columns — track canonical file path and storage mode
        $this->migrateAddColumn('artifacts', 'storage_mode', "TEXT NOT NULL DEFAULT 'database'");
        $this->migrateAddColumn('artifacts', 'canonical_path', 'TEXT');
        $this->migrateAddColumn('artifacts', 'content_hash', 'TEXT');
    }

    private function migrateAddColumn(string $table, string $column, string $definition): void
    {
        SchemaHelper::addColumnIfMissing($this->db, $table, $column, $definition);
    }

    /**
     * Create a new artifact.
     *
     * Filesystem-backed types write their content to a canonical file and leave
     * the DB `content` column empty (the file is authoritative). DB-only types
     * store content inline.
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
        bool $persistent = false,
    ): string {
        $id = IdGenerator::hex();
        $now = Clock::nowUtc();

        // Auto-persist artifacts linked to projects — project-linked artifacts
        // survive cleanupFinalized() so they remain available to later loop stages
        // (e.g. reviewers) whose processes boot and run cleanup before reading them.
        $isPersistent = $persistent || ($projectId !== null && $projectId !== '');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO artifacts (id, session_id, turn_id, title, type, content, language, filepath, stage, version, metadata, project_id, persistent, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?)
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
            $isPersistent ? 1 : 0,
            $now,
            $now,
        ]);
        // Filesystem-backed: resolve canonical path, write file, and clear the DB
        // content (the file becomes the source of truth).
        if ($this->fileService !== null && $this->fileService->isFilesystemBacked($type, $filepath, $projectId)) {
            $projectDir = $this->resolveProjectDirectory($projectId);
            $canonicalPath = $this->fileService->resolveCanonicalPath($id, $type, $title, $filepath, $projectId, $projectDir);

            if ($canonicalPath !== null && $this->fileService->writeContent($canonicalPath, $content)) {
                $contentHash = $this->fileService->computeContentHash($content);
                $this->db->prepare(
                    "UPDATE artifacts SET storage_mode = ?, canonical_path = ?, content_hash = ?, content = '', filepath = COALESCE(filepath, ?) WHERE id = ?",
                )->execute(['filesystem', $canonicalPath, $contentHash, $canonicalPath, $id]);
            }
        }

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
        $artifact = $this->getRaw($id, $sessionId);
        if ($artifact === null) {
            return false;
        }

        $newVersion = (int) $artifact['version'] + 1;
        $now = Clock::nowUtc();

        $canonicalPath = $artifact['canonical_path'] ?? null;
        $isFilesystemBacked = $this->fileService !== null
            && is_string($canonicalPath) && $canonicalPath !== ''
            && ($artifact['storage_mode'] ?? 'database') === 'filesystem';

        // Filesystem-backed artifacts keep the DB content empty (the file is the
        // source of truth); DB-only artifacts store content inline.
        $sets = ['content = ?', 'version = ?', 'updated_at = ?'];
        $params = [$isFilesystemBacked ? '' : $content, $newVersion, $now];

        if ($title !== null) {
            $sets[] = 'title = ?';
            $params[] = $title;
        }

        if ($stage !== null) {
            $sets[] = 'stage = ?';
            $params[] = $stage;
        }

        if ($isFilesystemBacked) {
            $this->fileService->writeContent((string) $canonicalPath, $content);
            $sets[] = 'content_hash = ?';
            $params[] = $this->fileService->computeContentHash($content);
        }

        $params[] = $id;
        $sql = 'UPDATE artifacts SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    /**
     * Patch non-versioned artifact fields.
     *
     * Supported keys: title, language, metadata, project_id, persistent.
     *
     * @param array<string, mixed> $patch
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function patch(string $id, array $patch, ?string $sessionId = null): bool
    {
        $artifact = $this->getRaw($id, $sessionId);
        if ($artifact === null) {
            return false;
        }

        $sets = ['updated_at = ?'];
        $params = [Clock::nowUtc()];

        if (array_key_exists('title', $patch)) {
            $sets[] = 'title = ?';
            $params[] = $patch['title'];
        }

        if (array_key_exists('language', $patch)) {
            if ($patch['language'] === null) {
                $sets[] = 'language = NULL';
            } else {
                $sets[] = 'language = ?';
                $params[] = $patch['language'];
            }
        }

        if (array_key_exists('metadata', $patch)) {
            if ($patch['metadata'] === null) {
                $sets[] = 'metadata = NULL';
            } else {
                $sets[] = 'metadata = ?';
                $params[] = json_encode($patch['metadata'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }

        if (array_key_exists('project_id', $patch)) {
            if ($patch['project_id'] === null) {
                $sets[] = 'project_id = NULL';
            } else {
                $sets[] = 'project_id = ?';
                $params[] = $patch['project_id'];
            }
        }

        if (array_key_exists('persistent', $patch)) {
            $sets[] = 'persistent = ?';
            $params[] = $patch['persistent'] ? 1 : 0;
        }

        if (count($sets) === 1) {
            return true;
        }

        $params[] = $id;
        $stmt = $this->db->prepare('UPDATE artifacts SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
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

        if ($row === false) {
            return null;
        }

        // For filesystem-backed artifacts, prefer the disk content over the DB snapshot
        $canonicalPath = $row['canonical_path'] ?? null;
        if ($this->fileService !== null && is_string($canonicalPath) && $canonicalPath !== '' && ($row['storage_mode'] ?? 'database') === 'filesystem') {
            $diskContent = $this->fileService->readContent($canonicalPath);
            if ($diskContent !== null) {
                $row['content'] = $diskContent;
            }
        }

        return $row;
    }

    /**
     * Get a single artifact's raw DB row without the disk-content overlay.
     *
     * Used internally where we need to compare DB content against disk (e.g. drift sync).
     *
     * @return array<string, mixed>|null
     */
    private function getRaw(string $id, ?string $sessionId = null): ?array
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
        ?string $createdAfter = null,
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

        if ($createdAfter !== null) {
            $where[] = 'created_at > ?';
            $params[] = $createdAfter;
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
        // Delete canonical file first (before losing the DB record)
        if ($this->fileService !== null) {
            $artifact = $this->get($id, $sessionId);
            $canonicalPath = $artifact['canonical_path'] ?? null;
            if ($artifact !== null && is_string($canonicalPath) && $canonicalPath !== '' && ($artifact['storage_mode'] ?? 'database') === 'filesystem') {
                $this->fileService->deleteFile($canonicalPath);
            }
        }

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
     * Delete multiple artifacts from a session.
     *
     * @param list<string> $ids
     * @return int Number of artifacts deleted.
     */
    public function bulkDelete(array $ids, string $sessionId): int
    {
        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $this->db->prepare(
            "DELETE FROM artifacts WHERE id IN ({$placeholders}) AND session_id = ?",
        );
        $stmt->execute([...$ids, $sessionId]);

        return $stmt->rowCount();
    }

    /**
     * Update only the stage of an artifact (e.g. draft → review → final).
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function updateStage(string $id, string $stage, ?string $sessionId = null): bool
    {
        // For filesystem-backed artifacts, sync disk content to DB before stage transition.
        // This ensures post-finalization consumers see the latest content even if the
        // file was edited externally.
        // Uses getRaw() to avoid the disk-content overlay that get() applies.
        if ($this->fileService !== null) {
            $artifact = $this->getRaw($id, $sessionId);
            $canonicalPath = $artifact['canonical_path'] ?? null;
            if ($artifact !== null && is_string($canonicalPath) && $canonicalPath !== '' && ($artifact['storage_mode'] ?? 'database') === 'filesystem') {
                $diskContent = $this->fileService->readContent($canonicalPath);
                if ($diskContent !== null && $diskContent !== ($artifact['content'] ?? '')) {
                    $contentHash = $this->fileService->computeContentHash($diskContent);
                    $this->db->prepare('UPDATE artifacts SET content = ?, content_hash = ? WHERE id = ?')->execute([$diskContent, $contentHash, $id]);
                }
            }
        }

        $now = Clock::nowUtc();

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
     * Persistent artifacts (linked to projects) and loop_output artifacts are
     * never cleaned up — loop_output artifacts are referenced by loop_stages.artifact_id
     * and must survive for loop debugging and reviewer evidence.
     *
     * @return int Number of artifacts deleted.
     */
    public function cleanupFinalized(): int
    {
        // Delete canonical files for filesystem-backed artifacts before removing DB records
        if ($this->fileService !== null) {
            $filesToDelete = $this->db->query(
                "SELECT canonical_path FROM artifacts WHERE stage = 'final' AND persistent = 0 AND type != 'loop_output' AND storage_mode = 'filesystem' AND canonical_path IS NOT NULL",
            );
            if ($filesToDelete !== false) {
                while ($row = $filesToDelete->fetch(PDO::FETCH_ASSOC)) {
                    $this->fileService->deleteFile((string) $row['canonical_path']);
                }
            }
        }

        $stmt = $this->db->prepare("DELETE FROM artifacts WHERE stage = 'final' AND persistent = 0 AND type != 'loop_output'");
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

        $now = Clock::nowUtc();
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

    /**
     * Resolve the project directory name, or null if no project store or project.
     */
    private function resolveProjectDirectory(?string $projectId): ?string
    {
        if ($projectId === null || $projectId === '' || $this->projectStore === null) {
            return null;
        }

        try {
            return $this->projectStore->getProjectDirectory($projectId);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }
}
