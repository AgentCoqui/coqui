<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Config\ConfigInterface;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;
use CoquiBot\Coqui\Contract\LoopStageResult;
use CoquiBot\Coqui\Contract\TerminationType;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

/**
 * Core orchestration engine for loop workflows.
 *
 * Mode-agnostic: used by both LoopRunner (REPL sync) and LoopManager (API async).
 * Handles loop lifecycle (create, advance, evaluate, complete) and context building
 * between stages. Does NOT execute agents — that's the runner's job.
 */
final class LoopExecutor
{
    public function __construct(
        private readonly LoopStore $loopStore,
        private readonly ProjectStore $projectStore,
        private readonly ArtifactStore $artifactStore,
        private readonly RoleResolver $roleResolver,
        private readonly RoleDiscovery $roleDiscovery,
        private readonly ConfigInterface $config,
    ) {}

    /**
     * Start a new loop: create the loop instance, auto-create a project, and first iteration.
     *
     * @return string Loop ID
     */
    public function startLoop(
        LoopDefinition $definition,
        string $goal,
        ?string $sessionId = null,
    ): string {
        // Auto-create a project for this loop
        $projectSlug = 'loop-' . $definition->name . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $projectId = $this->projectStore->createProject(
            title: sprintf('Loop: %s', $definition->name),
            slug: $projectSlug,
            description: $goal,
        );

        // Snapshot the definition so edits don't affect running loops
        $configuration = $definition->toArray();

        // Determine termination parameters
        $maxIterations = match ($definition->terminationCondition->type) {
            TerminationType::IterationBound => $definition->terminationCondition->maxIterations,
            TerminationType::EvaluationBound => $definition->terminationCondition->maxReviewRounds,
            default => null,
        };

        $deadline = $definition->terminationCondition->type === TerminationType::TimeBound
            ? $definition->terminationCondition->deadline
            : null;

        $terminationCriteria = $definition->terminationCondition->type === TerminationType::EvaluationBound
            ? $definition->terminationCondition->criteria
            : null;

        $loopId = $this->loopStore->createLoop(
            definitionName: $definition->name,
            goal: $goal,
            configuration: $configuration,
            sessionId: $sessionId,
            projectId: $projectId,
            maxIterations: $maxIterations,
            deadline: $deadline,
            terminationCriteria: $terminationCriteria,
        );

        // Create the first iteration and its sprint
        $this->advanceIteration($loopId, $definition, $projectId, $goal);

        return $loopId;
    }

    /**
     * Prepare the next stage for execution.
     *
     * Returns a LoopStageResult with the fully assembled prompt and role,
     * or null if there's no pending stage (iteration complete or loop not running).
     */
    public function prepareNextStage(string $loopId): ?LoopStageResult
    {
        $state = $this->loopStore->getCurrentState($loopId);
        if ($state === null) {
            return null;
        }

        $loop = $state['loop'];
        if ($loop['status'] !== 'running') {
            return null;
        }

        $iteration = $state['iteration'];
        if ($iteration === null) {
            return null;
        }

        $definition = LoopDefinition::fromArray(
            json_decode($loop['configuration'], true, 512, JSON_THROW_ON_ERROR),
        );

        // Find the next pending stage
        $stages = $state['stages'];
        $nextStage = null;
        foreach ($stages as $stage) {
            if ($stage['status'] === 'pending') {
                $nextStage = $stage;
                break;
            }
        }

        if ($nextStage === null) {
            return null; // All stages in this iteration are done
        }

        $stageIndex = (int) $nextStage['stage_index'];
        $roleDefinition = $definition->roles[$stageIndex] ?? null;
        if ($roleDefinition === null) {
            return null;
        }

        // Build the context prompt
        $prompt = $this->buildStagePrompt(
            definition: $definition,
            goal: $loop['goal'],
            iterationNumber: (int) $iteration['iteration_number'],
            maxIterations: $loop['max_iterations'] !== null ? (int) $loop['max_iterations'] : null,
            stageIndex: $stageIndex,
            totalStages: count($definition->roles),
            roleDefinition: $roleDefinition,
            completedStages: $this->loopStore->getCompletedStages($iteration['id']),
            previousOutcomes: $this->loopStore->getPreviousOutcomes($loopId, (int) $iteration['iteration_number']),
            terminationCriteria: $loop['termination_criteria'],
        );

        // Mark stage as running
        $this->loopStore->updateStage($nextStage['id'], 'running');
        $this->loopStore->updateLoopProgress($loopId, (int) $iteration['iteration_number'], $stageIndex);

        return new LoopStageResult(
            stageId: $nextStage['id'],
            loopId: $loopId,
            iterationId: $iteration['id'],
            stageIndex: $stageIndex,
            role: $roleDefinition->role,
            prompt: $prompt,
            maxIterations: $roleDefinition->maxIterations,
            sprintId: $iteration['sprint_id'],
            sessionId: $loop['session_id'],
        );
    }

