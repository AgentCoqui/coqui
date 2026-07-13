<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * One stage's rolled-up execution data in the loop live view.
 */
final readonly class LoopLiveStage
{
    /**
     * @param list<string>              $toolsUsed
     * @param array<string, mixed>|null $verdict
     */
    public function __construct(
        public int $iterationNumber,
        public int $stageIndex,
        public string $role,
        public ?string $model,
        public string $status,
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public int $durationMs,
        public array $toolsUsed,
        public ?string $resultSummary,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $taskId,
        public ?array $verdict = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'iteration_number' => $this->iterationNumber,
            'stage_index' => $this->stageIndex,
            'role' => $this->role,
            'model' => $this->model,
            'status' => $this->status,
            'tokens' => [
                'prompt' => $this->promptTokens,
                'completion' => $this->completionTokens,
                'total' => $this->totalTokens,
            ],
            'duration_ms' => $this->durationMs,
            'tools_used' => $this->toolsUsed,
            'result_summary' => $this->resultSummary,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'task_id' => $this->taskId,
            'verdict' => $this->verdict,
        ];
    }
}
