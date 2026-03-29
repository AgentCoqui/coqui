<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * ReactPHP-timer-driven coordinator for asynchronous loop execution.
 *
 * Runs inside the API server's event loop via a 5-second periodic timer.
 * On each tick, checks running loops for completed stages and advances them.
 * Stage execution is delegated to background tasks.
 */
final class LoopManager
{
    /** Poll interval in seconds for checking loop progress. */
    public const int TICK_INTERVAL = 5;

    /** @var callable|null */
    private $onNotify;

    public function __construct(
        private readonly LoopStore $loopStore,
        private readonly LoopExecutor $executor,
        private readonly SessionStorage $storage,
    ) {}

    /**
     * @param callable(string $event, array<string, mixed> $data): void $callback
     */
    public function setOnNotify(callable $callback): void
    {
        $this->onNotify = $callback;
    }

    /**
     * Periodic tick — advance running loops by checking stage completion.
     */
    public function tick(): void
    {
        $runningLoops = $this->loopStore->listLoops('running');

        foreach ($runningLoops as $loop) {
            try {
                $this->processLoop($loop);
            } catch (\Throwable $e) {
                $this->notify('loop.error', [
                    'loop_id' => $loop['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single running loop — check stages, advance, evaluate.
     *
     * @param array<string, mixed> $loop
     */
    private function processLoop(array $loop): void
    {
        $loopId = (string) $loop['id'];
        $state = $this->loopStore->getCurrentState($loopId);

        if ($state === null || $state['iteration'] === null) {
            return;
        }

        $iteration = $state['iteration'];
        $stages = $state['stages'];

        // Check if there's a running stage with a task_id — poll the task
        foreach ($stages as $stage) {
            if ($stage['status'] === 'running' && $stage['task_id'] !== null) {
                $this->checkStageTask($loopId, $stage);
                return; // Only process one stage at a time
            }
        }

        // Check if there's a pending stage that needs to be started
        foreach ($stages as $stage) {
            if ($stage['status'] === 'pending') {
                $this->startStageTask($loopId, $loop);
                return;
            }
        }

        // All stages are done — evaluate the iteration
        $pendingOrRunning = array_filter($stages, fn(array $s) => in_array($s['status'], ['pending', 'running'], true));
        if ($pendingOrRunning === []) {
            $outcome = $this->executor->evaluateIteration($loopId);

            $this->notify('loop.iteration_evaluated', [
                'loop_id' => $loopId,
                'iteration' => (int) $iteration['iteration_number'],
                'outcome' => $outcome->value,
            ]);

            if ($outcome === IterationOutcome::Complete || $outcome === IterationOutcome::LimitReached) {
                $this->notify('loop.complete', [
                    'loop_id' => $loopId,
                    'outcome' => $outcome->value,
                ]);
            }
        }
    }

    /**
     * Check if a running stage's background task has completed.
     *
     * @param array<string, mixed> $stage
     */
    private function checkStageTask(string $loopId, array $stage): void
    {
        $taskId = (string) $stage['task_id'];
        $task = $this->storage->getTask($taskId);

        if ($task === null) {
            return;
        }

        $status = (string) $task['status'];

        if ($status === 'completed') {
            $result = (string) ($task['result'] ?? '');
            $this->executor->completeStage(
                stageId: (string) $stage['id'],
                result: $result,
                taskId: $taskId,
            );

            $this->notify('loop.stage_completed', [
                'loop_id' => $loopId,
                'stage_id' => $stage['id'],
                'role' => $stage['role'],
                'task_id' => $taskId,
            ]);
        } elseif ($status === 'failed' || $status === 'cancelled') {
            $error = (string) ($task['error'] ?? $task['result'] ?? 'Task failed');
            $this->executor->failStage((string) $stage['id'], $error);

            $this->notify('loop.stage_failed', [
                'loop_id' => $loopId,
                'stage_id' => $stage['id'],
                'role' => $stage['role'],
                'error' => $error,
            ]);
        }
        // If still running/pending, do nothing — wait for next tick
    }

    /**
     * Start a background task for the next pending stage.
     *
     * @param array<string, mixed> $loop
     */
    private function startStageTask(string $loopId, array $loop): void
    {
        $stageResult = $this->executor->prepareNextStage($loopId);
        if ($stageResult === null) {
            return;
        }

        // Create a session for this stage's background task
        $sessionId = $this->storage->createSession($stageResult->role, 'loop-stage');

        // Create the background task
        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $stageResult->prompt,
            role: $stageResult->role,
            title: sprintf('[Loop] %s — iter %d, stage %d (%s)',
                $loop['definition_name'],
                $loop['current_iteration'],
                $stageResult->stageIndex + 1,
                $stageResult->role,
            ),
            maxIterations: $stageResult->maxIterations ?? 48,
        );

        // Update stage with task reference
        $this->loopStore->updateStage($stageResult->stageId, 'running', taskId: $taskId);

        $this->notify('loop.stage_started', [
            'loop_id' => $loopId,
            'stage_id' => $stageResult->stageId,
            'role' => $stageResult->role,
            'task_id' => $taskId,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function notify(string $event, array $data): void
    {
        if ($this->onNotify !== null) {
            ($this->onNotify)($event, $data);
        }
    }
}
