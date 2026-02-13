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

        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_messages_session ON messages(session_id)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_child_runs_session ON child_runs(session_id)');
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
    ): string {
        $id = bin2hex(random_bytes(16));
        $now = date('c');

        $stmt = $this->db->prepare(<<<SQL
            INSERT INTO messages (id, session_id, role, content, tool_calls, tool_call_id, created_at)
            VALUES (:id, :session_id, :role, :content, :tool_calls, :tool_call_id, :created_at)
        SQL);

        $stmt->execute([
            'id' => $id,
            'session_id' => $sessionId,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'tool_call_id' => $toolCallId,
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
        $this->db->prepare('UPDATE sessions SET token_count = :tokens WHERE id = :id')
            ->execute(['tokens' => $tokens, 'id' => $sessionId]);
    }

    public function deleteSession(string $id): void
    {
        $this->db->prepare('DELETE FROM sessions WHERE id = :id')
            ->execute(['id' => $id]);
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
