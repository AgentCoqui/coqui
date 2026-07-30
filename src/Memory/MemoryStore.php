<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Memory;

use CarmeloSantana\PHPAgents\Contract\EmbeddingProviderInterface;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Memory\MemoryEntry;
use CoquiBot\Coqui\Support\IdGenerator;
use CoquiBot\Coqui\Support\SqlitePragmas;
use DateTimeImmutable;
use PDO;

/**
 * SQLite-backed memory store with FTS5 full-text search and optional vector embeddings.
 *
 * FTS5 keyword search is always available (zero dependencies). Vector embeddings
 * provide semantic search when an EmbeddingProviderInterface is configured.
 * When no embedding provider is available, search degrades gracefully to FTS5 only.
 *
 * Memories are global and shared across all sessions, organized by area and tags.
 */
final class MemoryStore
{
    private const string LEGACY_SESSION_SUMMARY_AREA = 'session_summary';

    public const array AREA_DEFAULT_IMPORTANCE = [
        'identity' => 0.95,
        'developmental' => 0.85,
        'relational' => 0.85,
        'phenomenological' => 0.8,
        'preferences' => 0.8,
        'solutions' => 0.7,
        'facts' => 0.6,
        'context' => 0.5,
        'main' => 0.5,
        'session_summary' => 0.3,
    ];

    private const array CORE_SUMMARY_AREA_ORDER = [
        'identity',
        'developmental',
        'relational',
        'phenomenological',
        'preferences',
        'solutions',
        'facts',
        'context',
        'main',
    ];

    private PDO $db;
    private bool $tablesCreated = false;

    public function __construct(
        private readonly string $dbPath,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
    ) {
        $this->db = $this->connect();
    }

    public function getPdo(): PDO
    {
        return $this->db;
    }

    /**
     * Areas exposed to the agent-facing memory tools and auto-extractor.
     *
     * Internal compatibility buckets such as `main` and `session_summary`
     * remain stored, but are not offered as first-class agent categories.
     *
     * @return list<string>
     */
    public static function userFacingAreas(): array
    {
        return [
            'identity',
            'developmental',
            'relational',
            'phenomenological',
            'preferences',
            'facts',
            'solutions',
            'context',
        ];
    }

