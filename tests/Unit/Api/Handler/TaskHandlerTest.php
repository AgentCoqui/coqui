<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\BackgroundTaskManager;
use CoquiBot\Coqui\Api\Handler\TaskHandler;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Utility\PromptSizeValidator;
use React\Http\Message\ServerRequest;

function createTaskHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-task-handler-' . bin2hex(random_bytes(8)) . '.db';
    $workspacePath = sys_get_temp_dir() . '/coqui-task-handler-ws-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/personas/caelum', 0755, true);
    file_put_contents($workspacePath . '/personas/caelum/soul.md', '# Caelum' . "\n\nA calm companion.");
    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());
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
        'workspacePath' => $workspacePath,
        'storage' => $storage,
        'projectStore' => $projectStore,
        'handler' => new TaskHandler($storage, $taskManager, $roleResolver, new PersonaDiscovery($workspacePath), $projectStore),
    ];
}

function cleanupTaskHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['storage'] = null;

    if (isset($fixture['workspacePath'])) {
        cleanupTestTree($fixture['workspacePath']);
    }

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

test('task handler create inherits persona from parent session', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $parentSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => 'Review the recent changes',
                'role' => 'coder',
                'parent_session_id' => $parentSessionId,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $session = $fixture['storage']->getSession($body['session_id']);

        expect($response->getStatusCode())->toBe(201);
        expect($body['persona'])->toBe('caelum');
        expect($session['persona_id'])->toBe('caelum');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler create rejects closed parent sessions', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $parentSessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', 'caelum');
        $fixture['storage']->closeSession($parentSessionId, 'history-rollover', true);

        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => 'Continue the work',
                'parent_session_id' => $parentSessionId,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('session_closed');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler create accepts explicit persona without parent session', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => 'Review the recent changes',
                'role' => 'coder',
                'persona' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $session = $fixture['storage']->getSession($body['session_id']);

        expect($response->getStatusCode())->toBe(201);
        expect($body['persona'])->toBe('caelum');
        expect($session['persona_id'])->toBe('caelum');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler create rejects roles disallowed by the resolved persona', function () {
    $fixture = createTaskHandlerFixture();

    try {
        file_put_contents($fixture['workspacePath'] . '/personas/caelum/preferences.json', json_encode([
            'prompts' => [
                'roles' => [
                    'allow' => ['orchestrator'],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => 'Review the recent changes',
                'role' => 'coder',
                'persona' => 'caelum',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['error'])->toContain('does not allow role "coder"');
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

test('task handler create accepts project context', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $projectId = $fixture['projectStore']->createProject('Career Ops', 'career-ops');

        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => 'Review pipeline progress',
                'role' => 'coder',
                'project_id' => $projectId,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $task = $fixture['storage']->getTask($body['id']);

        expect($response->getStatusCode())->toBe(201);
        expect($body['project_id'])->toBe($projectId);
        expect($task['project_id'])->toBe($projectId);
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});

test('task handler create rejects unknown project context', function () {
    $fixture = createTaskHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/tasks',
            ['Content-Type' => 'application/json'],
            json_encode([
                'prompt' => 'Review pipeline progress',
                'role' => 'coder',
                'project_id' => 'nonexistent-project',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(404);
        expect($body['code'])->toBe('not_found');
    } finally {
        cleanupTaskHandlerFixture($fixture);
    }
});