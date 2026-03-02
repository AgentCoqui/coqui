<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Memory;

use CarmeloSantana\PHPAgents\Contract\EmbeddingProviderInterface;
use CarmeloSantana\PHPAgents\Contract\MemoryInterface;
use CarmeloSantana\PHPAgents\Memory\MemoryEntry;
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
final class MemoryStore implements MemoryInterface
{
    private PDO $db;
    private bool $tablesCreated = false;

    public function __construct(
        private readonly string $dbPath,
        private readonly ?EmbeddingProviderInterface $embeddingProvider = null,
    ) {
        $this->db = $this->connect();
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

        $id = bin2hex(random_bytes(16));
        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');
        $tags = $entry->metadata['tags'] ?? '';
        $metadata = json_encode($entry->metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO memories (id, content, area, tags, metadata, created_at, updated_at)
            VALUES (:id, :content, :area, :tags, :metadata, :created_at, :updated_at)
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':content' => $entry->content,
            ':area' => $entry->area,
            ':tags' => $tags,
            ':metadata' => $metadata,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        // Update FTS5 index
        $this->insertFts($id, $entry->content, $tags);

        // Generate embedding if provider available
        $this->embedMemory($id, $entry->content);

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
    public function search(string $query, int $limit = 10, float $threshold = 0.7): array
    {
        $this->ensureTables();

        if (trim($query) === '') {
            return [];
        }

        // Try vector search first if embeddings are available
        if ($this->embeddingProvider !== null) {
            $vectorResults = $this->vectorSearch($query, $limit, $threshold);
            if (!empty($vectorResults)) {
                return $vectorResults;
            }
        }

        // Fall back to FTS5 keyword search
        return $this->ftsSearch($query, $limit);
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
    }

    /**
     * Delete all memories semantically matching a query.
     *
     * @return int Number of deleted entries
     */
    public function forget(string $query, float $threshold = 0.7): int
    {
        $matches = $this->search($query, limit: 100, threshold: $threshold);
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
    public function list(string $area = 'main', int $limit = 50): array
    {
        $this->ensureTables();

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at
            FROM memories
            WHERE area = :area
            ORDER BY updated_at DESC
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
    public function update(string $id, string $content, ?string $area = null, ?string $tags = null): bool
    {
        $this->ensureTables();

        // Load existing to merge unchanged fields
        $existing = $this->getById($id);
        if ($existing === null) {
            return false;
        }

        $area ??= $existing->area;
        $tags ??= $existing->metadata['tags'] ?? '';
        $now = (new DateTimeImmutable())->format('Y-m-d\TH:i:s');

        $stmt = $this->db->prepare(<<<SQL
            UPDATE memories
            SET content = :content, area = :area, tags = :tags, updated_at = :updated_at
            WHERE id = :id
        SQL);

        $stmt->execute([
            ':id' => $id,
            ':content' => $content,
            ':area' => $area,
            ':tags' => $tags,
            ':updated_at' => $now,
        ]);

        // Rebuild FTS index for this entry
        $this->deleteFts($id);
        $this->insertFts($id, $content, $tags);

        // Regenerate embedding
        $this->db->prepare('DELETE FROM memory_embeddings WHERE memory_id = :id')->execute([':id' => $id]);
        $this->embedMemory($id, $content);

        return true;
    }

    /**
     * Get a specific memory by ID.
     */
    public function getById(string $id): ?MemoryEntry
    {
        $this->ensureTables();

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at
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
    public function listByTags(array $tags, int $limit = 50): array
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
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at
            FROM memories
            WHERE ({$where})
            ORDER BY updated_at DESC
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
    public function listAll(int $limit = 100): array
    {
        $this->ensureTables();

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at
            FROM memories
            ORDER BY updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Count total memories, optionally filtered by area.
     */
    public function count(?string $area = null): int
    {
        $this->ensureTables();

        if ($area !== null) {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM memories WHERE area = :area');
            $stmt->execute([':area' => $area]);
        } else {
            $stmt = $this->db->query('SELECT COUNT(*) FROM memories');
        }

        return (int) ($stmt?->fetchColumn() ?? 0);
    }

    /**
     * Get a compact summary of all core memories for system prompt injection.
     *
     * Returns formatted text suitable for including in an agent's system prompt.
     * Summarizes preferences and key facts without excessive detail.
     */
    public function getCoreSummary(int $limit = 30): string
    {
        $entries = $this->listAll(limit: $limit);

        if (empty($entries)) {
            return '';
        }

        $sections = [];
        $grouped = [];

        foreach ($entries as $entry) {
            $grouped[$entry->area][] = $entry;
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
     * Whether vector search is available.
     */
    public function hasVectorSearch(): bool
    {
        return $this->embeddingProvider !== null;
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
            mkdir($dir, 0755, true);
        }

        $db = new PDO("sqlite:{$this->dbPath}");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');

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
     * @return MemoryEntry[]
     */
    private function vectorSearch(string $query, int $limit, float $threshold): array
    {
        try {
            $queryEmbedding = $this->embeddingProvider?->embedText($query);
        } catch (\Throwable) {
            return []; // Fall back to FTS5
        }

        if ($queryEmbedding === null || empty($queryEmbedding)) {
            return [];
        }

        // Load all embeddings and compute cosine similarity in PHP
        $stmt = $this->db->query(<<<SQL
            SELECT e.memory_id, e.embedding, e.dimensions,
                   m.content, m.area, m.tags, m.metadata, m.created_at, m.updated_at
            FROM memory_embeddings e
            JOIN memories m ON m.id = e.memory_id
        SQL);

        if ($stmt === false) {
            return [];
        }

        $scored = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $storedEmbedding = array_values(unpack('f*', $row['embedding']) ?: []);
            $similarity = $this->cosineSimilarity($queryEmbedding, $storedEmbedding);

            if ($similarity >= $threshold) {
                $scored[] = [
                    'row' => $row,
                    'score' => $similarity,
                ];
            }
        }

        // Sort by similarity descending
        usort($scored, fn(array $a, array $b) => $b['score'] <=> $a['score']);

        $results = [];
        foreach (array_slice($scored, 0, $limit) as $item) {
            $entry = $this->rowToEntry($item['row']);
            $results[] = new MemoryEntry(
                content: $entry->content,
                area: $entry->area,
                metadata: $entry->metadata,
                id: $entry->id,
                score: $item['score'],
                createdAt: $entry->createdAt,
            );
        }

        return $results;
    }

    /**
     * @return MemoryEntry[]
     */
    private function ftsSearch(string $query, int $limit): array
    {
        // Sanitize query for FTS5 — escape special characters and wrap in quotes
        $sanitized = $this->sanitizeFtsQuery($query);

        if ($sanitized === '') {
            // Fall back to LIKE search if FTS sanitization removes everything
            return $this->likeSearch($query, $limit);
        }

        try {
            $stmt = $this->db->prepare(<<<SQL
                SELECT l.memory_id, m.content, m.area, m.tags, m.metadata, m.created_at, m.updated_at,
                       rank
                FROM memories_fts f
                JOIN memories_fts_lookup l ON l.rowid = f.rowid
                JOIN memories m ON m.id = l.memory_id
                WHERE memories_fts MATCH :query
                ORDER BY rank
                LIMIT :limit
            SQL);

            $stmt->bindValue(':query', $sanitized);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();

            $results = $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));

            if (!empty($results)) {
                return $results;
            }
        } catch (\Throwable) {
            // FTS query syntax error — fall back to LIKE
        }

        return $this->likeSearch($query, $limit);
    }

    /**
     * Simple LIKE-based fallback search when FTS5 fails or returns nothing.
     *
     * @return MemoryEntry[]
     */
    private function likeSearch(string $query, int $limit): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content, area, tags, metadata, created_at, updated_at
            FROM memories
            WHERE content LIKE :query OR tags LIKE :query
            ORDER BY updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->rowsToEntries($stmt->fetchAll(PDO::FETCH_ASSOC));
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

        return new MemoryEntry(
            content: $row['content'] ?? '',
            area: $row['area'] ?? 'main',
            metadata: $metadata,
            id: is_string($id) ? $id : null,
            createdAt: isset($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
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
