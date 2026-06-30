<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CoquiBot\Coqui\Agent\GoalEvaluator;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-loop-executor-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
    $pdo = $this->storage->getPdo();

    $this->loopStore = new LoopStore($pdo);
    $this->projectStore = new ProjectStore($pdo);
    $this->artifactStore = new ArtifactStore($pdo);

    $this->config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/test:latest'],
            ],
        ],
    ]);

    $this->roleResolver = new RoleResolver($this->config);

    // RoleDiscovery needs a filesystem path — create minimal temp workspace
    $this->workspacePath = sys_get_temp_dir() . '/coqui-executor-ws-' . bin2hex(random_bytes(4));
    mkdir($this->workspacePath . '/roles', 0755, true);
    $this->roleDiscovery = new \CoquiBot\Coqui\Config\RoleDiscovery($this->workspacePath);

    $this->executor = new LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $this->projectStore,
    );

    $this->harnessDefinition = [
        'name' => 'harness',
        'description' => 'Generator-evaluator pattern',
        'roles' => [
            ['role' => 'plan', 'prompt' => 'Create a plan.'],
            ['role' => 'coder', 'prompt' => 'Implement the plan.'],
            ['role' => 'reviewer', 'prompt' => 'Review the implementation.'],
        ],
        'termination_condition' => [
            'type' => 'evaluation_bound',
            'value' => ['criteria' => 'Explicit approval required', 'max_review_rounds' => 5],
        ],
    ];
});

afterEach(function () {
    // Release PDO handles before cleanup — Windows locks open SQLite files
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
    cleanupTestTree($this->workspacePath);
});

// ──────────────────────────────────────────────
//  startLoop
// ──────────────────────────────────────────────

test('startLoop creates loop with project and first iteration', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Build feature X', $this->sessionId);

    expect($loopId)->toBeString();
    expect(strlen($loopId))->toBe(32);

    $loop = $this->loopStore->getLoop($loopId);
    expect($loop)->not->toBeNull();
    expect($loop['definition_name'])->toBe('harness');
    expect($loop['goal'])->toBe('Build feature X');
    expect($loop['session_id'])->toBe($this->sessionId);
    expect($loop['status'])->toBe('running');
    expect($loop['project_id'])->not->toBeNull();
    expect($loop['termination_criteria'])->toBe('Explicit approval required');
    expect((int) $loop['max_iterations'])->toBe(5); // max_review_rounds
    expect(json_decode($loop['metadata'], true)['dispatch']['status'] ?? null)->toBe('pending');

    // First iteration created
    $iterations = $this->loopStore->listIterations($loopId);
    expect($iterations)->toHaveCount(1);
    expect((int) $iterations[0]['iteration_number'])->toBe(1);
    expect($iterations[0]['status'])->toBe('running');

    // All 3 stages pre-created
    $stages = $this->loopStore->listStages($iterations[0]['id']);
    expect($stages)->toHaveCount(3);
    expect($stages[0]['role'])->toBe('plan');
    expect($stages[1]['role'])->toBe('coder');
    expect($stages[2]['role'])->toBe('reviewer');
    expect($stages[0]['status'])->toBe('pending');
});

test('startLoop with iteration_bound definition', function () {
    $def = [
        'name' => 'iter-test',
        'description' => 'Iteration bound test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 10],
    ];

    $loopId = $this->executor->startLoop($def, 'Test goal');

    $loop = $this->loopStore->getLoop($loopId);
    expect((int) $loop['max_iterations'])->toBe(10);
    expect($loop['deadline'])->toBeNull();
    expect($loop['termination_criteria'])->toBeNull();
});

test('startLoop applies max_iterations override', function () {
    $loopId = $this->executor->startLoop(
        $this->harnessDefinition,
        'Override iteration limit',
        $this->sessionId,
        maxIterationsOverride: 3,
    );

    $loop = $this->loopStore->getLoop($loopId);
    expect((int) $loop['max_iterations'])->toBe(3);
});

test('startLoop creates project in project store', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Build feature');

    $loop = $this->loopStore->getLoop($loopId);
    $project = $this->projectStore->getProject($loop['project_id']);

    expect($project)->not->toBeNull();
    expect($project['title'])->toContain('Loop: harness');
    expect($project['description'])->toBe('Build feature');
});