    /**
     * Record the completion of a stage and persist its output.
     */
    public function completeStage(
        string $stageId,
        string $result,
        ?string $artifactId = null,
        ?string $taskId = null,
    ): void {
        // Truncate result_summary to a reasonable size for context building
        $summary = mb_strlen($result) > 2000
            ? mb_substr($result, 0, 2000) . "\n\n[... output truncated for context ...]"
            : $result;

        $this->loopStore->updateStage(
            id: $stageId,
            status: 'completed',
            taskId: $taskId,
            artifactId: $artifactId,
            resultSummary: $summary,
        );
    }

    /**
     * Mark a stage as failed.
     */
    public function failStage(string $stageId, string $error): void
    {
        $this->loopStore->updateStage(
            id: $stageId,
            status: 'failed',
            resultSummary: 'FAILED: ' . $error,
        );
    }

    /**
     * Evaluate whether the iteration cycle is complete and what to do next.
     *
     * Should be called after all stages in an iteration have completed.
     */
    public function evaluateIteration(string $loopId): IterationOutcome
    {
        $state = $this->loopStore->getCurrentState($loopId);
        if ($state === null) {
            return IterationOutcome::Failed;
        }

        $loop = $state['loop'];
        $iteration = $state['iteration'];
        $stages = $state['stages'];

        // Check if any stage failed
        $failedStages = array_filter($stages, fn(array $s) => $s['status'] === 'failed');
        if ($failedStages !== []) {
            $this->loopStore->updateIterationStatus($iteration['id'], 'failed', 'One or more stages failed');
            return IterationOutcome::Failed;
        }

        // Check if all stages are completed
        $pendingStages = array_filter($stages, fn(array $s) => $s['status'] !== 'completed');
        if ($pendingStages !== []) {
            return IterationOutcome::Continue; // Still stages to run
        }

        $definition = LoopDefinition::fromArray(
            json_decode($loop['configuration'], true, 512, JSON_THROW_ON_ERROR),
        );

        $iterationNumber = (int) $iteration['iteration_number'];

        // Evaluate based on termination type
        $outcome = match ($definition->terminationCondition->type) {
            TerminationType::EvaluationBound => $this->evaluateEvaluationBound($stages, $iterationNumber, $loop),
            TerminationType::IterationBound => $this->evaluateIterationBound($iterationNumber, $loop),
            TerminationType::TimeBound => $this->evaluateTimeBound($loop),
            TerminationType::Manual => IterationOutcome::Continue,
        };

        // Update iteration status based on outcome
        $iterationStatus = match ($outcome) {
            IterationOutcome::Complete, IterationOutcome::LimitReached => 'completed',
            IterationOutcome::Continue => 'completed', // Iteration completed, but loop continues
            IterationOutcome::Failed => 'failed',
        };

        $summary = $this->buildIterationSummary($stages);
        $this->loopStore->updateIterationStatus($iteration['id'], $iterationStatus, $summary);

        // If loop should continue, advance to next iteration
        if ($outcome === IterationOutcome::Continue) {
            $this->advanceIteration($loopId, $definition, $loop['project_id'], $loop['goal']);
        }

        // If loop is done, update its status
        if ($outcome === IterationOutcome::Complete || $outcome === IterationOutcome::LimitReached) {
            $this->loopStore->updateLoopStatus($loopId, 'completed');
        }

        if ($outcome === IterationOutcome::Failed) {
            $this->loopStore->updateLoopStatus($loopId, 'failed');
        }

        return $outcome;
    }

