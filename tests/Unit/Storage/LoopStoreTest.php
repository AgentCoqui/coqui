<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-loop-store-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $this->store = new LoopStore($this->storage->getPdo());
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

// ──────────────────────────────────────────────
//  Loop CRUD
// ──────────────────────────────────────────────

test('createLoop returns a 32-char hex id', function () {
    $id = $this->store->createLoop(
        definitionName: 'harness',
        goal: 'Build a feature',
        configuration: ['name' => 'harness', 'roles' => []],
        sessionId: $this->sessionId,
    );

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('createLoop stores loop with correct fields', function () {
    $id = $this->store->createLoop(
        definitionName: 'harness',
        goal: 'Build a widget',
        configuration: ['name' => 'harness', 'description' => 'test'],
        sessionId: $this->sessionId,
        projectId: 'proj-123',
        maxIterations: 5,
        deadline: '2025-12-31T23:59:59Z',
        terminationCriteria: 'Must pass all tests',
        metadata: ['source' => 'repl'],
    );

    $loop = $this->store->getLoop($id);

    expect($loop)->not->toBeNull();
    expect($loop['definition_name'])->toBe('harness');
    expect($loop['goal'])->toBe('Build a widget');
    expect($loop['session_id'])->toBe($this->sessionId);
    expect($loop['project_id'])->toBe('proj-123');
    expect($loop['status'])->toBe('running');
    expect((int) $loop['current_iteration'])->toBe(0);
    expect((int) $loop['current_stage'])->toBe(0);
    expect((int) $loop['max_iterations'])->toBe(5);
    expect($loop['deadline'])->toBe('2025-12-31T23:59:59Z');
    expect($loop['termination_criteria'])->toBe('Must pass all tests');
    expect($loop['started_at'])->not->toBeNull();
    expect($loop['last_activity_at'])->not->toBeNull();
    expect($loop['completed_at'])->toBeNull();
    expect(json_decode($loop['configuration'], true)['name'])->toBe('harness');
    expect(json_decode($loop['metadata'], true)['source'])->toBe('repl');
});

test('getLoop returns null for nonexistent id', function () {
    expect($this->store->getLoop('nonexistent'))->toBeNull();
});

test('listLoops returns all loops ordered by started_at desc', function () {
    $this->store->createLoop('harness', 'Goal A', ['name' => 'harness']);
    $this->store->createLoop('research', 'Goal B', ['name' => 'research']);

    $loops = $this->store->listLoops();

    expect($loops)->toHaveCount(2);
    $names = array_column($loops, 'definition_name');
    expect($names)->toContain('harness');
    expect($names)->toContain('research');
});

test('listLoops filters by status', function () {
    $id1 = $this->store->createLoop('harness', 'Goal A', ['name' => 'harness']);
    $id2 = $this->store->createLoop('research', 'Goal B', ['name' => 'research']);
    $this->store->updateLoopStatus($id1, 'completed');

    $running = $this->store->listLoops('running');
    $completed = $this->store->listLoops('completed');

    expect($running)->toHaveCount(1);
    expect($running[0]['id'])->toBe($id2);
    expect($completed)->toHaveCount(1);
    expect($completed[0]['id'])->toBe($id1);
});

test('listLoops returns empty array with no loops', function () {
    expect($this->store->listLoops())->toBe([]);
});

test('updateLoopStatus sets completed_at for terminal statuses', function () {
    $id = $this->store->createLoop('harness', 'Goal', []);

    $this->store->updateLoopStatus($id, 'completed');
    $loop = $this->store->getLoop($id);

    expect($loop['status'])->toBe('completed');
    expect($loop['completed_at'])->not->toBeNull();
});

test('updateLoopStatus does not set completed_at for non-terminal statuses', function () {
    $id = $this->store->createLoop('harness', 'Goal', []);

    $this->store->updateLoopStatus($id, 'paused');
    $loop = $this->store->getLoop($id);

    expect($loop['status'])->toBe('paused');
    expect($loop['completed_at'])->toBeNull();
});

test('updateLoopStatus sets completed_at for failed and cancelled', function () {
    $id1 = $this->store->createLoop('harness', 'Goal A', []);
    $id2 = $this->store->createLoop('harness', 'Goal B', []);

    $this->store->updateLoopStatus($id1, 'failed');
    $this->store->updateLoopStatus($id2, 'cancelled');

    expect($this->store->getLoop($id1)['completed_at'])->not->toBeNull();
    expect($this->store->getLoop($id2)['completed_at'])->not->toBeNull();
});

test('updateLoopProgress updates counters and last_activity_at', function () {
    $id = $this->store->createLoop('harness', 'Goal', []);

    $this->store->updateLoopProgress($id, 3, 2);

    $loop = $this->store->getLoop($id);
    expect((int) $loop['current_iteration'])->toBe(3);
    expect((int) $loop['current_stage'])->toBe(2);
    expect($loop['last_activity_at'])->not->toBeNull();
});

test('deleteLoop removes loop', function () {
    $id = $this->store->createLoop('harness', 'Goal', []);

    expect($this->store->deleteLoop($id))->toBeTrue();
    expect($this->store->getLoop($id))->toBeNull();
});

test('deleteLoop returns false for nonexistent', function () {
    expect($this->store->deleteLoop('nonexistent'))->toBeFalse();
});

test('deleteLoop cascades to iterations and stages', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $stageId = $this->store->createStage($iterationId, 0, 'coder');

    $this->store->deleteLoop($loopId);

    expect($this->store->getIteration($iterationId))->toBeNull();
    expect($this->store->getStage($stageId))->toBeNull();
});

// ──────────────────────────────────────────────
//  Iteration CRUD
// ──────────────────────────────────────────────

test('createIteration returns a 32-char hex id', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $id = $this->store->createIteration($loopId, 1);

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('createIteration stores with correct fields', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $id = $this->store->createIteration($loopId, 1, sprintId: 'sprint-abc');

    $iteration = $this->store->getIteration($id);

    expect($iteration)->not->toBeNull();
    expect($iteration['loop_id'])->toBe($loopId);
    expect((int) $iteration['iteration_number'])->toBe(1);
    expect($iteration['sprint_id'])->toBe('sprint-abc');
    expect($iteration['status'])->toBe('pending');
    expect($iteration['outcome_summary'])->toBeNull();
});

test('getIteration returns null for nonexistent', function () {
    expect($this->store->getIteration('nonexistent'))->toBeNull();
});

test('listIterations returns ordered by iteration_number asc', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $this->store->createIteration($loopId, 2);
    $this->store->createIteration($loopId, 1);
    $this->store->createIteration($loopId, 3);

    $iterations = $this->store->listIterations($loopId);

    expect($iterations)->toHaveCount(3);
    expect((int) $iterations[0]['iteration_number'])->toBe(1);
    expect((int) $iterations[1]['iteration_number'])->toBe(2);
    expect((int) $iterations[2]['iteration_number'])->toBe(3);
});

