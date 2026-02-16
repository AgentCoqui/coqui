<?php

declare(strict_types=1);

namespace CoquiBot\Dashboard\Service;

use PDO;

/**
 * Aggregate queries for the dashboard that SessionStorage doesn't provide.
 *
 * Operates read-only against the Coqui SQLite database.
 */
final class DashboardQueryService
{
    public function __construct(
        private readonly PDO $db,
    ) {}

    /**
     * Overall stats: total sessions, tokens, messages, average latency.
     *
     * @return array{total_sessions: int, total_tokens: int, total_messages: int, avg_latency_ms: float, total_turns: int}
     */
    public function getOverviewStats(?string $since = null): array
    {
        $dateFilter = $since !== null ? "WHERE created_at >= :since" : '';
        $params = $since !== null ? ['since' => $since] : [];

        $sessions = $this->scalar(
            "SELECT COUNT(*) FROM sessions {$dateFilter}",
            $params,
        );

        $tokens = $this->scalar(
            "SELECT COALESCE(SUM(token_count), 0) FROM sessions {$dateFilter}",
            $params,
        );

        $turnFilter = $since !== null ? "WHERE created_at >= :since" : '';
        $turnFilterWithCompleted = $since !== null
            ? "WHERE created_at >= :since AND completed_at IS NOT NULL"
            : "WHERE completed_at IS NOT NULL";

        $messages = $this->scalar(
            "SELECT COUNT(*) FROM messages {$dateFilter}",
            $params,
        );

        $turns = $this->scalar(
            "SELECT COUNT(*) FROM turns {$turnFilter}",
            $params,
        );

        $avgLatency = $this->scalar(
            "SELECT COALESCE(AVG(duration_ms), 0) FROM turns {$turnFilterWithCompleted}",
            $params,
        );

        return [
            'total_sessions' => (int) $sessions,
            'total_tokens' => (int) $tokens,
            'total_messages' => (int) $messages,
            'avg_latency_ms' => round((float) $avgLatency, 1),
            'total_turns' => (int) $turns,
        ];
    }

