<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * Projects a coqui `task_events` row to a CAP 0.5.0 `job-event.json` wire object.
 *
 * A JobEvent is one entry in a Job's progress stream (internal/diagnostics-only).
 * Its `id` is an autoincrement INTEGER primary key — NOT an opaque Id string — so
 * the producer emits it as an integer to match the schema. The coqui column
 * `task_id` maps to the wire field `job_id`; `data` is an object (never an array).
 */
final class JobEventProducer
{
    /**
     * @param array<string, mixed> $row A `task_events` row. Accepts either `job_id`
     *                                  or the raw `task_id` column as the job reference.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $jobId = $row['job_id'] ?? $row['task_id'] ?? '';

        return [
            'id' => (int) ($row['id'] ?? 0),
            'job_id' => (string) $jobId,
            'event_type' => (string) ($row['event_type'] ?? ''),
            'data' => WireFormat::object($row['data'] ?? null) ?? new \stdClass(),
            'created_at' => WireFormat::timestamp($row['created_at'] ?? null) ?? '',
        ];
    }
}