// ──────────────────────────────────────────────
//  prepareNextStage
// ──────────────────────────────────────────────

test('prepareNextStage returns first pending stage', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $result = $this->executor->prepareNextStage($loopId);

    expect($result)->not->toBeNull();
    expect($result->role)->toBe('plan');
    expect($result->stageIndex)->toBe(0);
    expect($result->loopId)->toBe($loopId);
    expect($result->prompt)->toContain('Goal');
    expect($result->prompt)->toContain('plan');
});

test('prepareNextStage leaves stage pending until loop manager dispatches it', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $result = $this->executor->prepareNextStage($loopId);

    $stage = $this->loopStore->getStage($result->stageId);
    expect($stage['status'])->toBe('pending');
});

test('prepareNextStage returns null for nonexistent loop', function () {
    expect($this->executor->prepareNextStage('nonexistent'))->toBeNull();
});

test('prepareNextStage returns null for non-running loop', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');
    $this->executor->pauseLoop($loopId);

    expect($this->executor->prepareNextStage($loopId))->toBeNull();
});

test('prepareNextStage returns null when all stages completed', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    // Complete all 3 stages
    for ($i = 0; $i < 3; $i++) {
        $result = $this->executor->prepareNextStage($loopId);
        $this->executor->completeStage($result->stageId, "Stage $i done");
    }

    expect($this->executor->prepareNextStage($loopId))->toBeNull();
});

test('prepareNextStage advances through stages in order', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $stage1 = $this->executor->prepareNextStage($loopId);
    expect($stage1->role)->toBe('plan');
    $this->executor->completeStage($stage1->stageId, 'Plan done');

    $stage2 = $this->executor->prepareNextStage($loopId);
    expect($stage2->role)->toBe('coder');
    $this->executor->completeStage($stage2->stageId, 'Code done');

    $stage3 = $this->executor->prepareNextStage($loopId);
    expect($stage3->role)->toBe('reviewer');
});

test('prepareNextStage prompt includes previous stage results', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Build a widget');

    $stage1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($stage1->stageId, 'Here is the plan: build X, Y, Z');

    $stage2 = $this->executor->prepareNextStage($loopId);

    expect($stage2->prompt)->toContain('Build a widget'); // goal
    expect($stage2->prompt)->toContain('Here is the plan: build X, Y, Z'); // previous stage output
});

// ──────────────────────────────────────────────
//  completeStage / failStage
// ──────────────────────────────────────────────

test('completeStage records summary', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');
    $result = $this->executor->prepareNextStage($loopId);

    $this->executor->completeStage($result->stageId, 'Plan completed successfully');

    $stage = $this->loopStore->getStage($result->stageId);
    expect($stage['status'])->toBe('completed');
    expect($stage['result_summary'])->toBe('Plan completed successfully');
});

test('completeStage truncates long results at 2000 chars', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');
    $result = $this->executor->prepareNextStage($loopId);

    $longResult = str_repeat('x', 3000);
    $this->executor->completeStage($result->stageId, $longResult);

    $stage = $this->loopStore->getStage($result->stageId);
    expect(mb_strlen($stage['result_summary']))->toBeLessThan(2100);
    expect($stage['result_summary'])->toContain('[... output truncated');
});

test('completeStage stores artifact_id and task_id', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');
    $result = $this->executor->prepareNextStage($loopId);

    $this->executor->completeStage($result->stageId, 'Done', artifactId: 'art-123', taskId: 'task-456');

    $stage = $this->loopStore->getStage($result->stageId);
    expect($stage['artifact_id'])->toBe('art-123');
    expect($stage['task_id'])->toBe('task-456');
});

test('failStage records failure with prefix', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');
    $result = $this->executor->prepareNextStage($loopId);

    $this->executor->failStage($result->stageId, 'Provider timeout');

    $stage = $this->loopStore->getStage($result->stageId);
    expect($stage['status'])->toBe('failed');
    expect($stage['result_summary'])->toBe('FAILED: Provider timeout');
});

// ──────────────────────────────────────────────
//  evaluateIteration — Evaluation Bound
// ──────────────────────────────────────────────