test('listIterations returns empty for loop with no iterations', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);

    expect($this->store->listIterations($loopId))->toBe([]);
});

test('updateIterationStatus to running sets started_at', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $id = $this->store->createIteration($loopId, 1);

    $this->store->updateIterationStatus($id, 'running');

    $iteration = $this->store->getIteration($id);
    expect($iteration['status'])->toBe('running');
    expect($iteration['started_at'])->not->toBeNull();
    expect($iteration['completed_at'])->toBeNull();
});

test('updateIterationStatus to running preserves existing started_at', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $id = $this->store->createIteration($loopId, 1);

    $this->store->updateIterationStatus($id, 'running');
    $firstStartedAt = $this->store->getIteration($id)['started_at'];

    usleep(10_000);
    $this->store->updateIterationStatus($id, 'running');
    $secondStartedAt = $this->store->getIteration($id)['started_at'];

    expect($secondStartedAt)->toBe($firstStartedAt);
});

test('updateIterationStatus to completed sets completed_at and outcome', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $id = $this->store->createIteration($loopId, 1);

    $this->store->updateIterationStatus($id, 'completed', 'All stages passed');

    $iteration = $this->store->getIteration($id);
    expect($iteration['status'])->toBe('completed');
    expect($iteration['outcome_summary'])->toBe('All stages passed');
    expect($iteration['completed_at'])->not->toBeNull();
});

