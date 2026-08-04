<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Loop;

use CoquiBot\Coqui\Storage\LoopStore;

/**
 * Emits a strictly-typed loop-live.json snapshot (CAP 0.5.0 CORE-6) from the
 * loop's persisted row, iterations, and stages.
 *
 * The producer reads the same LoopStore data the untyped LoopLiveViewBuilder
 * consumes, but maps internal status vocabularies onto the closed CAP enums and
 * emits only the schema-declared keys (additionalProperties:false). It is a pure
 * read with no side effects.
 */
final readonly class LoopLiveProducer
{
    public function __construct(private LoopStore $store) {}

    /**
     * @return array{loop_id: string, status: string, current_iteration: int, current_stage: int, budget: array{tokens_used: int, iterations_used: int, max_iterations: int|null, elapsed_ms: int}, stages: list<array{stage_index: int, role: string, status: string, result_summary: string|null}>}
     */
    public function toWire(string $loopId): array
    {
        $loop = $this->store->getLoop($loopId);
        $currentIteration = $loop !== null ? (int) ($loop['current_iteration'] ?? 0) : 0;
        $currentStage = $loop !== null ? (int) ($loop['current_stage'] ?? 0) : 0;
        $maxIterations = ($loop !== null && $loop['max_iterations'] !== null && $loop['max_iterations'] !== '')
            ? (int) $loop['max_iterations']
            : null;

        return [
            'loop_id' => $loopId,
            'status' => $this->mapLoopStatus($loop !== null ? (string) ($loop['status'] ?? 'running') : 'running'),
            'current_iteration' => max(0, $currentIteration),
            'current_stage' => max(0, $currentStage),
            'budget' => [
                'tokens_used' => 0,
                'iterations_used' => max(0, $currentIteration),
                'max_iterations' => $maxIterations,
                'elapsed_ms' => 0,
            ],
            'stages' => $this->currentStages($loopId),
        ];
    }

    /**
     * Stages of the loop's current (latest) iteration, mapped to the CAP shape.
     * Iterations are ordered ascending by number, so the last row is current.
     *
     * @return list<array{stage_index: int, role: string, status: string, result_summary: string|null}>
     */
    private function currentStages(string $loopId): array
    {
        $iterations = $this->store->listIterations($loopId);
        if ($iterations === []) {
            return [];
        }

        $current = $iterations[array_key_last($iterations)];
        $stages = [];
        foreach ($this->store->listStages((string) ($current['id'] ?? '')) as $stage) {
            $summary = $stage['result_summary'] ?? null;

            $stages[] = [
                'stage_index' => (int) ($stage['stage_index'] ?? 0),
                'role' => (string) ($stage['role'] ?? ''),
                'status' => $this->mapStageStatus((string) ($stage['status'] ?? 'pending')),
                'result_summary' => ($summary === null || $summary === '') ? null : (string) $summary,
            ];
        }

        return $stages;
    }

    /**
     * Map an internal loop status onto the closed loop.json status enum. The
     * internal vocabulary is already a subset; unknown values fall back to a
     * schema-legal default rather than leaking an out-of-set token.
     */
    private function mapLoopStatus(string $status): string
    {
        return match ($status) {
            'running', 'paused', 'completed', 'blocked', 'failed', 'cancelled' => $status,
            'needs_rework' => 'blocked',
            default => 'running',
        };
    }

    /**
     * Map an internal stage status onto the closed loop-stage.json status enum.
     * Internal 'completed' becomes 'done'; a failed/reworking stage folds onto
     * the nearest CAP status so the snapshot always validates strictly.
     */
    private function mapStageStatus(string $status): string
    {
        return match ($status) {
            'pending', 'running', 'blocked' => $status,
            'completed' => 'done',
            'failed' => 'blocked',
            'needs_rework' => 'needs_context',
            default => 'pending',
        };
    }
}