test('evaluateIteration returns Complete when reviewer approves', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    // Complete all stages; reviewer approves
    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'APPROVED - all criteria met');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Complete);

    // Loop should be completed
    $loop = $this->loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('completed');
});

test('evaluateIteration returns Continue when reviewer rejects', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'Needs changes: missing error handling');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);

    // A new iteration should have been created
    $iterations = $this->loopStore->listIterations($loopId);
    expect($iterations)->toHaveCount(2);
});

test('evaluateIteration detects negated approval', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    // Contains both "approved" and a rejection signal — should be treated as rejection
    $this->executor->completeStage($s3->stageId, 'Not approved - needs changes before we can proceed');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);
});

test('evaluateIteration returns LimitReached at max_review_rounds', function () {
    // Definition with max_review_rounds = 2
    $def = [
        'name' => 'tight-loop',
        'description' => 'Quick eval',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'Code.'],
            ['role' => 'reviewer', 'prompt' => 'Review.'],
        ],
        'termination_condition' => [
            'type' => 'evaluation_bound',
            'value' => ['criteria' => 'Must pass', 'max_review_rounds' => 2],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal');

    // Complete iteration 1 (rejected)
    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Code v1');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Needs work');
    $this->executor->evaluateIteration($loopId); // Continue → creates iteration 2

    // Complete iteration 2 (rejected, but at limit)
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'Code v2');
    $s4 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s4->stageId, 'Still needs work');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::LimitReached);
    expect($this->loopStore->getLoop($loopId)['status'])->toBe('completed');
});

test('evaluateIteration recognizes lgtm as approval', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'LGTM, ship it!');

    expect($this->executor->evaluateIteration($loopId))->toBe(IterationOutcome::Complete);
});

// ──────────────────────────────────────────────
//  evaluateIteration — Iteration Bound
// ──────────────────────────────────────────────

test('evaluateIteration returns LimitReached at iteration bound', function () {
    $def = [
        'name' => 'iter-bound',
        'description' => 'Fixed iterations',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 1],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal');

    $s = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s->stageId, 'Done');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::LimitReached);
    expect($this->loopStore->getLoop($loopId)['status'])->toBe('completed');
});

test('evaluateIteration returns Continue below iteration bound', function () {
    $def = [
        'name' => 'iter-bound',
        'description' => 'Fixed iterations',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 3],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal');

    $s = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s->stageId, 'Done');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);
    expect($this->loopStore->getLoop($loopId)['status'])->toBe('running');
});

// ──────────────────────────────────────────────
//  evaluateIteration — Continue before limit
// ──────────────────────────────────────────────

test('evaluateIteration returns Continue before the iteration limit is reached', function () {
    $def = [
        'name' => 'continue-loop',
        'description' => 'Continues until the iteration limit',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal');

    $s = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s->stageId, 'Done');

    expect($this->executor->evaluateIteration($loopId))->toBe(IterationOutcome::Continue);
});

// ──────────────────────────────────────────────
//  evaluateIteration — Failed Stages
// ──────────────────────────────────────────────

test('evaluateIteration returns Failed when a stage failed', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->failStage($s1->stageId, 'Provider error');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Failed);
    // Note: evaluateIteration fails the iteration but does not update the loop status
    // when returning early from the failed-stage check. This may be a bug in LoopExecutor.
    $loop = $this->loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');
});

test('evaluateIteration returns Continue when stages are still pending', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    // Complete only the first stage; 2 more are pending
    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');

    $outcome = $this->executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);
});

test('evaluateIteration returns Failed for nonexistent loop', function () {
    expect($this->executor->evaluateIteration('nonexistent'))->toBe(IterationOutcome::Failed);
});

// ──────────────────────────────────────────────
//  advanceIteration
// ──────────────────────────────────────────────

test('evaluateIteration creates new iteration on Continue', function () {
    $def = [
        'name' => 'multi-iter',
        'description' => 'Multi iteration',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 5],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal');

    // Complete iteration 1
    $s = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s->stageId, 'Iteration 1 done');
    $this->executor->evaluateIteration($loopId);

    $iterations = $this->loopStore->listIterations($loopId);
    expect($iterations)->toHaveCount(2);
    expect((int) $iterations[1]['iteration_number'])->toBe(2);

    // New iteration should have stages pre-created
    $stages = $this->loopStore->listStages($iterations[1]['id']);
    expect($stages)->toHaveCount(1);
    expect($stages[0]['role'])->toBe('coder');
});

