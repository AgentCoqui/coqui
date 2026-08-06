<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * Projects a coqui `loop_iterations` row to a CAP 0.5.0 `loop-iteration.json`
 * wire object — one pass through a Loop's role-chain.
 */
final class LoopIterationProducer
{
    /**
     * @param array<string, mixed> $row A `loop_iterations` row.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $summary = $row['outcome_summary'] ?? null;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'loop_id' => (string) ($row['loop_id'] ?? ''),
            'iteration_number' => max(1, (int) ($row['iteration_number'] ?? 1)),
            'status' => (string) ($row['status'] ?? 'pending'),
            'outcome_summary' => is_string($summary) && $summary !== '' ? $summary : null,
            'started_at' => WireFormat::timestamp($row['started_at'] ?? null),
            'completed_at' => WireFormat::timestamp($row['completed_at'] ?? null),
        ];
    }
}
