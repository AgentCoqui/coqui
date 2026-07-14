<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

function retryExecutorStores(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return [new LoopStore($pdo), new ProjectStore($pdo)];
}

// Two producer stages, first one requires an artifact. iteration_bound → no gate.
function retryProducerConfig(): array
{
    return [
        'name' => 'retryprod',
        'description' => 'producer loop with artifact requirement',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'do', 'artifact_required' => true],
            ['role' => 'coder2', 'prompt' => 'do more'],
        ],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 3],
    ];
}

test('operator retry clears the stale blocked verdict so a satisfied stage does not re-block', function () {
    [$loopStore, $projectStore] = retryExecutorStores();
    $executor = new LoopExecutor($loopStore, $projectStore);

    $projectId = $projectStore->createProject(title: 'p', slug: 'retry-1', description: 'd');
    $loopId = $executor->startLoop(retryProducerConfig(), 'goal', projectId: $projectId, maxIterationsOverride: 3);

    $state = $loopStore->getCurrentState($loopId);
    $iterationId = $state['iteration']['id'];
    $stages = $state['stages'];
    $stage0Id = $stages[0]['id'];

    // Round 1: producer completes with NO artifact → artifact_required halts the loop.
    $loopStore->updateStage(id: $stage0Id, status: 'completed', resultSummary: 'did work but wrote no artifact');
    expect($executor->evaluateIteration($loopId))->toBe(IterationOutcome::Blocked);
    expect($loopStore->getLoop($loopId)['status'])->toBe('blocked');

    // A blocked verdict is now persisted on stage 0.
    $blockedStage = $loopStore->getCurrentState($loopId)['stages'][0];
    expect($blockedStage['verdict'])->not->toBeNull();

    // Operator remediates and retries: reproduce exactly what the retry path does.
    $loopStore->resetStagesForIteration($iterationId);
    $loopStore->resetIterationForRetry($iterationId);
    $loopStore->updateLoopStatus($loopId, 'running');

    // The reset must clear the stale verdict so evaluation rebuilds a fresh one.
    $resetStage = $loopStore->getCurrentState($loopId)['stages'][0];
    expect($resetStage['verdict'])->toBeNull();

    // Rework: producer re-runs and THIS time produces an artifact.
    $loopStore->updateStage(
        id: $stage0Id,
        status: 'completed',
        artifactId: 'artifact-remediated',
        resultSummary: 'reworked, artifact written',
    );

    // A now-satisfied stage must NOT re-block; the loop proceeds (stage 1 pending).
    $outcome = $executor->evaluateIteration($loopId);
    expect($outcome)->not->toBe(IterationOutcome::Blocked);
    expect($outcome)->toBe(IterationOutcome::Continue);
    expect($loopStore->getLoop($loopId)['status'])->not->toBe('blocked');
});
