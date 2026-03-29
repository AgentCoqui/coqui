<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * SQLite-backed project and sprint persistence.
 *
 * Projects are session-independent containers that organize work across
 * multiple sessions. Sprints represent ordered work chunks within a project,
 * following a 4-state lifecycle: planned → in_progress → review → complete.
 */
final class ProjectStore
{
    /** @var array<string, list<string>> Valid state transitions for sprints */
    private const array SPRINT_TRANSITIONS = [
        'planned' => ['in_progress'],
        'in_progress' => ['review'],
        'review' => ['complete', 'rejected'],
        'rejected' => ['in_progress'],
    ];

    private const int MAX_REVIEW_ROUNDS_CAP = 5;

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
            CREATE TABLE IF NOT EXISTS sprints (
                id TEXT PRIMARY KEY,
                project_id TEXT NOT NULL,
                title TEXT NOT NULL,
                sprint_number INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'planned',
                contract_artifact_id TEXT,
                acceptance_criteria TEXT,
                reviewer_notes TEXT,
                review_round INTEGER NOT NULL DEFAULT 0,
                max_review_rounds INTEGER NOT NULL DEFAULT 3,
                last_session_id TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                completed_at TEXT,
                FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_sprints_project ON sprints(project_id)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_sprints_status ON sprints(project_id, status)
        SQL);

