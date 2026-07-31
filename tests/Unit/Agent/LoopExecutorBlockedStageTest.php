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
    return [new LoopStore($pdo), new ProjectStore($pdo), $pdo];
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

test('a stage whose role index has no definition at dispatch escalates blocked + Critical', function () {
    [$loopStore, $projectStore, $pdo] = blockExecutorStores();
    $executor = new LoopExecutor($loopStore, $projectStore);

    $projectId = $projectStore->createProject(title: 'p', slug: 'p-missing-role', description: 'd');
    $loopId = $executor->startLoop(twoProducerConfig(), 'goal', projectId: $projectId, maxIterationsOverride: 3);
    $stages = $loopStore->getCurrentState($loopId)['stages'];

    // Stage 0 is done; the next pending stage is index 1.
    $loopStore->updateStage(id: $stages[0]['id'], status: 'completed', resultSummary: 'stage 0 done');

    // The stored configuration loses the role at index 1, so the pending stage 1
    // now has no role/definition to dispatch — a hard configuration failure.
    $oneRoleConfig = twoProducerConfig();
    $oneRoleConfig['roles'] = [$oneRoleConfig['roles'][0]];
    $stmt = $pdo->prepare('UPDATE loops SET configuration = :config WHERE id = :id');
    $stmt->execute([':config' => json_encode($oneRoleConfig), ':id' => $loopId]);

    $result = $executor->prepareNextStage($loopId);

    expect($result)->toBeNull();
    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('blocked');
    $meta = json_decode($loop['metadata'], true);
    $severities = array_column($meta['escalation']['findings'], 'severity');
    expect($severities)->toContain('critical');
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
