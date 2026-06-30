<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;
use CoquiBot\Coqui\Support\SchemaHelper;
use PDO;

/**
 * SQLite-backed project persistence.
 *
 * Projects are session-independent containers that organize work across
 * multiple sessions. Each project is backed by a workspace directory; the
 * active project scopes an agent's work to one place.
 */
final class ProjectStore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS projects (
                id TEXT PRIMARY KEY,
                title TEXT NOT NULL,
                slug TEXT NOT NULL UNIQUE,
                description TEXT,
                status TEXT NOT NULL DEFAULT 'active',
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_slug ON projects(slug)
        SQL);

        // Migration: add directory column for project workspace directories
        $this->migrateAddColumn('projects', 'directory', 'TEXT DEFAULT NULL');

        // Migration: drop the legacy sprints table (removed with the sprint subsystem).
        $this->db->exec('DROP INDEX IF EXISTS idx_sprints_project');
        $this->db->exec('DROP INDEX IF EXISTS idx_sprints_status');
        $this->db->exec('DROP TABLE IF EXISTS sprints');
    }

    private function migrateAddColumn(string $table, string $column, string $definition): void
    {
        SchemaHelper::addColumnIfMissing($this->db, $table, $column, $definition);
    }

    // =========================================================================
    // Project CRUD
    // =========================================================================

    /**
     * Create a new project.
     *
     * @throws \InvalidArgumentException If slug format is invalid or already exists.
     */
    public function createProject(
        string $title,
        string $slug,
        ?string $description = null,
    ): string {
        if (!$this->isValidSlug($slug)) {
            throw new \InvalidArgumentException(
                'Slug must be lowercase alphanumeric with hyphens, 1-64 chars, no leading/trailing/double hyphens.',
            );
        }

        $existing = $this->getProjectBySlug($slug);
        if ($existing !== null) {
            throw new \InvalidArgumentException(sprintf('Project slug "%s" already exists.', $slug));
        }

        $id = IdGenerator::hex();
        $now = Clock::nowUtc();

        // Compute project directory name: {slug}-{first 8 chars of id}
        $directory = $slug . '-' . substr($id, 0, 8);

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO projects (id, title, slug, description, directory, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 'active', ?, ?)
        SQL);
        $stmt->execute([$id, $title, $slug, $description, $directory, $now, $now]);

        return $id;
    }

    /**
     * Get a project by ID or slug.
     *
     * @return array<string, mixed>|null
     */
    public function getProject(string $idOrSlug): ?array
    {
        // Try by ID first
        $stmt = $this->db->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$idOrSlug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $row;
        }

        // Fall back to slug
        return $this->getProjectBySlug($idOrSlug);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getProjectBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM projects WHERE slug = ?');
        $stmt->execute([$slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List projects with optional status filter.
     *
     * @return list<array<string, mixed>>
     */
    public function listProjects(?string $status = null, int $limit = 50): array
    {
        $where = [];
        $params = [];

        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $whereClause = $where !== [] ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM projects
            {$whereClause}
            ORDER BY updated_at DESC
            LIMIT ?
        SQL);

        $params[] = $limit;
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a project's fields.
     */
    public function updateProject(
        string $id,
        ?string $title = null,
        ?string $description = null,
        ?string $status = null,
    ): bool {
        $project = $this->getProject($id);
        if ($project === null) {
            return false;
        }

        $sets = ['updated_at = ?'];
        $now = Clock::nowUtc();
        $params = [$now];

        if ($title !== null) {
            $sets[] = 'title = ?';
            $params[] = $title;
        }

        if ($description !== null) {
            $sets[] = 'description = ?';
            $params[] = $description;
        }

        if ($status !== null) {
            if (!in_array($status, ['active', 'completed', 'archived'], true)) {
                throw new \InvalidArgumentException(sprintf('Invalid project status: %s', $status));
            }
            $sets[] = 'status = ?';
            $params[] = $status;
        }

        $params[] = $id;
        $sql = 'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    /**
     * Delete a project.
     */
    public function deleteProject(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Delete all projects.
     *
     * @return int Number of projects deleted.
     */
    public function deleteAllProjects(): int
    {
        $stmt = $this->db->prepare('DELETE FROM projects');
        $stmt->execute();

        return $stmt->rowCount();
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function isValidSlug(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > 64) {
            return false;
        }

        if ($slug !== strtolower($slug)) {
            return false;
        }

        if ((bool) preg_match('/[^a-z0-9\-]/', $slug)) {
            return false;
        }

        if (str_starts_with($slug, '-') || str_ends_with($slug, '-')) {
            return false;
        }

        if (str_contains($slug, '--')) {
            return false;
        }

        return true;
    }

    /**
     * Get the workspace-relative directory path for a project.
     *
     * Returns the directory name from the database, or computes a fallback
     * for projects created before the directory column was added.
     */
    public function getProjectDirectory(string $projectId): string
    {
        $project = $this->getProject($projectId);
        if ($project === null) {
            throw new \InvalidArgumentException(sprintf('Project "%s" not found.', $projectId));
        }

        $dir = $project['directory'] ?? null;
        if (is_string($dir) && $dir !== '') {
            return $dir;
        }

        // Fallback for pre-migration projects: {slug}-{first 8 chars of id}
        return $project['slug'] . '-' . substr($project['id'], 0, 8);
    }

    /**
     * Get aggregated project context for system prompt injection.
     *
     * Returns project metadata and the workspace directory path. Artifacts
     * are handled by their own toolkit — this focuses on project-level
     * context the agent needs for orientation.
     *
     * @return array{project: array<string, mixed>, directory: string}
     */
    public function getProjectContext(string $projectId): array
    {
        $project = $this->getProject($projectId);
        if ($project === null) {
            throw new \InvalidArgumentException(sprintf('Project "%s" not found.', $projectId));
        }

        return [
            'project' => $project,
            'directory' => $this->getProjectDirectory($projectId),
        ];
    }
}
