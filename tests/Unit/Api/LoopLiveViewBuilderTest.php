<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\LoopLiveViewBuilder;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Seed a loop with one completed stage (with a turn + a tool_call event) and
 * one running stage (task started, no turn yet). Returns [builder, loopId].
 *
 * @return array{0: LoopLiveViewBuilder, 1: string, 2: SessionStorage, 3: LoopStore}
 */
function seedLiveLoop(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-loop-live-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());

    $loopId = $loopStore->createLoop(
        definitionName: 'harness',
        goal: 'Ship the feature',
        configuration: ['name' => 'harness', 'roles' => [['role' => 'plan'], ['role' => 'reviewer']]],
        maxIterations: 4,
    );
    $loopStore->updateLoopProgress($loopId, 1, 1);

    $iterationId = $loopStore->createIteration($loopId, 1);

    // Stage 0 — completed, with a session + turn + event.
    $stage0 = $loopStore->createStage($iterationId, 0, 'plan');
    $session0 = $storage->createSession(modelRole: 'plan', model: '', visibility: 'hidden');
    $task0 = $storage->createTask(sessionId: $session0, prompt: 'plan it', role: 'plan');
    $turn0 = $storage->createTurn($session0, 'plan it', 'claude-sonnet-5');
    $storage->completeTurn($turn0, 'planned', 100, 50, 150, 1, 1200, json_encode(['grep', 'read']), 0);
    $storage->appendTaskEvent($task0, 'tool_call', ['tool' => 'grep']);
    $loopStore->updateStage($stage0, 'completed', taskId: $task0, resultSummary: 'planned');

    // Stage 1 — running, task started, no turn yet.
    $stage1 = $loopStore->createStage($iterationId, 1, 'reviewer');
    $session1 = $storage->createSession(modelRole: 'reviewer', model: '', visibility: 'hidden');
    $task1 = $storage->createTask(sessionId: $session1, prompt: 'review it', role: 'reviewer');
    // Heartbeat only writes for a 'running' task, so mark it running first.
    $storage->updateTaskStatus($task1, 'running');
    $storage->updateTaskHeartbeat($task1);
    $storage->appendTaskEvent($task1, 'tool_call', ['tool' => 'read']);
    $loopStore->updateStage($stage1, 'running', taskId: $task1);

    return [new LoopLiveViewBuilder($loopStore, $storage), $loopId, $storage, $loopStore];
}

test('returns null for an unknown loop', function (): void {
    [$builder] = seedLiveLoop();
    expect($builder->build('does-not-exist'))->toBeNull();
});

test('surfaces loop meta and position', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $snapshot = $builder->build($loopId)->toArray();

    expect($snapshot['loop']['id'])->toBe($loopId);
    expect($snapshot['loop']['definition_name'])->toBe('harness');
    expect($snapshot['loop']['goal'])->toBe('Ship the feature');
    expect($snapshot['position']['current_iteration'])->toBe(1);
    expect($snapshot['position']['max_iterations'])->toBe(4);
    expect($snapshot['position']['stages_per_iteration'])->toBe(2);
    expect($snapshot['position']['current_stage_role'])->toBe('reviewer');
});

test('rolls up per-stage model and tokens', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $snapshot = $builder->build($loopId)->toArray();

    expect($snapshot['stages'])->toHaveCount(2);
    $plan = $snapshot['stages'][0];
    expect($plan['role'])->toBe('plan');
    expect($plan['model'])->toBe('claude-sonnet-5');
    expect($plan['tokens'])->toBe(['prompt' => 100, 'completion' => 50, 'total' => 150]);
    expect($plan['tools_used'])->toBe(['grep', 'read']);
    expect($plan['status'])->toBe('completed');
});

test('sums the loop token budget across stages', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $budget = $builder->build($loopId)->toArray()['budget'];

    expect($budget['tokens'])->toBe(['prompt' => 100, 'completion' => 50, 'total' => 150]);
    expect($budget['iterations'])->toBe(['used' => 1, 'max' => 4]);
});

test('computes elapsed and remaining time against an injected now', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loop-time-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());
    $loopId = $loopStore->createLoop(
        definitionName: 'harness',
        goal: 'g',
        configuration: ['roles' => [['role' => 'plan']]],
        maxIterations: 2,
        deadline: '2026-07-11T10:10:00Z',
    );
    // started_at is set by createLoop to "now". LoopStore::updateLoop whitelists
    // only goal/max_iterations, so seed started_at (and the deadline) directly on
    // the row for a deterministic time assertion.
    $storage->getPdo()
        ->prepare('UPDATE loops SET started_at = ?, deadline = ? WHERE id = ?')
        ->execute(['2026-07-11T10:00:00Z', '2026-07-11T10:10:00Z', $loopId]);

    $budget = (new LoopLiveViewBuilder($loopStore, $storage))
        ->build($loopId, '2026-07-11T10:03:00Z')
        ->toArray()['budget'];

    expect($budget['time']['elapsed_seconds'])->toBe(180);
    expect($budget['time']['remaining_seconds'])->toBe(420);
    expect($budget['time']['deadline'])->toBe('2026-07-11T10:10:00Z');
});

