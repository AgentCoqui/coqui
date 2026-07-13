<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-loop-durability-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $pdo = $this->storage->getPdo();

    $this->loopStore = new LoopStore($pdo);
    $this->projectStore = new ProjectStore($pdo);
    $this->artifactStore = artifactStoreForTest($pdo);
    $this->executor = new LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $this->projectStore,
    );
    $this->manager = new LoopManager(
        storage: $this->storage,
        loopStore: $this->loopStore,
        executor: $this->executor,
        artifactStore: $this->artifactStore,
    );

    $this->config = [
        'name' => 'x',
        'description' => 'durability loop',
        'roles' => [['role' => 'coder', 'prompt' => 'do']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 1],
    ];
});

afterEach(function () {
    $this->manager = null;
    $this->executor = null;
    $this->artifactStore = null;
    $this->projectStore = null;
    $this->loopStore = null;
    $this->storage = null;

    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

test('tick does not advance a blocked loop', function () {
    $projectId = $this->projectStore->createProject(title: 'p', slug: 'd-1', description: 'd');
    $loopId = $this->loopStore->createLoop('x', 'goal', $this->config, projectId: $projectId, maxIterations: 1);
    $iterId = $this->loopStore->createIteration($loopId, 1);
    $this->loopStore->createStage($iterId, 0, 'coder');
    $this->loopStore->updateLoopStatus($loopId, 'blocked');

    $this->manager->tick();

    // No stage dispatched — the single stage is still pending with no task.
    $stage = $this->loopStore->getCurrentState($loopId)['stages'][0];
    expect($stage['status'])->toBe('pending');
    expect($stage['task_id'])->toBeNull();
});

test('a running stage whose task is missing is reset to pending for re-dispatch', function () {
    $projectId = $this->projectStore->createProject(title: 'p', slug: 'd-2', description: 'd');
    $loopId = $this->loopStore->createLoop('x', 'goal', $this->config, projectId: $projectId, maxIterations: 1);
    $iterId = $this->loopStore->createIteration($loopId, 1);
    $stageId = $this->loopStore->createStage($iterId, 0, 'coder');
    // Simulate a crashed dispatch: stage running, but its task id does not exist.
    $this->loopStore->updateStage(id: $stageId, status: 'running', taskId: 'ghost-task-id');

    $this->manager->reconcile();

    $stage = $this->loopStore->getStage($stageId);
    expect(in_array($stage['status'], ['pending', 'failed'], true))->toBeTrue();
    $meta = $stage['metadata'] !== null ? json_decode($stage['metadata'], true) : [];
    expect(($meta['dispatch_attempts'] ?? 0))->toBeGreaterThanOrEqual(1);
    if ($stage['status'] === 'pending') {
        expect($stage['task_id'])->toBeNull();
    }
});

test('a stage that orphans on every re-dispatch cycle eventually fails (bound fires)', function () {
    $projectId = $this->projectStore->createProject(title: 'p', slug: 'd-3', description: 'd');
    $loopId = $this->loopStore->createLoop('x', 'goal', $this->config, projectId: $projectId, maxIterations: 1);
    $iterId = $this->loopStore->createIteration($loopId, 1);
    $stageId = $this->loopStore->createStage($iterId, 0, 'coder');
    // First orphan: a crashed dispatch — running with a task id that does not exist.
    $this->loopStore->updateStage(id: $stageId, status: 'running', taskId: 'ghost-task-id');

    $pdo = $this->storage->getPdo();
    $attemptsSeen = [];

    // Drive the loop the way the runtime does: reconcile() recovers the orphan
    // (resetting to pending + incrementing dispatch_attempts), tick() re-dispatches
    // it (creating a real task), then we simulate the task vanishing again by
    // deleting it — so the SAME stage orphans on every cycle.
    for ($cycle = 0; $cycle < 8; $cycle++) {
        $this->manager->reconcile();

        $stage = $this->loopStore->getStage($stageId);
        $meta = $stage['metadata'] !== null ? json_decode($stage['metadata'], true) : [];
        $attemptsSeen[] = (int) ($meta['dispatch_attempts'] ?? 0);

        if ($stage['status'] === 'failed') {
            break;
        }

        // Re-dispatch the recovered (pending) stage.
        $this->manager->tick();

        // The tick created a fresh background task and set the stage running.
        // Simulate that task vanishing (crash) so the next reconcile re-orphans it.
        $running = $this->loopStore->getStage($stageId);
        $taskId = (string) ($running['task_id'] ?? '');
        if ($taskId !== '') {
            $pdo->prepare('DELETE FROM background_tasks WHERE id = ?')->execute([$taskId]);
        }
    }

    $stage = $this->loopStore->getStage($stageId);

    // The bound must fire: a perpetually-orphaning stage must end failed, not
    // re-dispatch forever.
    expect($stage['status'])->toBe('failed');

    // And dispatch_attempts must actually climb across cycles — not be pinned at 1
    // (which is the clobber bug: handoff metadata replacing the counter).
    expect(max($attemptsSeen))->toBeGreaterThan(2);
});