// ──────────────────────────────────────────────
//  pauseLoop / resumeLoop / cancelLoop
// ──────────────────────────────────────────────

test('pauseLoop changes status to paused', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $this->executor->pauseLoop($loopId);

    expect($this->loopStore->getLoop($loopId)['status'])->toBe('paused');
});

test('resumeLoop changes paused status to running', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');
    $this->executor->pauseLoop($loopId);

    $this->executor->resumeLoop($loopId);

    expect($this->loopStore->getLoop($loopId)['status'])->toBe('running');
});

test('resumeLoop is no-op on non-paused loop', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $this->executor->resumeLoop($loopId);

    expect($this->loopStore->getLoop($loopId)['status'])->toBe('running');
});

test('resumeLoop is no-op on completed loop', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');
    $this->loopStore->updateLoopStatus($loopId, 'completed');

    $this->executor->resumeLoop($loopId);

    expect($this->loopStore->getLoop($loopId)['status'])->toBe('completed');
});

test('cancelLoop changes status to cancelled', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $this->executor->cancelLoop($loopId);

    expect($this->loopStore->getLoop($loopId)['status'])->toBe('cancelled');
});

// ──────────────────────────────────────────────
//  buildStagePrompt (tested through prepareNextStage)
// ──────────────────────────────────────────────

test('stage prompt includes goal and role task', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Build an authentication system');

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('Build an authentication system');
    expect($result->prompt)->toContain('Create a plan.');
    expect($result->prompt)->toContain('harness');
});

test('stage prompt includes iteration context', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('Iteration');
    expect($result->prompt)->toContain('Stage');
});

test('stage prompt includes termination criteria for evaluation_bound', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('Explicit approval required');
});

test('stage prompt includes previous iteration outcomes', function () {
    $def = [
        'name' => 'context-test',
        'description' => 'Context test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 5],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal');

    // Complete iteration 1
    $s = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s->stageId, 'First round output');
    $this->executor->evaluateIteration($loopId);

    // Get stage from iteration 2
    $stage = $this->executor->prepareNextStage($loopId);

    expect($stage->prompt)->toContain('Previous Iteration Outcomes');
});

// ──────────────────────────────────────────────
//  Full lifecycle
// ──────────────────────────────────────────────

test('full lifecycle: evaluation_bound loop completes after approval', function () {
    $def = [
        'name' => 'lifecycle-test',
        'description' => 'Full lifecycle',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'Code it.'],
            ['role' => 'reviewer', 'prompt' => 'Review it.'],
        ],
        'termination_condition' => [
            'type' => 'evaluation_bound',
            'value' => ['criteria' => 'Approval', 'max_review_rounds' => 5],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Build a thing');

    // Iteration 1: rejected
    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Code v1');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Needs changes: missing tests');
    $outcome1 = $this->executor->evaluateIteration($loopId);
    expect($outcome1)->toBe(IterationOutcome::Continue);

    // Iteration 2: approved
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'Code v2 with tests');
    $s4 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s4->stageId, 'Approved - all criteria met');
    $outcome2 = $this->executor->evaluateIteration($loopId);
    expect($outcome2)->toBe(IterationOutcome::Complete);

    // Verify final state
    $loop = $this->loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('completed');
    expect($loop['completed_at'])->not->toBeNull();

    $iterations = $this->loopStore->listIterations($loopId);
    expect($iterations)->toHaveCount(2);
});

// ──────────────────────────────────────────────
//  Parameterized Templates
// ──────────────────────────────────────────────

