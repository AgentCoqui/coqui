<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Notification\NotificationPublisher;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\Clock;

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
 * that ArtifactToolkit scopes to the shared work-scope session. This ensures
 * all stages can see each other's artifacts.
 */
final class LoopManager
{
    /** @var array<string, true> Loops currently being advanced (prevent double-scheduling) */
    private array $advancingLoops = [];

    private ?string $lastTickAt = null;

    private ?string $lastReconcileAt = null;

    public function __construct(
        private readonly SessionStorage $storage,
        private readonly LoopStore $loopStore,
        private readonly LoopExecutor $executor,
        private readonly ArtifactStore $artifactStore,
        private readonly ?NotificationPublisher $publisher = null,
    ) {}

    /**
     * Advance running loops by preparing next stages and spawning background tasks.
     *
     * Called by ReactPHP timer every 5 seconds.
     */
    public function tick(): void
    {
        $this->lastTickAt = Clock::nowUtc();
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
        $this->lastReconcileAt = Clock::nowUtc();
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

        // Explicit blocked-skip guard: only running loops dispatch stages. Blocked
        // (and paused/failed) loops are absent from listLoops('running'), so this is
        // defence in depth against a status change mid-tick.
        if (($state['loop']['status'] ?? null) !== 'running') {
            return;
        }

        $stages = $state['stages'];

        // If any stage is currently running, verify its task is alive; recover
        // orphans (missing task) rather than stalling forever.
        foreach ($stages as $stage) {
            if ($stage['status'] !== 'running') {
                continue;
            }
            $taskId = (string) ($stage['task_id'] ?? '');
            $task = $taskId !== '' ? $this->storage->getTask($taskId) : null;
            if ($task === null) {
                $this->recoverOrphanStage($stage);
                return; // recovered this tick; next tick re-dispatches or fails
            }

            return; // a live task is running — wait for reconciliation
        }

        // Prepare the next pending stage
        $stageResult = $this->executor->prepareNextStage($loopId);
        if ($stageResult === null) {
            // No pending stages — iteration may be complete, evaluate
            $this->evaluateAndAdvance($loopId);
            return;
        }

        // Dispatch idempotency: a pending stage that already carries a task_id
        // crashed between task creation and its running status update. Re-link the
        // existing task rather than spawning a duplicate; reconciliation handles it.
        $existingTaskId = (string) ($this->loopStore->getStage($stageResult->stageId)['task_id'] ?? '');
        if ($existingTaskId !== '') {
            $existingTask = $this->storage->getTask($existingTaskId);
            if ($existingTask !== null) {
                $this->loopStore->updateStage(
                    id: $stageResult->stageId,
                    status: 'running',
                    taskId: $existingTaskId,
                );

                return;
            }
        }

        $this->advancingLoops[$loopId] = true;

        // The loop's parent session is the work-scope session — artifacts are
        // scoped here. Each stage gets its own execution session for clean
        // context windows, but parent_session_id links back to the shared work
        // scope so ArtifactToolkit can access cross-stage data.
        $workScopeSessionId = $stageResult->sessionId;
        $workScopeSession = $workScopeSessionId !== null ? $this->storage->getSession($workScopeSessionId) : null;
        $activeProfile = is_array($workScopeSession) && is_string($workScopeSession['profile'] ?? null) && $workScopeSession['profile'] !== ''
            ? $workScopeSession['profile']
            : null;

        // Create a fresh execution session for this stage's background task
        $sessionId = $this->storage->createSession(
            modelRole: $stageResult->role,
            model: '',
            profile: $activeProfile,
            visibility: 'hidden',
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
            metadata: $stageResult->handoffMetadata?->toArray(),
        );

        // Link the task to the stage record
        $this->loopStore->updateStage(
            id: $stageResult->stageId,
            status: 'running',
            taskId: $taskId,
            metadata: $stageResult->handoffMetadata?->toArray(),
        );
        $this->loopStore->updateLoopProgress($loopId, (int) ($state['iteration']['iteration_number'] ?? 0), $stageResult->stageIndex);
        $this->loopStore->updateLoopMetadata($loopId, [
            'dispatch' => [
                'status' => 'dispatched',
                'message' => 'Stage background task created successfully.',
                'task_id' => $taskId,
                'stage_id' => $stageResult->stageId,
                'stage_index' => $stageResult->stageIndex,
                'updated_at' => Clock::nowUtc(),
            ],
        ]);
    }

