<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Structured outcome captured after a learner follow-up task finishes.
 */
final readonly class LearnerOutcomeMetadata
{
    /**
     * @param list<string> $skillsCreated
     * @param list<string> $skillsUpdated
     */
    public function __construct(
        public string $evaluationId,
        public string $taskId,
        public string $taskStatus,
        public array $skillsCreated,
        public array $skillsUpdated,
        public ?string $resultExcerpt,
        public ?string $error,
        public string $capturedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'evaluation_id' => $this->evaluationId,
            'task_id' => $this->taskId,
            'task_status' => $this->taskStatus,
            'skills_created' => $this->skillsCreated,
            'skills_updated' => $this->skillsUpdated,
            'result_excerpt' => $this->resultExcerpt,
            'error' => $this->error,
            'captured_at' => $this->capturedAt,
        ];
    }
}