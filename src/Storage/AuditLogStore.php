<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Storage;

use PDO;

/**
 * Read side of the audit log.
 *
 * Shares the same PDO instance as SessionStorage, which owns the table and
 * remains the sole write path (see SessionStorage::logAudit).
 */
final class AuditLogStore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->createIndexes();
    }

    private function createIndexes(): void
    {
        // session/action/turn indexes are created by SessionStorage; these two
        // serve the new time-ordered and tool-filtered query paths.
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at)');
        $this->db->exec('CREATE INDEX IF NOT EXISTS idx_audit_log_tool ON audit_log(tool_name)');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function query(AuditLogQuery $query): array
    {
        [$where, $params] = $this->conditions($query);

        $sql = 'SELECT id, session_id, turn_id, tool_name, action, reason, arguments, created_at
                FROM audit_log
                ' . $where . '
                ORDER BY created_at DESC, id DESC
                LIMIT :limit OFFSET :offset';

        $stmt = $this->db->prepare($sql);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->bindValue(':limit', $query->limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $query->offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->decodeRow(...), $rows);
    }

    public function count(AuditLogQuery $query): int
    {
        [$where, $params] = $this->conditions($query);

        $stmt = $this->db->prepare('SELECT COUNT(*) FROM audit_log ' . $where);

        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }

        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{0: string, 1: array<string, string>}
     */
    private function conditions(AuditLogQuery $query): array
    {
        $conditions = [];
        $params = [];

        if ($query->sessionId !== null) {
            $conditions[] = 'session_id = :session_id';
            $params[':session_id'] = $query->sessionId;
        }

        if ($query->toolName !== null) {
            $conditions[] = 'tool_name = :tool_name';
            $params[':tool_name'] = $query->toolName;
        }

        if ($query->action !== null) {
            $conditions[] = 'action = :action';
            $params[':action'] = $query->action;
        }

        if ($query->after !== null) {
            $conditions[] = 'created_at >= :after';
            $params[':after'] = $query->after;
        }

        if ($query->before !== null) {
            $conditions[] = 'created_at < :before';
            $params[':before'] = $query->before;
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        $raw = is_string($row['arguments'] ?? null) ? $row['arguments'] : '';
        $decoded = json_decode($raw, true);

        $row['arguments'] = is_array($decoded) ? $decoded : ['_raw' => $raw];

        return $row;
    }
}