    /**
     * Recover a running stage whose background task has vanished (crashed
     * dispatch or deleted task). Resets to pending for one re-dispatch, bounded
     * by a dispatch_attempts guard; over the bound, the stage fails.
     *
     * @param array<string, mixed> $stage
     */
    private function recoverOrphanStage(array $stage): void
    {
        $meta = [];
        if (is_string($stage['metadata'] ?? null) && $stage['metadata'] !== '') {
            $decoded = json_decode($stage['metadata'], true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }
        $attempts = (int) ($meta['dispatch_attempts'] ?? 0) + 1;
        $meta['dispatch_attempts'] = $attempts;

        $stageId = (string) $stage['id'];

        if ($attempts > 2) {
            $this->executor->failStage($stageId, 'Stage task was lost and exceeded re-dispatch attempts.');
            $this->loopStore->updateStage(id: $stageId, status: 'failed', metadata: $meta);

            return;
        }

        // Reset to pending with a cleared task link so the next tick re-dispatches.
        $this->loopStore->updateStage(id: $stageId, status: 'pending', metadata: $meta);
        $this->loopStore->clearStageTask($stageId);
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
                // The linked task vanished (crashed dispatch or deleted task).
                // Recover the orphan instead of stalling on it forever.
                $this->recoverOrphanStage($stage);
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

                $this->publishLoopNotification(
                    loopId: $loopId,
                    iterationNumber: (int) ($state['iteration']['iteration_number'] ?? 0),
                    stageIndex: (int) ($stage['stage_index'] ?? 0),
                    outcome: 'stage_completed',
                    title: sprintf('Stage completed: %s', $stage['role'] ?? 'unknown'),
                );
            } elseif ($taskStatus === 'failed' || $taskStatus === 'cancelled') {
                $error = $task['error'] ?? 'Task ' . $taskStatus;
                $this->executor->failStage(
                    stageId: (string) $stage['id'],
                    error: (string) $error,
                );

                $this->publishLoopNotification(
                    loopId: $loopId,
                    iterationNumber: (int) ($state['iteration']['iteration_number'] ?? 0),
                    stageIndex: (int) ($stage['stage_index'] ?? 0),
                    outcome: 'stage_failed',
                    title: sprintf('Stage failed: %s', $stage['role'] ?? 'unknown'),
                    detail: mb_substr((string) $error, 0, 200),
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
            $this->publishLoopNotification(
                loopId: $loopId,
                outcome: 'failed',
                title: 'Loop failed',
                priority: 'high',
            );
        } elseif ($outcome === IterationOutcome::Complete || $outcome === IterationOutcome::LimitReached) {
            $label = $outcome === IterationOutcome::Complete ? 'Loop completed' : 'Loop completed (iteration limit reached)';
            $this->publishLoopNotification(
                loopId: $loopId,
                outcome: 'completed',
                title: $label,
            );
        }
    }

    /**
     * Mark a loop as failed and log the error. Never throws.
     */
    private function failLoop(string $loopId, string $phase, \Throwable $e): void
    {
        try {
            $this->loopStore->updateLoopMetadata($loopId, [
                'dispatch' => [
                    'status' => 'failed',
                    'message' => sprintf('Loop dispatch failed during %s.', $phase),
                    'error' => mb_substr($e->getMessage(), 0, 200),
                    'updated_at' => Clock::nowUtc(),
                ],
            ]);
            $this->loopStore->updateLoopStatus($loopId, 'failed');
            $this->publishLoopNotification(
                loopId: $loopId,
                outcome: 'failed',
                title: sprintf('Loop failed during %s', $phase),
                detail: mb_substr($e->getMessage(), 0, 200),
                priority: 'high',
            );
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

        // Resolve project_id from the loop record
        $loop = $this->loopStore->getLoop($loopId);
        $projectId = $loop['project_id'] ?? null;

        return $this->artifactStore->create(
            sessionId: $workScopeSessionId,
            title: sprintf('Loop output: %s (iter %d, stage %d)', $role, $iterationNumber, $stageIndex),
            content: $output,
            type: 'loop_output',
            projectId: $projectId !== '' ? $projectId : null,
            createdBy: sprintf('loop:%s stage:%d', $role, $stageIndex),
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

    /**
     * Publish a loop lifecycle notification to the parent session.
     *
     * Uses fingerprint deduplication via loopFingerprint().
     * Failures are silently swallowed — notifications must never break loop execution.
     */
    private function publishLoopNotification(
        string $loopId,
        ?int $iterationNumber = null,
        ?int $stageIndex = null,
        string $outcome = '',
        string $title = '',
        ?string $detail = null,
        string $priority = 'normal',
    ): void {
        if ($this->publisher === null) {
            return;
        }

        try {
            $loop = $this->loopStore->getLoop($loopId);
            if ($loop === null) {
                return;
            }

            $targetSession = NotificationPublisher::resolveTargetSession(
                sessionId: (string) ($loop['session_id'] ?? ''),
            );

            $loopName = is_string($loop['name'] ?? null) && $loop['name'] !== ''
                ? $loop['name']
                : (is_string($loop['definition_name'] ?? null) ? $loop['definition_name'] : '');
            $notifTitle = $loopName !== '' ? "{$title} [{$loopName}]" : $title;

            $fingerprint = NotificationPublisher::loopFingerprint(
                loopId: $loopId,
                iterationNumber: $iterationNumber ?? 0,
                stageIndex: $stageIndex,
                outcome: $outcome !== '' ? $outcome : null,
            );

            $kind = match (true) {
                str_starts_with($outcome, 'stage_') => "loop.{$outcome}",
                default => "loop.{$outcome}",
            };
            $metadata = [
                'loop_id' => $loopId,
                'loop_name' => $loopName,
                'iteration_number' => $iterationNumber,
                'stage_index' => $stageIndex,
                'outcome' => $outcome,
            ];

            if ($kind === 'loop.failed') {
                $this->publisher->actionable(
                    sessionId: $targetSession,
                    kind: $kind,
                    title: $notifTitle,
                    message: $detail,
                    fingerprint: $fingerprint,
                    sourceType: 'loop',
                    sourceId: $loopId,
                    metadata: $metadata,
                    priority: $priority,
                );

                return;
            }

            $this->publisher->info(
                sessionId: $targetSession,
                kind: $kind,
                title: $notifTitle,
                message: $detail,
                fingerprint: $fingerprint,
                sourceType: 'loop',
                sourceId: $loopId,
                metadata: $metadata,
                priority: $priority,
            );
        } catch (\Throwable) {
            // Never break loop execution for notification failures
        }
    }

    public function lastTickAt(): ?string
    {
        return $this->lastTickAt;
    }

    public function lastReconcileAt(): ?string
    {
        return $this->lastReconcileAt;
    }
}
