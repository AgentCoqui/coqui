<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * Projects a coqui `loop_stages` row to a CAP 0.5.0 `loop-stage.json` wire object
 * — one role's execution within an Iteration.
 *
 * The coqui column `task_id` maps to the wire field `job_id` (a stage runs on a
 * Job). `verdict` is a persisted gate verdict object or null; coqui stores it in
 * the `metadata` blob under `verdict`, so it is emitted null here unless present.
 */
final class LoopStageProducer
{
    /**
     * @param array<string, mixed> $row A `loop_stages` row.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $jobId = $row['task_id'] ?? $row['job_id'] ?? null;
        $artifactId = $row['artifact_id'] ?? null;
        $summary = $row['result_summary'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'iteration_id' => (string) ($row['iteration_id'] ?? ''),
            'stage_index' => max(0, (int) ($row['stage_index'] ?? 0)),
            'role' => (string) ($row['role'] ?? ''),
            'job_id' => is_string($jobId) && $jobId !== '' ? $jobId : null,
            'artifact_id' => is_string($artifactId) && $artifactId !== '' ? $artifactId : null,
            'status' => (string) ($row['status'] ?? 'pending'),
            'verdict' => WireFormat::object($row['verdict'] ?? null),
            'result_summary' => is_string($summary) && $summary !== '' ? $summary : null,
            'started_at' => WireFormat::timestamp($row['started_at'] ?? null),
            'completed_at' => WireFormat::timestamp($row['completed_at'] ?? null),
        ];
    }
}