    /**
     * Save a new memory entry.
     *
     * Inserts into the memories table, FTS5 index, and optionally generates
     * a vector embedding for semantic search.
     */
    public function save(MemoryEntry $entry): string
    {
        $this->ensureTables();

        $id = IdGenerator::hex();
        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $tags = $entry->metadata['tags'] ?? '';
        $importance = (float) ($entry->metadata['importance'] ?? self::AREA_DEFAULT_IMPORTANCE[$entry->area] ?? 0.5);
        $metadata = json_encode($entry->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $memoryType = $entry->type;
        $validUntil = $entry->validUntil?->format('Y-m-d\TH:i:s');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO memories (id, content, area, tags, metadata, importance, memory_type, valid_until, persona_id, session_id, created_at, updated_at)
            VALUES (:id, :content, :area, :tags, :metadata, :importance, :memory_type, :valid_until, :persona_id, :session_id, :created_at, :updated_at)
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':content' => $entry->content,
            ':area' => $entry->area,
            ':tags' => $tags,
            ':metadata' => $metadata,
            ':importance' => $importance,
            ':memory_type' => $memoryType,
            ':valid_until' => $validUntil,
            ':persona_id' => $entry->personaId,
            ':session_id' => $entry->sessionId,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        // Update FTS5 index
        $this->insertFts($id, $entry->content, $tags);

        // Generate embedding if provider available
        $this->embedMemory($id, $entry->content);

        $this->incrementCacheVersion();

        return $id;
    }

    /**
     * Search memories by query string.
     *
     * Uses a hybrid strategy: FTS5 keyword search first, then re-ranked by
     * cosine similarity when vector embeddings are available. Falls back to
     * pure FTS5 when no embedding provider is configured.
     *
     * @return MemoryEntry[]
     */
    public function search(string $query, int $limit = 10, float $threshold = 0.7, ?string $profileId = null): array
    {
        $this->ensureTables();

        if (trim($query) === '') {
            return [];
        }

        $candidates = [];

        // Collect vector candidates (if embedding provider available)
        if ($this->embeddingProvider !== null) {
            $candidates = $this->vectorSearchCandidates($query, $limit * 2, $threshold, $profileId);
        }

        // Merge FTS candidates (deduplicates by ID, vector results take priority)
        foreach ($this->ftsSearchCandidates($query, $limit * 2, $profileId) as $id => $candidate) {
            if (!isset($candidates[$id])) {
                $candidates[$id] = $candidate;
            }
        }

        // LIKE fallback if nothing found from vector + FTS
        if (empty($candidates)) {
            $candidates = $this->likeSearchCandidates($query, $limit * 2, $profileId);
        }

        if (empty($candidates)) {
            return [];
        }

        // Apply multi-dimensional composite scoring
        foreach ($candidates as &$c) {
            $c['composite'] = $this->computeCompositeScore(
                similarity: $c['similarity'] ?? null,
                importance: (float) ($c['importance'] ?? 0.5),
                lastAccessedAt: $c['last_accessed_at'] ?? null,
                accessCount: (int) ($c['access_count'] ?? 0),
                updatedAt: $c['updated_at'],
            );
        }
        unset($c);

        // Sort by composite score descending
        uasort($candidates, fn(array $a, array $b) => $b['composite'] <=> $a['composite']);

        // Take top N and convert to MemoryEntry
        $results = [];
        foreach (array_slice($candidates, 0, $limit, true) as $c) {
            $results[] = $this->candidateToEntry($c);
        }

        // Reinforce access counts for returned results
        $ids = array_filter(array_map(fn(MemoryEntry $e) => $e->id, $results));
        if (!empty($ids)) {
            $this->reinforceAccess($ids);
        }

        return $results;
    }

    /**
     * Delete a specific memory by ID.
     */
    public function delete(string $id): void
    {
        $this->ensureTables();

        $this->db->prepare('DELETE FROM memories WHERE id = :id')->execute([':id' => $id]);
        $this->db->prepare('DELETE FROM memories_fts WHERE rowid IN (SELECT rowid FROM memories_fts_lookup WHERE memory_id = :id)')->execute([':id' => $id]);

        // Clean up lookup and embeddings
        $this->db->prepare('DELETE FROM memories_fts_lookup WHERE memory_id = :id')->execute([':id' => $id]);
        $this->db->prepare('DELETE FROM memory_embeddings WHERE memory_id = :id')->execute([':id' => $id]);

        $this->incrementCacheVersion();
    }

    /**
     * Delete all memories in a specific area.
     *
     * @return int Number of deleted entries
     */
    public function deleteArea(string $area): int
    {
        $this->ensureTables();

        $ids = $this->db->prepare('SELECT id FROM memories WHERE area = :area');
        $ids->execute([':area' => $area]);

        $deleted = 0;
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!is_string($id) || $id === '') {
                continue;
            }

            $this->delete($id);
            $deleted++;
        }

