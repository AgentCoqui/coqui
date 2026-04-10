<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Structured metadata describing a prepared loop stage handoff.
 */
final readonly class LoopStageHandoffMetadata
{
    /**
     * @param list<string> $artifactIds
     * @param list<string> $completedStageRoles
     */
    public function __construct(
        public string $loopId,
        public string $iterationId,
        public string $stageId,
        public int $stageIndex,
        public int $totalStages,
        public string $role,
        public array $artifactIds = [],
        public array $completedStageRoles = [],
        public bool $requiresExplicitEvidence = false,
        public ?string $sessionId = null,
        public ?string $projectId = null,
        public ?string $sprintId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'loop_id' => $this->loopId,
            'iteration_id' => $this->iterationId,
            'stage_id' => $this->stageId,
            'stage_index' => $this->stageIndex,
            'total_stages' => $this->totalStages,
            'role' => $this->role,
            'artifact_ids' => $this->artifactIds,
            'completed_stage_roles' => $this->completedStageRoles,
            'requires_explicit_evidence' => $this->requiresExplicitEvidence,
            'session_id' => $this->sessionId,
            'project_id' => $this->projectId,
            'sprint_id' => $this->sprintId,
        ];
    }
}