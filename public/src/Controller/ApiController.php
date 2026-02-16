<?php

declare(strict_types=1);

namespace CoquiBot\Dashboard\Controller;

use CoquiBot\Dashboard\Service\DashboardQueryService;
use PDO;

/**
 * JSON API endpoints for dashboard stats, sessions, turns, and audit log.
 */
final class ApiController
{
    public function __construct(
        private readonly PDO $db,
        private readonly DashboardQueryService $query,
    ) {}

    /**
     * GET /api/stats — aggregate dashboard stats.
     */
    public function stats(): void
    {
        $period = $_GET['period'] ?? 'all';
        $since = DashboardQueryService::periodToDate($period);

        $this->json($this->query->getOverviewStats($since));
    }

    /**
     * GET /api/stats/tokens — token usage over time.
     */
    public function tokensOverTime(): void
    {
        $period = $_GET['period'] ?? '7d';
        $since = DashboardQueryService::periodToDate($period);
        $granularity = DashboardQueryService::periodToGranularity($period);
        $model = $_GET['model'] ?? null;

        $this->json($this->query->getTokensOverTime($granularity, $since, $model));
    }

    /**
     * GET /api/stats/tools — tool usage frequency.
     */
    public function toolUsage(): void
    {
        $period = $_GET['period'] ?? 'all';
        $since = DashboardQueryService::periodToDate($period);

        $this->json($this->query->getToolUsageFrequency($since));
    }

    /**
     * GET /api/stats/models — model usage breakdown.
     */
    public function modelUsage(): void
    {
        $period = $_GET['period'] ?? 'all';
        $since = DashboardQueryService::periodToDate($period);

        $this->json($this->query->getModelUsageBreakdown($since));
    }

    /**
     * GET /api/stats/filters — distinct values for filter dropdowns.
     */
    public function filterOptions(): void
    {
        $this->json([
            'tools' => $this->query->getDistinctTools(),
            'models' => $this->query->getDistinctModels(),
        ]);
    }

    /**
     * GET /api/sessions — paginated session list.
     */
    public function sessions(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? $_GET['per_page'] ?? 20)));

        $this->json($this->query->getSessionsPaginated($page, $limit));
    }

    /**
     * GET /api/sessions/{id} — single session detail.
     */
    public function session(string $id): void
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, model_role, model, created_at, updated_at, token_count
            FROM sessions WHERE id = :id
        SQL);
        $stmt->execute(['id' => $id]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($session === false) {
            $this->json(['error' => 'Session not found'], 404);
            return;
        }

        // Enrich with counts
        $session['turn_count'] = (int) $this->scalar(
            'SELECT COUNT(*) FROM turns WHERE session_id = :id',
            ['id' => $id],
        );
        $session['message_count'] = (int) $this->scalar(
            'SELECT COUNT(*) FROM messages WHERE session_id = :id',
            ['id' => $id],
        );
        $session['child_run_count'] = (int) $this->scalar(
            'SELECT COUNT(*) FROM child_runs WHERE session_id = :id',
            ['id' => $id],
        );

        $this->json($session);
    }

    /**
     * GET /api/sessions/{id}/messages — all messages for a session.
     */
    public function sessionMessages(string $id): void
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, role, content, tool_calls, tool_call_id, turn_id, created_at
            FROM messages
            WHERE session_id = :session_id
            ORDER BY created_at ASC
        SQL);
        $stmt->execute(['session_id' => $id]);

        $this->json($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * GET /api/sessions/{id}/turns — turns for a session.
     */
    public function sessionTurns(string $id): void
    {
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 50)));

        $stmt = $this->db->prepare(<<<SQL
            SELECT id, turn_number, user_prompt, response_text, model,
                   prompt_tokens, completion_tokens, total_tokens, iterations,
                   duration_ms, tools_used, child_agent_count, created_at, completed_at
            FROM turns
            WHERE session_id = :session_id
            ORDER BY turn_number ASC
            LIMIT :limit
        SQL);
        $stmt->bindValue('session_id', $id);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $this->json($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * GET /api/sessions/{id}/turns/{turnId} — single turn with messages.
     */
    public function sessionTurn(string $id, string $turnId): void
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, turn_number, user_prompt, response_text, model,
                   prompt_tokens, completion_tokens, total_tokens, iterations,
                   duration_ms, tools_used, child_agent_count, created_at, completed_at
            FROM turns
            WHERE id = :turn_id AND session_id = :session_id
        SQL);
        $stmt->execute(['turn_id' => $turnId, 'session_id' => $id]);
        $turn = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($turn === false) {
            $this->json(['error' => 'Turn not found'], 404);
            return;
        }

        // Nest messages
        $msgStmt = $this->db->prepare(<<<SQL
            SELECT id, role, content, tool_calls, tool_call_id, created_at
            FROM messages WHERE turn_id = :turn_id ORDER BY created_at ASC
        SQL);
        $msgStmt->execute(['turn_id' => $turnId]);
        $turn['messages'] = $msgStmt->fetchAll(PDO::FETCH_ASSOC);

        // Nest audit entries
        $auditStmt = $this->db->prepare(<<<SQL
            SELECT id, tool_name, arguments, action, reason, created_at
            FROM audit_log WHERE turn_id = :turn_id ORDER BY created_at ASC
        SQL);
        $auditStmt->execute(['turn_id' => $turnId]);
        $turn['audit'] = $auditStmt->fetchAll(PDO::FETCH_ASSOC);

        $this->json($turn);
    }

    /**
     * GET /api/sessions/{id}/child-runs — child agent runs.
     */
    public function sessionChildRuns(string $id): void
    {
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, parent_iteration, agent_role, model, prompt, result, token_count, created_at
            FROM child_runs
            WHERE session_id = :session_id
            ORDER BY created_at ASC
        SQL);
        $stmt->execute(['session_id' => $id]);

        $this->json($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * GET /api/audit — paginated audit log.
     */
    public function auditLog(): void
    {
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? $_GET['per_page'] ?? 50)));

        $this->json($this->query->getAuditLogPaginated(
            page: $page,
            perPage: $limit,
            action: !empty($_GET['action']) ? $_GET['action'] : null,
            toolName: !empty($_GET['tool']) ? $_GET['tool'] : null,
            sessionId: !empty($_GET['session']) ? $_GET['session'] : null,
            since: isset($_GET['period']) ? DashboardQueryService::periodToDate($_GET['period']) : null,
        ));
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        return $stmt->fetchColumn();
    }
}
