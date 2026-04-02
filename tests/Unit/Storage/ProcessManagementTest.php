<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\SessionStorage;

beforeEach(function () {
    $this->dbPath = sys_get_temp_dir() . '/coqui-process-mgmt-test-' . bin2hex(random_bytes(8)) . '.db';
    $this->storage = new SessionStorage($this->dbPath);
    $this->sessionId = $this->storage->createSession('orchestrator', 'test/model');
});

afterEach(function () {
    $this->storage = null;
    if (file_exists($this->dbPath)) {
        unlink($this->dbPath);
    }
});

// --- updateTaskStatusConditional ---

test('updateTaskStatusConditional updates when status matches', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running');

    $updated = $this->storage->updateTaskStatusConditional($taskId, 'failed', 'running', [
        'error' => 'Process crashed',
    ]);

    expect($updated)->toBeTrue();

    $task = $this->storage->getTask($taskId);
    expect($task['status'])->toBe('failed');
    expect($task['error'])->toBe('Process crashed');
});

test('updateTaskStatusConditional does not update when status does not match', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'completed', ['result' => 'done']);

    $updated = $this->storage->updateTaskStatusConditional($taskId, 'failed', 'running', [
        'error' => 'Should not happen',
    ]);

    expect($updated)->toBeFalse();

    $task = $this->storage->getTask($taskId);
    expect($task['status'])->toBe('completed');
    expect($task['error'])->toBeNull();
});

test('updateTaskStatusConditional sets timestamp columns', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running');

    $this->storage->updateTaskStatusConditional($taskId, 'completed', 'running');

    $task = $this->storage->getTask($taskId);
    expect($task['completed_at'])->not->toBeNull();
});

// --- updateTaskHeartbeat ---

test('updateTaskHeartbeat sets last_heartbeat_at', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running');

    $this->storage->updateTaskHeartbeat($taskId);

    $task = $this->storage->getTask($taskId);
    expect($task['last_heartbeat_at'])->not->toBeNull();
});

test('updateTaskHeartbeat only updates running tasks', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    // Task is still 'pending' — heartbeat should have no effect
    $this->storage->updateTaskHeartbeat($taskId);

    $task = $this->storage->getTask($taskId);
    expect($task['last_heartbeat_at'])->toBeNull();
});

// --- getStaleRunningTasks ---

test('getStaleRunningTasks returns tasks with old heartbeats', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running');

    // Manually set an old heartbeat
    $oldTime = date('c', time() - 600); // 10 minutes ago
    $db = new PDO('sqlite:' . $this->dbPath);
    $db->exec("UPDATE background_tasks SET last_heartbeat_at = '{$oldTime}' WHERE id = '{$taskId}'");

    $stale = $this->storage->getStaleRunningTasks(300); // 5 min threshold
    expect($stale)->toHaveCount(1);
    expect($stale[0]['id'])->toBe($taskId);
});

test('getStaleRunningTasks excludes tasks with recent heartbeats', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running');
    $this->storage->updateTaskHeartbeat($taskId);

    $stale = $this->storage->getStaleRunningTasks(300);
    expect($stale)->toHaveCount(0);
});

test('getStaleRunningTasks excludes tasks with no heartbeat', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running');
    // No heartbeat set — task just started, not yet stale

    $stale = $this->storage->getStaleRunningTasks(300);
    expect($stale)->toHaveCount(0);
});

// --- getTimedOutRunningTasks ---

test('getTimedOutRunningTasks returns tasks exceeding max execution seconds', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt', maxExecutionSeconds: 60);
    $this->storage->updateTaskStatus($taskId, 'running');

    // Manually backdate the started_at timestamp
    $oldTime = date('c', time() - 120); // 2 minutes ago (exceeds 60s limit)
    $db = new PDO('sqlite:' . $this->dbPath);
    $db->exec("UPDATE background_tasks SET started_at = '{$oldTime}' WHERE id = '{$taskId}'");

    $timedOut = $this->storage->getTimedOutRunningTasks();
    expect($timedOut)->toHaveCount(1);
    expect($timedOut[0]['id'])->toBe($taskId);
});

test('getTimedOutRunningTasks excludes tasks within time limit', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt', maxExecutionSeconds: 3600);
    $this->storage->updateTaskStatus($taskId, 'running');

    $timedOut = $this->storage->getTimedOutRunningTasks();
    expect($timedOut)->toHaveCount(0);
});

