<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use CarmeloSantana\PHPAgents\Enum\Role;
use CarmeloSantana\PHPAgents\Message\AssistantMessage;
use CarmeloSantana\PHPAgents\Message\Conversation;
use CarmeloSantana\PHPAgents\Message\SystemMessage;
use CarmeloSantana\PHPAgents\Message\ToolResultMessage;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Tool\ToolCall;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Support\ProcessSpawner;
use PDO;

/**
 * SQLite-backed session persistence for Coqui.
 *
 * Each terminal instance can have its own database file, enabling
 * parallel sessions and resume capability.
 */
final class SessionStorage
{
    private PDO $db;
    private ?\Closure $expectedCoquiProcessChecker;

    public function __construct(string $dbPath, ?\Closure $expectedCoquiProcessChecker = null)
    {
        $this->expectedCoquiProcessChecker = $expectedCoquiProcessChecker;

        $dir = dirname($dbPath);
        if ($dir !== '' && $dir !== '.' && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->db = new PDO("sqlite:{$dbPath}");
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode=WAL');
        $this->db->exec('PRAGMA foreign_keys=ON');
        $this->db->exec('PRAGMA synchronous=NORMAL');
        $this->db->exec('PRAGMA cache_size=-8000');
        $this->db->exec('PRAGMA temp_store=MEMORY');

        $this->createTables();
    }

    private function createTables(): void
    {
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS sessions (
                id TEXT PRIMARY KEY,
                model_role TEXT NOT NULL,
                model TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                token_count INTEGER DEFAULT 0
            )
        SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS messages (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                role TEXT NOT NULL,
                content TEXT NOT NULL,
                tool_calls TEXT,
                tool_call_id TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS child_runs (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                parent_iteration INTEGER NOT NULL,
                agent_role TEXT NOT NULL,
                model TEXT NOT NULL,
                prompt TEXT NOT NULL,
                result TEXT NOT NULL,
                token_count INTEGER DEFAULT 0,
                metadata TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS audit_log (
                id TEXT PRIMARY KEY,
                session_id TEXT,
                tool_name TEXT NOT NULL,
                arguments TEXT NOT NULL,
                action TEXT NOT NULL,
                reason TEXT,
                created_at TEXT NOT NULL,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL
            )
        SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS turns (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                turn_number INTEGER NOT NULL,
                user_prompt TEXT NOT NULL,
                response_text TEXT,
                model TEXT,
                prompt_tokens INTEGER DEFAULT 0,
                completion_tokens INTEGER DEFAULT 0,
                total_tokens INTEGER DEFAULT 0,
                iterations INTEGER DEFAULT 0,
                duration_ms INTEGER DEFAULT 0,
                tools_used TEXT,
                child_agent_count INTEGER DEFAULT 0,
                turn_process_id TEXT DEFAULT NULL,
                result_payload TEXT DEFAULT NULL,
                created_at TEXT NOT NULL,
                completed_at TEXT,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_messages_session ON messages(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_child_runs_session ON child_runs(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_session ON audit_log(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_turns_session ON turns(session_id)');

        // Migrations for existing tables — add turn_id FK columns
        $this->migrateAddColumn('messages', 'turn_id', 'TEXT REFERENCES turns(id) ON DELETE SET NULL');
        $this->migrateAddColumn('audit_log', 'turn_id', 'TEXT REFERENCES turns(id) ON DELETE SET NULL');
        $this->migrateAddColumn('turns', 'turn_process_id', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('turns', 'result_payload', 'TEXT DEFAULT NULL');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_messages_turn ON messages(turn_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_turn ON audit_log(turn_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_turns_turn_process ON turns(turn_process_id)');

        // Migration: add title column to sessions
        $this->migrateAddColumn('sessions', 'title', 'TEXT DEFAULT NULL');

        // Background tasks tables
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS background_tasks (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                parent_session_id TEXT,
                pid INTEGER,
                status TEXT NOT NULL DEFAULT 'pending',
                title TEXT,
                prompt TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'orchestrator',
                metadata TEXT,
                result TEXT,
                error TEXT,
                max_iterations INTEGER DEFAULT 25,
                created_at TEXT NOT NULL,
                started_at TEXT,
                completed_at TEXT,
                cancelled_at TEXT,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS task_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                task_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                FOREIGN KEY (task_id) REFERENCES background_tasks(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS task_inputs (
                id TEXT PRIMARY KEY,
                task_id TEXT NOT NULL,
                content TEXT NOT NULL,
                consumed INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL,
                FOREIGN KEY (task_id) REFERENCES background_tasks(id) ON DELETE CASCADE
            )
        SQL);

        // Migration: add tool columns for background tool execution
        $this->migrateAddColumn('background_tasks', 'tool_name', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('background_tasks', 'tool_arguments', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('background_tasks', 'metadata', 'TEXT DEFAULT NULL');

        // Migration: add schedule_id for scheduled task tracking
        $this->migrateAddColumn('background_tasks', 'schedule_id', 'TEXT DEFAULT NULL');

        // Migration: heartbeat tracking for stale process detection
        $this->migrateAddColumn('background_tasks', 'last_heartbeat_at', 'TEXT DEFAULT NULL');

        // Migration: per-task max execution time (seconds, 0 = no limit)
        $this->migrateAddColumn('background_tasks', 'max_execution_seconds', 'INTEGER DEFAULT 3600');

        // Migration: project/sprint context for loop stage tasks (artifact auto-scoping)
        $this->migrateAddColumn('background_tasks', 'project_id', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('background_tasks', 'sprint_id', 'TEXT DEFAULT NULL');

        // Migration: soft-delete flag for summarized messages
        $this->migrateAddColumn('messages', 'is_summarized', 'INTEGER NOT NULL DEFAULT 0');

        // Migration: active project tracking per session
        $this->migrateAddColumn('sessions', 'active_project_id', 'TEXT DEFAULT NULL');

        // Migration: personality profile per session
        $this->migrateAddColumn('sessions', 'profile', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('sessions', 'is_closed', 'INTEGER NOT NULL DEFAULT 0');
        $this->migrateAddColumn('sessions', 'is_archived', 'INTEGER NOT NULL DEFAULT 0');
        $this->migrateAddColumn('sessions', 'closed_at', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('sessions', 'archived_at', 'TEXT DEFAULT NULL');
        $this->migrateAddColumn('sessions', 'closure_reason', 'TEXT DEFAULT NULL');

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_profile_updated ON sessions(profile, updated_at DESC)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_sessions_closed_updated ON sessions(is_closed, updated_at DESC)');

        // Migration: child-run metadata for typed handoffs and provenance
        $this->migrateAddColumn('child_runs', 'metadata', 'TEXT DEFAULT NULL');

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_background_tasks_status ON background_tasks(status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_background_tasks_session ON background_tasks(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_background_tasks_schedule ON background_tasks(schedule_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_task_events_task ON task_events(task_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_task_inputs_task ON task_inputs(task_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_task_inputs_consumed ON task_inputs(consumed)');

        // Interactive turn processes — child processes for API agent turns
        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS turn_processes (
                id TEXT PRIMARY KEY,
                session_id TEXT NOT NULL,
                prompt TEXT NOT NULL,
                file_paths TEXT,
                status TEXT NOT NULL DEFAULT 'pending',
                pid INTEGER,
                result TEXT,
                error TEXT,
                created_at TEXT NOT NULL,
                started_at TEXT,
                completed_at TEXT,
                FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS turn_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                turn_process_id TEXT NOT NULL,
                event_type TEXT NOT NULL,
                data TEXT NOT NULL DEFAULT '{}',
                created_at TEXT NOT NULL,
                FOREIGN KEY (turn_process_id) REFERENCES turn_processes(id) ON DELETE CASCADE
            )
        SQL);

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_turn_processes_session ON turn_processes(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_turn_processes_status ON turn_processes(status)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_turn_events_turn_process ON turn_events(turn_process_id)');
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

    public function createSession(string $modelRole, string $model, ?string $profile = null): string
    {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO sessions (id, model_role, model, profile, created_at, updated_at)
            VALUES (:id, :model_role, :model, :profile, :created_at, :updated_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'model_role' => $modelRole,
            'model' => $model,
            'profile' => $profile,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listSessions(int $limit = 50, bool $excludeTaskSessions = true, bool $activeOnly = true, ?string $status = null): array
    {
        $join = $excludeTaskSessions
            ? 'LEFT JOIN background_tasks bt ON bt.session_id = s.id'
            : '';
        $conditions = [];

        if ($excludeTaskSessions) {
            $conditions[] = 'bt.id IS NULL';
        }

        if ($status === 'active') {
            $conditions[] = 's.is_closed = 0';
        } elseif ($status === 'closed') {
            $conditions[] = 's.is_closed = 1';
        } elseif ($status === 'archived') {
            $conditions[] = 's.is_archived = 1';
        } elseif ($activeOnly) {
            $conditions[] = 's.is_closed = 0';
        }

        $filter = $conditions !== []
            ? 'WHERE ' . implode(' AND ', $conditions)
            : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT s.id, s.model_role, s.model, s.title, s.profile, s.active_project_id, s.created_at, s.updated_at, s.token_count,
                   s.is_closed, s.is_archived, s.closed_at, s.archived_at, s.closure_reason
            FROM sessions s
            {$join}
            {$filter}
            ORDER BY s.updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $this->normalizeSessionRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(string $id): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, model_role, model, title, profile, active_project_id, created_at, updated_at, token_count,
                   is_closed, is_archived, closed_at, archived_at, closure_reason
            FROM sessions
            WHERE id = :id
        SQL);

        $stmt->execute(['id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session === false) {
            return null;
        }

        return $this->normalizeSessionRow($session);
    }

    /**
     * @return array{active: int, closed: int, archived: int, total: int}
     */
    public function getSessionStatusCounts(bool $excludeTaskSessions = true): array
    {
        $join = $excludeTaskSessions
            ? 'LEFT JOIN background_tasks bt ON bt.session_id = s.id'
            : '';
        $filter = $excludeTaskSessions
            ? 'WHERE bt.id IS NULL'
            : '';

        $stmt = $this->db->query(<<<SQL
            SELECT
                SUM(CASE WHEN s.is_closed = 0 THEN 1 ELSE 0 END) AS active_count,
                SUM(CASE WHEN s.is_closed = 1 THEN 1 ELSE 0 END) AS closed_count,
                SUM(CASE WHEN s.is_archived = 1 THEN 1 ELSE 0 END) AS archived_count,
                COUNT(*) AS total_count
            FROM sessions s
            {$join}
            {$filter}
        SQL);

        if ($stmt === false) {
            return ['active' => 0, 'closed' => 0, 'archived' => 0, 'total' => 0];
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return ['active' => 0, 'closed' => 0, 'archived' => 0, 'total' => 0];
        }

        return [
            'active' => (int) ($row['active_count'] ?? 0),
            'closed' => (int) ($row['closed_count'] ?? 0),
            'archived' => (int) ($row['archived_count'] ?? 0),
            'total' => (int) ($row['total_count'] ?? 0),
        ];
    }

    /**
     * Update a session's title.
     */
    public function updateSessionTitle(string $sessionId, string $title): void
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE sessions SET title = :title, updated_at = :updated_at WHERE id = :id
        SQL);

        $stmt->execute([
            'title' => $title,
            'updated_at' => date('c'),
            'id' => $sessionId,
        ]);
    }

    /**
     * Update a session's active role and resolved model.
     */
    public function updateSessionRole(string $sessionId, string $modelRole, string $model): void
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE sessions SET model_role = :model_role, model = :model, updated_at = :updated_at WHERE id = :id
        SQL);

        $stmt->execute([
            'model_role' => $modelRole,
            'model' => $model,
            'updated_at' => date('c'),
            'id' => $sessionId,
        ]);
    }

    /**
     * Update a session's active personality profile.
     */
    public function updateSessionProfile(string $sessionId, ?string $profile): void
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE sessions SET profile = :profile, updated_at = :updated_at WHERE id = :id
        SQL);

        $stmt->execute([
            'profile' => $profile,
            'updated_at' => date('c'),
            'id' => $sessionId,
        ]);
    }

    /**
     * Set or clear the active project for a session.
     */
    public function setActiveProject(string $sessionId, ?string $projectId): void
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE sessions SET active_project_id = :project_id, updated_at = :updated_at WHERE id = :id
        SQL);

        $stmt->execute([
            'project_id' => $projectId,
            'updated_at' => date('c'),
            'id' => $sessionId,
        ]);
    }

    /**
     * Get the active project ID for a session, or null if none is set.
     */
    public function getActiveProjectId(string $sessionId): ?string
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT active_project_id FROM sessions WHERE id = :id
        SQL);

        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $value = $row['active_project_id'];

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Clear active project references for all sessions pointing at a project.
     *
     * @return int Number of sessions updated.
     */
    public function clearActiveProjectReferences(string $projectId): int
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE sessions
            SET active_project_id = NULL, updated_at = :updated_at
            WHERE active_project_id = :project_id
        SQL);

        $stmt->execute([
            'updated_at' => date('c'),
            'project_id' => $projectId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * Clear all active project references across all sessions.
     *
     * @return int Number of sessions updated.
     */
    public function clearAllActiveProjects(): int
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE sessions
            SET active_project_id = NULL, updated_at = :updated_at
            WHERE active_project_id IS NOT NULL
        SQL);

        $stmt->execute([
            'updated_at' => date('c'),
        ]);

        return $stmt->rowCount();
    }

    /**
     * Expose the PDO connection for shared-database consumers (e.g. ArtifactStore).
     */
    public function getPdo(): PDO
    {
        return $this->db;
    }

    public function addMessage(
        string $sessionId,
        string $role,
        string $content,
        ?string $toolCalls = null,
        ?string $toolCallId = null,
        ?string $turnId = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO messages (id, session_id, role, content, tool_calls, tool_call_id, turn_id, created_at)
            VALUES (:id, :session_id, :role, :content, :tool_calls, :tool_call_id, :turn_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'tool_call_id' => $toolCallId,
            'turn_id' => $turnId,
            'created_at' => $now,
        ]);

        $this->db->prepare('UPDATE sessions SET updated_at = :now WHERE id = :id')
            ->execute(['now' => $now, 'id' => $sessionId]);

        return $id;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getMessages(string $sessionId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, role, content, tool_calls, tool_call_id, created_at
            FROM messages
            WHERE session_id = :session_id
            ORDER BY created_at ASC
        SQL);

        $stmt->execute(['session_id' => $sessionId]);

        return $this->normalizeSessionRows($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get only active (non-summarized) messages for a session.
     *
     * Same as getMessages() but excludes rows already marked is_summarized=1.
     * Use this when identifying which messages to mark during summarization —
     * it ensures the ID-marking logic operates on the same message set that
     * loadConversation() returns to the agent.
     *
     * @return array<array<string, mixed>>
     */
    public function getActiveMessages(string $sessionId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, role, content, tool_calls, tool_call_id, created_at
            FROM messages
            WHERE session_id = :session_id AND is_summarized = 0
            ORDER BY created_at ASC
        SQL);

        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Rebuild a Conversation object from persisted messages.
     *
     * Each row is wrapped in a try/catch so a single corrupted message
     * (malformed UTF-8, invalid role, bad JSON) does not kill the entire
     * conversation. Skipped rows are silently dropped — the doctor
     * command can surface them.
     */
    public function loadConversation(string $sessionId): Conversation
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, role, content, tool_calls, tool_call_id, created_at
            FROM messages
            WHERE session_id = :session_id AND is_summarized = 0
            ORDER BY created_at ASC
        SQL);

        $stmt->execute(['session_id' => $sessionId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conversation = new Conversation();

        foreach ($messages as $msg) {
            try {
                $role = Role::from($msg['role']);
                $content = $this->sanitizeUtf8($msg['content'] ?? '');
                $toolCalls = $msg['tool_calls'] !== null
                    ? $this->decodeToolCalls($msg['tool_calls'])
                    : [];

                // Skip legacy no-op assistant messages. Some providers can reject
                // replayed histories containing empty assistant messages that have
                // neither text content nor tool calls.
                if ($role === Role::Assistant && trim($content) === '' && $toolCalls === []) {
                    continue;
                }

                $toolCallId = $msg['tool_call_id'] ?? ('unknown_' . $msg['id']);

                $message = match ($role) {
                    Role::System => new SystemMessage($content),
                    Role::User => new UserMessage($this->decodeUserContent($content)),
                    Role::Assistant => new AssistantMessage($content, $toolCalls),
                    Role::Tool => new ToolResultMessage(
                        (new ToolResult(ToolResultStatus::Success, $content))->withCallId($toolCallId),
                    ),
                };

                $conversation->add($message);
            } catch (\Throwable) {
                // Skip corrupted message — doctor command can diagnose these
                continue;
            }
        }

        return $conversation;
    }

    /**
     * @return ToolCall[]
     */
    private function decodeToolCalls(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($data)) {
            return [];
        }

        $calls = [];
        foreach ($data as $item) {
            if (isset($item['id'], $item['name'], $item['arguments'])) {
                $calls[] = new ToolCall(
                    $item['id'],
                    $item['name'],
                    is_array($item['arguments']) ? $item['arguments'] : [],
                    is_array($item['metadata'] ?? null) ? $item['metadata'] : [],
                );
            }
        }

        return $calls;
    }

    /**
     * Decode user message content, detecting multimodal JSON arrays.
     *
     * UserMessage content is stored as TEXT in the database. For plain text
     * messages, the string is returned as-is. For multimodal messages (with
     * images), the content was JSON-encoded before storage — this method
     * detects and decodes it back to the array format UserMessage expects.
     *
     * @return string|array<array{type: string, text?: string, image_url?: array<string, mixed>}>
     */
    private function decodeUserContent(string $content): string|array
    {
        // Fast check: multimodal content is a JSON array starting with [{"type":
        if (!str_starts_with(trim($content), '[{"type"')) {
            return $content;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $content;
        }

        // Validate structure: must be an array of objects with 'type' keys
        if (!is_array($decoded) || $decoded === []) {
            return $content;
        }

        foreach ($decoded as $item) {
            if (!is_array($item) || !isset($item['type'])) {
                return $content;
            }
        }

        return $decoded;
    }

    /**
     * Replace invalid UTF-8 sequences with the Unicode replacement character.
     *
     * This prevents malformed content (e.g., from web scraping results)
     * from poisoning the entire conversation when sent to a provider
     * that requires valid JSON (and therefore valid UTF-8).
     */
    private function sanitizeUtf8(string $value): string
    {
        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
    }

    /**
     * Run integrity checks on session data for the doctor command.
     *
     * @return array{ok: bool, issues: list<array{id: string, role: string, issue: string}>}
     */
    public function checkMessageIntegrity(string $sessionId, int $limit = 200): array
    {
        $issues = [];

        // Read raw bytes to detect malformed UTF-8
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, role, content, tool_calls, tool_call_id
            FROM messages
            WHERE session_id = :session_id
            ORDER BY created_at DESC
            LIMIT :limit
        SQL);
        $stmt->bindValue('session_id', $sessionId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            $role = $row['role'];

            // Check valid role
            if (!in_array($role, ['user', 'assistant', 'tool', 'system'], true)) {
                $issues[] = ['id' => $id, 'role' => $role, 'issue' => "Invalid role: {$role}"];
            }

            // Check UTF-8 validity
            if (is_string($row['content']) && !mb_check_encoding($row['content'], 'UTF-8')) {
                $issues[] = ['id' => $id, 'role' => $role, 'issue' => 'Malformed UTF-8 in content'];
            }

            // Check tool_calls JSON validity
            if ($row['tool_calls'] !== null) {
                $decoded = json_decode($row['tool_calls'], true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $issues[] = ['id' => $id, 'role' => $role, 'issue' => 'Invalid JSON in tool_calls: ' . json_last_error_msg()];
                }
            }

            // Check tool messages have tool_call_id
            if ($role === 'tool' && ($row['tool_call_id'] === null || $row['tool_call_id'] === '')) {
                $issues[] = ['id' => $id, 'role' => $role, 'issue' => 'Tool message missing tool_call_id'];
            }
        }

        return ['ok' => empty($issues), 'issues' => $issues];
    }

    /**
     * Delete specific messages by ID. Used by doctor --repair.
     *
     * @param string[] $messageIds
     * @return int Number of rows deleted
     */
    public function deleteMessages(array $messageIds): int
    {
        if (empty($messageIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM messages WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($messageIds));

        return $stmt->rowCount();
    }

    /**
     * Soft-delete messages by marking them as summarized.
     *
     * Marked messages are excluded from loadConversation() but remain in the
     * database for audit, doctor, and summarization ID calculation.
     *
     * @param string[] $messageIds
     * @return int Number of rows marked
     */
    public function markMessagesAsSummarized(array $messageIds): int
    {
        if (empty($messageIds)) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->db->prepare("UPDATE messages SET is_summarized = 1 WHERE id IN ({$placeholders})");
        $stmt->execute(array_values($messageIds));

        return $stmt->rowCount();
    }

    /**
     * Repair malformed UTF-8 content in messages by replacing invalid bytes.
     *
     * @return int Number of rows repaired
     */
    public function repairUtf8Content(string $sessionId): int
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, content FROM messages WHERE session_id = :session_id
        SQL);
        $stmt->execute(['session_id' => $sessionId]);

        $repaired = 0;
        $update = $this->db->prepare('UPDATE messages SET content = :content WHERE id = :id');

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_string($row['content']) && !mb_check_encoding($row['content'], 'UTF-8')) {
                $clean = mb_convert_encoding($row['content'], 'UTF-8', 'UTF-8');
                $update->execute(['content' => $clean, 'id' => $row['id']]);
                $repaired++;
            }
        }

        return $repaired;
    }

    /**
     * Get database summary statistics for the doctor command.
     *
     * @return array{sessions: int, messages: int, turns: int, audit_entries: int, db_size_bytes: int}
     */
    public function getDatabaseStats(): array
    {
        return [
            'sessions' => $this->queryScalarInt('SELECT COUNT(*) FROM sessions'),
            'messages' => $this->queryScalarInt('SELECT COUNT(*) FROM messages'),
            'turns' => $this->queryScalarInt('SELECT COUNT(*) FROM turns'),
            'audit_entries' => $this->queryScalarInt('SELECT COUNT(*) FROM audit_log'),
            'db_size_bytes' => $this->queryScalarInt('PRAGMA page_count')
                * $this->queryScalarInt('PRAGMA page_size'),
        ];
    }

    /**
     * Execute a scalar query and return the result as an integer.
     */
    private function queryScalarInt(string $sql): int
    {
        $stmt = $this->db->query($sql);

        if ($stmt === false) {
            throw new \RuntimeException(sprintf('Query failed: %s', $sql));
        }

        return (int) $stmt->fetchColumn();
    }

    /**
     * Verify all expected tables exist.
     *
     * @return array{ok: bool, missing: string[]}
     */
    public function checkTablesExist(): array
    {
        $expected = ['sessions', 'messages', 'turns', 'audit_log', 'child_runs', 'background_tasks', 'task_events', 'task_inputs', 'turn_processes', 'turn_events'];
        $missing = [];

        foreach ($expected as $table) {
            $stmt = $this->db->prepare(
                "SELECT name FROM sqlite_master WHERE type='table' AND name = :name",
            );
            $stmt->execute(['name' => $table]);
            if ($stmt->fetch() === false) {
                $missing[] = $table;
            }
        }

        return ['ok' => empty($missing), 'missing' => $missing];
    }

    public function isSessionClosed(string $sessionId): bool
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT is_closed
            FROM sessions
            WHERE id = :id
        SQL);

        $stmt->execute(['id' => $sessionId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (int) $value === 1;
    }

    public function isSessionArchived(string $sessionId): bool
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT is_archived
            FROM sessions
            WHERE id = :id
        SQL);

        $stmt->execute(['id' => $sessionId]);
        $value = $stmt->fetchColumn();

        return $value !== false && (int) $value === 1;
    }

    public function isSessionWritable(string $sessionId): bool
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT 1
            FROM sessions
            WHERE id = :id AND is_closed = 0
            LIMIT 1
        SQL);

        $stmt->execute(['id' => $sessionId]);

        return $stmt->fetchColumn() !== false;
    }

    public function closeSession(string $sessionId, string $reason, bool $archive = true): void
    {
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            UPDATE sessions
            SET is_closed = 1,
                is_archived = :is_archived,
                closed_at = :closed_at,
                archived_at = :archived_at,
                closure_reason = :closure_reason,
                updated_at = :updated_at
            WHERE id = :id
        SQL);

        $stmt->execute([
            'is_archived' => $archive ? 1 : 0,
            'closed_at' => $now,
            'archived_at' => $archive ? $now : null,
            'closure_reason' => $reason,
            'updated_at' => $now,
            'id' => $sessionId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeSessionRow(array $row): array
    {
        if ($row === []) {
            return $row;
        }

        $isClosed = (int) ($row['is_closed'] ?? 0);
        $isArchived = (int) ($row['is_archived'] ?? 0);

        $row['is_closed'] = $isClosed;
        $row['is_archived'] = $isArchived;
        $row['status'] = $isArchived === 1
            ? 'archived'
            : ($isClosed === 1 ? 'closed' : 'active');

        return $row;
    }

    /**
     * @param array<array<string, mixed>> $rows
     * @return array<array<string, mixed>>
     */
    private function normalizeSessionRows(array $rows): array
    {
        return array_map(fn(array $row): array => $this->normalizeSessionRow($row), $rows);
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listActiveInteractiveSessionsForProfile(string $profile): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT s.id, s.model_role, s.model, s.title, s.profile, s.active_project_id, s.created_at, s.updated_at, s.token_count,
                   s.is_closed, s.is_archived, s.closed_at, s.archived_at, s.closure_reason
            FROM sessions s
            LEFT JOIN background_tasks bt ON bt.session_id = s.id
            WHERE bt.id IS NULL
              AND s.profile = :profile
              AND s.is_closed = 0
            ORDER BY s.updated_at DESC
        SQL);

        $stmt->execute(['profile' => $profile]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<string>
     */
    public function closeOtherActiveInteractiveSessionsForProfile(string $profile, string $keepSessionId, string $reason): array
    {
        $sessions = $this->listActiveInteractiveSessionsForProfile($profile);
        $closedIds = [];

        foreach ($sessions as $session) {
            $sessionId = (string) ($session['id'] ?? '');
            if ($sessionId === '' || $sessionId === $keepSessionId) {
                continue;
            }

            $this->closeSession($sessionId, $reason, true);
            $closedIds[] = $sessionId;
        }

        return $closedIds;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function logChildRun(
        string $sessionId,
        int $parentIteration,
        string $agentRole,
        string $model,
        string $prompt,
        string $result,
        int $tokenCount = 0,
        ?array $metadata = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO child_runs (id, session_id, parent_iteration, agent_role, model, prompt, result, token_count, metadata, created_at)
            VALUES (:id, :session_id, :parent_iteration, :agent_role, :model, :prompt, :result, :token_count, :metadata, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'parent_iteration' => $parentIteration,
            'agent_role' => $agentRole,
            'model' => $model,
            'prompt' => $prompt,
            'result' => $result,
            'token_count' => $tokenCount,
            'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => $now,
        ]);

        return $id;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getChildRuns(string $sessionId): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, parent_iteration, agent_role, model, prompt, result, token_count, metadata, created_at
            FROM child_runs
            WHERE session_id = :session_id
            ORDER BY created_at ASC
        SQL);

        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function updateTokenCount(string $sessionId, int $tokens): void
    {
        $this->db->prepare('UPDATE sessions SET token_count = token_count + :tokens WHERE id = :id')
            ->execute(['tokens' => $tokens, 'id' => $sessionId]);
    }

    /**
     * Log a tool execution audit entry.
     *
     * @param array<string, mixed> $arguments
     */
    public function logAudit(
        ?string $sessionId,
        string $toolName,
        array $arguments,
        string $action,
        ?string $reason = null,
        ?string $turnId = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO audit_log (id, session_id, tool_name, arguments, action, reason, turn_id, created_at)
            VALUES (:id, :session_id, :tool_name, :arguments, :action, :reason, :turn_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'tool_name' => $toolName,
            'arguments' => json_encode($arguments, JSON_UNESCAPED_SLASHES),
            'action' => $action,
            'reason' => $reason,
            'turn_id' => $turnId,
            'created_at' => $now,
        ]);

        return $id;
    }

    /**
     * Get audit log entries, optionally filtered by session and/or action.
     *
     * @return array<array<string, mixed>>
     */
    public function getAuditLog(
        ?string $sessionId = null,
        ?string $action = null,
        int $limit = 100,
    ): array {
        $conditions = [];
        $params = [];

        if ($sessionId !== null) {
            $conditions[] = 'session_id = :session_id';
            $params['session_id'] = $sessionId;
        }

        if ($action !== null) {
            $conditions[] = 'action = :action';
            $params['action'] = $action;
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, tool_name, arguments, action, reason, created_at
            FROM audit_log
            {$where}
            ORDER BY created_at DESC
            LIMIT :limit
        SQL);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Delete a session and its cascaded data.
     *
     * Refuses deletion if the session owns persistent (project-linked) artifacts,
     * since those must survive across sessions. Detach artifacts from the session
     * or remove the persistent flag before deleting.
     *
     * @throws \RuntimeException If the session has persistent artifacts.
     */
    public function deleteSession(string $id, bool $force = false): void
    {
        if (!$force) {
            try {
                $stmt = $this->db->prepare(
                    'SELECT COUNT(*) FROM artifacts WHERE session_id = :id AND persistent = 1',
                );
                $stmt->execute(['id' => $id]);

                if (((int) $stmt->fetchColumn()) > 0) {
                    throw new \RuntimeException(sprintf(
                        'Session "%s" has persistent project artifacts. Use force=true or detach artifacts first.',
                        $id,
                    ));
                }
            } catch (\PDOException) {
                // Artifacts table may not exist yet — safe to proceed
            }
        }

        $this->db->prepare('DELETE FROM sessions WHERE id = :id')
            ->execute(['id' => $id]);
    }

    /**
     * Create a new turn record for a request-response cycle.
     *
     * Turn number is auto-incremented per session.
     */
    public function createTurn(
        string $sessionId,
        string $userPrompt,
        ?string $model = null,
        ?string $turnProcessId = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        // Calculate next turn number for this session
        $stmt = $this->db->prepare(<<<SQL
            SELECT COALESCE(MAX(turn_number), 0) + 1 AS next_turn
            FROM turns
            WHERE session_id = :session_id
        SQL);
        $stmt->execute(['session_id' => $sessionId]);
        $turnNumber = (int) $stmt->fetchColumn();

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO turns (id, session_id, turn_number, user_prompt, model, turn_process_id, created_at)
            VALUES (:id, :session_id, :turn_number, :user_prompt, :model, :turn_process_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'turn_number' => $turnNumber,
            'user_prompt' => $userPrompt,
            'model' => $model,
            'turn_process_id' => $turnProcessId,
            'created_at' => $now,
        ]);

        return $id;
    }

    /**
     * Complete a turn with all metadata from the agent execution.
     */
    public function completeTurn(
        string $turnId,
        string $responseText,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        int $iterations,
        int $durationMs,
        string $toolsUsed,
        int $childAgentCount,
    ): void {
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            UPDATE turns
            SET response_text = :response_text,
                prompt_tokens = :prompt_tokens,
                completion_tokens = :completion_tokens,
                total_tokens = :total_tokens,
                iterations = :iterations,
                duration_ms = :duration_ms,
                tools_used = :tools_used,
                child_agent_count = :child_agent_count,
                completed_at = :completed_at
            WHERE id = :id
        SQL);

        $stmt->execute([
            'response_text' => $responseText,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'iterations' => $iterations,
            'duration_ms' => $durationMs,
            'tools_used' => $toolsUsed,
            'child_agent_count' => $childAgentCount,
            'completed_at' => $now,
            'id' => $turnId,
        ]);
    }

    /**
     * Persist the rich serialized result payload for historical turn inspection.
     *
     * @param array<string, mixed> $payload
     */
    public function storeTurnResultPayload(string $turnId, array $payload): void
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE turns
            SET result_payload = :result_payload
            WHERE id = :id
        SQL);

        $stmt->execute([
            'result_payload' => json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}',
            'id' => $turnId,
        ]);
    }

    /**
     * Get turns for a session ordered by turn number.
     *
     * @return array<array<string, mixed>>
     */
    public function getTurns(string $sessionId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, turn_number, user_prompt, response_text, model,
                   prompt_tokens, completion_tokens, total_tokens, iterations,
                   duration_ms, tools_used, child_agent_count, turn_process_id,
                   result_payload, created_at, completed_at
            FROM turns
            WHERE session_id = :session_id
            ORDER BY turn_number ASC
            LIMIT :limit
        SQL);

        $stmt->bindValue('session_id', $sessionId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn(array $turn): array => $this->normalizeTurnRow($turn),
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );
    }

    /**
     * Get a single normalized turn row without nested messages.
     *
     * @return array<string, mixed>|null
     */
    public function getTurn(string $turnId): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, turn_number, user_prompt, response_text, model,
                   prompt_tokens, completion_tokens, total_tokens, iterations,
                   duration_ms, tools_used, child_agent_count, turn_process_id,
                   result_payload, created_at, completed_at
            FROM turns
            WHERE id = :id
        SQL);

        $stmt->execute(['id' => $turnId]);
        $turn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($turn === false) {
            return null;
        }

        return $this->normalizeTurnRow($turn);
    }

    /**
     * Get a turn with its messages nested under a 'messages' key.
     *
     * @return array<string, mixed>|null
     */
    public function getTurnWithMessages(string $turnId): ?array
    {
        $turn = $this->getTurn($turnId);

        if ($turn === null) {
            return null;
        }

        $msgStmt = $this->db->prepare(<<<SQL
            SELECT id, role, content, tool_calls, tool_call_id, created_at
            FROM messages
            WHERE turn_id = :turn_id
            ORDER BY created_at ASC
        SQL);

        $msgStmt->execute(['turn_id' => $turnId]);
        $turn['messages'] = $msgStmt->fetchAll(PDO::FETCH_ASSOC);
        $turn['events'] = isset($turn['turn_process_id']) && is_string($turn['turn_process_id']) && $turn['turn_process_id'] !== ''
            ? $this->getDecodedTurnEvents($turn['turn_process_id'])
            : [];

        return $turn;
    }

    /**
     * @param array<string, mixed> $turn
     * @return array<string, mixed>
     */
    private function normalizeTurnRow(array $turn): array
    {
        $payload = $this->decodeTurnResultPayload($turn['result_payload'] ?? null);

        $normalized = $turn;
        $normalized['tools_used'] = $this->decodeToolsUsed($turn['tools_used'] ?? null);
        $normalized['content'] = (string) ($turn['response_text'] ?? '');
        $normalized['restart_requested'] = false;
        $normalized['iteration_limit_reached'] = false;
        $normalized['budget_exhausted'] = false;
        $normalized['context_usage'] = null;
        $normalized['file_edits'] = null;
        $normalized['error'] = null;
        $normalized['review_feedback'] = null;
        $normalized['review_approved'] = null;
        $normalized['background_tasks'] = null;

        if ($payload !== null) {
            $normalized = array_replace($normalized, $payload);
        }

        unset($normalized['result_payload']);

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function decodeToolsUsed(mixed $rawToolsUsed): array
    {
        if (is_array($rawToolsUsed)) {
            return array_values(array_filter($rawToolsUsed, is_string(...)));
        }

        if (!is_string($rawToolsUsed) || $rawToolsUsed === '') {
            return [];
        }

        $decoded = json_decode($rawToolsUsed, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, is_string(...)))
            : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeTurnResultPayload(mixed $rawPayload): ?array
    {
        if (is_array($rawPayload)) {
            return $rawPayload;
        }

        if (!is_string($rawPayload) || $rawPayload === '') {
            return null;
        }

        $decoded = json_decode($rawPayload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getDecodedTurnEvents(string $turnProcessId, int $limit = 500): array
    {
        return array_map(
            function (array $event): array {
                $data = $event['data'] ?? '{}';

                return [
                    'id' => (int) $event['id'],
                    'event_type' => (string) $event['event_type'],
                    'data' => is_string($data) ? (json_decode($data, true) ?? new \stdClass()) : $data,
                    'created_at' => (string) $event['created_at'],
                ];
            },
            $this->getTurnEvents($turnProcessId, limit: $limit),
        );
    }

    /**
     * Get the most recent session ID, if any.
     */
    public function getLatestSessionId(): ?string
    {
        $stmt = $this->db->query(<<<SQL
            SELECT id FROM sessions ORDER BY updated_at DESC LIMIT 1
        SQL);

        if ($stmt === false) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && isset($row['id']) ? (string) $row['id'] : null;
    }

    public function getLatestSessionIdForProfile(string $profile): ?string
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id
            FROM sessions
            WHERE profile = :profile AND is_closed = 0
            ORDER BY updated_at DESC
            LIMIT 1
        SQL);

        $stmt->execute(['profile' => $profile]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && isset($row['id']) ? (string) $row['id'] : null;
    }

    public function getLatestInteractiveUnprofiledSessionId(): ?string
    {
        $stmt = $this->db->query(<<<SQL
            SELECT s.id
            FROM sessions s
            LEFT JOIN background_tasks bt ON bt.session_id = s.id
            WHERE bt.id IS NULL
              AND s.profile IS NULL
                            AND s.is_closed = 0
            ORDER BY s.updated_at DESC
            LIMIT 1
        SQL);

        if ($stmt === false) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && isset($row['id']) ? (string) $row['id'] : null;
    }

    public function getLatestInteractiveSessionId(): ?string
    {
        $stmt = $this->db->query(<<<SQL
            SELECT s.id
            FROM sessions s
            LEFT JOIN background_tasks bt ON bt.session_id = s.id
            WHERE bt.id IS NULL
              AND s.is_closed = 0
            ORDER BY s.updated_at DESC
            LIMIT 1
        SQL);

        if ($stmt === false) {
            return null;
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && isset($row['id']) ? (string) $row['id'] : null;
    }

    public function getLatestInteractiveSessionIdForProfile(string $profile): ?string
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT s.id
            FROM sessions s
            LEFT JOIN background_tasks bt ON bt.session_id = s.id
            WHERE bt.id IS NULL
              AND s.profile = :profile
                            AND s.is_closed = 0
            ORDER BY s.updated_at DESC
            LIMIT 1
        SQL);

        $stmt->execute(['profile' => $profile]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) && isset($row['id']) ? (string) $row['id'] : null;
    }

    public function isInteractiveSession(string $sessionId): bool
    {
        if ($this->isSessionClosed($sessionId)) {
            return false;
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT 1
            FROM background_tasks
            WHERE session_id = :session_id
            LIMIT 1
        SQL);

        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->fetchColumn() === false;
    }

    // -------------------------------------------------------------------------
    // Background Tasks
    // -------------------------------------------------------------------------

    /**
     * Create a new background task record.
     *
     * @param array<string, mixed>|null $metadata
     */
    public function createTask(
        string $sessionId,
        string $prompt,
        string $role = 'orchestrator',
        ?string $parentSessionId = null,
        ?string $title = null,
        int $maxIterations = 25,
        ?string $toolName = null,
        ?string $toolArguments = null,
        ?string $scheduleId = null,
        int $maxExecutionSeconds = 3600,
        ?string $projectId = null,
        ?string $sprintId = null,
        ?array $metadata = null,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO background_tasks (id, session_id, parent_session_id, status, title, prompt, role, metadata, max_iterations, tool_name, tool_arguments, schedule_id, max_execution_seconds, project_id, sprint_id, created_at)
            VALUES (:id, :session_id, :parent_session_id, 'pending', :title, :prompt, :role, :metadata, :max_iterations, :tool_name, :tool_arguments, :schedule_id, :max_execution_seconds, :project_id, :sprint_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'parent_session_id' => $parentSessionId,
            'title' => $title,
            'prompt' => $prompt,
            'role' => $role,
            'metadata' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            'max_iterations' => $maxIterations,
            'tool_name' => $toolName,
            'tool_arguments' => $toolArguments,
            'schedule_id' => $scheduleId,
            'max_execution_seconds' => $maxExecutionSeconds,
            'project_id' => $projectId,
            'sprint_id' => $sprintId,
            'created_at' => $now,
        ]);

        return $id;
    }

    /**
     * Update task status and optionally set result/error/PID fields.
     *
     * @param array<string, mixed> $extra Additional columns to update (result, error, pid)
     */
    public function updateTaskStatus(string $taskId, string $status, array $extra = []): void
    {
        $sets = ['status = :status'];
        $params = ['status' => $status, 'id' => $taskId];

        $now = date('c');

        // Auto-set timestamp columns based on status transition
        match ($status) {
            'running' => $sets[] = 'started_at = :started_at',
            'completed', 'failed' => $sets[] = 'completed_at = :completed_at',
            'cancelled' => $sets[] = 'cancelled_at = :cancelled_at',
            default => null,
        };

        if ($status === 'running') {
            $params['started_at'] = $now;
        } elseif ($status === 'completed' || $status === 'failed') {
            $params['completed_at'] = $now;
        } elseif ($status === 'cancelled') {
            $params['cancelled_at'] = $now;
        }

        foreach ($extra as $col => $val) {
            if (in_array($col, ['result', 'error', 'pid'], true)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $val;
            }
        }

        $setClause = implode(', ', $sets);

        $stmt = $this->db->prepare("UPDATE background_tasks SET {$setClause} WHERE id = :id");
        $stmt->execute($params);
    }

    /**
     * Conditionally update task status — only if current status matches expected.
     *
     * Prevents race condition where parent overwrites a status the child already committed.
     *
     * @param array<string, mixed> $extra Additional columns to update (result, error, pid)
     * @return bool True if a row was updated
     */
    public function updateTaskStatusConditional(string $taskId, string $newStatus, string $expectedCurrentStatus, array $extra = []): bool
    {
        $sets = ['status = :new_status'];
        $params = ['new_status' => $newStatus, 'expected_status' => $expectedCurrentStatus, 'id' => $taskId];

        $now = date('c');

        match ($newStatus) {
            'running' => $sets[] = 'started_at = :started_at',
            'completed', 'failed' => $sets[] = 'completed_at = :completed_at',
            'cancelled' => $sets[] = 'cancelled_at = :cancelled_at',
            default => null,
        };

        if ($newStatus === 'running') {
            $params['started_at'] = $now;
        } elseif ($newStatus === 'completed' || $newStatus === 'failed') {
            $params['completed_at'] = $now;
        } elseif ($newStatus === 'cancelled') {
            $params['cancelled_at'] = $now;
        }

        foreach ($extra as $col => $val) {
            if (in_array($col, ['result', 'error', 'pid'], true)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $val;
            }
        }

        $setClause = implode(', ', $sets);

        $stmt = $this->db->prepare("UPDATE background_tasks SET {$setClause} WHERE id = :id AND status = :expected_status");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * Update the heartbeat timestamp for a running task.
     */
    public function updateTaskHeartbeat(string $taskId): void
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE background_tasks SET last_heartbeat_at = :now WHERE id = :id AND status = 'running'
        SQL);
        $stmt->execute(['now' => date('c'), 'id' => $taskId]);
    }

