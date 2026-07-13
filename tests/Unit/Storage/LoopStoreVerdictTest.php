<?php
declare(strict_types=1);

use CoquiBot\Coqui\Storage\LoopStore;

function verdictStore(): LoopStore
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return new LoopStore($pdo);
}

test('recordStageVerdict persists a verdict json readable via getStage', function () {
    $store = verdictStore();
    $loopId = $store->createLoop('harness', 'goal', ['name' => 'harness'], maxIterations: 3);
    $iterId = $store->createIteration($loopId, 1);
    $stageId = $store->createStage($iterId, 0, 'reviewer');

    $store->recordStageVerdict($stageId, '{"status":"done","requirements_met":true}');

    $stage = $store->getStage($stageId);
    expect($stage['verdict'])->toBe('{"status":"done","requirements_met":true}');
});

test('verdict defaults to null for a fresh stage', function () {
    $store = verdictStore();
    $loopId = $store->createLoop('harness', 'goal', ['name' => 'harness'], maxIterations: 3);
    $iterId = $store->createIteration($loopId, 1);
    $stageId = $store->createStage($iterId, 0, 'coder');
    expect($store->getStage($stageId)['verdict'])->toBeNull();
});
