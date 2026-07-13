<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\LoopManager;
use CoquiBot\Coqui\Notification\NotificationPublisher;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;

test('a loop that blocks publishes an actionable loop.blocked notification', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-loop-blocked-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $pdo = $storage->getPdo();

    $loopStore = new LoopStore($pdo);
    $projectStore = new ProjectStore($pdo);
    $artifactStore = artifactStoreForTest($pdo);

    // NotificationPublisher is final — drive a real store and assert on the
    // persisted actionable notification instead of a subclassed test double.
    $publisher = new NotificationPublisher(new NotificationStore($pdo));

    $executor = new LoopExecutor(loopStore: $loopStore, projectStore: $projectStore);
    $manager = new LoopManager(
        storage: $storage,
        loopStore: $loopStore,
        executor: $executor,
        artifactStore: $artifactStore,
        publisher: $publisher,
    );

    $sessionId = $storage->createSession('orchestrator', 'ollama/qwen3:latest');
    $projectId = $projectStore->createProject(title: 'p', slug: 'bn-1', description: 'd');

    // Two producer stages so a BLOCKED self-signal on stage 0 halts the loop.
    $definition = [
        'name' => 'blocking-loop',
        'description' => 'two-producer loop',
        'roles' => [
            ['role' => 'coder', 'prompt' => 'do'],
            ['role' => 'coder2', 'prompt' => 'do more'],
        ],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 3],
    ];
    $loopId = $executor->startLoop(
        $definition,
        'goal',
        $sessionId,
        projectId: $projectId,
        maxIterationsOverride: 3,
    );

    // Producer self-signals BLOCKED via its result summary.
    $stages = $loopStore->getCurrentState($loopId)['stages'];
    $loopStore->updateStage(
        id: $stages[0]['id'],
        status: 'completed',
        resultSummary: "STATUS: BLOCKED\nmissing dependency",
    );

    $manager->reconcile();

    $store = new NotificationStore($pdo);
    $actionable = $store->getUnclaimedActionable($sessionId, ['loop.blocked']);

    expect($actionable)->not->toBeEmpty();

    $storage = null;
    if (file_exists($dbPath)) {
        unlink($dbPath);
    }
});
