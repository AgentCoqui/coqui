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

    public function __construct(string $dbPath)
    {
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
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_messages_turn ON messages(turn_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_turn ON audit_log(turn_id)');

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

        // Migration: add schedule_id for scheduled task tracking
        $this->migrateAddColumn('background_tasks', 'schedule_id', 'TEXT DEFAULT NULL');

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

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_turn_processes_session ON turn_processes(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_turn_processes_status ON turn_processes(status)');
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

    public function createSession(string $modelRole, string $model): string
    {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO sessions (id, model_role, model, created_at, updated_at)
            VALUES (:id, :model_role, :model, :created_at, :updated_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'model_role' => $modelRole,
            'model' => $model,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function listSessions(int $limit = 50, bool $excludeTaskSessions = true): array
    {
        $join = $excludeTaskSessions
            ? 'LEFT JOIN background_tasks bt ON bt.session_id = s.id'
            : '';
        $filter = $excludeTaskSessions
            ? 'WHERE bt.id IS NULL'
            : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT s.id, s.model_role, s.model, s.title, s.created_at, s.updated_at, s.token_count
            FROM sessions s
            {$join}
            {$filter}
            ORDER BY s.updated_at DESC
            LIMIT :limit
        SQL);

        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSession(string $id): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, model_role, model, title, created_at, updated_at, token_count
            FROM sessions
            WHERE id = :id
        SQL);

        $stmt->execute(['id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session === false) {
            return null;
        }

        return $session;
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
        $messages = $this->getMessages($sessionId);
        $conversation = new Conversation();

        foreach ($messages as $msg) {
            try {
                $role = Role::from($msg['role']);
                $content = $this->sanitizeUtf8($msg['content'] ?? '');
                $toolCalls = $msg['tool_calls'] !== null
                    ? $this->decodeToolCalls($msg['tool_calls'])
                    : [];
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
        $expected = ['sessions', 'messages', 'turns', 'audit_log', 'child_runs', 'background_tasks', 'task_events', 'task_inputs'];
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

    public function logChildRun(
        string $sessionId,
        int $parentIteration,
        string $agentRole,
        string $model,
        string $prompt,
        string $result,
        int $tokenCount = 0,
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO child_runs (id, session_id, parent_iteration, agent_role, model, prompt, result, token_count, created_at)
            VALUES (:id, :session_id, :parent_iteration, :agent_role, :model, :prompt, :result, :token_count, :created_at)
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
            SELECT id, parent_iteration, agent_role, model, prompt, result, token_count, created_at
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

    public function deleteSession(string $id): void
    {
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
            INSERT INTO turns (id, session_id, turn_number, user_prompt, model, created_at)
            VALUES (:id, :session_id, :turn_number, :user_prompt, :model, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'turn_number' => $turnNumber,
            'user_prompt' => $userPrompt,
            'model' => $model,
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
     * Get turns for a session ordered by turn number.
     *
     * @return array<array<string, mixed>>
     */
    public function getTurns(string $sessionId, int $limit = 50): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, turn_number, user_prompt, response_text, model,
                   prompt_tokens, completion_tokens, total_tokens, iterations,
                   duration_ms, tools_used, child_agent_count, created_at, completed_at
            FROM turns
            WHERE session_id = :session_id
            ORDER BY turn_number ASC
            LIMIT :limit
        SQL);

        $stmt->bindValue('session_id', $sessionId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get a turn with its messages nested under a 'messages' key.
     *
     * @return array<string, mixed>|null
     */
    public function getTurnWithMessages(string $turnId): ?array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, turn_number, user_prompt, response_text, model,
                   prompt_tokens, completion_tokens, total_tokens, iterations,
                   duration_ms, tools_used, child_agent_count, created_at, completed_at
            FROM turns
            WHERE id = :id
        SQL);

        $stmt->execute(['id' => $turnId]);
        $turn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($turn === false) {
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

        return $turn;
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

    // -------------------------------------------------------------------------
    // Background Tasks
    // -------------------------------------------------------------------------

    /**
     * Create a new background task record.
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
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO background_tasks (id, session_id, parent_session_id, status, title, prompt, role, max_iterations, tool_name, tool_arguments, schedule_id, created_at)
            VALUES (:id, :session_id, :parent_session_id, 'pending', :title, :prompt, :role, :max_iterations, :tool_name, :tool_arguments, :schedule_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'parent_session_id' => $parentSessionId,
            'title' => $title,
            'prompt' => $prompt,
            'role' => $role,
            'max_iterations' => $maxIterations,
            'tool_name' => $toolName,
            'tool_arguments' => $toolArguments,
            'schedule_id' => $scheduleId,
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
                   max_iterations, created_at, started_at, completed_at, cancelled_at
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
     * Mark all 'running' or 'cancelling' tasks as 'failed' during crash recovery.
     */
    public function markOrphanedTasksFailed(string $error = 'Server restarted — task process was lost'): int
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE background_tasks
            SET status = 'failed', error = :error, completed_at = :now
            WHERE status IN ('running', 'cancelling')
        SQL);

        $stmt->execute(['error' => $error, 'now' => date('c')]);

        return $stmt->rowCount();
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
     * Mark orphaned turn processes (stuck in pending/running) as failed.
     *
     * Called on API server startup to clean up from previous crashes.
     */
    public function markOrphanedTurnProcessesFailed(string $error = 'Server restarted — turn process was lost'): int
    {
        $stmt = $this->db->prepare(<<<SQL
            UPDATE turn_processes
            SET status = 'failed', error = :error, completed_at = :now
            WHERE status IN ('pending', 'running')
        SQL);

        $stmt->execute([
            'error' => $error,
            'now' => date('c'),
        ]);

        return $stmt->rowCount();
    }
}