test('identifies the running stage with model, heartbeat and latest activity', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $current = $builder->build($loopId)->toArray()['current_stage'];

    expect($current)->not->toBeNull();
    expect($current['role'])->toBe('reviewer');
    expect($current['status'])->toBe('running');
    expect($current['last_heartbeat_at'])->not->toBeNull();
    expect($current['latest_activity']['type'])->toBe('tool_call');
    expect($current['latest_activity']['summary'])->toBe('Called read');
});

test('current stage is null when nothing is running', function (): void {
    [$builder, $loopId, $storage, $loopStore] = seedLiveLoop();
    // Complete the running stage.
    foreach ($loopStore->listIterations($loopId) as $iteration) {
        foreach ($loopStore->listStages((string) $iteration['id']) as $stage) {
            if (($stage['status'] ?? null) === 'running') {
                $loopStore->updateStage((string) $stage['id'], 'completed');
            }
        }
    }
    expect($builder->build($loopId)->toArray()['current_stage'])->toBeNull();
});

test('builds a newest-first recent-event feed', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $events = $builder->build($loopId)->toArray()['recent_events'];

    expect($events)->toHaveCount(2);
    // Stage 1's 'read' event was appended after stage 0's 'grep' event → newest first.
    expect($events[0]['summary'])->toBe('Called read');
    expect($events[0]['role'])->toBe('reviewer');
    expect($events[1]['summary'])->toBe('Called grep');
});

test('surfaces the newest events when a stage exceeds the recent-events limit', function (): void {
    [$builder, $loopId, $storage, $loopStore] = seedLiveLoop();

    // Find the running stage's task and flood it with more than the 50-event
    // recent limit, each event distinctly numbered by insertion order.
    $runningTaskId = null;
    foreach ($loopStore->listIterations($loopId) as $iteration) {
        foreach ($loopStore->listStages((string) $iteration['id']) as $stage) {
            if (($stage['status'] ?? null) === 'running') {
                $runningTaskId = (string) $stage['task_id'];
            }
        }
    }
    expect($runningTaskId)->not->toBeNull();

    for ($i = 1; $i <= 60; $i++) {
        $storage->appendTaskEvent($runningTaskId, 'tool_call', ['tool' => 'tool-' . $i]);
    }

    $snapshot = $builder->build($loopId)->toArray();

    // The newest inserted event must lead the recent-events feed and be the
    // current stage's latest activity — not the oldest-50 the ASC query returns.
    expect($snapshot['recent_events'][0]['summary'])->toBe('Called tool-60');
    expect($snapshot['current_stage']['latest_activity']['summary'])->toBe('Called tool-60');
});

test('a running stage with no turn yet reports zero tokens and null model', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $reviewer = $builder->build($loopId)->toArray()['stages'][1];

    expect($reviewer['role'])->toBe('reviewer');
    expect($reviewer['model'])->toBeNull();
    expect($reviewer['tokens'])->toBe(['prompt' => 0, 'completion' => 0, 'total' => 0]);
});

test('exposes loop escalation and per-stage verdict in the snapshot', function (): void {
    [$builder, $loopId, $storage, $loopStore] = seedLiveLoop();

    // Record a blocked status + an escalation record in the loop's metadata.
    $storage->getPdo()
        ->prepare('UPDATE loops SET status = ?, metadata = ? WHERE id = ?')
        ->execute([
            'blocked',
            json_encode(['escalation' => ['reason' => 'needs human input', 'raised_at' => '2026-07-13T00:00:00Z']]),
            $loopId,
        ]);

    // Attach a structured verdict to the completed (plan) stage.
    $planStageId = null;
    foreach ($loopStore->listIterations($loopId) as $iteration) {
        foreach ($loopStore->listStages((string) $iteration['id']) as $stage) {
            if (($stage['role'] ?? null) === 'plan') {
                $planStageId = (string) $stage['id'];
            }
        }
    }
    expect($planStageId)->not->toBeNull();
    $loopStore->recordStageVerdict($planStageId, json_encode(['decision' => 'pass', 'confidence' => 0.9]));

    $snapshot = $builder->build($loopId)->toArray();

    expect($snapshot['loop']['escalation'])->toBe([
        'reason' => 'needs human input',
        'raised_at' => '2026-07-13T00:00:00Z',
    ]);
    expect($snapshot['stages'][0]['role'])->toBe('plan');
    expect($snapshot['stages'][0]['verdict'])->toBe(['decision' => 'pass', 'confidence' => 0.9]);
    // Stages without a verdict stay null and never break existing consumers.
    expect($snapshot['stages'][1]['verdict'])->toBeNull();
});

test('loop escalation is null when metadata carries no escalation', function (): void {
    [$builder, $loopId] = seedLiveLoop();
    $snapshot = $builder->build($loopId)->toArray();

    expect($snapshot['loop']['escalation'])->toBeNull();
    expect($snapshot['stages'][0]['verdict'])->toBeNull();
});
