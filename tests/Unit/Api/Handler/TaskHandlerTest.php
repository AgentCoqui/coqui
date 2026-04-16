<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Handler\TaskHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Utility\PromptSizeValidator;
use React\Http\Message\ServerRequest;

function createTaskHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-task-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => [
                    'orchestrator' => 'ollama/qwen3:latest',
                    'coder' => 'anthropic/claude-3-5-sonnet',
                ],
            ],
        ],
    ]);
    $roleResolver = new RoleResolver($config);
    $taskManager = new BackgroundTaskManager(
        storage: $storage,
        coquiBinPath: '/nonexistent/coqui',
        configPath: '',
        workDir: sys_get_temp_dir(),
        workspacePath: '',
        maxConcurrent: 0,
    );

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'handler' => new TaskHandler($storage, $taskManager, $roleResolver),
    ];
}

function cleanupTaskHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['storage'] = null;

    if (file_exists($fixture['dbPath'])) {
        unlink($fixture['dbPath']);
    }
}

test('task handler create returns a pending task when concurrency is unavailable', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => 'Review the recent changes',
                'role' => 'coder',
                'title' => 'Code Review',
                'max_iterations' => 12,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $task = $fixture['storage']->getTask($body['id']);
        $session = $fixture['storage']->getSession($body['session_id']);

        expect($response->getStatusCode())->toBe(201);
        expect($body['status'])->toBe('pending');
        expect($task['status'])->toBe('pending');
        expect($session['model_role'])->toBe('coder');
        expect($session['model'])->toBe('anthropic/claude-3-5-sonnet');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler create validates missing prompt', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode(['role' => 'coder']) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('missing_field');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler create rejects oversized prompt', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => str_repeat('x', PromptSizeValidator::API_MAX_PROMPT_BYTES + 1),
                'role' => 'coder',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(413);
        expect($body['code'])->toBe('payload_too_large');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler create accepts prompt at shared limit', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $prompt = str_repeat('x', PromptSizeValidator::API_MAX_PROMPT_BYTES);
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => $prompt,
                'role' => 'coder',
                'title' => 'Large Prompt Task',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $task = $fixture['storage']->getTask($body['id']);

        expect($response->getStatusCode())->toBe(201);
        expect(strlen((string) $task['prompt']))->toBe(PromptSizeValidator::API_MAX_PROMPT_BYTES);
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler list and get normalize metadata and process status', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('coder', 'anthropic/claude-3-5-sonnet');
        $taskId = $fixture['storage']->createTask(
            sessionId: $sessionId,
            prompt: 'Run a review',
            role: 'coder',
            title: 'Review Task',
            metadata: ['loop_id' => 'loop-123'],
        );

        $listResponse = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/tasks'));
        $listBody = json_decode((string) $listResponse->getBody(), true);
        $getResponse = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/tasks/' . $taskId), $taskId);
        $getBody = json_decode((string) $getResponse->getBody(), true);

        expect($listResponse->getStatusCode())->toBe(200);
        expect($listBody['count'])->toBe(1);
        expect($listBody['tasks'][0]['metadata']['loop_id'])->toBe('loop-123');
        expect($getResponse->getStatusCode())->toBe(200);
        expect($getBody['metadata']['loop_id'])->toBe('loop-123');
        expect($getBody['process_alive'])->toBeFalse();
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler addInput queues content for running tasks', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('coder', 'anthropic/claude-3-5-sonnet');
        $taskId = $fixture['storage']->createTask(
            sessionId: $sessionId,
            prompt: 'Run a review',
            role: 'coder',
        );
        $fixture['storage']->updateTaskStatus($taskId, 'running');

        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks/' . $taskId . '/input',
            ['Content-Type' => 'application/json'],
            json_encode(['content' => 'Please include the API handler changes']) ?: '',
        );

        $response = $fixture['handler']->addInput($request, $taskId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['status'])->toBe('queued');
        expect($fixture['storage']->consumeTaskInputs($taskId))->toBe(['Please include the API handler changes']);
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler addInput rejects oversized content', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('coder', 'anthropic/claude-3-5-sonnet');
        $taskId = $fixture['storage']->createTask(
            sessionId: $sessionId,
            prompt: 'Run a review',
            role: 'coder',
        );
        $fixture['storage']->updateTaskStatus($taskId, 'running');

        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks/' . $taskId . '/input',
            ['Content-Type' => 'application/json'],
            json_encode(['content' => str_repeat('x', PromptSizeValidator::API_MAX_PROMPT_BYTES + 1)]) ?: '',
        );

        $response = $fixture['handler']->addInput($request, $taskId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(413);
        expect($body['code'])->toBe('payload_too_large');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler addInput accepts content at shared limit', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('coder', 'anthropic/claude-3-5-sonnet');
        $taskId = $fixture['storage']->createTask(
            sessionId: $sessionId,
            prompt: 'Run a review',
            role: 'coder',
        );
        $fixture['storage']->updateTaskStatus($taskId, 'running');

        $content = str_repeat('x', PromptSizeValidator::API_MAX_PROMPT_BYTES);
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks/' . $taskId . '/input',
            ['Content-Type' => 'application/json'],
            json_encode(['content' => $content]) ?: '',
        );

        $response = $fixture['handler']->addInput($request, $taskId);
        $inputs = $fixture['storage']->consumeTaskInputs($taskId);

        expect($response->getStatusCode())->toBe(201);
        expect($inputs)->toHaveCount(1);
        expect(strlen($inputs[0]))->toBe(PromptSizeValidator::API_MAX_PROMPT_BYTES);
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler cancel updates pending task state through the manager', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('coder', 'anthropic/claude-3-5-sonnet');
        $taskId = $fixture['storage']->createTask(
            sessionId: $sessionId,
            prompt: 'Run a review',
            role: 'coder',
        );

        $response = $fixture['handler']->cancel(new ServerRequest('POST', '/api/v1/tasks/' . $taskId . '/cancel'), $taskId);
        $body = json_decode((string) $response->getBody(), true);
        $task = $fixture['storage']->getTask($taskId);

        expect($response->getStatusCode())->toBe(200);
        expect($body['status'])->toBe('cancelling');
        expect($task['status'])->toBe('cancelled');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});