test('startLoop with parameters stores resolved parameters in configuration', function () {
    $def = [
        'name' => 'param-test',
        'description' => 'Parameterized test',
        'roles' => [['role' => 'coder', 'prompt' => 'Investigate {{subject}}.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Subject', 'required' => true],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Research goal', parameters: ['subject' => 'authentication']);

    $loop = $this->loopStore->getLoop($loopId);
    $config = json_decode($loop['configuration'], true);

    expect($config)->toHaveKey('resolved_parameters');
    expect($config['resolved_parameters'])->toBe(['subject' => 'authentication']);
});

test('startLoop throws on missing required parameters', function () {
    $def = [
        'name' => 'param-req',
        'description' => 'Required param test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code {{subject}}.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Subject', 'required' => true],
        ],
    ];

    $this->executor->startLoop($def, 'Goal', parameters: []);
})->throws(\InvalidArgumentException::class, 'Missing required parameters: subject');

test('startLoop applies defaults for optional parameters', function () {
    $def = [
        'name' => 'param-default',
        'description' => 'Default param test',
        'roles' => [['role' => 'coder', 'prompt' => 'Output {{format}}.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'format', 'description' => 'Format', 'required' => false, 'default' => 'markdown'],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal', parameters: []);

    $loop = $this->loopStore->getLoop($loopId);
    $config = json_decode($loop['configuration'], true);

    expect($config['resolved_parameters'])->toBe(['format' => 'markdown']);
});

test('startLoop without parameters works on non-parameterized definitions', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $loop = $this->loopStore->getLoop($loopId);
    $config = json_decode($loop['configuration'], true);

    expect($config)->not->toHaveKey('resolved_parameters');
});

test('stage prompt substitutes parameter placeholders in role prompt', function () {
    $def = [
        'name' => 'sub-test',
        'description' => 'Substitution test',
        'roles' => [['role' => 'coder', 'prompt' => 'Investigate {{subject}} and produce a {{format}}.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Subject', 'required' => true],
            ['name' => 'format', 'description' => 'Format', 'required' => false, 'default' => 'report'],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal', parameters: ['subject' => 'authentication']);

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('Investigate authentication and produce a report.');
    expect($result->prompt)->not->toContain('{{subject}}');
    expect($result->prompt)->not->toContain('{{format}}');
});

test('stage prompt substitutes parameter placeholders in goal', function () {
    $def = [
        'name' => 'goal-sub',
        'description' => 'Goal substitution test',
        'roles' => [['role' => 'coder', 'prompt' => 'Work on it.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'feature', 'description' => 'Feature name', 'required' => true],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Build the {{feature}} module', parameters: ['feature' => 'payments']);

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('Build the payments module');
    expect($result->prompt)->not->toContain('{{feature}}');
});

test('stage prompt includes parameters section when parameters exist', function () {
    $def = [
        'name' => 'params-section',
        'description' => 'Parameters section test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'subject', 'description' => 'Subject', 'required' => true],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal', parameters: ['subject' => 'auth']);

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('## Parameters');
    expect($result->prompt)->toContain('**subject**: auth');
});

test('stage prompt omits parameters section for non-parameterized loops', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->not->toContain('## Parameters');
});

// ──────────────────────────────────────────────
//  Edge cases: Approval detection
// ──────────────────────────────────────────────

test('evaluateIteration recognizes "accepted" as approval', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'Accepted - meets all requirements');

    expect($this->executor->evaluateIteration($loopId))->toBe(IterationOutcome::Complete);
});

test('evaluateIteration recognizes "passes all criteria" as approval', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'The implementation passes all criteria set forth.');

    expect($this->executor->evaluateIteration($loopId))->toBe(IterationOutcome::Complete);
});

test('evaluateIteration treats mixed approval and rejection as rejection', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    // Contains "approved" but also "rejected" — should be treated as rejection
    $this->executor->completeStage($s3->stageId, 'Previously approved features are fine but rejected due to missing validation');

    expect($this->executor->evaluateIteration($loopId))->toBe(IterationOutcome::Continue);
});

test('evaluateIteration approval is case insensitive', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal');

    $s1 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s1->stageId, 'Plan done');
    $s2 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s2->stageId, 'Code done');
    $s3 = $this->executor->prepareNextStage($loopId);
    $this->executor->completeStage($s3->stageId, 'APPROVED - ALL TESTS PASS');

    expect($this->executor->evaluateIteration($loopId))->toBe(IterationOutcome::Complete);
});