test('updateIterationStatus to failed sets completed_at', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $id = $this->store->createIteration($loopId, 1);

    $this->store->updateIterationStatus($id, 'failed', 'Stage 2 errored');

    $iteration = $this->store->getIteration($id);
    expect($iteration['status'])->toBe('failed');
    expect($iteration['completed_at'])->not->toBeNull();
});

// ──────────────────────────────────────────────
//  Stage CRUD
// ──────────────────────────────────────────────

test('createStage returns a 32-char hex id', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $id = $this->store->createStage($iterationId, 0, 'plan');

    expect($id)->toBeString();
    expect(strlen($id))->toBe(32);
});

test('createStage stores with correct fields', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $id = $this->store->createStage($iterationId, 0, 'plan');

    $stage = $this->store->getStage($id);

    expect($stage)->not->toBeNull();
    expect($stage['iteration_id'])->toBe($iterationId);
    expect((int) $stage['stage_index'])->toBe(0);
    expect($stage['role'])->toBe('plan');
    expect($stage['status'])->toBe('pending');
    expect($stage['task_id'])->toBeNull();
    expect($stage['artifact_id'])->toBeNull();
    expect($stage['result_summary'])->toBeNull();
});

test('getStage returns null for nonexistent', function () {
    expect($this->store->getStage('nonexistent'))->toBeNull();
});

test('listStages returns ordered by stage_index asc', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $this->store->createStage($iterationId, 2, 'reviewer');
    $this->store->createStage($iterationId, 0, 'plan');
    $this->store->createStage($iterationId, 1, 'coder');

    $stages = $this->store->listStages($iterationId);

    expect($stages)->toHaveCount(3);
    expect($stages[0]['role'])->toBe('plan');
    expect($stages[1]['role'])->toBe('coder');
    expect($stages[2]['role'])->toBe('reviewer');
});

test('updateStage to running sets started_at and optional task_id', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $id = $this->store->createStage($iterationId, 0, 'plan');

    $this->store->updateStage(
        $id,
        'running',
        taskId: 'task-abc',
        metadata: ['loop_id' => 'loop-123', 'stage_index' => 0],
    );

    $stage = $this->store->getStage($id);
    expect($stage['status'])->toBe('running');
    expect($stage['task_id'])->toBe('task-abc');
    expect($stage['started_at'])->not->toBeNull();
    expect($stage['completed_at'])->toBeNull();
    expect(json_decode((string) $stage['metadata'], true)['loop_id'])->toBe('loop-123');
});

test('updateStage to running preserves existing started_at', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $id = $this->store->createStage($iterationId, 0, 'plan');

    $this->store->updateStage($id, 'running');
    $firstStartedAt = $this->store->getStage($id)['started_at'];

    usleep(10_000);
    $this->store->updateStage($id, 'running');

    expect($this->store->getStage($id)['started_at'])->toBe($firstStartedAt);
});

test('updateStage to completed sets all optional fields', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $id = $this->store->createStage($iterationId, 0, 'plan');

    $this->store->updateStage(
        id: $id,
        status: 'completed',
        taskId: 'task-xyz',
        artifactId: 'artifact-abc',
        resultSummary: 'Plan completed successfully',
    );

    $stage = $this->store->getStage($id);
    expect($stage['status'])->toBe('completed');
    expect($stage['task_id'])->toBe('task-xyz');
    expect($stage['artifact_id'])->toBe('artifact-abc');
    expect($stage['result_summary'])->toBe('Plan completed successfully');
    expect($stage['completed_at'])->not->toBeNull();
});

test('updateStage to failed sets completed_at', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $id = $this->store->createStage($iterationId, 0, 'plan');

    $this->store->updateStage($id, 'failed', resultSummary: 'Provider timeout');

    $stage = $this->store->getStage($id);
    expect($stage['status'])->toBe('failed');
    expect($stage['result_summary'])->toBe('Provider timeout');
    expect($stage['completed_at'])->not->toBeNull();
});

// ──────────────────────────────────────────────
//  Composite Queries
// ──────────────────────────────────────────────

