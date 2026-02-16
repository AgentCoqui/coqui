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
    public function listSessions(int $limit = 50): array
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, model_role, model, created_at, updated_at, token_count
            FROM sessions
            ORDER BY updated_at DESC
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
            SELECT id, model_role, model, created_at, updated_at, token_count
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
     */
    public function loadConversation(string $sessionId): Conversation
    {
        $messages = $this->getMessages($sessionId);
        $conversation = new Conversation();

        foreach ($messages as $msg) {
            $role = Role::from($msg['role']);
            $content = $msg['content'];
            $toolCalls = $msg['tool_calls'] !== null
                ? $this->decodeToolCalls($msg['tool_calls'])
                : [];
            $toolCallId = $msg['tool_call_id'];

            $message = match ($role) {
                Role::System => new SystemMessage($content),
                Role::User => new UserMessage($content),
                Role::Assistant => new AssistantMessage($content, $toolCalls),
                Role::Tool => new ToolResultMessage(
                    (new ToolResult(ToolResultStatus::Success, $content))->withCallId($toolCallId),
                ),
            };

            $conversation->add($message);
        }

        return $conversation;
    }

    /**
     * @return ToolCall[]
     */
    private function decodeToolCalls(string $json): array
    {
        $data = json_decode($json, true);

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
                );
            }
        }

        return $calls;
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
}