    /**
     * Get running tasks whose heartbeat has gone stale (>5 minutes since last heartbeat).
     *
     * Only returns tasks that have a heartbeat set (excludes tasks that never started heartbeating).
     *
     * @return array<array<string, mixed>>
     */
    public function getStaleRunningTasks(int $staleThresholdSeconds = 300): array
    {
        $cutoff = date('c', time() - $staleThresholdSeconds);

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, pid, started_at, last_heartbeat_at
            FROM background_tasks
            WHERE status = 'running'
              AND last_heartbeat_at IS NOT NULL
              AND last_heartbeat_at < :cutoff
        SQL);
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get running tasks that have exceeded their max execution time.
     *
     * @return array<array<string, mixed>>
     */
    public function getTimedOutRunningTasks(): array
    {
        $now = time();

        $stmt = $this->db->query(<<<SQL
            SELECT id, pid, started_at, max_execution_seconds
            FROM background_tasks
            WHERE status = 'running'
              AND started_at IS NOT NULL
              AND max_execution_seconds > 0
        SQL);

        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter in PHP since SQLite date arithmetic is limited
        return array_values(array_filter($rows, function (array $row) use ($now): bool {
            $started = strtotime($row['started_at']);

            return $started !== false && ($now - $started) > (int) $row['max_execution_seconds'];
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTask(string $id): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM background_tasks WHERE id = :id
        SQL);

        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Find the most recent task for a given schedule.
     *
     * @return array<string, mixed>|null
     */
    public function getTaskByScheduleId(string $scheduleId): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM background_tasks WHERE schedule_id = :schedule_id ORDER BY created_at DESC LIMIT 1
        SQL);

        $stmt->execute(['schedule_id' => $scheduleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Find the most recent task created by a specific automation notification.
     *
     * @param list<string> $statuses
     * @return array<string, mixed>|null
     */
    public function findTaskByAutomationNotificationId(
        string $notificationId,
        array $statuses = ['pending', 'running', 'completed'],
    ): ?array {
        if ($statuses === []) {
            return null;
        }

        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
        $stmt = $this->db->prepare(<<<SQL
            SELECT *
            FROM background_tasks
            WHERE metadata IS NOT NULL
              AND json_extract(metadata, '$.automation.notification_id') = ?
              AND status IN ({$statusPlaceholders})
            ORDER BY created_at DESC
            LIMIT 1
        SQL);

        $params = [$notificationId, ...$statuses];
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Find the most recent task with a given title, optionally filtered by role and status.
     *
     * @param list<string> $statuses
     * @return array<string, mixed>|null
     */
    public function findRecentTaskByTitle(
        string $title,
        ?string $role = null,
        array $statuses = ['pending', 'running', 'completed'],
        int $lookbackHours = 24,
    ): ?array {
        if ($statuses === []) {
            return null;
        }

        $cutoff = date('c', time() - ($lookbackHours * 3600));
        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
        $roleClause = $role !== null ? 'AND role = ?' : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT *
            FROM background_tasks
            WHERE title = ?
              {$roleClause}
              AND status IN ({$statusPlaceholders})
              AND created_at >= ?
            ORDER BY created_at DESC
            LIMIT 1
        SQL);

        $params = [$title];
        if ($role !== null) {
            $params[] = $role;
        }
        $params = [...$params, ...$statuses, $cutoff];

        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Get all tasks with a specific status.
     *
     * @return array<array<string, mixed>>
     */
    public function getTasksByStatus(string $status): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM background_tasks WHERE status = :status ORDER BY created_at ASC
        SQL);

        $stmt->execute(['status' => $status]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * List background tasks with optional status filter.
     *
     * @return array<array<string, mixed>>
     */
    public function listTasks(?string $status = null, int $limit = 50): array
    {
        $where = '';
        $params = [];

        if ($status !== null && $status !== 'all') {
            $where = 'WHERE status = :status';
            $params['status'] = $status;
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, parent_session_id, pid, status, title, prompt, role,
                   metadata, max_iterations, created_at, started_at, completed_at, cancelled_at
            FROM background_tasks
            {$where}
            ORDER BY created_at DESC
            LIMIT :limit
        SQL);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Append an event to the task event log.
     */
    public function appendTaskEvent(string $taskId, string $eventType, mixed $data = null): void
    {
        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO task_events (task_id, event_type, data, created_at)
            VALUES (:task_id, :event_type, :data, :created_at)
        SQL);

        $stmt->execute([
            'task_id' => $taskId,
            'event_type' => $eventType,
            'data' => json_encode($data ?? new \stdClass(), JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => date('c'),
        ]);
    }

    /**
     * Get task events, optionally starting after a given event ID.
     *
     * @return array<array<string, mixed>>
     */
    public function getTaskEvents(string $taskId, ?int $sinceId = null, int $limit = 100): array
    {
        $where = 'task_id = :task_id';
        $params = ['task_id' => $taskId];

        if ($sinceId !== null) {
            $where .= ' AND id > :since_id';
            $params['since_id'] = $sinceId;
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, event_type, data, created_at
            FROM task_events
            WHERE {$where}
            ORDER BY id ASC
            LIMIT :limit
        SQL);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Append an event to the turn process event log.
     */
    public function appendTurnEvent(string $turnProcessId, string $eventType, mixed $data = null): void
    {
        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO turn_events (turn_process_id, event_type, data, created_at)
            VALUES (:turn_process_id, :event_type, :data, :created_at)
        SQL);

        $stmt->execute([
            'turn_process_id' => $turnProcessId,
            'event_type' => $eventType,
            'data' => json_encode($data ?? new \stdClass(), JSON_UNESCAPED_SLASHES) ?: '{}',
            'created_at' => date('c'),
        ]);
    }

    /**
     * Get turn process events, optionally starting after a given event ID.
     *
     * @return array<array<string, mixed>>
     */
    public function getTurnEvents(string $turnProcessId, ?int $sinceId = null, int $limit = 100): array
    {
        $where = 'turn_process_id = :turn_process_id';
        $params = ['turn_process_id' => $turnProcessId];

        if ($sinceId !== null) {
            $where .= ' AND id > :since_id';
            $params['since_id'] = $sinceId;
        }

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, event_type, data, created_at
            FROM turn_events
            WHERE {$where}
            ORDER BY id ASC
            LIMIT :limit
        SQL);

        foreach ($params as $key => $val) {
            $stmt->bindValue($key, $val);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add pending user input for a running task.
     */
    public function addTaskInput(string $taskId, string $content): string
    {
        $id = bin2hex(random_bytes(16));

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO task_inputs (id, task_id, content, consumed, created_at)
            VALUES (:id, :task_id, :content, 0, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'task_id' => $taskId,
            'content' => $content,
            'created_at' => date('c'),
        ]);

        return $id;
    }

    /**
     * Consume all unconsumed inputs for a task.
     *
     * Marks them as consumed atomically and returns the content strings.
     *
     * @return string[]
     */
    public function consumeTaskInputs(string $taskId): array
    {
        $this->db->beginTransaction();

        try {
            $stmt = $this->db->prepare(<<<SQL
                SELECT id, content FROM task_inputs
                WHERE task_id = :task_id AND consumed = 0
                ORDER BY created_at ASC
            SQL);
            $stmt->execute(['task_id' => $taskId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($rows)) {
                $this->db->commit();
                return [];
            }

            $ids = array_column($rows, 'id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $update = $this->db->prepare("UPDATE task_inputs SET consumed = 1 WHERE id IN ({$placeholders})");
            $update->execute($ids);

            $this->db->commit();

            return array_column($rows, 'content');
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Mark orphaned tasks as failed — only those whose process is actually dead.
     *
     * Checks each running/cancelling task's PID with posix_kill($pid, 0) before
     * marking it failed. Tasks with no PID or a dead PID are considered orphaned.
     */
    public function markOrphanedTasksFailed(string $error = 'Server restarted — task process was lost'): int
    {
        $stmt = $this->db->query(<<<SQL
            SELECT id, pid FROM background_tasks WHERE status IN ('running', 'cancelling')
        SQL);

        if ($stmt === false) {
            return 0;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        $now = date('c');

        $update = $this->db->prepare(<<<SQL
            UPDATE background_tasks SET status = 'failed', error = :error, completed_at = :now WHERE id = :id
        SQL);

        foreach ($rows as $row) {
            $pid = (int) ($row['pid'] ?? 0);

            // Only keep the task if the PID is alive AND still belongs to Coqui task:run.
            if ($this->isExpectedCoquiProcessAlive($pid, 'task:run')) {
                continue;
            }

            $update->execute(['error' => $error, 'now' => $now, 'id' => $row['id']]);
            $count++;
        }

        return $count;
    }

    /**
     * Get pending tasks ordered by creation time (FIFO).
     *
     * @return array<array<string, mixed>>
     */
    public function getPendingTasks(int $limit = 10): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, prompt, role, max_iterations, title
            FROM background_tasks
            WHERE status = 'pending'
            ORDER BY created_at ASC
            LIMIT :limit
        SQL);

        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if a session belongs to a background task.
     */
    public function isTaskSession(string $sessionId): bool
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT COUNT(*) FROM background_tasks WHERE session_id = :session_id
        SQL);
        $stmt->execute(['session_id' => $sessionId]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get the count of tasks by status.
     *
     * @return array<string, int>
     */
    public function getTaskCounts(): array
    {
        $stmt = $this->db->query(<<<SQL
            SELECT status, COUNT(*) as count FROM background_tasks GROUP BY status
        SQL);

        if ($stmt === false) {
            return [];
        }

        $counts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Purge old task events for tasks in terminal states.
     *
     * Deletes events older than the given number of days for tasks
     * that are completed, failed, or cancelled. Running/pending
     * task events are never purged.
     *
     * @return int Number of events deleted
     */
    public function purgeOldTaskEvents(int $maxAgeDays = 7): int
    {
        $cutoff = date('c', time() - ($maxAgeDays * 86400));

        $stmt = $this->db->prepare(<<<SQL
            DELETE FROM task_events
            WHERE task_id IN (
                SELECT id FROM background_tasks WHERE status IN ('completed', 'failed', 'cancelled')
            )
            AND created_at < :cutoff
        SQL);

        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    /**
     * Get active (running + pending) background tasks for footer rendering.
     *
     * Returns a lightweight result set with only the columns needed by
     * BackgroundTaskSummary. Ordered by creation time (oldest first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveBackgroundSummary(): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, status, title, role, tool_name, started_at, created_at
            FROM background_tasks
            WHERE status IN ('running', 'pending')
            ORDER BY created_at ASC
        SQL);

        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // ─── Turn Process Methods ────────────────────────────────────────────────

    /**
     * Create a pending turn process record for an interactive API agent turn.
     *
     * @param string[]|null $filePaths
     */
    public function createTurnProcess(string $sessionId, string $prompt, ?array $filePaths = null): string
    {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO turn_processes (id, session_id, prompt, file_paths, status, created_at)
            VALUES (:id, :session_id, :prompt, :file_paths, 'pending', :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'prompt' => $prompt,
            'file_paths' => $filePaths !== null ? json_encode($filePaths, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => $now,
        ]);

        return $id;
    }

    /**
     * Update turn process status and optionally set result/error/PID fields.
     *
     * @param array<string, mixed> $extra Additional columns to update (result, error, pid)
     */
    public function updateTurnProcessStatus(string $turnProcessId, string $status, array $extra = []): void
    {
        $sets = ['status = :status'];
        $params = ['status' => $status, 'id' => $turnProcessId];

        $now = date('c');

        match ($status) {
            'running' => $sets[] = 'started_at = :started_at',
            'completed', 'failed' => $sets[] = 'completed_at = :completed_at',
            default => null,
        };

        if ($status === 'running') {
            $params['started_at'] = $now;
        } elseif ($status === 'completed' || $status === 'failed') {
            $params['completed_at'] = $now;
        }

        foreach ($extra as $col => $val) {
            if (in_array($col, ['result', 'error', 'pid'], true)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $val;
            }
        }

        $setClause = implode(', ', $sets);

        $stmt = $this->db->prepare("UPDATE turn_processes SET {$setClause} WHERE id = :id");
        $stmt->execute($params);
    }

    /**
     * Conditionally update turn process status — only if current status matches expected.
     *
     * @param array<string, mixed> $extra Additional columns to update (result, error, pid)
     * @return bool True if a row was updated
     */
    public function updateTurnProcessStatusConditional(string $turnProcessId, string $newStatus, string $expectedCurrentStatus, array $extra = []): bool
    {
        $sets = ['status = :new_status'];
        $params = ['new_status' => $newStatus, 'expected_status' => $expectedCurrentStatus, 'id' => $turnProcessId];

        $now = date('c');

        match ($newStatus) {
            'running' => $sets[] = 'started_at = :started_at',
            'completed', 'failed' => $sets[] = 'completed_at = :completed_at',
            default => null,
        };

        if ($newStatus === 'running') {
            $params['started_at'] = $now;
        } elseif ($newStatus === 'completed' || $newStatus === 'failed') {
            $params['completed_at'] = $now;
        }

        foreach ($extra as $col => $val) {
            if (in_array($col, ['result', 'error', 'pid'], true)) {
                $sets[] = "{$col} = :{$col}";
                $params[$col] = $val;
            }
        }

        $setClause = implode(', ', $sets);

        $stmt = $this->db->prepare("UPDATE turn_processes SET {$setClause} WHERE id = :id AND status = :expected_status");
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTurnProcess(string $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM turn_processes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Get the active (pending or running) turn process for a session, if any.
     *
     * @return array<string, mixed>|null
     */
    public function getActiveTurnProcess(string $sessionId): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT * FROM turn_processes
            WHERE session_id = :session_id AND status IN ('pending', 'running')
            ORDER BY created_at DESC LIMIT 1
        SQL);

        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * Mark orphaned turn processes as failed — only those whose process is actually dead.
     *
     * Called on API server startup to clean up from previous crashes.
     */
    public function markOrphanedTurnProcessesFailed(string $error = 'Server restarted — turn process was lost'): int
    {
        $stmt = $this->db->query(<<<SQL
            SELECT id, pid FROM turn_processes WHERE status IN ('pending', 'running')
        SQL);

        if ($stmt === false) {
            return 0;
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        $now = date('c');

        $update = $this->db->prepare(<<<SQL
            UPDATE turn_processes SET status = 'failed', error = :error, completed_at = :now WHERE id = :id
        SQL);

        foreach ($rows as $row) {
            $pid = (int) ($row['pid'] ?? 0);

            // Only keep the turn if the PID is alive AND still belongs to Coqui turn:run.
            if ($this->isExpectedCoquiProcessAlive($pid, 'turn:run')) {
                continue;
            }

            $update->execute(['error' => $error, 'now' => $now, 'id' => $row['id']]);
            $count++;
        }

        return $count;
    }

    private function isExpectedCoquiProcessAlive(int $pid, string $subcommand): bool
    {
        if ($this->expectedCoquiProcessChecker !== null) {
            return (bool) ($this->expectedCoquiProcessChecker)($pid, $subcommand);
        }

        if ($pid <= 0 || !ProcessSpawner::isProcessAlive($pid)) {
            return false;
        }

        $command = PHP_OS_FAMILY === 'Windows'
            ? $this->windowsProcessCommandLine($pid)
            : shell_exec(sprintf('ps -o command= -p %d 2>/dev/null', $pid));

        if (!is_string($command) || trim($command) === '') {
            return false;
        }

        return str_contains($command, 'bin/coqui')
            && str_contains($command, $subcommand);
    }

    private function windowsProcessCommandLine(int $pid): ?string
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open([
            'powershell',
            '-NoProfile',
            '-Command',
            sprintf('(Get-CimInstance Win32_Process -Filter "ProcessId = %d" -ErrorAction SilentlyContinue).CommandLine', $pid),
        ], $descriptors, $pipes, null, null);

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $stdout === false ? null : trim($stdout);
    }
}