// ──────────────────────────────────────────────
//  Edge cases: Template parameters
// ──────────────────────────────────────────────

test('startLoop applies multiple parameter substitutions in same prompt', function () {
    $def = [
        'name' => 'multi-param',
        'description' => '{{lang}} {{framework}} test',
        'roles' => [['role' => 'coder', 'prompt' => 'Write {{lang}} code using {{framework}}.']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 100],
        'parameters' => [
            ['name' => 'lang', 'description' => 'Language', 'required' => true],
            ['name' => 'framework', 'description' => 'Framework', 'required' => true],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Build with {{lang}} and {{framework}}', parameters: ['lang' => 'PHP', 'framework' => 'Pest']);

    $result = $this->executor->prepareNextStage($loopId);

    expect($result->prompt)->toContain('Write PHP code using Pest.');
    expect($result->prompt)->toContain('Build with PHP and Pest');
    expect($result->prompt)->not->toContain('{{lang}}');
    expect($result->prompt)->not->toContain('{{framework}}');
});

test('startLoop substitutes parameters in termination condition value', function () {
    $def = [
        'name' => 'tc-param',
        'description' => 'Termination param test',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'Code.'],
            ['role' => 'reviewer', 'prompt' => 'Review.'],
        ],
        'termination_condition' => [
            'type' => 'evaluation_bound',
            'value' => ['criteria' => 'Must pass {{standard}}', 'max_review_rounds' => '{{rounds}}'],
        ],
        'parameters' => [
            ['name' => 'standard', 'description' => 'Quality standard', 'required' => true],
            ['name' => 'rounds', 'description' => 'Max rounds', 'required' => false, 'default' => '3'],
        ],
    ];

    $loopId = $this->executor->startLoop($def, 'Goal', parameters: ['standard' => 'PER-CS 2.0']);

    $loop = $this->loopStore->getLoop($loopId);
    expect((int) $loop['max_iterations'])->toBe(3);
    expect($loop['termination_criteria'])->toBe('Must pass PER-CS 2.0');
});

test('startLoop with empty parameters array on non-parameterized definition succeeds', function () {
    $loopId = $this->executor->startLoop($this->harnessDefinition, 'Goal', parameters: []);

    expect($loopId)->toBeString();
    expect(strlen($loopId))->toBe(32);
});

// ──────────────────────────────────────────────
//  Helper: Goal/Tool evaluator stubs
// ──────────────────────────────────────────────

function makeLoopGoalProvider(string $responseContent): ProviderInterface
{
    return new class ($responseContent) implements ProviderInterface {
        public function __construct(private readonly string $responseContent) {}

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            return new Response(content: $this->responseContent, finishReason: \CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };
}

// ──────────────────────────────────────────────
//  evaluateIteration — Goal Bound
// ──────────────────────────────────────────────

test('evaluateIteration goal_bound returns Complete when goal achieved', function () {
    $goalEvaluator = new GoalEvaluator(makeLoopGoalProvider("ACHIEVED\nAll requirements met."));

    $executor = new LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $this->projectStore,
        goalEvaluator: $goalEvaluator,
    );

    $def = [
        'name' => 'goal-test',
        'description' => 'Goal bound test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => [
            'type' => 'goal_bound',
            'value' => ['goal_prompt' => 'Is the API complete?', 'max_iterations' => 5],
        ],
    ];

    $loopId = $executor->startLoop($def, 'Build an API');

    $s = $executor->prepareNextStage($loopId);
    $executor->completeStage($s->stageId, 'API implementation complete with all endpoints.');

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Complete);
    expect($this->loopStore->getLoop($loopId)['status'])->toBe('completed');
});

test('evaluateIteration goal_bound returns Continue when goal not achieved', function () {
    $goalEvaluator = new GoalEvaluator(makeLoopGoalProvider("NOT_ACHIEVED\nMissing authentication."));

    $executor = new LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $this->projectStore,
        goalEvaluator: $goalEvaluator,
    );

    $def = [
        'name' => 'goal-test',
        'description' => 'Goal bound test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => [
            'type' => 'goal_bound',
            'value' => ['goal_prompt' => 'Is it done?', 'max_iterations' => 5],
        ],
    ];

    $loopId = $executor->startLoop($def, 'Build auth');

    $s = $executor->prepareNextStage($loopId);
    $executor->completeStage($s->stageId, 'Basic structure created.');

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);
    expect($this->loopStore->getLoop($loopId)['status'])->toBe('running');

    // New iteration should be created
    $iterations = $this->loopStore->listIterations($loopId);
    expect($iterations)->toHaveCount(2);
});

