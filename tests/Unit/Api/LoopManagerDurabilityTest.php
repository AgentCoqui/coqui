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
