<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Structured provenance for learner follow-up tasks created from evaluations.
 */
final readonly class LearnerFollowUpMetadata
{
    /**
     * @param list<string> $evidenceSources
     * @param list<string> $childRunIds
     */
    public function __construct(
        public string $evaluationId,
        public string $evaluatedSessionId,
        public string $overallGrade,
        public float $overallScore,
        public ?string $sessionTitle = null,
        public array $evidenceSources = [],
        public array $childRunIds = [],
        public int $childRunCount = 0,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'evaluation_id' => $this->evaluationId,
            'evaluated_session_id' => $this->evaluatedSessionId,
            'overall_grade' => $this->overallGrade,
            'overall_score' => $this->overallScore,
            'session_title' => $this->sessionTitle,
            'evidence_sources' => $this->evidenceSources,
            'child_run_ids' => $this->childRunIds,
            'child_run_count' => $this->childRunCount,
        ];
    }
}