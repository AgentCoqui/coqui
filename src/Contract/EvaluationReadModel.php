<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

final readonly class EvaluationReadModel
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $id,
        public string $sessionId,
        public ?string $sessionTitle,
        public ?string $evaluatorTaskId,
        public ?string $learnerFollowUpTaskId,
        public ?string $learnerFollowUpLinkedAt,
        public ?array $learnerOutcomeMetadata,
        public string $overallGrade,
        public float $scoreCompletion,
        public float $scoreHallucination,
        public float $scoreEfficiency,
        public float $overallScore,
        public string $report,
        public ?string $model,
        public ?array $metadata,
        public string $createdAt,
    ) {}

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $metadata = null;
        if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        } elseif (isset($row['metadata']) && is_array($row['metadata'])) {
            $metadata = $row['metadata'];
        }

        return new self(
            id: (string) ($row['id'] ?? ''),
            sessionId: (string) ($row['session_id'] ?? ''),
            sessionTitle: isset($row['session_title']) && is_string($row['session_title']) ? $row['session_title'] : null,
            evaluatorTaskId: isset($row['evaluator_task_id']) && is_string($row['evaluator_task_id']) ? $row['evaluator_task_id'] : null,
            learnerFollowUpTaskId: isset($row['learner_follow_up_task_id']) && is_string($row['learner_follow_up_task_id']) ? $row['learner_follow_up_task_id'] : null,
            learnerFollowUpLinkedAt: isset($row['learner_follow_up_linked_at']) && is_string($row['learner_follow_up_linked_at']) ? $row['learner_follow_up_linked_at'] : null,
            learnerOutcomeMetadata: self::decodeJsonArray($row['learner_outcome_metadata'] ?? null),
            overallGrade: (string) ($row['overall_grade'] ?? ''),
            scoreCompletion: (float) ($row['score_completion'] ?? 0.0),
            scoreHallucination: (float) ($row['score_hallucination'] ?? 0.0),
            scoreEfficiency: (float) ($row['score_efficiency'] ?? 0.0),
            overallScore: (float) ($row['overall_score'] ?? 0.0),
            report: (string) ($row['report'] ?? ''),
            model: isset($row['model']) && is_string($row['model']) ? $row['model'] : null,
            metadata: $metadata,
            createdAt: (string) ($row['created_at'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'session_title' => $this->sessionTitle,
            'evaluator_task_id' => $this->evaluatorTaskId,
            'learner_follow_up_task_id' => $this->learnerFollowUpTaskId,
            'learner_follow_up_linked_at' => $this->learnerFollowUpLinkedAt,
            'learner_outcome_metadata' => $this->learnerOutcomeMetadata,
            'overall_grade' => $this->overallGrade,
            'score_completion' => $this->scoreCompletion,
            'score_hallucination' => $this->scoreHallucination,
            'score_efficiency' => $this->scoreEfficiency,
            'overall_score' => $this->overallScore,
            'report' => $this->report,
            'model' => $this->model,
            'metadata' => $this->metadata,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'session_id' => $this->sessionId,
            'session_title' => $this->sessionTitle,
            'evaluator_task_id' => $this->evaluatorTaskId,
            'learner_follow_up_task_id' => $this->learnerFollowUpTaskId,
            'learner_follow_up_linked_at' => $this->learnerFollowUpLinkedAt,
            'learner_outcome_metadata' => $this->learnerOutcomeMetadata,
            'overall_grade' => $this->overallGrade,
            'score_completion' => $this->scoreCompletion,
            'score_hallucination' => $this->scoreHallucination,
            'score_efficiency' => $this->scoreEfficiency,
            'overall_score' => $this->overallScore,
            'model' => $this->model,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJsonArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : null;
    }
}