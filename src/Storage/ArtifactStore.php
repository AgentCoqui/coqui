<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use CoquiBot\Coqui\Support\SchemaHelper;
use PDO;

/**
 * Files-only artifact index.
 *
 * Every artifact's canonical content is a plain file on disk under
 * `artifacts/<type>/<slug>-<shortid>.<ext>` (owned by {@see ArtifactFileService}).
 * The DB row is a pure index — `id, session_id, project_id, created_by, title,
 * type, path, content_hash, version, timestamps` — never the content itself.
 * A monotonic `version` counter records how many times the artifact was
 * rewritten; there is no per-version history (that lives in the user's VCS).
 *
 * Retention is ownership-based: project-linked artifacts persist; session-only
 * artifacts are removed when their session is deleted.
 */
final class ArtifactStore
{
    public function __construct(
        private readonly PDO $db,
        private readonly ArtifactFileService $fileService,
    ) {
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
                type TEXT NOT NULL DEFAULT 'document',
                content TEXT NOT NULL DEFAULT '',
                language TEXT,
                filepath TEXT,
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

        // Index / provenance columns.
        $this->migrateAddColumn('artifacts', 'project_id', 'TEXT');
        $this->migrateAddColumn('artifacts', 'path', 'TEXT');
        $this->migrateAddColumn('artifacts', 'content_hash', 'TEXT');
        $this->migrateAddColumn('artifacts', 'created_by', 'TEXT');
    }

    private function migrateAddColumn(string $table, string $column, string $definition): void
    {
        SchemaHelper::addColumnIfMissing($this->db, $table, $column, $definition);
    }