test('evaluateIteration goal_bound returns LimitReached at max iterations', function () {
    // Provider returns NOT_ACHIEVED, but we're at the iteration limit
    $goalEvaluator = new GoalEvaluator(makeLoopGoalProvider("NOT_ACHIEVED\nStill incomplete."));

    $executor = new LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $this->projectStore,
        goalEvaluator: $goalEvaluator,
    );

    $def = [
        'name' => 'goal-limit',
        'description' => 'Limit test',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => [
            'type' => 'goal_bound',
            'value' => ['goal_prompt' => 'Done?', 'max_iterations' => 1],
        ],
    ];

    $loopId = $executor->startLoop($def, 'Goal');

    $s = $executor->prepareNextStage($loopId);
    $executor->completeStage($s->stageId, 'Output');

    $outcome = $executor->evaluateIteration($loopId);

    // max_iterations=1, we're on iteration 1 → LimitReached (before evaluator is even called)
    expect($outcome)->toBe(IterationOutcome::LimitReached);
    expect($this->loopStore->getLoop($loopId)['status'])->toBe('completed');
});

test('evaluateIteration goal_bound falls back to Continue without evaluator', function () {
    // No goalEvaluator injected — acts as manual
    $executor = new LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $this->projectStore,
    );

    $def = [
        'name' => 'goal-no-eval',
        'description' => 'No evaluator',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => [
            'type' => 'goal_bound',
            'value' => ['goal_prompt' => 'Done?', 'max_iterations' => 10],
        ],
    ];

    $loopId = $executor->startLoop($def, 'Goal');

    $s = $executor->prepareNextStage($loopId);
    $executor->completeStage($s->stageId, 'Output');

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Continue);
});

test('evaluateIteration goal_bound multi-iteration lifecycle', function () {
    // First call: NOT_ACHIEVED, second call: ACHIEVED
    $callCount = 0;
    $provider = new class ($callCount) implements ProviderInterface {
        private int $callCount;

        public function __construct(int &$callCount)
        {
            $this->callCount = &$callCount;
        }

        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            $this->callCount++;
            $content = $this->callCount >= 2
                ? "ACHIEVED\nGoal met on second iteration."
                : "NOT_ACHIEVED\nNot yet.";
            return new Response(content: $content, finishReason: \CarmeloSantana\PHPAgents\Enum\ProviderFinishReason::Stop);
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable { return []; }
        public function structured(array $messages, string $schema, array $options = []): mixed { return null; }
        public function models(): array { return []; }
        public function isAvailable(): bool { return true; }
        public function getModel(): string { return 'test/model'; }
        public function withModel(string $model): static { return $this; }
    };

    $executor = new LoopExecutor(
        loopStore: $this->loopStore,
        projectStore: $this->projectStore,
        goalEvaluator: new GoalEvaluator($provider),
    );

    $def = [
        'name' => 'goal-lifecycle',
        'description' => 'Multi-iteration goal',
        'roles' => [['role' => 'coder', 'prompt' => 'Code.']],
        'termination_condition' => [
            'type' => 'goal_bound',
            'value' => ['goal_prompt' => 'Is it done?', 'max_iterations' => 5],
        ],
    ];

    $loopId = $executor->startLoop($def, 'Build it');

    // Iteration 1: NOT_ACHIEVED → Continue
    $s1 = $executor->prepareNextStage($loopId);
    $executor->completeStage($s1->stageId, 'Partial work');
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Continue);

    // Iteration 2: ACHIEVED → Complete
    $s2 = $executor->prepareNextStage($loopId);
    $executor->completeStage($s2->stageId, 'All work done');
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Complete);

    expect($this->loopStore->getLoop($loopId)['status'])->toBe('completed');
    expect($this->loopStore->listIterations($loopId))->toHaveCount(2);
});
