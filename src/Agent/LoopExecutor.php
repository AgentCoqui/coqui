<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Contract\LoopStageHandoffMetadata;
use CoquiBot\Coqui\Contract\LoopParameterDefinition;
use CoquiBot\Coqui\Contract\LoopRoleDefinition;
use CoquiBot\Coqui\Contract\LoopStageResult;
use CoquiBot\Coqui\Contract\TerminationType;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\IdGenerator;

/**
 * Core orchestration engine for loop workflows.
 *
 * Mode-agnostic: used by LoopManager (API async) for background task orchestration.
 * Handles loop lifecycle (create, advance, evaluate, complete) and context building
 * between stages. Does NOT execute agents — that's the runner's job.
 */
final class LoopExecutor
{
    public function __construct(
        private readonly LoopStore $loopStore,
        private readonly ProjectStore $projectStore,
        private readonly ?SessionStorage $sessionStorage = null,
        private readonly ?GoalEvaluator $goalEvaluator = null,
        private readonly ?ToolBoundEvaluator $toolBoundEvaluator = null,
    ) {}

    /**
     * Start a new loop: resolve or create a project, create the loop instance, and first iteration.
     *
     * Project resolution order:
     * 1. Explicit project_id or project_slug — reuse the specified project
     * 2. Active project on the parent session — inherit from the session
     * 3. Auto-create a new project scoped to this loop
     *
     * Accepts a raw definition array so template parameters can be substituted into ALL fields
     * (including termination conditions) before parsing into typed value objects.
     *
     * @param array<string, mixed>  $rawDefinition        Raw decoded JSON from the loop definition file
     * @param array<string, string> $parameters           Template parameter values for {{variable}} substitution
     * @return string Loop ID
     */
    public function startLoop(
        array $rawDefinition,
        string $goal,
        ?string $sessionId = null,
        array $parameters = [],
        ?string $projectId = null,
        ?string $projectSlug = null,
        ?int $maxIterationsOverride = null,
    ): string {
        // Extract declared parameters from the raw definition
        $declaredParams = [];
        foreach ($rawDefinition['parameters'] ?? [] as $paramData) {
            if (is_array($paramData)) {
                $declaredParams[] = LoopParameterDefinition::fromArray($paramData);
            }
        }

        // Validate required parameters
        $requiredNames = array_values(array_map(
            static fn(LoopParameterDefinition $p) => $p->name,
            array_filter($declaredParams, static fn(LoopParameterDefinition $p) => $p->required),
        ));
        $missing = array_diff($requiredNames, array_keys($parameters));
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                sprintf('Missing required parameters: %s', implode(', ', $missing)),
            );
        }

        // Resolve parameters (merge provided with defaults)
        $resolvedParameters = [];
        foreach ($declaredParams as $param) {
            if (isset($parameters[$param->name])) {
                $resolvedParameters[$param->name] = $parameters[$param->name];
            } elseif ($param->default !== null) {
                $resolvedParameters[$param->name] = $param->default;
            }
        }

        // Build replacement map and substitute into the FULL raw definition
        $substitutedData = $rawDefinition;
        if ($resolvedParameters !== []) {
            $replacements = [];
            foreach ($resolvedParameters as $key => $value) {
                $replacements['{{' . $key . '}}'] = $value;
            }
            $substitutedData = $this->substituteParameters($rawDefinition, $replacements);
            $goal = strtr($goal, $replacements);
        }

        // Now parse the substituted data into typed value objects
        $definition = LoopDefinition::fromArray($substitutedData);

        // Resolve project: explicit → session active → auto-create
        $resolvedProjectId = $this->resolveProject($definition, $goal, $projectId, $projectSlug, $sessionId);

        // Snapshot the definition so edits don't affect running loops
        $configuration = $definition->toArray();

        // Store resolved parameters alongside the configuration
        if ($resolvedParameters !== []) {
            $configuration['resolved_parameters'] = $resolvedParameters;
        }

        if ($maxIterationsOverride !== null && $maxIterationsOverride < 1) {
            throw new \InvalidArgumentException('max_iterations must be greater than 0');
        }

        // Determine termination parameters
        /** @var int|null $maxIterations */
        $maxIterations = $maxIterationsOverride ?? match ($definition->terminationCondition->type) {
            TerminationType::IterationBound,
            TerminationType::GoalBound,
            TerminationType::ToolBound => $definition->terminationCondition->maxIterations,
            TerminationType::EvaluationBound => $definition->terminationCondition->maxReviewRounds,
            default => null,
        };

        $deadline = $definition->terminationCondition->type === TerminationType::TimeBound
            ? $definition->terminationCondition->deadline
            : null;

        $terminationCriteria = match ($definition->terminationCondition->type) {
            TerminationType::EvaluationBound => $definition->terminationCondition->criteria,
            TerminationType::GoalBound => $definition->terminationCondition->goalPrompt,
            default => null,
        };

        $loopId = $this->loopStore->createLoop(
            definitionName: $definition->name,
            goal: $goal,
            configuration: $configuration,
            sessionId: $sessionId,
            projectId: $resolvedProjectId,
            maxIterations: $maxIterations,
            deadline: $deadline,
            terminationCriteria: $terminationCriteria,
            metadata: [
                'dispatch' => [
                    'status' => 'pending',
                    'message' => 'Waiting for the API loop manager to create the first stage background task.',
                    'updated_at' => Clock::nowUtc(),
                ],
            ],
        );

        // Create the first iteration
        $this->advanceIteration($loopId, $definition, $resolvedProjectId, $goal);

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
            json_decode($loop['configuration'], true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR),
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
        $completedStages = $this->loopStore->getCompletedStages($iteration['id']);

        $prompt = $this->buildStagePrompt(
            definition: $definition,
            goal: $loop['goal'],
            iterationNumber: (int) $iteration['iteration_number'],
            maxIterations: $loop['max_iterations'] !== null ? (int) $loop['max_iterations'] : null,
            stageIndex: $stageIndex,
            totalStages: count($definition->roles),
            roleDefinition: $roleDefinition,
            completedStages: $completedStages,
            previousOutcomes: $this->loopStore->getPreviousOutcomes($loopId, (int) $iteration['iteration_number']),
            terminationCriteria: $loop['termination_criteria'],
            resolvedParameters: $this->extractResolvedParameters($loop['configuration']),
            projectId: $loop['project_id'] ?? null,
        );

        $handoffMetadata = new LoopStageHandoffMetadata(
            loopId: $loopId,
            iterationId: $iteration['id'],
            stageId: $nextStage['id'],
            stageIndex: $stageIndex,
            totalStages: count($definition->roles),
            role: $roleDefinition->role,
            artifactIds: array_values(array_filter(array_map(
                static fn(array $stage): string => (string) ($stage['artifact_id'] ?? ''),
                $completedStages,
            ))),
            completedStageRoles: array_map(
                static fn(array $stage): string => (string) ($stage['role'] ?? ''),
                $completedStages,
            ),
            sessionId: $loop['session_id'] ?? null,
            projectId: $loop['project_id'] ?? null,
        );

        return new LoopStageResult(
            stageId: $nextStage['id'],
            loopId: $loopId,
            iterationId: $iteration['id'],
            stageIndex: $stageIndex,
            role: $roleDefinition->role,
            prompt: $prompt,
            maxIterations: $roleDefinition->maxIterations,
            sessionId: $loop['session_id'],
            projectId: $loop['project_id'] ?? null,
            handoffMetadata: $handoffMetadata,
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

        if ($iteration === null) {
            return IterationOutcome::Failed;
        }

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
            json_decode($loop['configuration'], true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR),
        );

        $iterationNumber = (int) $iteration['iteration_number'];

        if ($this->hasReachedIterationLimit($loop, $iterationNumber)) {
            $outcome = IterationOutcome::LimitReached;
        } else {
            // Evaluate based on termination type
            $outcome = match ($definition->terminationCondition->type) {
                TerminationType::EvaluationBound => $this->evaluateEvaluationBound($stages, $iterationNumber, $loop),
                TerminationType::IterationBound => $this->evaluateIterationBound($iterationNumber, $loop),
                TerminationType::TimeBound => $this->evaluateTimeBound($loop),
                TerminationType::GoalBound => $this->evaluateGoalBound($definition, $stages, $iterationNumber, $loop),
                TerminationType::ToolBound => $this->evaluateToolBound($definition, $iterationNumber, $loop),
                TerminationType::Manual => IterationOutcome::Continue,
            };
        }

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
    //  Private: Project Resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve the project for a new loop.
     *
     * Resolution order:
     * 1. Explicit project_id — verify it exists and use it
     * 2. Explicit project_slug — look up by slug and use it
     * 3. Active project on the parent session — inherit from session
     * 4. Auto-create a new project for this loop
     */
    private function resolveProject(
        LoopDefinition $definition,
        string $goal,
        ?string $projectId,
        ?string $projectSlug,
        ?string $sessionId,
    ): string {
        // 1. Explicit project_id
        if ($projectId !== null && $projectId !== '') {
            $project = $this->projectStore->getProject($projectId);
            if ($project === null) {
                throw new \InvalidArgumentException(sprintf('Project "%s" not found', $projectId));
            }
            return (string) $project['id'];
        }

        // 2. Explicit project_slug
        if ($projectSlug !== null && $projectSlug !== '') {
            $project = $this->projectStore->getProject($projectSlug);
            if ($project === null) {
                throw new \InvalidArgumentException(sprintf('Project with slug "%s" not found', $projectSlug));
            }
            return (string) $project['id'];
        }

        // 3. Active project on the parent session
        if ($sessionId !== null && $this->sessionStorage !== null) {
            $activeProjectId = $this->sessionStorage->getActiveProjectId($sessionId);
            if ($activeProjectId !== null) {
                $project = $this->projectStore->getProject($activeProjectId);
                if ($project !== null) {
                    return (string) $project['id'];
                }
            }
        }

        // 4. Auto-create a new project
        $slug = 'loop-' . $definition->name . '-' . substr(IdGenerator::hex(4), 0, 8);
        return $this->projectStore->createProject(
            title: sprintf('Loop: %s', $definition->name),
            slug: $slug,
            description: $goal,
        );
    }

    // ──────────────────────────────────────────────
    //  Private: Iteration Lifecycle
    // ──────────────────────────────────────────────

    /**
     * Create a new iteration and pre-create all stage records.
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

        $iterationId = $this->loopStore->createIteration(
            loopId: $loopId,
            iterationNumber: $nextNumber,
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

    /**
     * Evaluate a goal_bound loop — delegate to GoalEvaluator for LLM judgment.
     *
     * Falls back to Continue when GoalEvaluator is not available (loop acts as Manual).
     *
     * @param list<array<string, mixed>> $stages
     * @param array<string, mixed> $loop
     */
    private function evaluateGoalBound(
        LoopDefinition $definition,
        array $stages,
        int $iterationNumber,
        array $loop,
    ): IterationOutcome {
        // If no evaluator available, act as manual (continue indefinitely)
        if ($this->goalEvaluator === null) {
            return IterationOutcome::Continue;
        }

        // Get the last stage's output for evaluation
        $lastStage = end($stages);
        if ($lastStage === false || ($lastStage['result_summary'] ?? null) === null) {
            return IterationOutcome::Continue;
        }

        $previousOutcomes = $this->loopStore->getPreviousOutcomes($loop['id'], $iterationNumber);

        $result = $this->goalEvaluator->evaluate(
            goal: $loop['goal'],
            goalPrompt: $definition->terminationCondition->goalPrompt,
            lastStageOutput: $lastStage['result_summary'],
            previousOutcomes: $previousOutcomes,
        );

        return $result->achieved
            ? IterationOutcome::Complete
            : IterationOutcome::Continue;
    }

    /**
     * Evaluate a tool_bound loop — delegate to ToolBoundEvaluator for direct tool execution.
     *
     * Falls back to Continue when ToolBoundEvaluator is not available (loop acts as Manual).
     *
     * @param array<string, mixed> $loop
     */
    private function evaluateToolBound(
        LoopDefinition $definition,
        int $iterationNumber,
        array $loop,
    ): IterationOutcome {
        // If no evaluator available, act as manual
        if ($this->toolBoundEvaluator === null) {
            return IterationOutcome::Continue;
        }

        $tc = $definition->terminationCondition;
        if ($tc->toolName === null || $tc->operator === null || $tc->threshold === null) {
            return IterationOutcome::Continue;
        }

        $result = $this->toolBoundEvaluator->evaluate(
            toolName: $tc->toolName,
            arguments: $tc->toolArguments ?? [],
            operator: $tc->operator,
            threshold: $tc->threshold,
        );

        return $result->met
            ? IterationOutcome::Complete
            : IterationOutcome::Continue;
    }

    /**
     * @param array<string, mixed> $loop
     */
    private function hasReachedIterationLimit(array $loop, int $iterationNumber): bool
    {
        $maxIterations = $loop['max_iterations'] !== null ? (int) $loop['max_iterations'] : null;

        return $maxIterations !== null && $iterationNumber >= $maxIterations;
    }

    // ──────────────────────────────────────────────
    //  Private: Prompt Building
    // ──────────────────────────────────────────────

    /**
     * Build the full context prompt for a stage execution.
     *
     * @param list<array<string, mixed>> $completedStages
     * @param list<array{iteration_number: int, outcome_summary: string|null, status: string}> $previousOutcomes
     * @param array<string, string> $resolvedParameters
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
        array $resolvedParameters = [],
        ?string $projectId = null,
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
                $artifactRef = '';
                if (isset($cs['artifact_id']) && $cs['artifact_id'] !== '') {
                    $isTruncated = str_contains($stageSummary, '[... output truncated for context ...]');
                    if ($isTruncated) {
                        $artifactRef = "\n> ⚠️ **Output was truncated.** The full content is available as artifact `{$cs['artifact_id']}`."
                            . "\n> Use `artifact_get(id: \"{$cs['artifact_id']}\")` to read the complete output before making your evaluation.";
                    } else {
                        $artifactRef = "\n> **Artifact ID:** `{$cs['artifact_id']}` — use `artifact_get` to read the full output.";
                    }
                }

                // Check if the previous stage was budget-exhausted
                $budgetNote = '';
                if ($this->sessionStorage !== null && isset($cs['task_id']) && $cs['task_id'] !== '') {
                    try {
                        $events = $this->sessionStorage->getTaskEvents($cs['task_id']);
                        foreach ($events as $event) {
                            if (($event['event_type'] ?? '') === 'budget_exhausted') {
                                $budgetNote = "\n> ⚠️ **This stage reached its context budget limit.** Its summary may be incomplete — verify the actual state of the work before proceeding.";
                                break;
                            }
                        }
                    } catch (\Throwable) {
                        // Non-fatal — proceed without budget note
                    }
                }

                $stageLines[] = "### Stage " . ((int) $cs['stage_index'] + 1) . ": {$cs['role']}\n{$stageSummary}{$artifactRef}{$budgetNote}";
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

        // Role-specific task (with parameter substitution)
        $rolePrompt = $roleDefinition->prompt;
        if ($resolvedParameters !== []) {
            $replacements = [];
            foreach ($resolvedParameters as $key => $value) {
                $replacements['{{' . $key . '}}'] = $value;
            }
            $rolePrompt = strtr($rolePrompt, $replacements);
            $goal = strtr($goal, $replacements);
        }

        // Re-apply substituted goal to the section we already built
        $sections[1] = "## Goal\n{$goal}";

        // Add parameter context for the agent
        if ($resolvedParameters !== []) {
            $paramLines = [];
            foreach ($resolvedParameters as $key => $value) {
                $paramLines[] = "- **{$key}**: {$value}";
            }
            $sections[] = "## Parameters\n" . implode("\n", $paramLines);
        }

        // Add loop scoping context with project details
        if ($projectId !== null && $projectId !== '') {
            $sections[] = $this->buildProjectContextSection($projectId);
        }

        $sections[] = "## Your Task\n{$rolePrompt}";

        return implode("\n\n", $sections);
    }

    /**
     * Extract resolved parameters from the stored loop configuration JSON.
     *
     * @return array<string, string>
     */
    private function extractResolvedParameters(string $configurationJson): array
    {
        $config = json_decode($configurationJson, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);

        return $config['resolved_parameters'] ?? [];
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
            $firstLine = (string) strtok($summary, "\n");
            if (mb_strlen($firstLine) > 200) {
                $firstLine = mb_substr($firstLine, 0, 200) . '...';
            }

            $lines[] = "- {$role} [{$status}]: {$firstLine}";
        }

        return implode("\n", $lines);
    }

    /**
     * Build a project context section for stage prompts.
     *
     * Mirrors the OrchestratorAgent::injectProjectContext() pattern so
     * loop stage agents get project awareness — name, slug, description,
     * and directory.
     */
    private function buildProjectContextSection(?string $projectId): string
    {
        $lines = ['## Project Context'];

        if ($projectId === null || $projectId === '') {
            $lines[] = '(No project assigned)';
            return implode("\n", $lines);
        }

        try {
            $context = $this->projectStore->getProjectContext($projectId);
        } catch (\Throwable) {
            // Fallback to raw ID if context resolution fails
            $lines[] = "- **project_id**: `{$projectId}`";
            return implode("\n", $lines);
        }

        $project = $context['project'];
        $lines[] = sprintf('**%s** (`%s`) — %s', $project['title'], $project['slug'], $project['status']);

        if (!empty($project['description'])) {
            $lines[] = $project['description'];
        }

        $lines[] = sprintf('**Directory:** `projects/%s/`', $context['directory']);
        $lines[] = '';
        $lines[] = "**IDs for filtering:**";
        $lines[] = "- `project_id`: `{$projectId}`";
        $lines[] = '';
        $lines[] = 'Use `artifact_list(project_id: "' . $projectId . '", type: "loop_output")` to find loop-specific artifacts.';

        return implode("\n", $lines);
    }

    /**
     * Recursively apply strtr() to all string values in a nested array.
     *
     * Non-string values pass through unchanged. This enables template parameter
     * substitution in termination condition fields, role prompts, and all other
     * string values in a loop definition.
     *
     * @param array<string, mixed>  $data         The array to process
     * @param array<string, string> $replacements Map of {{key}} => value
     * @return array<string, mixed>
     */
    private function substituteParameters(array $data, array $replacements): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $result[$key] = strtr($value, $replacements);
            } elseif (is_array($value)) {
                $result[$key] = $this->substituteParameters($value, $replacements);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