    /**
     * Pause a running loop.
     */
    public function pauseLoop(string $loopId): void
    {
        $this->loopStore->updateLoopStatus($loopId, 'paused');
    }

    /**
     * Resume a paused loop.
     */
    public function resumeLoop(string $loopId): void
    {
        $loop = $this->loopStore->getLoop($loopId);
        if ($loop !== null && $loop['status'] === 'paused') {
            $this->loopStore->updateLoopStatus($loopId, 'running');
        }
    }

    /**
     * Cancel a running or paused loop.
     */
    public function cancelLoop(string $loopId): void
    {
        $this->loopStore->updateLoopStatus($loopId, 'cancelled');
    }

    // ──────────────────────────────────────────────
    //  Private: Iteration Lifecycle
    // ──────────────────────────────────────────────

    /**
     * Create a new iteration with its sprint and pre-create all stage records.
     */
    private function advanceIteration(
        string $loopId,
        LoopDefinition $definition,
        ?string $projectId,
        string $goal,
    ): void {
        // Determine next iteration number
        $iterations = $this->loopStore->listIterations($loopId);
        $nextNumber = count($iterations) + 1;

        // Create a sprint for this iteration
        $sprintId = null;
        if ($projectId !== null) {
            $sprintTitle = sprintf('%s — iteration %d', $definition->name, $nextNumber);
            $criteria = $definition->terminationCondition->criteria;

            $sprintId = $this->projectStore->createSprint(
                projectId: $projectId,
                title: $sprintTitle,
                acceptanceCriteria: $criteria,
            );
        }

        $iterationId = $this->loopStore->createIteration(
            loopId: $loopId,
            iterationNumber: $nextNumber,
            sprintId: $sprintId,
        );

        // Pre-create stage records for all roles in the definition
        foreach ($definition->roles as $index => $roleDefinition) {
            $this->loopStore->createStage(
                iterationId: $iterationId,
                stageIndex: $index,
                role: $roleDefinition->role,
            );
        }

        $this->loopStore->updateLoopProgress($loopId, $nextNumber, 0);
        $this->loopStore->updateIterationStatus($iterationId, 'running');
    }

    // ──────────────────────────────────────────────
    //  Private: Termination Evaluation
    // ──────────────────────────────────────────────

    /**
     * Evaluate an evaluation_bound loop by checking the last stage (evaluator) output.
     *
     * The last role in the definition is expected to be the evaluator. Its output
     * is checked for approval signals.
     *
     * @param list<array<string, mixed>> $stages
     * @param array<string, mixed> $loop
     */
    private function evaluateEvaluationBound(array $stages, int $iterationNumber, array $loop): IterationOutcome
    {
        // Check iteration limit for evaluation_bound (max_review_rounds)
        $maxIterations = $loop['max_iterations'] !== null ? (int) $loop['max_iterations'] : null;
        if ($maxIterations !== null && $iterationNumber >= $maxIterations) {
            return IterationOutcome::LimitReached;
        }

        // The last stage is the evaluator — check its output for approval
        $lastStage = end($stages);
        if ($lastStage === false || $lastStage['result_summary'] === null) {
            return IterationOutcome::Continue;
        }

        $output = strtolower($lastStage['result_summary']);

        // Simple approval detection — look for common approval signals
        $approvalSignals = ['approved', 'approve', 'lgtm', 'looks good', 'accepted', 'passes all criteria'];
        $rejectionSignals = ['rejected', 'needs changes', 'needs_changes', 'needs work', 'not approved', 'revisions needed'];

        foreach ($approvalSignals as $signal) {
            if (str_contains($output, $signal)) {
                // Verify it's not a negated approval
                $negated = false;
                foreach ($rejectionSignals as $rejection) {
                    if (str_contains($output, $rejection)) {
                        $negated = true;
                        break;
                    }
                }
                if (!$negated) {
                    return IterationOutcome::Complete;
                }
            }
        }

        return IterationOutcome::Continue;
    }

