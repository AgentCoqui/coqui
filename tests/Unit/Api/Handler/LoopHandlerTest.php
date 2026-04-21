<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\Handler\LoopHandler;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createLoopHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-loop-handler-' . bin2hex(random_bytes(8)) . '.db';
    $workspacePath = sys_get_temp_dir() . '/coqui-loop-handler-ws-' . bin2hex(random_bytes(8));
    mkdir($workspacePath . '/loops', 0755, true);

    file_put_contents(
        $workspacePath . '/loops/harness.json',
        json_encode([
            'name' => 'harness',
            'description' => 'Generator-evaluator pattern',
            'parameters' => [
                [
                    'name' => 'subject',
                    'description' => 'What to work on',
                ],
                [
                    'name' => 'stack',
                    'description' => 'Technology context',
                    'required' => false,
                    'default' => 'php',
                ],
            ],
            'roles' => [
                ['role' => 'plan', 'prompt' => 'Plan work for {{subject}} using {{stack}}.'],
                ['role' => 'reviewer', 'prompt' => 'Review progress for {{subject}}.'],
            ],
            'termination_condition' => [
                'type' => 'evaluation_bound',
                'value' => [
                    'criteria' => 'Explicit approval required',
                    'max_review_rounds' => 4,
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
    );

    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());
    $loopStore = new LoopStore($storage->getPdo());
    $discovery = new LoopDiscovery($workspacePath);
    $executor = new LoopExecutor($loopStore, $projectStore, $storage);

    return [
        'dbPath' => $dbPath,
        'workspacePath' => $workspacePath,
        'storage' => $storage,
        'projectStore' => $projectStore,
        'loopStore' => $loopStore,
        'handler' => new LoopHandler($loopStore, $discovery, $executor, $storage, $projectStore),
    ];
}

function cleanupLoopHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['loopStore'] = null;
    $fixture['projectStore'] = null;
    $fixture['storage'] = null;

    cleanupTestTree($fixture['workspacePath']);
    cleanupSqliteTestDb($fixture['dbPath']);
}

test('loop handler definitions include parameter metadata', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $response = $fixture['handler']->definitions(new ServerRequest('GET', '/api/v1/loops/definitions'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['count'])->toBe(1);
        expect($body['definitions'][0]['parameters'])->toHaveCount(2);
        expect($body['definitions'][0]['parameters'][0]['name'])->toBe('subject');
        expect($body['definitions'][0]['parameters'][1]['default'])->toBe('php');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler creates loop scoped to sprint project and applies parameters', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $projectId = $fixture['projectStore']->createProject('Career Ops', 'career-ops');
        $sprintId = $fixture['projectStore']->createSprint($projectId, 'Refactor API');

        $request = new ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'harness',
                'goal' => 'Refactor the loop API',
                'session_id' => $sessionId,
                'sprint_id' => $sprintId,
                'parameters' => [
                    'subject' => 'loop lifecycle API',
                ],
                'max_iterations' => 2,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);
        $storedLoop = $fixture['loopStore']->getLoop($body['loop']['id']);
        $configuration = json_decode((string) $storedLoop['configuration'], true);

        expect($response->getStatusCode())->toBe(201);
        expect($body['loop']['project_id'])->toBe($projectId);
        expect($body['iteration']['sprint_id'])->toBe($sprintId);
        expect($body['iteration']['status'])->toBe('running');
        expect($body['stages'])->toHaveCount(2);
        expect((int) $storedLoop['max_iterations'])->toBe(2);
        expect($configuration['roles'][0]['prompt'])->toContain('loop lifecycle API');
        expect($configuration['roles'][0]['prompt'])->toContain('php');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler create rejects unknown session', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'harness',
                'goal' => 'Refactor the loop API',
                'session_id' => 'missing-session',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(404);
        expect($body['code'])->toBe('session_not_found');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler create rejects closed sessions', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->closeSession($sessionId, 'history-rollover', true);

        $request = new ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'harness',
                'goal' => 'Inspect historical work',
                'session_id' => $sessionId,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('session_closed');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler lifecycle endpoints update status', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $loopId = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/loops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'definition' => 'harness',
                    'goal' => 'Refactor the loop API',
                    'parameters' => ['subject' => 'loop lifecycle API'],
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $loopId->getBody(), true);
        $createdLoopId = $createdBody['loop']['id'];

        $pauseResponse = $fixture['handler']->pause(new ServerRequest('POST', '/api/v1/loops/' . $createdLoopId . '/pause'), $createdLoopId);
        $resumeResponse = $fixture['handler']->resume(new ServerRequest('POST', '/api/v1/loops/' . $createdLoopId . '/resume'), $createdLoopId);
        $stopResponse = $fixture['handler']->stop(new ServerRequest('POST', '/api/v1/loops/' . $createdLoopId . '/stop'), $createdLoopId);

        expect($pauseResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $pauseResponse->getBody(), true)['status'])->toBe('paused');
        expect($resumeResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $resumeResponse->getBody(), true)['status'])->toBe('running');
        expect($stopResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $stopResponse->getBody(), true)['status'])->toBe('cancelled');
        expect($fixture['loopStore']->getLoop($createdLoopId)['status'])->toBe('cancelled');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler rejects invalid lifecycle transition', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/loops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'definition' => 'harness',
                    'goal' => 'Refactor the loop API',
                    'parameters' => ['subject' => 'loop lifecycle API'],
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $createResponse->getBody(), true);
        $createdLoopId = $createdBody['loop']['id'];

        $response = $fixture['handler']->resume(new ServerRequest('POST', '/api/v1/loops/' . $createdLoopId . '/resume'), $createdLoopId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('conflict');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});