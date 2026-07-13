<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Contract\IterationOutcome;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

function blockExecutorStores(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return [new LoopStore($pdo), new ProjectStore($pdo)];
}

// A 2-role iteration_bound definition: coder (producer) then a non-reviewer producer.
function twoProducerConfig(): array
{
    return [
        'name' => 'twoprod',
        'description' => 'two-producer loop',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'do'],
            ['role' => 'coder2', 'prompt' => 'do more'],
        ],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 3],
    ];
}

test('a non-gate stage that self-signals BLOCKED halts the loop into blocked', function () {
    [$loopStore, $projectStore] = blockExecutorStores();
    $executor = new LoopExecutor($loopStore, $projectStore);

    $projectId = $projectStore->createProject(title: 'p', slug: 'p-1', description: 'd');
    $loopId = $executor->startLoop(twoProducerConfig(), 'goal', projectId: $projectId, maxIterationsOverride: 3);
    $state = $loopStore->getCurrentState($loopId);
    $stages = $state['stages'];

    // Stage 0 completes with a BLOCKED self-signal; stage 1 still pending.
    $loopStore->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: "STATUS: BLOCKED\nmissing dependency");

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Blocked);
    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('blocked');
    $meta = json_decode($loop['metadata'], true);
    expect($meta['escalation']['reason'])->toContain('blocked');
    // The current iteration is left retryable.
    $iter = $loopStore->getCurrentState($loopId)['iteration'];
    expect($iter['status'])->toBe('needs_rework');
});

test('an artifact_required non-gate stage with no artifact halts the loop into blocked', function () {
    [$loopStore, $projectStore] = blockExecutorStores();
    $executor = new LoopExecutor($loopStore, $projectStore);

    $config = twoProducerConfig();
    $config['roles'][0]['artifact_required'] = true;

    $projectId = $projectStore->createProject(title: 'p', slug: 'p-2', description: 'd');
    $loopId = $executor->startLoop($config, 'goal', projectId: $projectId, maxIterationsOverride: 3);
    $stages = $loopStore->getCurrentState($loopId)['stages'];

    // Stage 0 completes Done but produced NO artifact (artifact_id null).
    $loopStore->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: 'did work but wrote no artifact');

    $outcome = $executor->evaluateIteration($loopId);

    expect($outcome)->toBe(IterationOutcome::Blocked);
    expect($loopStore->getLoop($loopId)['status'])->toBe('blocked');
});
