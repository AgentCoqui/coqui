<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * Projects a coqui `background_tasks` row to a CAP 0.5.0 `job.json` wire object.
 *
 * The Job is an internal (diagnostics-only) collection: the async unit a Turn or
 * Loop Stage runs on. coqui's `background_tasks` table is the Job store; this
 * producer emits exactly the schema's property set (`additionalProperties:false`)
 * and drops the operational-only columns (pid, schedule_id, heartbeat, tool_*,
 * max_execution_seconds, project_id, sprint_id) that the contract does not model.
 */
final class JobProducer
{
    /** CAP job status is a closed set; coqui's transient `cancelling` maps to `running`. */
    private const array STATUSES = ['pending', 'running', 'completed', 'failed', 'cancelled'];

    /**
     * @param array<string, mixed> $row A `background_tasks` row.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $parent = $row['parent_session_id'] ?? null;
        $title = $row['title'] ?? null;
        $result = $row['result'] ?? null;
        $error = $row['error'] ?? null;
        $status = (string) ($row['status'] ?? 'pending');

        if (!in_array($status, self::STATUSES, true)) {
            // `cancelling` is a coqui-internal in-flight state with no CAP peer.
            $status = $status === 'cancelling' ? 'running' : 'failed';
        }

        return [
            'id' => (string) ($row['id'] ?? ''),
            'session_id' => (string) ($row['session_id'] ?? ''),
            'parent_session_id' => is_string($parent) && $parent !== '' ? $parent : null,
            'status' => $status,
            'title' => is_string($title) && $title !== '' ? $title : null,
            'prompt' => (string) ($row['prompt'] ?? ''),
            'role' => (string) ($row['role'] ?? 'orchestrator'),
            'metadata' => WireFormat::object($row['metadata'] ?? null),
            'result' => is_string($result) ? $result : null,
            'error' => is_string($error) ? $error : null,
            'max_iterations' => max(1, (int) ($row['max_iterations'] ?? 25)),
            'created_at' => WireFormat::timestamp($row['created_at'] ?? null) ?? '',
            'started_at' => WireFormat::timestamp($row['started_at'] ?? null),
            'completed_at' => WireFormat::timestamp($row['completed_at'] ?? null),
            'cancelled_at' => WireFormat::timestamp($row['cancelled_at'] ?? null),
        ];
    }
}
