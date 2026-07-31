<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * Projects a coqui `audit_log` row to a CAP 0.5.0 `audit-record.json` wire object.
 *
 * An AuditRecord is the record of a gated/sensitive tool action (internal /
 * diagnostics-only). `arguments` is always an object (coqui persists it as a JSON
 * string that has already passed through the fail-closed redactor). The coqui
 * `turn_id` column is operational context the contract does not model and is
 * dropped so the object is `additionalProperties:false`-clean.
 */
final class AuditRecordProducer
{
    /**
     * @param array<string, mixed> $row An `audit_log` row. `arguments` may be a JSON
     *                                  string (raw column) or a decoded array.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $sessionId = $row['session_id'] ?? null;
        $reason = $row['reason'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'session_id' => is_string($sessionId) && $sessionId !== '' ? $sessionId : null,
            'tool_name' => (string) ($row['tool_name'] ?? ''),
            'arguments' => WireFormat::object($row['arguments'] ?? null) ?? new \stdClass(),
            'action' => (string) ($row['action'] ?? ''),
            'reason' => is_string($reason) ? $reason : null,
            'created_at' => WireFormat::timestamp($row['created_at'] ?? null) ?? '',
        ];
    }
}
