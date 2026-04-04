<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CoquiBot\Coqui\Contract\LoopStageHandoffMetadata;

/**
 * Configuration returned by LoopExecutor when preparing the next stage for execution.
 *
 * Contains everything a runner (REPL or API) needs to execute a single stage:
 * the role to use, the assembled prompt, and the stage record ID for reporting back.
 */
final readonly class LoopStageResult
{
    /**
     * @param string $stageId       Database ID of the loop_stages record
     * @param string $loopId        Parent loop ID
     * @param string $iterationId   Parent iteration ID
     * @param int    $stageIndex    0-based position in the role sequence
     * @param string $role          Coqui role name for this stage
     * @param string $prompt        Fully assembled prompt with context
     * @param int|null $maxIterations  Per-stage iteration limit (null = role default)
     * @param string|null $sprintId   Linked sprint ID for artifact/todo creation
     * @param string|null $sessionId   Session ID for artifact/todo scoping
     * @param string|null $projectId   Loop project ID for artifact auto-scoping
     * @param LoopStageHandoffMetadata|null $handoffMetadata Typed stage coordination metadata for persistence and observability
     */
    public function __construct(
        public string $stageId,
        public string $loopId,
        public string $iterationId,
        public int $stageIndex,
        public string $role,
        public string $prompt,
        public ?int $maxIterations = null,
        public ?string $sprintId = null,
        public ?string $sessionId = null,
        public ?string $projectId = null,
        public ?LoopStageHandoffMetadata $handoffMetadata = null,
    ) {}
}