test('getTimedOutRunningTasks excludes tasks with zero max execution seconds', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt', maxExecutionSeconds: 0);
    $this->storage->updateTaskStatus($taskId, 'running');

    // Backdate to make it old
    $oldTime = date('c', time() - 86400);
    $db = new PDO('sqlite:' . $this->dbPath);
    $db->exec("UPDATE background_tasks SET started_at = '{$oldTime}' WHERE id = '{$taskId}'");

    $timedOut = $this->storage->getTimedOutRunningTasks();
    expect($timedOut)->toHaveCount(0);
});

// --- createTask with maxExecutionSeconds ---

test('createTask stores max_execution_seconds', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt', maxExecutionSeconds: 7200);

    $task = $this->storage->getTask($taskId);
    expect((int) $task['max_execution_seconds'])->toBe(7200);
});

test('createTask defaults max_execution_seconds to 3600', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');

    $task = $this->storage->getTask($taskId);
    expect((int) $task['max_execution_seconds'])->toBe(3600);
});

// --- markOrphanedTasksFailed (PID-validated) ---

test('markOrphanedTasksFailed marks tasks with dead PIDs', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running', ['pid' => 999999]); // unlikely real PID

    $count = $this->storage->markOrphanedTasksFailed();

    expect($count)->toBe(1);

    $task = $this->storage->getTask($taskId);
    expect($task['status'])->toBe('failed');
});

test('markOrphanedTasksFailed marks tasks with no PID as orphaned', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    $this->storage->updateTaskStatus($taskId, 'running'); // No PID set

    $count = $this->storage->markOrphanedTasksFailed();

    expect($count)->toBe(1);
});

test('markOrphanedTasksFailed preserves tasks with live PIDs', function () {
    $taskId = $this->storage->createTask($this->sessionId, 'test prompt');
    // Use our own PID — which is definitely alive
    $this->storage->updateTaskStatus($taskId, 'running', ['pid' => getmypid()]);

    $count = $this->storage->markOrphanedTasksFailed();

    expect($count)->toBe(0);

    $task = $this->storage->getTask($taskId);
    expect($task['status'])->toBe('running');
})->skip(PHP_OS_FAMILY === 'Windows', 'PID liveness check relies on posix_kill; wmic is unavailable on modern Windows');

// --- updateTurnProcessStatusConditional ---

test('updateTurnProcessStatusConditional updates when status matches', function () {
    $turnId = $this->storage->createTurnProcess($this->sessionId, 'test prompt');
    $this->storage->updateTurnProcessStatus($turnId, 'running');

    $updated = $this->storage->updateTurnProcessStatusConditional($turnId, 'failed', 'running', [
        'error' => 'Process crashed',
    ]);

    expect($updated)->toBeTrue();

    $turn = $this->storage->getTurnProcess($turnId);
    expect($turn['status'])->toBe('failed');
});

test('updateTurnProcessStatusConditional does not update when status mismatches', function () {
    $turnId = $this->storage->createTurnProcess($this->sessionId, 'test prompt');
    $this->storage->updateTurnProcessStatus($turnId, 'completed');

    $updated = $this->storage->updateTurnProcessStatusConditional($turnId, 'failed', 'running');

    expect($updated)->toBeFalse();

    $turn = $this->storage->getTurnProcess($turnId);
    expect($turn['status'])->toBe('completed');
});

// --- markOrphanedTurnProcessesFailed (PID-validated) ---

test('markOrphanedTurnProcessesFailed marks processes with dead PIDs', function () {
    $turnId = $this->storage->createTurnProcess($this->sessionId, 'test prompt');
    $this->storage->updateTurnProcessStatus($turnId, 'running', ['pid' => 999999]);

    $count = $this->storage->markOrphanedTurnProcessesFailed();

    expect($count)->toBe(1);

    $turn = $this->storage->getTurnProcess($turnId);
    expect($turn['status'])->toBe('failed');
});

test('markOrphanedTurnProcessesFailed preserves processes with live PIDs', function () {
    $turnId = $this->storage->createTurnProcess($this->sessionId, 'test prompt');
    $this->storage->updateTurnProcessStatus($turnId, 'running', ['pid' => getmypid()]);

    $count = $this->storage->markOrphanedTurnProcessesFailed();

    expect($count)->toBe(0);

    $turn = $this->storage->getTurnProcess($turnId);
    expect($turn['status'])->toBe('running');
})->skip(PHP_OS_FAMILY === 'Windows', 'PID liveness check relies on posix_kill; wmic is unavailable on modern Windows');