    /**
     * Create a new artifact: write its content to a file and index the row.
     *
     * @param array<string, mixed>|null $metadata Optional structured metadata.
     */
    public function create(
        string $sessionId,
        string $title,
        string $content,
        string $type = 'document',
        ?string $language = null,
        ?string $projectId = null,
        ?string $createdBy = null,
        ?string $turnId = null,
        ?array $metadata = null,
    ): string {
        $id = IdGenerator::hex();
        $now = Clock::nowUtc();

        $path = $this->fileService->pathFor($type, $title, $id, $language);
        $hash = $this->fileService->write($path, $content);

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO artifacts
                (id, session_id, turn_id, title, type, content, language, filepath, path, content_hash, created_by, version, metadata, project_id, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, '', ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $sessionId,
            $turnId,
            $title,
            $type,
            $language,
            $path,
            $path,
            $hash,
            $createdBy,
            $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            $projectId,
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * Full-rewrite update: overwrite the file and bump the version counter.
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function update(
        string $id,
        string $content,
        ?string $title = null,
        ?string $sessionId = null,
    ): bool {
        $row = $this->fetchRow($id, $sessionId);
        if ($row === null) {
            return false;
        }

        $path = (string) ($row['path'] ?? '');
        if ($path === '') {
            // Safety net for any pre-migration row missing a path.
            $path = $this->fileService->pathFor(
                (string) $row['type'],
                (string) $row['title'],
                $id,
                isset($row['language']) ? (string) $row['language'] : null,
            );
        }

        $hash = $this->fileService->write($path, $content);
        $newVersion = ((int) $row['version']) + 1;

        $sets = ["content = ''", 'path = ?', 'content_hash = ?', 'version = ?', 'updated_at = ?'];
        $params = [$path, $hash, $newVersion, Clock::nowUtc()];

        if ($title !== null) {
            $sets[] = 'title = ?';
            $params[] = $title;
        }

        $params[] = $id;
        $this->db->prepare('UPDATE artifacts SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        return true;
    }

    /**
     * Patch non-content index fields.
     *
     * Supported keys: title, metadata, project_id.
     *
     * @param array<string, mixed> $patch
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function patch(string $id, array $patch, ?string $sessionId = null): bool
    {
        $row = $this->fetchRow($id, $sessionId);
        if ($row === null) {
            return false;
        }

        $sets = ['updated_at = ?'];
        $params = [Clock::nowUtc()];

        if (array_key_exists('title', $patch)) {
            $sets[] = 'title = ?';
            $params[] = $patch['title'];
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
            if ($patch['project_id'] === null || $patch['project_id'] === '') {
                $sets[] = 'project_id = NULL';
            } else {
                $sets[] = 'project_id = ?';
                $params[] = $patch['project_id'];
            }
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
     * Get a single artifact by ID, with content read from its file.
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     * @return array<string, mixed>|null
     */
    public function get(string $id, ?string $sessionId = null): ?array
    {
        $row = $this->fetchRow($id, $sessionId);
        if ($row === null) {
            return null;
        }

        $path = (string) ($row['path'] ?? '');
        if ($path !== '') {
            $disk = $this->fileService->read($path);
            if ($disk !== null) {
                $row['content'] = $disk;
            }
        }

        return $row;
    }

    /**
     * Fetch the raw index row (no file-content overlay).
     *
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $id, ?string $sessionId = null): ?array
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
     * List artifacts for a session, optionally filtered by type/project/time.
     *
     * @return list<array<string, mixed>>
     */
    public function list(
        string $sessionId,
        ?string $type = null,
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
     * Recent artifacts for the pinned prompt index.
     *
     * Scope is session→project (spec): when a project is loaded, return the
     * project's most-recently-updated artifacts *across all sessions* (artifacts
     * are shared, not session-private); otherwise fall back to the session's own.
     * Never filtered by creator — provenance is display-only.
     *
     * @return list<array<string, mixed>>
     */
    public function listRecent(string $sessionId, ?string $projectId = null, int $limit = 10): array
    {
        if ($projectId !== null && $projectId !== '') {
            $stmt = $this->db->prepare(
                'SELECT * FROM artifacts WHERE project_id = ? ORDER BY updated_at DESC LIMIT ?',
            );
            $stmt->execute([$projectId, $limit]);

            /** @var list<array<string, mixed>> */
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $this->list($sessionId, limit: $limit);
    }

    /**
     * Delete an artifact: remove its file, then its index row.
     *
     * @param string|null $sessionId When provided, validates the artifact belongs to this session.
     */
    public function delete(string $id, ?string $sessionId = null): bool
    {
        $row = $this->fetchRow($id, $sessionId);
        if ($row !== null) {
            $path = (string) ($row['path'] ?? '');
            if ($path !== '') {
                $this->fileService->delete($path);
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
     * Whether the session owns any project-linked artifacts (which block
     * non-forced session deletion and must be detached first).
     */
    public function hasProjectLinkedArtifacts(string $sessionId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM artifacts WHERE session_id = ? AND project_id IS NOT NULL AND project_id != '' LIMIT 1",
        );
        $stmt->execute([$sessionId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Remove session-only artifacts (no project link) and their files.
     * Project-linked artifacts persist. Called when a session is deleted.
     *
     * @return int Number of artifacts removed.
     */
    public function cleanupSessionArtifacts(string $sessionId): int
    {
        $select = $this->db->prepare(
            "SELECT path FROM artifacts WHERE session_id = ? AND (project_id IS NULL OR project_id = '')",
        );
        $select->execute([$sessionId]);

        foreach ($select->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $path = (string) ($row['path'] ?? '');
            if ($path !== '') {
                $this->fileService->delete($path);
            }
        }

        $delete = $this->db->prepare(
            "DELETE FROM artifacts WHERE session_id = ? AND (project_id IS NULL OR project_id = '')",
        );
        $delete->execute([$sessionId]);

        return $delete->rowCount();
    }

    /**
     * Serialize an index row to the CAP `artifact.json` wire shape.
     *
     * Emits ONLY the schema-declared properties (the object is
     * `additionalProperties:false`-clean). `session_id` is required.
     *
     * Files-only mapping: coqui's `title` is the schema's `name`, and the
     * canonical on-disk `path` is the opaque `content_ref` the spec never
     * dereferences (falling back to the `content_hash` if a row predates its
     * path). `metadata` emits as a JSON object (stdClass) or null — never a bare
     * `[]`. `created_at` is normalized to RFC-3339 UTC (Z).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $path = (string) ($row['path'] ?? '');
        $contentRef = $path !== '' ? $path : (string) ($row['content_hash'] ?? '');

        return [
            'id' => (string) ($row['id'] ?? ''),
            'session_id' => (string) ($row['session_id'] ?? ''),
            'name' => (string) ($row['title'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'content_ref' => $contentRef,
            'metadata' => self::wireObject($row['metadata'] ?? null),
            'created_at' => self::wireTimestamp($row['created_at'] ?? null) ?? Clock::nowUtc(),
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
     * field typed `object|null`. An empty object survives as `stdClass`, never a
     * bare `[]` (which JSON would encode as an array and break the schema).
     */
    private static function wireObject(mixed $value): ?\stdClass
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
        }

        return null;
    }

    /**
     * One-time forward migration: move any inline-content row to a file.
     *
     * For every row that still carries inline `content` and has no `path`,
     * write the content to a file per the current convention and blank the
     * DB content. Rows referenced by `loop_stages.artifact_id` are migrated,
     * never dropped.
     *
     * @return int Number of rows migrated.
     */
    public function migrateLegacyContent(): int
    {
        $stmt = $this->db->query(
            "SELECT id, title, type, language, content FROM artifacts
             WHERE content IS NOT NULL AND content != '' AND (path IS NULL OR path = '')",
        );
        if ($stmt === false) {
            return 0;
        }

        $count = 0;
        $update = $this->db->prepare(
            "UPDATE artifacts SET path = ?, filepath = COALESCE(filepath, ?), content_hash = ?, content = '', updated_at = ? WHERE id = ?",
        );

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $path = $this->fileService->pathFor(
                (string) $row['type'],
                (string) $row['title'],
                (string) $row['id'],
                isset($row['language']) ? (string) $row['language'] : null,
            );
            $hash = $this->fileService->write($path, (string) $row['content']);
            $update->execute([$path, $path, $hash, Clock::nowUtc(), $row['id']]);
            $count++;
        }

        return $count;
    }
}