        return $deleted;
    }

    /**
     * Delete all memories semantically matching a query.
     *
     * @return int Number of deleted entries
     */
    public function forget(string $query, float $threshold = 0.7, ?string $profileId = null): int
    {
        $matches = $this->search($query, limit: 100, threshold: $threshold, profileId: $profileId);
        $count = 0;

        foreach ($matches as $entry) {
            if ($entry->id !== null) {
                $this->delete($entry->id);
                $count++;
            }
        }

        return $count;
    }

    /**
     * List all memories in an area.
     *
     * @return MemoryEntry[]
     */
    public function list(string $area = 'main', int $limit = 50, ?string $profileId = null): array
    {
        $this->ensureTables();

        $profileClause = $this->buildPersonaClause($profileId);

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at,
                   importance, access_count, last_accessed_at, persona_id, session_id
            FROM memories
            WHERE area = :area AND archived_at IS NULL{$profileClause}
            ORDER BY importance DESC, updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue(':area', $area);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Update an existing memory's content.
     *
     * Regenerates FTS index and vector embedding.
     */
    public function update(string $id, string $content, ?string $area = null, ?string $tags = null, ?float $importance = null): bool
    {
        $this->ensureTables();

        // Load existing to merge unchanged fields
        $existing = $this->getById($id);
        if ($existing === null) {
            return false;
        }

        $area ??= $existing->area;
        $tags ??= $existing->metadata['tags'] ?? '';
        $importance ??= (float) ($existing->metadata['importance'] ?? self::AREA_DEFAULT_IMPORTANCE[$existing->area] ?? 0.5);
        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');

        // Delete FTS index BEFORE updating the memories table — FTS5 delete
        // command requires the original content, which is read via subquery.
        $this->deleteFts($id);

        $stmt = $this->db->prepare(<<<SQL
            UPDATE memories
            SET content = :content, area = :area, tags = :tags, importance = :importance, updated_at = :updated_at
            WHERE id = :id
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':content' => $content,
            ':area' => $area,
            ':tags' => $tags,
            ':importance' => $importance,
            ':updated_at' => $now,
        ]);

        // Rebuild FTS index with the new content
        $this->insertFts($id, $content, $tags);

        // Regenerate embedding
        $this->db->prepare('DELETE FROM memory_embeddings WHERE memory_id = :id')->execute([':id' => $id]);
        $this->embedMemory($id, $content);

        $this->incrementCacheVersion();

        return true;
    }

    /**
     * Get a specific memory by ID.
     */
    public function getById(string $id): ?MemoryEntry
    {
        $this->ensureTables();

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at,
                   importance, access_count, last_accessed_at, archived_at,
                   persona_id, session_id
            FROM memories
            WHERE id = :id
        SQL);

        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->rowToEntry($row);
    }

    /**
     * List memories filtered by tags.
     *
     * Matches any memory containing at least one of the provided tags.
     *
     * @param string[] $tags
     * @return MemoryEntry[]
     */
    public function listByTags(array $tags, int $limit = 50, ?string $profileId = null): array
    {
        $this->ensureTables();

        if (empty($tags)) {
            return [];
        }

        // Build LIKE conditions for each tag
        $conditions = [];
        $params = [];
        foreach ($tags as $i => $tag) {
            $key = ":tag{$i}";
            $conditions[] = "tags LIKE {$key}";
            $params[$key] = '%' . trim($tag) . '%';
        }

        $where = implode(' OR ', $conditions);
        $profileClause = $this->buildPersonaClause($profileId);
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at,
                   importance, access_count, last_accessed_at, persona_id, session_id
            FROM memories
            WHERE ({$where}) AND archived_at IS NULL{$profileClause}
            ORDER BY importance DESC, updated_at DESC
            LIMIT :limit
        SQL);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * List all memories across all areas.
     *
     * @return MemoryEntry[]
     */
    public function listAll(int $limit = 100, ?string $profileId = null): array
    {
        $this->ensureTables();

        $profileClause = $this->buildPersonaClause($profileId);

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at,
                   importance, access_count, last_accessed_at, persona_id, session_id
            FROM memories
            WHERE archived_at IS NULL{$profileClause}
            ORDER BY importance DESC, updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Count total memories, optionally filtered by area.
     */
    public function count(?string $area = null, ?string $profileId = null): int
    {
        $this->ensureTables();

        $profileClause = $this->buildPersonaClause($profileId);

        if ($area !== null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM memories WHERE area = :area AND archived_at IS NULL' . $profileClause);
            if ($stmt === false) {
                return 0;
            }
            $stmt->execute([':area' => $area]);

            return (int) $stmt->fetchColumn();
        }

        $stmt = $this->db->query('SELECT COUNT(*) FROM memories WHERE archived_at IS NULL' . $profileClause);
        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Count memories recorded within a specific session.
     *
     * Used by the loop engine's soft `memory_required` check — a stage that was
     * asked to record a canonical-artifact memory pointer but wrote none earns a
     * Minor concern (never blocking).
     */
    public function countBySession(string $sessionId): int
    {
        $this->ensureTables();

        $stmt = $this->getPdo()->prepare('SELECT COUNT(*) FROM memories WHERE session_id = ?');
        $stmt->execute([$sessionId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get a compact summary of all core memories for system prompt injection.
     *
    * Returns formatted text suitable for including in an agent's system prompt.
    * Prioritizes continuity-heavy areas such as identity and developmental arc
    * ahead of more general preferences, facts, and project context.
     */
    public function getCoreSummary(int $limit = 30, ?string $profileId = null): string
    {
        $this->ensureTables();

        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $profileClause = $this->buildPersonaClause($profileId);

        // Fetch active knowledge memories — exclude task-type and expired memories
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at,
                   importance, access_count, last_accessed_at, persona_id, session_id
            FROM memories
            WHERE archived_at IS NULL
              AND area != :excluded_area
              AND memory_type != 'task'
              AND (valid_until IS NULL OR valid_until > :now){$profileClause}
            ORDER BY importance DESC, access_count DESC, updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue(':excluded_area', self::LEGACY_SESSION_SUMMARY_AREA);
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $entries = $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));

        if (empty($entries)) {
            return '';
        }

        $sections = [];
        $grouped = [];

        foreach ($entries as $entry) {
            $grouped[$entry->area][] = $entry;
        }

        foreach (self::CORE_SUMMARY_AREA_ORDER as $area) {
            if (!isset($grouped[$area])) {
                continue;
            }

            $areaEntries = $grouped[$area];
            $items = array_map(
                fn(MemoryEntry $e) => '- ' . $e->content,
                $areaEntries,
            );
            $sections[] = "**{$area}:**\n" . implode("\n", $items);
            unset($grouped[$area]);
        }

        foreach ($grouped as $area => $areaEntries) {
            $items = array_map(
                fn(MemoryEntry $e) => '- ' . $e->content,
                $areaEntries,
            );
            $sections[] = "**{$area}:**\n" . implode("\n", $items);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Run decay analysis and archive stale, low-value memories.
     *
     * Memories with importance >= 0.9 are pinned and exempt from decay.
     * Archived memories are soft-deleted (excluded from search/summaries but recoverable).
     *
     * @return int Number of memories archived
     */
    public function decayAndArchive(float $archiveThreshold = 0.1, int $halfLifeDays = 60): int
    {
        $this->ensureTables();

        $now = time();

        $stmt = $this->db->query(<<<SQL
            SELECT id, importance, access_count, last_accessed_at, updated_at
            FROM memories
            WHERE archived_at IS NULL AND importance < 0.9
        SQL);

        if ($stmt === false) {
            return 0;
        }

        $toArchive = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $importance = (float) ($row['importance'] ?? 0.5);
            $accessCount = (int) ($row['access_count'] ?? 0);
            $lastAccessed = $row['last_accessed_at'] ?? $row['updated_at'];

            $daysSince = max(0, ($now - strtotime($lastAccessed)) / 86400);
            $recencyFactor = exp(-0.693 * $daysSince / $halfLifeDays);

            // Floor of 0.3 so new memories aren't immediately archived
            $accessFactor = min(1.0, 0.3 + ($accessCount / 30) * 0.7);

            $effectiveScore = $importance * $recencyFactor * $accessFactor;

            if ($effectiveScore < $archiveThreshold) {
                $toArchive[] = $row['id'];
            }
        }

        if (empty($toArchive)) {
            return 0;
        }

        $nowStr = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $placeholders = implode(',', array_fill(0, count($toArchive), '?'));
        $this->db->prepare("UPDATE memories SET archived_at = ? WHERE id IN ({$placeholders})")
            ->execute([$nowStr, ...$toArchive]);

        return count($toArchive);
    }

    /**
     * Restore an archived memory, making it active again.
     */
    public function restoreMemory(string $id): bool
    {
        $this->ensureTables();

        $stmt = $this->db->prepare('UPDATE memories SET archived_at = NULL WHERE id = :id AND archived_at IS NOT NULL');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get the top N most important active memories for prompt recapitulation.
     *
     * @return MemoryEntry[]
     */
    public function getTopImportantMemories(int $limit = 5, ?string $profileId = null): array
    {
        $this->ensureTables();

        $profileClause = $this->buildPersonaClause($profileId);

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at,
                   importance, access_count, last_accessed_at, persona_id, session_id
            FROM memories
            WHERE archived_at IS NULL AND area != :excluded_area{$profileClause}
            ORDER BY importance DESC, access_count DESC, updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue(':excluded_area', self::LEGACY_SESSION_SUMMARY_AREA);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Whether vector search is available.
     */
    public function hasVectorSearch(): bool
    {
        return $this->embeddingProvider !== null;
    }

    /**
     * Get the current cache version counter.
     *
     * Used by MemorySummarizer to detect content changes that don't alter count.
     */
    public function getCacheVersion(): int
    {
        $this->ensureTables();

        try {
            $stmt = $this->db->query('SELECT cache_version FROM memory_summary WHERE id = 1');

            if ($stmt === false) {
                return 0;
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row !== false ? (int) ($row['cache_version'] ?? 0) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Increment the cache version counter after any mutation.
     */
    private function incrementCacheVersion(): void
    {
        try {
            $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');

            // Ensure summary row exists, then increment
            $this->db->exec("INSERT OR IGNORE INTO memory_summary (id, summary, memory_count, generated_at, cache_version) VALUES (1, '', 0, '{$now}', 0)");
            $this->db->exec('UPDATE memory_summary SET cache_version = cache_version + 1 WHERE id = 1');
        } catch (\Throwable) {
            // Cache version failure is non-fatal
        }
    }

    /**
     * Import entries from the legacy FileMemory MEMORY.md file.
     *
     * @param MemoryEntry[] $entries
     * @return int Number of imported entries
     */
    public function importLegacyEntries(array $entries): int
    {
        $count = 0;

        foreach ($entries as $entry) {
            $this->save($entry);
            $count++;
        }

        return $count;
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function connect(): PDO
    {
        $dir = dirname($this->dbPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, CoquiDefaults::DIRECTORY_MODE, true);
        }

        $db = new PDO("sqlite:{$this->dbPath}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SqlitePragmas::applyTo($db);

        return $db;
    }

    private function ensureTables(): void
    {
        if ($this->tablesCreated) {
            return;
        }

        // Core memories table
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS memories (
                id TEXT PRIMARY KEY,
                content TEXT NOT NULL,
                area TEXT NOT NULL DEFAULT 'main',
                tags TEXT NOT NULL DEFAULT '',
                metadata TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_area ON memories(area)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_updated ON memories(updated_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_tags ON memories(tags)');

        // FTS5 virtual table for full-text search
        $this->db->exec(<<<SQL
            CREATE VIRTUAL TABLE IF NOT EXISTS memories_fts USING fts5(
                content,
                tags,
                content='',
                tokenize='porter unicode61'
            )
        SQL);

        // Lookup table to map FTS rowids to memory IDs
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS memories_fts_lookup (
                rowid INTEGER PRIMARY KEY,
                memory_id TEXT NOT NULL,
                FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_fts_lookup_memory ON memories_fts_lookup(memory_id)');

        // Vector embeddings table (optional — populated only when embedding provider is available)
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS memory_embeddings (
                memory_id TEXT PRIMARY KEY,
                embedding BLOB NOT NULL,
                model TEXT NOT NULL,
                dimensions INTEGER NOT NULL,
                created_at TEXT NOT NULL,
                FOREIGN KEY (memory_id) REFERENCES memories(id) ON DELETE CASCADE
            )
        SQL);

        // Summary cache
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS memory_summary (
                id INTEGER PRIMARY KEY CHECK (id = 1),
                summary TEXT NOT NULL DEFAULT '',
                memory_count INTEGER NOT NULL DEFAULT 0,
                generated_at TEXT NOT NULL
            )
        SQL);

        // Migrate: add columns for importance scoring and decay
        $migrations = [
            'ALTER TABLE memories ADD COLUMN importance REAL NOT NULL DEFAULT 0.5',
            'ALTER TABLE memories ADD COLUMN access_count INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE memories ADD COLUMN last_accessed_at TEXT',
            'ALTER TABLE memories ADD COLUMN archived_at TEXT',
            'ALTER TABLE memories ADD COLUMN memory_type TEXT NOT NULL DEFAULT \'knowledge\'',
            'ALTER TABLE memories ADD COLUMN valid_until TEXT',
        ];

        foreach ($migrations as $sql) {
            try {
                $this->db->exec($sql);
            } catch (\PDOException) {
                // Column already exists — skip
            }
        }

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_importance ON memories(importance)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_archived ON memories(archived_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_last_accessed ON memories(last_accessed_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_type ON memories(memory_type)');

        // Migrate summary table for extraction tracking and cache versioning
        $summaryMigrations = [
            'ALTER TABLE memory_summary ADD COLUMN last_extraction_at TEXT',
            'ALTER TABLE memory_summary ADD COLUMN cache_version INTEGER NOT NULL DEFAULT 0',
            'ALTER TABLE memory_summary ADD COLUMN persona_hash INTEGER NOT NULL DEFAULT 0',
        ];

        foreach ($summaryMigrations as $sql) {
            try {
                $this->db->exec($sql);
            } catch (\PDOException) {
                // Column already exists
            }
        }

        // Migrate: add profile and session attribution columns
        $profileMigrations = [
            'ALTER TABLE memories ADD COLUMN persona_id TEXT',
            'ALTER TABLE memories ADD COLUMN session_id TEXT',
        ];

        foreach ($profileMigrations as $sql) {
            try {
                $this->db->exec($sql);
            } catch (\PDOException) {
                // Column already exists
            }
        }

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_memories_persona ON memories(persona_id)');

        $this->tablesCreated = true;
    }

    private function insertFts(string $memoryId, string $content, string $tags): void
    {
        $stmt = $this->db->prepare('INSERT INTO memories_fts (content, tags) VALUES (:content, :tags)');
        $stmt->execute([':content' => $content, ':tags' => $tags]);

        $rowid = $this->db->lastInsertId();

        $stmt = $this->db->prepare('INSERT INTO memories_fts_lookup (rowid, memory_id) VALUES (:rowid, :memory_id)');
        $stmt->execute([':rowid' => $rowid, ':memory_id' => $memoryId]);
    }

    private function deleteFts(string $memoryId): void
    {
        // Get the FTS rowid for this memory
        $stmt = $this->db->prepare('SELECT rowid FROM memories_fts_lookup WHERE memory_id = :id');
        $stmt->execute([':id' => $memoryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            // Delete from FTS using the special delete command
            $ftsStmt = $this->db->prepare(<<<SQL
                INSERT INTO memories_fts(memories_fts, rowid, content, tags)
                VALUES ('delete', :rowid, (SELECT content FROM memories WHERE id = :id), (SELECT tags FROM memories WHERE id = :id))
            SQL);
            $ftsStmt->execute([':rowid' => $row['rowid'], ':id' => $memoryId]);

            $this->db->prepare('DELETE FROM memories_fts_lookup WHERE memory_id = :id')->execute([':id' => $memoryId]);
        }
    }

    private function embedMemory(string $id, string $content): void
    {
        if ($this->embeddingProvider === null) {
            return;
        }

        try {
            $embedding = $this->embeddingProvider->embedText($content);
            $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');

            $stmt = $this->db->prepare(<<<SQL
                INSERT OR REPLACE INTO memory_embeddings (memory_id, embedding, model, dimensions, created_at)
                VALUES (:memory_id, :embedding, :model, :dimensions, :created_at)
            SQL);

            $stmt->execute([
                ':memory_id' => $id,
                ':embedding' => pack('f*', ...$embedding),
                ':model' => $this->embeddingProvider->model(),
                ':dimensions' => $this->embeddingProvider->dimensions(),
                ':created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Embedding failure is non-fatal — FTS5 search still works
        }
    }

    /**
     * @return array<string, array<string, mixed>> Candidates keyed by memory ID
     */
    private function vectorSearchCandidates(string $query, int $limit, float $threshold, ?string $profileId = null): array
    {
        try {
            $queryEmbedding = $this->embeddingProvider?->embedText($query);
        } catch (\Throwable) {
            return []; // Fall back to FTS5
        }

        if ($queryEmbedding === null || empty($queryEmbedding)) {
            return [];
        }

        $profileClause = $this->buildPersonaClause($profileId, 'm');

        // Load all embeddings for active memories and compute cosine similarity in PHP
        $stmt = $this->db->query(<<<SQL
            SELECT e.memory_id, e.embedding, e.dimensions,
                   m.content, m.area, m.tags, m.metadata, m.created_at, m.updated_at,
                   m.importance, m.access_count, m.last_accessed_at, m.persona_id, m.session_id
            FROM memory_embeddings e
            JOIN memories m ON m.id = e.memory_id
            WHERE m.archived_at IS NULL{$profileClause}
        SQL);

        if ($stmt === false) {
            return [];
        }

        $candidates = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $storedEmbedding = array_values(unpack('f*', $row['embedding']) ?: []);
            $similarity = $this->cosineSimilarity($queryEmbedding, $storedEmbedding);

            if ($similarity >= $threshold) {
                $id = $row['memory_id'];
                $candidates[$id] = [
                    'id' => $id,
                    'content' => $row['content'],
                    'area' => $row['area'],
                    'tags' => $row['tags'],
                    'metadata' => $row['metadata'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'importance' => (float) ($row['importance'] ?? 0.5),
                    'access_count' => (int) ($row['access_count'] ?? 0),
                    'last_accessed_at' => $row['last_accessed_at'] ?? null,
                    'similarity' => $similarity,
                ];
            }
        }

        return $candidates;
    }

    /**
     * @return array<string, array<string, mixed>> Candidates keyed by memory ID
     */
    private function ftsSearchCandidates(string $query, int $limit, ?string $profileId = null): array
    {
        $sanitized = $this->sanitizeFtsQuery($query);

        if ($sanitized === '') {
            return $this->likeSearchCandidates($query, $limit, $profileId);
        }

        $profileClause = $this->buildPersonaClause($profileId, 'm');

        try {
            $stmt = $this->db->prepare(<<<SQL
                SELECT l.memory_id, m.content, m.area, m.tags, m.metadata, m.created_at, m.updated_at,
                       m.importance, m.access_count, m.last_accessed_at, m.persona_id, m.session_id
                FROM memories_fts f
                JOIN memories_fts_lookup l ON l.rowid = f.rowid
                JOIN memories m ON m.id = l.memory_id
                WHERE memories_fts MATCH :query AND m.archived_at IS NULL{$profileClause}
                ORDER BY rank
                LIMIT :limit
            SQL);

            $stmt->bindValue(':query', $sanitized);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $candidates = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $id = $row['memory_id'];
                $candidates[$id] = [
                    'id' => $id,
                    'content' => $row['content'],
                    'area' => $row['area'],
                    'tags' => $row['tags'],
                    'metadata' => $row['metadata'],
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                    'importance' => (float) ($row['importance'] ?? 0.5),
                    'access_count' => (int) ($row['access_count'] ?? 0),
                    'last_accessed_at' => $row['last_accessed_at'] ?? null,
                    'similarity' => null,
                ];
            }

            if (!empty($candidates)) {
                return $candidates;
            }
        } catch (\Throwable) {
            // FTS query syntax error — fall back to LIKE
        }

        return $this->likeSearchCandidates($query, $limit, $profileId);
    }

    /**
     * Simple LIKE-based fallback search when FTS5 fails or returns nothing.
     *
     * @return array<string, array<string, mixed>> Candidates keyed by memory ID
     */
    private function likeSearchCandidates(string $query, int $limit, ?string $profileId = null): array
    {
        $profileClause = $this->buildPersonaClause($profileId);

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at,
                   importance, access_count, last_accessed_at, persona_id, session_id
            FROM memories
            WHERE (content LIKE :query OR tags LIKE :query) AND archived_at IS NULL{$profileClause}
            ORDER BY updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $candidates = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = $row['id'];
            $candidates[$id] = [
                'id' => $id,
                'content' => $row['content'],
                'area' => $row['area'],
                'tags' => $row['tags'],
                'metadata' => $row['metadata'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
                'importance' => (float) ($row['importance'] ?? 0.5),
                'access_count' => (int) ($row['access_count'] ?? 0),
                'last_accessed_at' => $row['last_accessed_at'] ?? null,
                'similarity' => null,
            ];
        }

        return $candidates;
    }

    /**
     * Compute multi-dimensional composite score for memory ranking.
     *
     * Blends similarity, recency, importance, and access frequency.
     */
    private function computeCompositeScore(
        ?float $similarity,
        float $importance,
        ?string $lastAccessedAt,
        int $accessCount,
        string $updatedAt,
    ): float {
        $wSim = 0.40;
        $wRec = 0.20;
        $wImp = 0.25;
        $wAcc = 0.15;

        // Similarity component (FTS/LIKE results get a neutral 0.5)
        $simScore = $similarity ?? 0.5;

        // Recency component (exponential decay, half-life 30 days)
        $referenceTime = $lastAccessedAt ?? $updatedAt;
        $daysSinceAccess = max(0, (time() - strtotime($referenceTime)) / 86400);
        $recencyScore = exp(-0.693 * $daysSinceAccess / 30);

        // Access frequency component (capped at 1.0)
        $accScore = min(1.0, $accessCount / 20);

        return ($wSim * $simScore) + ($wRec * $recencyScore) + ($wImp * $importance) + ($wAcc * $accScore);
    }

    /**
     * Convert a raw candidate array to a MemoryEntry with composite score.
     *
     * @param array<string, mixed> $candidate
     */
    private function candidateToEntry(array $candidate): MemoryEntry
    {
        $metadata = json_decode($candidate['metadata'] ?? '{}', true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        if (isset($candidate['tags']) && $candidate['tags'] !== '') {
            $metadata['tags'] = $candidate['tags'];
        }
        $metadata['importance'] = $candidate['importance'] ?? 0.5;
        $metadata['access_count'] = $candidate['access_count'] ?? 0;
        if (isset($candidate['last_accessed_at'])) {
            $metadata['last_accessed_at'] = $candidate['last_accessed_at'];
        }

        return new MemoryEntry(
            content: $candidate['content'] ?? '',
            area: $candidate['area'] ?? 'main',
            metadata: $metadata,
            id: $candidate['id'] ?? null,
            score: $candidate['composite'] ?? $candidate['similarity'] ?? null,
            createdAt: isset($candidate['created_at']) ? new DateTimeImmutable($candidate['created_at']) : null,
            personaId: $candidate['persona_id'] ?? null,
            sessionId: $candidate['session_id'] ?? null,
        );
    }

    /**
     * Batch-update access counters for memories returned by search.
     *
     * @param string[] $ids
     */
    private function reinforceAccess(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $this->db->prepare(<<<SQL
            UPDATE memories
            SET access_count = access_count + 1,
                last_accessed_at = ?
            WHERE id IN ({$placeholders})
        SQL)->execute([$now, ...$ids]);
    }

    /**
     * Sanitize a user query for FTS5 MATCH syntax.
     *
     * Wraps individual words in quotes and joins with OR to maximize matches.
     */
    private function sanitizeFtsQuery(string $query): string
    {
        // Remove FTS5 special characters
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query) ?? $query;
        $words = preg_split('/\s+/', trim($clean), -1, PREG_SPLIT_NO_EMPTY);

        if (empty($words)) {
            return '';
        }

        // Wrap each word in quotes and join with OR for broad matching
        $terms = array_map(fn(string $w) => '"' . $w . '"', $words);

        return implode(' OR ', $terms);
    }

    /**
    /**
     * Build a SQL WHERE clause fragment for profile filtering.
     *
     * When a profile is active, returns memories belonging to that profile
     * plus untagged (legacy) memories. When null, returns no additional filter.
     */
    private function buildPersonaClause(?string $profileId, string $alias = ''): string
    {
        if ($profileId === null) {
            return '';
        }

        $col = $alias !== '' ? "{$alias}.persona_id" : 'persona_id';
        $escaped = $this->db->quote($profileId);

        return " AND ({$col} = {$escaped} OR {$col} IS NULL)";
    }

    /**
     * Compute cosine similarity between two vectors.
     *
     * @param float[] $a
     * @param float[] $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0, $n = count($a); $i < $n; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        $denominator = sqrt($normA) * sqrt($normB);

        return $denominator > 0.0 ? $dotProduct / $denominator : 0.0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowToEntry(array $row): MemoryEntry
    {
        $id = $row['memory_id'] ?? $row['id'] ?? null;
        $metadata = json_decode($row['metadata'] ?? '{}', true);

        if (!is_array($metadata)) {
            $metadata = [];
        }

        // Preserve tags in metadata for consistency
        if (isset($row['tags']) && $row['tags'] !== '') {
            $metadata['tags'] = $row['tags'];
        }

        // Include scoring metadata when available
        if (isset($row['importance'])) {
            $metadata['importance'] = (float) $row['importance'];
        }
        if (isset($row['access_count'])) {
            $metadata['access_count'] = (int) $row['access_count'];
        }
        if (isset($row['last_accessed_at'])) {
            $metadata['last_accessed_at'] = $row['last_accessed_at'];
        }
        if (isset($row['archived_at'])) {
            $metadata['archived_at'] = $row['archived_at'];
        }

        return new MemoryEntry(
            content: $row['content'] ?? '',
            area: $row['area'] ?? 'main',
            metadata: $metadata,
            id: is_string($id) ? $id : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
            type: $row['memory_type'] ?? 'knowledge',
            validUntil: is_string($row['valid_until'] ?? null) && $row['valid_until'] !== ''
                ? new DateTimeImmutable($row['valid_until'])
                : null,
            personaId: $row['persona_id'] ?? null,
            sessionId: $row['session_id'] ?? null,
        );
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return MemoryEntry[]
     */
    private function rowsToEntries(array $rows): array
    {
        return array_map(fn(array $row) => $this->rowToEntry($row), $rows);
    }
}
