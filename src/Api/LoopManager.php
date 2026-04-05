<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Advances running loops by creating background tasks for each stage.
 *
 * Ticked every 5 seconds by a ReactPHP periodic timer in ApiCommand.
 * On each tick:
 *   1. Lists running loops
 *   2. For each, checks if the current stage needs a background task
 *   3. Creates session + task and links it to the stage via task_id
 *
 * A reconciliation timer (every 3s) checks running stages whose linked
 * tasks have completed, calls LoopExecutor::completeStage() or failStage(),
 * and evaluates the iteration to decide whether to continue or stop.
 *
 * Each stage runs in its own isolated session for clean context windows,
 * but the loop's parent session ID is propagated as parent_session_id so
 * that ArtifactToolkit, TodoToolkit, and SprintToolkit scope to the shared
 * work-scope session. This ensures all stages can see each other's artifacts.
 */
final class LoopManager
{
    /** @var array<string, true> Loops currently being advanced (prevent double-scheduling) */
    private array $advancingLoops = [];

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly LoopStore $loopStore,
        private readonly LoopExecutor $executor,
        private readonly ArtifactStore $artifactStore,
    ) {}

    /**
     * Advance running loops by preparing next stages and spawning background tasks.
     *
     * Called by ReactPHP timer every 5 seconds.
     */
    public function tick(): void
    {
        $runningLoops = $this->loopStore->listLoops('running');

        foreach ($runningLoops as $loop) {
            $loopId = (string) $loop['id'];

            // Skip loops currently being processed
            if (isset($this->advancingLoops[$loopId])) {
                continue;
            }

            try {
                $this->advanceLoop($loopId);
            } catch (\Throwable $e) {
                $this->failLoop($loopId, 'advance', $e);
            }
        }
    }

    /**
     * Check running stages for completed background tasks and advance the loop.
     *
     * Called by ReactPHP timer every 3 seconds.
     */
    public function reconcile(): void
    {
        $runningLoops = $this->loopStore->listLoops('running');

        foreach ($runningLoops as $loop) {
            $loopId = (string) $loop['id'];

            try {
                $this->reconcileLoop($loopId);
            } catch (\Throwable $e) {
                $this->failLoop($loopId, 'reconcile', $e);
            }
        }

        // Clear the advancing set each reconciliation cycle
        $this->advancingLoops = [];
    }

    /**
     * Advance a single loop: prepare the next stage and spawn a background task.
     */
    private function advanceLoop(string $loopId): void
    {
        // Check if there's already a running stage with an active task
        $state = $this->loopStore->getCurrentState($loopId);
        if ($state === null || $state['iteration'] === null) {
            return;
        }

        $stages = $state['stages'];

        // If any stage is currently running, don't advance — wait for reconciliation
        foreach ($stages as $stage) {
            if ($stage['status'] === 'running') {
                return;
            }
        }

        // Prepare the next pending stage
        $stageResult = $this->executor->prepareNextStage($loopId);
        if ($stageResult === null) {
            // No pending stages — iteration may be complete, evaluate
            $this->evaluateAndAdvance($loopId);
            return;
        }

        $this->advancingLoops[$loopId] = true;

        // The loop's parent session is the work-scope session — artifacts, todos,
        // and sprints are scoped here. Each stage gets its own execution session
        // for clean context windows, but parent_session_id links back to the
        // shared work scope so ArtifactToolkit/TodoToolkit/SprintToolkit can
        // access cross-stage data.
        $workScopeSessionId = $stageResult->sessionId;

        // Create a fresh execution session for this stage's background task
        $sessionId = $this->storage->createSession(
            modelRole: $stageResult->role,
            model: '',
        );

        // Propagate active project context from parent session to task session
        if ($workScopeSessionId !== null) {
            $parentProjectId = $this->storage->getActiveProjectId($workScopeSessionId);
            if ($parentProjectId !== null) {
                $this->storage->setActiveProject($sessionId, $parentProjectId);
            }
        }

        // Create the background task with parent_session_id for work-scope propagation
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $stageResult->prompt,
            role: $stageResult->role,
            parentSessionId: $workScopeSessionId,
            title: sprintf(
                'Loop stage: %s (iter %d, stage %d)',
                $stageResult->role,
                (int) ($state['iteration']['iteration_number'] ?? 0),
                $stageResult->stageIndex,
            ),
            maxIterations: min($stageResult->maxIterations ?? 48, 100),
            projectId: $stageResult->projectId,
            sprintId: $stageResult->sprintId,
            metadata: $stageResult->handoffMetadata?->toArray(),
        );

        // Link the task to the stage record
        $this->loopStore->updateStage(
            id: $stageResult->stageId,
            status: 'running',
            taskId: $taskId,
            metadata: $stageResult->handoffMetadata?->toArray(),
        );
    }

    /**
     * Reconcile a single loop: check running stages for completed tasks.
     */
    private function reconcileLoop(string $loopId): void
    {
        $state = $this->loopStore->getCurrentState($loopId);
        if ($state === null || $state['iteration'] === null) {
            return;
        }

        foreach ($state['stages'] as $stage) {
            if ($stage['status'] !== 'running') {
                continue;
            }

            $taskId = $stage['task_id'] ?? null;
            if ($taskId === null || $taskId === '') {
                continue;
            }

            $task = $this->storage->getTask($taskId);
            if ($task === null) {
                continue;
            }

            $taskStatus = (string) $task['status'];

            if ($taskStatus === 'completed') {
                // Extract the task output from the session's last message
                $output = $this->extractTaskOutput($task);

                // Create a loop_output artifact in the work-scope session so
                // subsequent stages (e.g. reviewer) can see the output via
                // ArtifactToolkit scoped to the same parent session.
                $artifactId = $this->createStageArtifact(
                    loopId: $loopId,
                    stage: $stage,
                    task: $task,
                    output: $output,
                    state: $state,
                );

                $this->executor->completeStage(
                    stageId: (string) $stage['id'],
                    result: $output,
                    taskId: $taskId,
                    artifactId: $artifactId,
                );
            } elseif ($taskStatus === 'failed' || $taskStatus === 'cancelled') {
                $error = $task['error'] ?? 'Task ' . $taskStatus;
                $this->executor->failStage(
                    stageId: (string) $stage['id'],
                    error: (string) $error,
                );
            } else {
                // Still running or pending — skip
                continue;
            }
        }

        // After reconciling stages, check if iteration is complete
        $this->evaluateAndAdvance($loopId);
    }

    /**
     * Evaluate the current iteration and advance or complete the loop.
     */
    private function evaluateAndAdvance(string $loopId): void
    {
        try {
            $outcome = $this->executor->evaluateIteration($loopId);
        } catch (\Throwable $e) {
            $this->failLoop($loopId, 'evaluate', $e);
            return;
        }

        // LoopExecutor handles status updates and iteration advancement internally.
        // Complete, LimitReached, and Failed all update the loop status.
        // Continue creates the next iteration with new stages.
        // No further action needed here — the next tick() will pick up new stages.

        if ($outcome === IterationOutcome::Failed) {
            // Ensure the loop is marked failed if it isn't already
            $loop = $this->loopStore->getLoop($loopId);
            if ($loop !== null && $loop['status'] === 'running') {
                $this->loopStore->updateLoopStatus($loopId, 'failed');
            }
        }
    }

    /**
     * Mark a loop as failed and log the error. Never throws.
     */
    private function failLoop(string $loopId, string $phase, \Throwable $e): void
    {
        try {
            $this->loopStore->updateLoopStatus($loopId, 'failed');
            error_log(sprintf(
                '[LoopManager] Loop %s failed during %s: %s in %s:%d',
                $loopId,
                $phase,
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        } catch (\Throwable) {
            // Last resort — never crash the API server
        }
    }

    /**
     * Create a loop_output artifact in the work-scope session for a completed stage.
     *
     * @param array<string, mixed> $stage
     * @param array<string, mixed> $task
     * @param array<string, mixed> $state
     */
    private function createStageArtifact(
        string $loopId,
        array $stage,
        array $task,
        string $output,
        array $state,
    ): ?string {
        if ($output === '' || $output === '(no output)') {
            return null;
        }

        // Resolve the work-scope session from the task's parent_session_id,
        // falling back to the loop's own session_id.
        $workScopeSessionId = $task['parent_session_id'] ?? null;
        if ($workScopeSessionId === null || $workScopeSessionId === '') {
            $loop = $this->loopStore->getLoop($loopId);
            $workScopeSessionId = $loop['session_id'] ?? null;
        }

        if ($workScopeSessionId === null || $workScopeSessionId === '') {
            return null;
        }

        $role = (string) ($stage['role'] ?? 'unknown');
        $iterationNumber = (int) ($state['iteration']['iteration_number'] ?? 0);
        $stageIndex = (int) ($stage['stage_index'] ?? 0);

        // Resolve project_id and sprint_id from the loop and iteration records
        $loop = $this->loopStore->getLoop($loopId);
        $projectId = $loop['project_id'] ?? null;
        $sprintId = $state['iteration']['sprint_id'] ?? null;

        return $this->artifactStore->create(
            sessionId: $workScopeSessionId,
            title: sprintf('Loop output: %s (iter %d, stage %d)', $role, $iterationNumber, $stageIndex),
            content: $output,
            type: 'loop_output',
            stage: 'final',
            projectId: $projectId !== '' ? $projectId : null,
            sprintId: $sprintId !== '' ? $sprintId : null,
            metadata: [
                'loop_id' => $loopId,
                'stage_id' => (string) ($stage['id'] ?? ''),
                'task_id' => (string) ($task['id'] ?? ''),
                'role' => $role,
                'iteration_number' => $iterationNumber,
                'stage_index' => $stageIndex,
            ],
        );
    }

    /**
     * Extract the final output from a completed background task.
     *
     * Reads the task's session messages to find the assistant's last response.
     *
     * @param array<string, mixed> $task
     */
    private function extractTaskOutput(array $task): string
    {
        $sessionId = $task['session_id'] ?? null;
        if ($sessionId === null) {
            return $task['result'] ?? '(no output)';
        }

        // Get the last assistant message from the task's session
        $messages = $this->storage->getMessages((string) $sessionId);
        $lastAssistant = '';

        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? '') === 'assistant') {
                $lastAssistant = (string) ($message['content'] ?? '');
                break;
            }
        }

        return $lastAssistant !== '' ? $lastAssistant : ($task['result'] ?? '(no output)');
    }
}
