<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;

test('pending_guidance is injected into the next stage prompt and then cleared', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $executor = new LoopExecutor($loopStore, $projectStore);

    $projectId = $projectStore->createProject(title: 'p', slug: 'gd-1', description: 'd');
    $config = [
        'name' => 'harness',
        'description' => 'guidance harness',
        'roles' => [['role' => 'coder', 'prompt' => 'implement it']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 3],
    ];
    $loopId = $executor->startLoop($config, 'goal', projectId: $projectId, maxIterationsOverride: 3);
    $loopStore->updateLoopMetadata($loopId, ['pending_guidance' => 'Use approach B instead of A.']);

    $result = $executor->prepareNextStage($loopId);

    expect($result)->not->toBeNull();
    expect($result->prompt)->toContain('## Operator Guidance');
    expect($result->prompt)->toContain('Use approach B instead of A.');

    // Consumed and cleared.
    $meta = json_decode($loopStore->getLoop($loopId)['metadata'], true);
    expect($meta['pending_guidance'] ?? null)->toBeNull();
});
