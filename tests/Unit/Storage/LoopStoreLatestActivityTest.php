<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\SessionStorage;

test('latestActivityId returns the max task_events id across a loop\'s stages', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loopstore-activity-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());

    try {
        $loopId = $loopStore->createLoop(
            definitionName: 'harness',
            goal: 'g',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 2,
        );
        $iterationId = $loopStore->createIteration($loopId, 1);
        $stageId = $loopStore->createStage($iterationId, 0, 'plan');
        $sessionId = $storage->createSession(modelRole: 'plan', model: '', visibility: 'hidden');
        $taskId = $storage->createTask(sessionId: $sessionId, prompt: 'plan it', role: 'plan');
        $loopStore->updateStage($stageId, 'running', $taskId);

        $storage->appendTaskEvent($taskId, 'tool_call', ['tool' => 'a']);
        $storage->appendTaskEvent($taskId, 'tool_call', ['tool' => 'b']);

        $events = $storage->getTaskEvents($taskId);
        $expected = (int) $events[array_key_last($events)]['id'];

        expect($loopStore->latestActivityId($loopId))->toBe($expected);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

test('latestActivityId returns null when the loop has no events', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-loopstore-activity-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $loopStore = new LoopStore($storage->getPdo());

    try {
        $loopId = $loopStore->createLoop(
            definitionName: 'harness',
            goal: 'g',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 2,
        );
        expect($loopStore->latestActivityId($loopId))->toBeNull();
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