test('getCurrentState returns loop, latest iteration, and its stages', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iter1 = $this->store->createIteration($loopId, 1);
    $this->store->createStage($iter1, 0, 'plan');

    $iter2 = $this->store->createIteration($loopId, 2);
    $stage1 = $this->store->createStage($iter2, 0, 'plan');
    $stage2 = $this->store->createStage($iter2, 1, 'coder');

    $state = $this->store->getCurrentState($loopId);

    expect($state)->not->toBeNull();
    expect($state['loop']['id'])->toBe($loopId);
    expect((int) $state['iteration']['iteration_number'])->toBe(2);
    expect($state['stages'])->toHaveCount(2);
    expect($state['stages'][0]['role'])->toBe('plan');
    expect($state['stages'][1]['role'])->toBe('coder');
});

test('getCurrentState returns null for nonexistent loop', function () {
    expect($this->store->getCurrentState('nonexistent'))->toBeNull();
});

test('getCurrentState returns null iteration when loop has no iterations', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);

    $state = $this->store->getCurrentState($loopId);

    expect($state['loop']['id'])->toBe($loopId);
    expect($state['iteration'])->toBeNull();
    expect($state['stages'])->toBe([]);
});

test('getCompletedStages filters to completed only', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $s1 = $this->store->createStage($iterationId, 0, 'plan');
    $s2 = $this->store->createStage($iterationId, 1, 'coder');
    $s3 = $this->store->createStage($iterationId, 2, 'reviewer');

    $this->store->updateStage($s1, 'completed', resultSummary: 'Plan done');
    $this->store->updateStage($s2, 'running');
    // s3 stays pending

    $completed = $this->store->getCompletedStages($iterationId);

    expect($completed)->toHaveCount(1);
    expect($completed[0]['role'])->toBe('plan');
    expect($completed[0]['result_summary'])->toBe('Plan done');
});

test('getCompletedStages returns empty when none completed', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iterationId = $this->store->createIteration($loopId, 1);
    $this->store->createStage($iterationId, 0, 'plan');

    expect($this->store->getCompletedStages($iterationId))->toBe([]);
});

test('getPreviousOutcomes returns only iterations before specified number', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $iter1 = $this->store->createIteration($loopId, 1);
    $iter2 = $this->store->createIteration($loopId, 2);
    $iter3 = $this->store->createIteration($loopId, 3);

    $this->store->updateIterationStatus($iter1, 'completed', 'First round done');
    $this->store->updateIterationStatus($iter2, 'completed', 'Second round done');
    $this->store->updateIterationStatus($iter3, 'running');

    $outcomes = $this->store->getPreviousOutcomes($loopId, 3);

    expect($outcomes)->toHaveCount(2);
    expect((int) $outcomes[0]['iteration_number'])->toBe(1);
    expect($outcomes[0]['outcome_summary'])->toBe('First round done');
    expect((int) $outcomes[1]['iteration_number'])->toBe(2);
    expect($outcomes[1]['outcome_summary'])->toBe('Second round done');
});

test('getPreviousOutcomes returns empty for first iteration', function () {
    $loopId = $this->store->createLoop('harness', 'Goal', []);
    $this->store->createIteration($loopId, 1);

    expect($this->store->getPreviousOutcomes($loopId, 1))->toBe([]);
});

test('countActive returns count of running loops', function () {
    $id1 = $this->store->createLoop('harness', 'Goal A', []);
    $id2 = $this->store->createLoop('research', 'Goal B', []);
    $id3 = $this->store->createLoop('harness', 'Goal C', []);

    $this->store->updateLoopStatus($id2, 'completed');

    expect($this->store->countActive())->toBe(2);
});

test('countActive returns zero when no loops exist', function () {
    expect($this->store->countActive())->toBe(0);
});

test('countActive returns zero when all loops are terminal', function () {
    $id1 = $this->store->createLoop('harness', 'Goal A', []);
    $id2 = $this->store->createLoop('research', 'Goal B', []);

    $this->store->updateLoopStatus($id1, 'completed');
    $this->store->updateLoopStatus($id2, 'failed');

    expect($this->store->countActive())->toBe(0);
});
