<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Toolkit\LoopToolkit;

test('loop_control retry revives a blocked loop, clears the breaker, and stores the note', function () {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $loopStore = new LoopStore($pdo);

    $config = [
        'name' => 'x',
        'roles' => [['role' => 'coder', 'prompt' => 'do']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => ['max_iterations' => 3]],
    ];
    $loopId = $loopStore->createLoop('x', 'goal', $config, maxIterations: 3);
    $iterId = $loopStore->createIteration($loopId, 1);
    $stageId = $loopStore->createStage($iterId, 0, 'coder');
    $loopStore->updateStage(id: $stageId, status: 'completed', resultSummary: 'blocked round');
    $loopStore->updateIterationStatus($iterId, 'needs_rework', 'blocked');
    $loopStore->updateLoopMetadata($loopId, ['rework_attempts' => 3, 'escalation' => ['reason' => 'stuck']]);
    $loopStore->updateLoopStatus($loopId, 'blocked');

    $toolkit = new LoopToolkit($loopStore, new LoopDiscovery(sys_get_temp_dir()));
    $control = toolFromToolkit($toolkit, 'loop_control');
    $result = $control->execute(['action' => 'retry', 'id' => $loopId, 'note' => 'Use approach B.']);

    expect($result->status)->toBe(ToolResultStatus::Success);

    $loop = $loopStore->getLoop($loopId);
    expect($loop['status'])->toBe('running');

    $meta = json_decode($loop['metadata'], true);
    expect($meta['rework_attempts'])->toBe(0);
    expect($meta['pending_guidance'])->toBe('Use approach B.');

    // Stage reset to pending for re-dispatch.
    expect($loopStore->getStage($stageId)['status'])->toBe('pending');
});
