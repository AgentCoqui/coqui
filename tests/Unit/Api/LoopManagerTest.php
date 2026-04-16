<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-loop-manager-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $pdo = $this->storage->getPdo();

    $this->loopStore = new LoopStore($pdo);
    $this->projectStore = new ProjectStore($pdo);
    $this->artifactStore = new ArtifactStore($pdo);
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

    $this->parentSessionId = $this->storage->createSession('orchestrator', 'ollama/qwen3:latest');
    $this->definition = [
        'name' => 'single-stage',
        'description' => 'Single stage loop',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'Implement the requested change.'],
        ],
        'termination_condition' => [
            'type' => 'iteration_bound',
            'value' => 1,
        ],
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

test('tick creates a background task for the next pending stage', function () {
    $loopId = $this->executor->startLoop($this->definition, 'Build the feature', $this->parentSessionId);

    $this->manager->tick();

    $state = $this->loopStore->getCurrentState($loopId);
    $stage = $state['stages'][0];
    $task = $this->storage->getTask((string) $stage['task_id']);

    expect($stage['status'])->toBe('running');
    expect($stage['task_id'])->not->toBeNull();
    expect($task)->not->toBeNull();
    expect($task['parent_session_id'])->toBe($this->parentSessionId);
    expect($task['prompt'])->toContain('Build the feature');
});

test('tick inherits active profile from parent loop session', function () {
    $profiledParentSessionId = $this->storage->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
    $loopId = $this->executor->startLoop($this->definition, 'Build the feature', $profiledParentSessionId);

    $this->manager->tick();

    $state = $this->loopStore->getCurrentState($loopId);
    $stage = $state['stages'][0];
    $task = $this->storage->getTask((string) $stage['task_id']);
    $session = $this->storage->getSession((string) $task['session_id']);

    expect($session)->not->toBeNull();
    expect($session['profile'])->toBe('caelum');
});

test('reconcile completes a finished stage and creates a loop output artifact', function () {
    $loopId = $this->executor->startLoop($this->definition, 'Build the feature', $this->parentSessionId);

    $this->manager->tick();

    $state = $this->loopStore->getCurrentState($loopId);
    $stage = $state['stages'][0];
    $taskId = (string) $stage['task_id'];
    $task = $this->storage->getTask($taskId);

    $this->storage->addMessage((string) $task['session_id'], 'assistant', 'Completed implementation output');
    $this->storage->updateTaskStatus($taskId, 'completed', ['result' => 'Fallback output']);

    $this->manager->reconcile();

    $updatedStage = $this->loopStore->getStage((string) $stage['id']);
    $artifacts = $this->artifactStore->list($this->parentSessionId, 'loop_output');

    expect($updatedStage['status'])->toBe('completed');
    expect($updatedStage['artifact_id'])->not->toBeNull();
    expect($artifacts)->toHaveCount(1);
    expect($artifacts[0]['content'])->toBe('Completed implementation output');
    expect($artifacts[0]['stage'])->toBe('final');
});

test('reconcile marks the stage failed when the linked task fails', function () {
    $loopId = $this->executor->startLoop($this->definition, 'Build the feature', $this->parentSessionId);

    $this->manager->tick();

    $state = $this->loopStore->getCurrentState($loopId);
    $stage = $state['stages'][0];
    $taskId = (string) $stage['task_id'];

    $this->storage->updateTaskStatus($taskId, 'failed', ['error' => 'Process crashed']);

    $this->manager->reconcile();

    $updatedStage = $this->loopStore->getStage((string) $stage['id']);
    $loop = $this->loopStore->getLoop($loopId);

    expect($updatedStage['status'])->toBe('failed');
    expect($loop['status'])->toBe('failed');
});