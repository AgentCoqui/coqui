<?php

declare(strict_types=1);

use CoquiBot\Coqui\Notification\EscalateLoopFailureAction;
use CoquiBot\Coqui\Notification\NotificationAutomationRunner;
use CoquiBot\Coqui\Notification\RetryBackgroundTaskAction;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\NotificationStore;
use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-notification-runner-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $pdo = $this->storage->getPdo();

    $this->store = new NotificationStore($pdo);
    $this->loopStore = new LoopStore($pdo);
    $this->parentSessionId = $this->storage->createSession('orchestrator', 'test/model');
});

afterEach(function () {
    releaseTestObjectProperties($this);
    cleanupSqliteTestDb($this->dbPath);
});

test('runner creates a retry task for failed background task notifications', function () {
    $taskSessionId = $this->storage->createSession('coder', 'test/model');
    $taskId = $this->storage->createTask(
        sessionId: $taskSessionId,
        prompt: 'Retry the deployment.',
        role: 'coder',
        parentSessionId: $this->parentSessionId,
        title: 'Deploy app',
        maxIterations: 18,
    );
    $this->storage->updateTaskStatus($taskId, 'failed', ['error' => 'Connection refused']);

    $notificationId = $this->store->create(
        sessionId: $this->parentSessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Task failed: Deploy app',
        message: 'Connection refused',
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    $runner = new NotificationAutomationRunner(
        store: $this->store,
        handlers: [new RetryBackgroundTaskAction($this->storage)],
        leaseSeconds: 60,
        batchSize: 5,
        maxAttempts: 3,
        retryDelaySeconds: 30,
        runnerId: 'runner-test',
    );

    $runner->tick();

    $notification = $this->store->get($notificationId);
    $followUp = $this->storage->findTaskByAutomationNotificationId($notificationId);
    $stats = $runner->stats();

    expect($notification['claim_status'])->toBe('completed');
    expect($notification['completed_at'])->not->toBeNull();
    expect($notification['read_at'])->toBeNull();
    expect($followUp)->not->toBeNull();
    expect($followUp['parent_session_id'])->toBe($this->parentSessionId);
    expect($followUp['prompt'])->toBe('Retry the deployment.');
    expect($followUp['title'])->toBe('Retry: Deploy app');
    expect($stats['claimed'])->toBe(1);
    expect($stats['completed'])->toBe(1);
    expect($stats['perKind']['task.failed']['claimed'])->toBe(1);
    expect($stats['perKind']['task.failed']['completed'])->toBe(1);
});

test('runner retry task inherits profile from target session', function () {
    $profiledParentSessionId = $this->storage->createSession('orchestrator', 'test/model', 'caelum');
    $taskSessionId = $this->storage->createSession('coder', 'test/model', 'caelum');
    $taskId = $this->storage->createTask(
        sessionId: $taskSessionId,
        prompt: 'Retry the deployment.',
        role: 'coder',
        parentSessionId: $profiledParentSessionId,
        title: 'Deploy app',
    );
    $this->storage->updateTaskStatus($taskId, 'failed', ['error' => 'Connection refused']);

    $notificationId = $this->store->create(
        sessionId: $profiledParentSessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Task failed: Deploy app',
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    $runner = new NotificationAutomationRunner(
        store: $this->store,
        handlers: [new RetryBackgroundTaskAction($this->storage)],
        runnerId: 'runner-test',
    );

    $runner->tick();

    $followUp = $this->storage->findTaskByAutomationNotificationId($notificationId);
    $session = $followUp !== null ? $this->storage->getSession((string) $followUp['session_id']) : null;

    expect($session)->not->toBeNull();
    expect($session['persona_id'])->toBe('caelum');
});

test('runner skips retry when the source task is no longer failed', function () {
    $taskSessionId = $this->storage->createSession('coder', 'test/model');
    $taskId = $this->storage->createTask(
        sessionId: $taskSessionId,
        prompt: 'Do some work.',
        role: 'coder',
        parentSessionId: $this->parentSessionId,
        title: 'Do work',
    );
    $this->storage->updateTaskStatus($taskId, 'completed', ['result' => 'done']);

    $notificationId = $this->store->create(
        sessionId: $this->parentSessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Task failed: Do work',
        sourceType: 'background_task',
        sourceId: $taskId,
    );

    $runner = new NotificationAutomationRunner(
        store: $this->store,
        handlers: [new RetryBackgroundTaskAction($this->storage)],
        runnerId: 'runner-test',
    );

    $runner->tick();

    $notification = $this->store->get($notificationId);

    expect($notification['claim_status'])->toBe('completed');
    expect($this->storage->findTaskByAutomationNotificationId($notificationId))->toBeNull();
    expect($runner->stats()['skipped'])->toBe(1);
});

test('runner creates an investigation task for failed loops', function () {
    $loopId = $this->loopStore->createLoop(
        definitionName: 'harness',
        goal: 'Ship the feature',
        configuration: ['roles' => []],
        sessionId: $this->parentSessionId,
    );
    $this->loopStore->updateLoopStatus($loopId, 'failed');

    $notificationId = $this->store->create(
        sessionId: $this->parentSessionId,
        class: 'actionable',
        kind: 'loop.failed',
        title: 'Loop failed [harness]',
        message: 'Reviewer stage failed',
        sourceType: 'loop',
        sourceId: $loopId,
    );

    $runner = new NotificationAutomationRunner(
        store: $this->store,
        handlers: [new EscalateLoopFailureAction($this->storage, $this->loopStore)],
        runnerId: 'runner-test',
    );

    $runner->tick();

    $notification = $this->store->get($notificationId);
    $followUp = $this->storage->findTaskByAutomationNotificationId($notificationId);

    expect($notification['claim_status'])->toBe('completed');
    expect($followUp)->not->toBeNull();
    expect($followUp['role'])->toBe('orchestrator');
    expect($followUp['title'])->toContain('Investigate failed loop');
    expect($followUp['prompt'])->toContain($loopId);
    expect($followUp['parent_session_id'])->toBe($this->parentSessionId);
});

test('runner loop investigation inherits profile from target session', function () {
    $profiledParentSessionId = $this->storage->createSession('orchestrator', 'test/model', 'caelum');
    $loopId = $this->loopStore->createLoop(
        definitionName: 'harness',
        goal: 'Ship the feature',
        configuration: ['roles' => []],
        sessionId: $profiledParentSessionId,
    );
    $this->loopStore->updateLoopStatus($loopId, 'failed');

    $notificationId = $this->store->create(
        sessionId: $profiledParentSessionId,
        class: 'actionable',
        kind: 'loop.failed',
        title: 'Loop failed [harness]',
        sourceType: 'loop',
        sourceId: $loopId,
    );

    $runner = new NotificationAutomationRunner(
        store: $this->store,
        handlers: [new EscalateLoopFailureAction($this->storage, $this->loopStore)],
        runnerId: 'runner-test',
    );

    $runner->tick();

    $followUp = $this->storage->findTaskByAutomationNotificationId($notificationId);
    $session = $followUp !== null ? $this->storage->getSession((string) $followUp['session_id']) : null;

    expect($session)->not->toBeNull();
    expect($session['persona_id'])->toBe('caelum');
});

test('reclaim releases expired claims for another processing tick', function () {
    $taskSessionId = $this->storage->createSession('coder', 'test/model');
    $taskId = $this->storage->createTask(
        sessionId: $taskSessionId,
        prompt: 'Retry build.',
        role: 'coder',
        parentSessionId: $this->parentSessionId,
        title: 'Build app',
    );
    $this->storage->updateTaskStatus($taskId, 'failed', ['error' => 'Bad gateway']);

    $notificationId = $this->store->create(
        sessionId: $this->parentSessionId,
        class: 'actionable',
        kind: 'task.failed',
        title: 'Task failed: Build app',
        sourceType: 'background_task',
        sourceId: $taskId,
    );
    $this->store->claim($notificationId, 'runner-a', 1);
    $expired = (new DateTimeImmutable('-10 minutes'))->format('Y-m-d\TH:i:s\Z');
    $this->storage->getPdo()->prepare('UPDATE notifications SET claim_expires_at = ? WHERE id = ?')->execute([$expired, $notificationId]);

    $runner = new NotificationAutomationRunner(
        store: $this->store,
        handlers: [new RetryBackgroundTaskAction($this->storage)],
        retryDelaySeconds: 1,
        runnerId: 'runner-b',
    );

    $runner->reclaim();
    $this->storage->getPdo()->prepare('UPDATE notifications SET next_attempt_at = ? WHERE id = ?')->execute([
        (new DateTimeImmutable('-1 second'))->format('Y-m-d\TH:i:s\Z'),
        $notificationId,
    ]);
    $runner->tick();

    $notification = $this->store->get($notificationId);

    expect($notification['claim_status'])->toBe('completed');
    expect($runner->stats()['reclaimed'])->toBe(1);
    expect($this->storage->findTaskByAutomationNotificationId($notificationId))->not->toBeNull();
});