        $this->db->exec(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_slug ON projects(slug)
        SQL);
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

        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO projects (id, title, slug, description, status, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'active', ?, ?)
        SQL);
        $stmt->execute([$id, $title, $slug, $description, $now, $now]);

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
            SELECT p.*,
                   (SELECT COUNT(*) FROM sprints WHERE project_id = p.id) AS sprint_count,
                   (SELECT COUNT(*) FROM sprints WHERE project_id = p.id AND status = 'complete') AS sprints_completed
            FROM projects p
            {$whereClause}
            ORDER BY p.updated_at DESC
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
        $now = gmdate('Y-m-d\TH:i:s\Z');
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
     * Delete a project and all its sprints (via CASCADE).
     */
    public function deleteProject(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Sprint CRUD
    // =========================================================================

    /**
     * Create a new sprint within a project.
     *
     * @throws \InvalidArgumentException If project does not exist.
     */
    public function createSprint(
        string $projectId,
        string $title,
        ?string $acceptanceCriteria = null,
        ?string $lastSessionId = null,
        int $maxReviewRounds = 3,
    ): string {
        $project = $this->getProject($projectId);
        if ($project === null) {
            throw new \InvalidArgumentException(sprintf('Project "%s" not found.', $projectId));
        }

        $id = bin2hex(random_bytes(16));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $sprintNumber = $this->nextSprintNumber($projectId);
        $maxReviewRounds = min($maxReviewRounds, self::MAX_REVIEW_ROUNDS_CAP);

        $stmt = $this->db->prepare(<<<'SQL'
            INSERT INTO sprints (id, project_id, title, sprint_number, status, acceptance_criteria, last_session_id, max_review_rounds, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'planned', ?, ?, ?, ?, ?)
        SQL);
        $stmt->execute([
            $id,
            $projectId,
            $title,
            $sprintNumber,
            $acceptanceCriteria,
            $lastSessionId,
            $maxReviewRounds,
            $now,
            $now,
        ]);

        return $id;
    }

    /**
     * Get a sprint by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getSprint(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM sprints WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * List sprints for a project with optional status filter.
     *
     * @return list<array<string, mixed>>
     */
    public function listSprints(string $projectId, ?string $status = null): array
    {
        $where = ['project_id = ?'];
        $params = [$projectId];

        if ($status !== null) {
            $where[] = 'status = ?';
            $params[] = $status;
        }

        $whereClause = implode(' AND ', $where);

        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM sprints
            WHERE {$whereClause}
            ORDER BY sprint_number ASC
        SQL);
        $stmt->execute($params);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update a sprint's modifiable fields.
     */
    public function updateSprint(
        string $id,
        ?string $title = null,
        ?string $acceptanceCriteria = null,
        ?string $contractArtifactId = null,
        ?string $lastSessionId = null,
    ): bool {
        $sprint = $this->getSprint($id);
        if ($sprint === null) {
            return false;
        }

        $sets = ['updated_at = ?'];
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $params = [$now];

        if ($title !== null) {
            $sets[] = 'title = ?';
            $params[] = $title;
        }

        if ($acceptanceCriteria !== null) {
            $sets[] = 'acceptance_criteria = ?';
            $params[] = $acceptanceCriteria;
        }

        if ($contractArtifactId !== null) {
            $sets[] = 'contract_artifact_id = ?';
            $params[] = $contractArtifactId;
        }

        if ($lastSessionId !== null) {
            $sets[] = 'last_session_id = ?';
            $params[] = $lastSessionId;
        }

        $params[] = $id;
        $sql = 'UPDATE sprints SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    /**
     * Transition a sprint to a new lifecycle state.
     *
     * Valid transitions:
     *   planned → in_progress
     *   in_progress → review
     *   review → complete | rejected
     *   rejected → in_progress
     *
     * @throws \InvalidArgumentException On invalid transition or max review rounds exceeded.
     */
    public function transitionSprint(string $id, string $newStatus, ?string $notes = null): bool
    {
        $sprint = $this->getSprint($id);
        if ($sprint === null) {
            return false;
        }

        $currentStatus = (string) $sprint['status'];
        $allowed = self::SPRINT_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid sprint transition: %s → %s. Allowed: %s',
                $currentStatus,
                $newStatus,
                $allowed !== [] ? implode(', ', $allowed) : 'none (terminal state)',
            ));
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $sets = ['status = ?', 'updated_at = ?'];
        $params = [$newStatus, $now];

        if ($newStatus === 'rejected') {
            $reviewRound = (int) $sprint['review_round'] + 1;
            $maxRounds = (int) $sprint['max_review_rounds'];

            if ($reviewRound > $maxRounds) {
                throw new \InvalidArgumentException(sprintf(
                    'Sprint has exceeded max review rounds (%d/%d). Requires user intervention.',
                    $reviewRound,
                    $maxRounds,
                ));
            }

            $sets[] = 'review_round = ?';
            $params[] = $reviewRound;

            if ($notes !== null) {
                $sets[] = 'reviewer_notes = ?';
                $params[] = $notes;
            }
        }

        if ($newStatus === 'complete') {
            $sets[] = 'completed_at = ?';
            $params[] = $now;
        }

        $params[] = $id;
        $sql = 'UPDATE sprints SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $this->db->prepare($sql)->execute($params);

        return true;
    }

    /**
     * Get the first active sprint (in_progress or review) for a project.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveSprintForProject(string $projectId): ?array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT * FROM sprints
            WHERE project_id = ? AND status IN ('in_progress', 'review')
            ORDER BY sprint_number ASC
            LIMIT 1
        SQL);
        $stmt->execute([$projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Get sprints with a specific last_session_id that are in active states.
     *
     * @return list<array<string, mixed>>
     */
    public function getActiveSprintsForSession(string $sessionId): array
    {
        $stmt = $this->db->prepare(<<<'SQL'
            SELECT s.*, p.title AS project_title, p.slug AS project_slug
            FROM sprints s
            JOIN projects p ON p.id = s.project_id
            WHERE s.last_session_id = ? AND s.status IN ('in_progress', 'review', 'rejected')
            ORDER BY s.sprint_number ASC
        SQL);
        $stmt->execute([$sessionId]);

        /** @var list<array<string, mixed>> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get sprint progress by aggregating linked todo stats.
     *
     * @param string|null $sessionId When provided, scopes stats to this session.
     * @return array{total: int, completed: int, in_progress: int, pending: int, percent: int}
     */
    public function getSprintProgress(string $sprintId, TodoStore $todoStore, ?string $sessionId = null): array
    {
        $stats = $todoStore->getSprintStats($sprintId, $sessionId);

        $total = $stats['total'];
        $percent = $total > 0 ? (int) round(($stats['completed'] / $total) * 100) : 0;

        return [
            'total' => $total,
            'completed' => $stats['completed'],
            'in_progress' => $stats['in_progress'],
            'pending' => $stats['pending'],
            'percent' => $percent,
        ];
    }

    /**
     * Delete a sprint.
     */
    public function deleteSprint(string $id): bool
    {
        $stmt = $this->db->prepare('DELETE FROM sprints WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->rowCount() > 0;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function nextSprintNumber(string $projectId): int
    {
        $stmt = $this->db->prepare(
            'SELECT MAX(sprint_number) FROM sprints WHERE project_id = ?',
        );
        $stmt->execute([$projectId]);
        $max = $stmt->fetchColumn();

        return $max !== false && $max !== null ? ((int) $max) + 1 : 1;
    }

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
}