    /**
     * Evaluate an iteration_bound loop — simply count iterations.
     *
     * @param array<string, mixed> $loop
     */
    private function evaluateIterationBound(int $iterationNumber, array $loop): IterationOutcome
    {
        $maxIterations = (int) $loop['max_iterations'];

        return $iterationNumber >= $maxIterations
            ? IterationOutcome::LimitReached
            : IterationOutcome::Continue;
    }

    /**
     * Evaluate a time_bound loop — check deadline.
     *
     * @param array<string, mixed> $loop
     */
    private function evaluateTimeBound(array $loop): IterationOutcome
    {
        $deadline = $loop['deadline'] ?? null;
        if ($deadline === null) {
            return IterationOutcome::Continue;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $deadlineTime = new \DateTimeImmutable($deadline, new \DateTimeZone('UTC'));

        return $now >= $deadlineTime
            ? IterationOutcome::LimitReached
            : IterationOutcome::Continue;
    }

    // ──────────────────────────────────────────────
    //  Private: Prompt Building
    // ──────────────────────────────────────────────

    /**
     * Build the full context prompt for a stage execution.
     *
     * @param list<array<string, mixed>> $completedStages
     * @param list<array{iteration_number: int, outcome_summary: string|null, status: string}> $previousOutcomes
     */
    private function buildStagePrompt(
        LoopDefinition $definition,
        string $goal,
        int $iterationNumber,
        ?int $maxIterations,
        int $stageIndex,
        int $totalStages,
        LoopRoleDefinition $roleDefinition,
        array $completedStages,
        array $previousOutcomes,
        ?string $terminationCriteria,
    ): string {
        $iterationLabel = $maxIterations !== null
            ? "{$iterationNumber}/{$maxIterations}"
            : "{$iterationNumber} (unlimited)";

        $sections = [];

        $sections[] = "# Loop: {$definition->name}";
        $sections[] = "## Goal\n{$goal}";
        $sections[] = "## Iteration {$iterationLabel}\nStage {$stageIndex}/{$totalStages} in this cycle.";
        $sections[] = "## Your Role: {$roleDefinition->role} (Stage " . ($stageIndex + 1) . " of {$totalStages})";

        // Previous stages in this cycle
        if ($completedStages !== []) {
            $stageLines = [];
            foreach ($completedStages as $cs) {
                $stageSummary = $cs['result_summary'] ?? '(no output recorded)';
                $stageLines[] = "### Stage " . ((int) $cs['stage_index'] + 1) . ": {$cs['role']}\n{$stageSummary}";
            }
            $sections[] = "## Previous Stages This Cycle\n" . implode("\n\n", $stageLines);
        }

        // Previous iteration outcomes
        if ($previousOutcomes !== []) {
            $outcomeLines = [];
            foreach ($previousOutcomes as $po) {
                $summary = $po['outcome_summary'] ?? $po['status'];
                $outcomeLines[] = "- Iteration {$po['iteration_number']}: {$summary}";
            }
            $sections[] = "## Previous Iteration Outcomes\n" . implode("\n", $outcomeLines);
        }

        // Termination criteria (acceptance criteria)
        if ($terminationCriteria !== null && $terminationCriteria !== '') {
            $sections[] = "## Acceptance Criteria\n{$terminationCriteria}";
        }

        // Role-specific task
        $sections[] = "## Your Task\n{$roleDefinition->prompt}";

        return implode("\n\n", $sections);
    }

    /**
     * Build a summary of an iteration from its completed stages.
     *
     * @param list<array<string, mixed>> $stages
     */
    private function buildIterationSummary(array $stages): string
    {
        $lines = [];
        foreach ($stages as $stage) {
            $status = $stage['status'];
            $role = $stage['role'];
            $summary = $stage['result_summary'] ?? '(no output)';

            // Truncate to one line for the iteration summary
            $firstLine = strtok($summary, "\n");
            if (mb_strlen($firstLine) > 200) {
                $firstLine = mb_substr($firstLine, 0, 200) . '...';
            }

            $lines[] = "- {$role} [{$status}]: {$firstLine}";
        }

        return implode("\n", $lines);
    }
}
