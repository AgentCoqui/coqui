<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Contract\IterationOutcome;
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
 */
final class LoopManager
{
    /** @var array<string, true> Loops currently being advanced (prevent double-scheduling) */
    private array $advancingLoops = [];

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly LoopStore $loopStore,
        private readonly LoopExecutor $executor,
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

            $this->advanceLoop($loopId);
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
            $this->reconcileLoop($loopId);
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

        // Create a session for this stage's background task
        $sessionId = $this->storage->createSession(
            modelRole: $stageResult->role,
            model: '',
        );

        // Create the background task
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $stageResult->prompt,
            role: $stageResult->role,
            title: sprintf(
                'Loop stage: %s (iter %d, stage %d)',
                $stageResult->role,
                (int) ($state['iteration']['iteration_number'] ?? 0),
                $stageResult->stageIndex,
            ),
            maxIterations: min($stageResult->maxIterations ?? 48, 100),
        );

        // Link the task to the stage record
        $this->loopStore->updateStage(
            id: $stageResult->stageId,
            status: 'running',
            taskId: $taskId,
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
                $this->executor->completeStage(
                    stageId: (string) $stage['id'],
                    result: $output,
                    taskId: $taskId,
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
        $outcome = $this->executor->evaluateIteration($loopId);

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