    /**
     * Token usage over time grouped by time bucket.
     *
     * @return array<array{bucket: string, model: string, total_tokens: int, prompt_tokens: int, completion_tokens: int}>
     */
    public function getTokensOverTime(string $granularity = 'day', ?string $since = null, ?string $model = null): array
    {
        $format = match ($granularity) {
            'hour' => '%Y-%m-%d %H:00',
            'day' => '%Y-%m-%d',
            'week' => '%Y-W%W',
            'month' => '%Y-%m',
            default => '%Y-%m-%d',
        };

        $conditions = ['completed_at IS NOT NULL'];
        $params = [];

        if ($since !== null) {
            $conditions[] = 'created_at >= :since';
            $params['since'] = $since;
        }

        if ($model !== null) {
            $conditions[] = 'model = :model';
            $params['model'] = $model;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $stmt = $this->db->prepare(<<<SQL
            SELECT
                strftime('{$format}', created_at) AS bucket,
                COALESCE(model, 'unknown') AS model,
                SUM(total_tokens) AS total_tokens,
                SUM(prompt_tokens) AS prompt_tokens,
                SUM(completion_tokens) AS completion_tokens,
                COUNT(*) AS turn_count
            FROM turns
            {$where}
            GROUP BY bucket, model
            ORDER BY bucket ASC
        SQL);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Tool usage frequency from audit log.
     *
     * @return array<array{tool_name: string, total: int, approved: int, denied: int, blocked: int}>
     */
    public function getToolUsageFrequency(?string $since = null): array
    {
        $conditions = [];
        $params = [];

        if ($since !== null) {
            $conditions[] = 'created_at >= :since';
            $params['since'] = $since;
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $stmt = $this->db->prepare(<<<SQL
            SELECT
                tool_name,
                COUNT(*) AS total,
                SUM(CASE WHEN action = 'approved' THEN 1 ELSE 0 END) AS approved,
                SUM(CASE WHEN action = 'denied' THEN 1 ELSE 0 END) AS denied,
                SUM(CASE WHEN action = 'blocked' THEN 1 ELSE 0 END) AS blocked
            FROM audit_log
            {$where}
            GROUP BY tool_name
            ORDER BY total DESC
        SQL);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Model usage breakdown from turns table.
     *
     * @return array<array{model: string, turn_count: int, total_tokens: int, avg_duration_ms: float}>
     */
    public function getModelUsageBreakdown(?string $since = null): array
    {
        $conditions = ['model IS NOT NULL'];
        $params = [];

        if ($since !== null) {
            $conditions[] = 'created_at >= :since';
            $params['since'] = $since;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $stmt = $this->db->prepare(<<<SQL
            SELECT
                model,
                COUNT(*) AS turn_count,
                COALESCE(SUM(total_tokens), 0) AS total_tokens,
                COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                COALESCE(SUM(completion_tokens), 0) AS completion_tokens,
                ROUND(COALESCE(AVG(duration_ms), 0), 1) AS avg_duration_ms
            FROM turns
            {$where}
            GROUP BY model
            ORDER BY turn_count DESC
        SQL);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Distinct tool names for filter dropdowns.
     *
     * @return string[]
     */
    public function getDistinctTools(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT tool_name FROM audit_log ORDER BY tool_name ASC');

        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Distinct model names for filter dropdowns.
     *
     * @return string[]
     */
    public function getDistinctModels(): array
    {
        $stmt = $this->db->query('SELECT DISTINCT model FROM turns WHERE model IS NOT NULL ORDER BY model ASC');

        if ($stmt === false) {
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Paginated audit log with filters.
     *
     * @return array{data: array, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getAuditLogPaginated(
        int $page = 1,
        int $perPage = 50,
        ?string $action = null,
        ?string $toolName = null,
        ?string $sessionId = null,
        ?string $since = null,
    ): array {
        $conditions = [];
        $params = [];

        if ($action !== null) {
            $conditions[] = 'action = :action';
            $params['action'] = $action;
        }

        if ($toolName !== null) {
            $conditions[] = 'tool_name = :tool_name';
            $params['tool_name'] = $toolName;
        }

        if ($sessionId !== null) {
            $conditions[] = 'session_id = :session_id';
            $params['session_id'] = $sessionId;
        }

        if ($since !== null) {
            $conditions[] = 'created_at >= :since';
            $params['since'] = $since;
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total
        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM audit_log {$where}");
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        // Fetch page
        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(<<<SQL
            SELECT id, session_id, tool_name, arguments, action, reason, turn_id, created_at
            FROM audit_log
            {$where}
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        SQL);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    /**
     * Paginated sessions list.
     *
     * @return array{data: array, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getSessionsPaginated(int $page = 1, int $perPage = 20): array
    {
        $total = (int) $this->scalar('SELECT COUNT(*) FROM sessions', []);

        $offset = ($page - 1) * $perPage;
        $stmt = $this->db->prepare(<<<SQL
            SELECT
                s.id,
                s.model_role,
                s.model,
                s.created_at,
                s.updated_at,
                s.token_count,
                (SELECT COUNT(*) FROM turns WHERE session_id = s.id) AS turn_count,
                (SELECT COUNT(*) FROM messages WHERE session_id = s.id) AS message_count
            FROM sessions s
            ORDER BY s.updated_at DESC
            LIMIT :limit OFFSET :offset
        SQL);

        $stmt->bindValue('limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil(max($total, 1) / $perPage),
        ];
    }

    /**
     * Resolve a period string to an ISO 8601 date.
     */
    public static function periodToDate(string $period): ?string
    {
        return match ($period) {
            '1h' => date('c', strtotime('-1 hour')),
            '24h' => date('c', strtotime('-24 hours')),
            '7d' => date('c', strtotime('-7 days')),
            '30d' => date('c', strtotime('-30 days')),
            '90d' => date('c', strtotime('-90 days')),
            'all' => null,
            default => null,
        };
    }

    /**
     * Resolve a period string to a granularity level.
     */
    public static function periodToGranularity(string $period): string
    {
        return match ($period) {
            '1h', '24h' => 'hour',
            '7d' => 'day',
            '30d' => 'day',
            '90d' => 'week',
            default => 'day',
        };
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
