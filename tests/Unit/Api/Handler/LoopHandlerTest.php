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

test('loop handler creates loop scoped to project and applies parameters', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $projectId = $fixture['projectStore']->createProject('Career Ops', 'career-ops');

        $request = new ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'harness',
                'goal' => 'Refactor the loop API',
                'session_id' => $sessionId,
                'project_id' => $projectId,
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

test('loop handler updates editable loop fields and merges metadata', function () {
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
                    'max_iterations' => 2,
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $createResponse->getBody(), true);
        $createdLoopId = $createdBody['loop']['id'];

        $updateResponse = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/loops/' . $createdLoopId,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'goal' => 'Ship loop edit and delete support',
                    'max_iterations' => 4,
                    'metadata' => [
                        'dispatch' => [
                            'operator_note' => 'Keep the patch scope narrow.',
                        ],
                    ],
                    'labels' => ['backend', 'app-api'],
                ]) ?: '',
            ),
            $createdLoopId,
        );
        $updatedBody = json_decode((string) $updateResponse->getBody(), true);

        expect($updateResponse->getStatusCode())->toBe(200);
        expect($updatedBody['loop']['goal'])->toBe('Ship loop edit and delete support');
        expect((int) $updatedBody['loop']['max_iterations'])->toBe(4);
        expect($updatedBody['loop']['metadata']['dispatch']['status'])->toBe('pending');
        expect($updatedBody['loop']['metadata']['dispatch']['operator_note'])->toBe('Keep the patch scope narrow.');
        expect($updatedBody['loop']['metadata']['labels'])->toBe(['backend', 'app-api']);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler delete rejects active loops', function () {
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

        $deleteResponse = $fixture['handler']->delete(
            new ServerRequest('DELETE', '/api/v1/loops/' . $createdLoopId),
            $createdLoopId,
        );
        $deleteBody = json_decode((string) $deleteResponse->getBody(), true);

        expect($deleteResponse->getStatusCode())->toBe(409);
        expect($deleteBody['code'])->toBe('conflict');
        expect($fixture['loopStore']->getLoop($createdLoopId))->not->toBeNull();
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler delete removes terminal loops', function () {
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

        $fixture['handler']->stop(new ServerRequest('POST', '/api/v1/loops/' . $createdLoopId . '/stop'), $createdLoopId);

        $deleteResponse = $fixture['handler']->delete(
            new ServerRequest('DELETE', '/api/v1/loops/' . $createdLoopId),
            $createdLoopId,
        );
        $deleteBody = json_decode((string) $deleteResponse->getBody(), true);

        expect($deleteResponse->getStatusCode())->toBe(200);
        expect($deleteBody['deleted'])->toBeTrue();
        expect($fixture['loopStore']->getLoop($createdLoopId))->toBeNull();
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler exposes full history and aggregate metrics', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $loopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'Inspect loop history',
            configuration: ['roles' => [['role' => 'plan'], ['role' => 'reviewer']]],
            maxIterations: 3,
            metadata: ['dispatch' => ['status' => 'pending']],
        );

        $iterationOne = $fixture['loopStore']->createIteration($loopId, 1);
        $stageOneA = $fixture['loopStore']->createStage($iterationOne, 0, 'plan');
        $stageOneB = $fixture['loopStore']->createStage($iterationOne, 1, 'reviewer');
        $fixture['loopStore']->updateIterationStatus($iterationOne, 'running');
        $fixture['loopStore']->updateStage($stageOneA, 'running', taskId: 'task-1');
        $fixture['loopStore']->updateStage($stageOneA, 'completed', taskId: 'task-1', artifactId: 'artifact-1', resultSummary: 'Drafted plan');
        $fixture['loopStore']->updateStage($stageOneB, 'running', taskId: 'task-2');
        $fixture['loopStore']->updateStage($stageOneB, 'failed', taskId: 'task-2', resultSummary: 'Review failed');
        $fixture['loopStore']->updateIterationStatus($iterationOne, 'needs_rework', 'First pass needs work');

        $iterationTwo = $fixture['loopStore']->createIteration($loopId, 2);
        $stageTwoA = $fixture['loopStore']->createStage($iterationTwo, 0, 'plan');
        $stageTwoB = $fixture['loopStore']->createStage($iterationTwo, 1, 'reviewer');
        $fixture['loopStore']->updateIterationStatus($iterationTwo, 'running');
        $fixture['loopStore']->updateStage($stageTwoA, 'running', taskId: 'task-3');
        $fixture['loopStore']->updateStage($stageTwoA, 'completed', taskId: 'task-3', artifactId: 'artifact-2', resultSummary: 'Updated plan');
        $fixture['loopStore']->updateStage($stageTwoB, 'running', taskId: 'task-4');
        $fixture['loopStore']->updateStage($stageTwoB, 'completed', taskId: 'task-4', resultSummary: 'Review passed');
        $fixture['loopStore']->updateIterationStatus($iterationTwo, 'completed', 'Approved');

        $fixture['loopStore']->updateLoopProgress($loopId, 2, 2);
        $fixture['loopStore']->updateLoopStatus($loopId, 'completed');

        $historyResponse = $fixture['handler']->history(
            new ServerRequest('GET', '/api/v1/loops/' . $loopId . '/history'),
            $loopId,
        );
        $historyBody = json_decode((string) $historyResponse->getBody(), true);

        $metricsResponse = $fixture['handler']->metrics(
            new ServerRequest('GET', '/api/v1/loops/' . $loopId . '/metrics'),
            $loopId,
        );
        $metricsBody = json_decode((string) $metricsResponse->getBody(), true);

        expect($historyResponse->getStatusCode())->toBe(200);
        expect($historyBody['count'])->toBe(2);
        expect($historyBody['history'][0]['iteration_number'])->toBe(1);
        expect($historyBody['history'][0]['stage_count'])->toBe(2);
        expect($historyBody['history'][0]['completed_stage_count'])->toBe(1);
        expect($historyBody['history'][0]['stages'][1]['status'])->toBe('failed');
        expect($historyBody['history'][1]['iteration_number'])->toBe(2);
        expect($historyBody['history'][1]['stages'][1]['status'])->toBe('completed');

        expect($metricsResponse->getStatusCode())->toBe(200);
        expect($metricsBody['status'])->toBe('completed');
        expect($metricsBody['iterations']['total'])->toBe(2);
        expect($metricsBody['iterations']['by_status']['needs_rework'])->toBe(1);
        expect($metricsBody['iterations']['by_status']['completed'])->toBe(1);
        expect($metricsBody['stages']['total'])->toBe(4);
        expect($metricsBody['stages']['by_status']['completed'])->toBe(3);
        expect($metricsBody['stages']['by_status']['failed'])->toBe(1);
        expect($metricsBody['stages']['by_role']['plan'])->toBe(2);
        expect($metricsBody['stages']['by_role']['reviewer'])->toBe(2);
        expect($metricsBody['timings']['iteration_timings'])->toHaveCount(2);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler exposes active loop count', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $runningLoopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'Track active loops',
            configuration: ['roles' => []],
        );

        $completedLoopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'Completed loop',
            configuration: ['roles' => []],
        );
        $fixture['loopStore']->updateLoopStatus($completedLoopId, 'completed');

        $response = $fixture['handler']->activeCount(
            new ServerRequest('GET', '/api/v1/loops/active/count'),
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['active'])->toBe(1);
        expect($fixture['loopStore']->getLoop($runningLoopId)['status'])->toBe('running');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler retries the latest failed iteration', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/loops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'definition' => 'harness',
                    'goal' => 'Recover a failed loop iteration',
                    'parameters' => ['subject' => 'loop recovery'],
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $createResponse->getBody(), true);
        $loopId = $createdBody['loop']['id'];
        $iterationId = $createdBody['iteration']['id'];
        $stages = $fixture['loopStore']->listStages($iterationId);

        $fixture['loopStore']->updateStage($stages[0]['id'], 'completed', taskId: 'task-plan', artifactId: 'artifact-plan', resultSummary: 'Plan done');
        $fixture['loopStore']->updateStage($stages[1]['id'], 'failed', taskId: 'task-review', resultSummary: 'Review failed');
        $fixture['loopStore']->updateIterationStatus($iterationId, 'failed', 'Reviewer rejected the work');
        $fixture['loopStore']->updateLoopStatus($loopId, 'failed');

        $response = $fixture['handler']->retryIteration(
            new ServerRequest('POST', '/api/v1/loops/' . $loopId . '/iterations/' . $iterationId . '/retry'),
            $loopId,
            $iterationId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['loop']['status'])->toBe('running');
        expect($body['iteration']['id'])->toBe($iterationId);
        expect($body['iteration']['status'])->toBe('running');
        expect($body['stages'][0]['status'])->toBe('pending');
        expect($body['stages'][0]['task_id'])->toBeNull();
        expect($body['stages'][0]['artifact_id'])->toBeNull();
        expect($body['stages'][1]['status'])->toBe('pending');
        expect($body['loop']['metadata']['dispatch']['status'])->toBe('pending');
        expect($body['loop']['metadata']['dispatch']['message'])->toContain('Operator retried');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('POST creates a definition and 409s on duplicate', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $body = json_encode([
            'name' => 'api-made',
            'description' => 'via api',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => 2],
        ]) ?: '';

        $created = $fixture['handler']->createDefinition(
            new ServerRequest('POST', '/api/v1/loops/definitions', ['Content-Type' => 'application/json'], $body)
        );
        expect($created->getStatusCode())->toBe(201);
        expect(json_decode((string) $created->getBody(), true)['name'])->toBe('api-made');

        $dup = $fixture['handler']->createDefinition(
            new ServerRequest('POST', '/api/v1/loops/definitions', ['Content-Type' => 'application/json'], $body)
        );
        expect($dup->getStatusCode())->toBe(409);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('POST 400s on an invalid name and invalid structure', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $badName = $fixture['handler']->createDefinition(new ServerRequest(
            'POST', '/api/v1/loops/definitions', [], json_encode(['name' => '../evil', 'roles' => []]) ?: ''
        ));
        expect($badName->getStatusCode())->toBe(400);

        $badShape = $fixture['handler']->createDefinition(new ServerRequest(
            'POST', '/api/v1/loops/definitions', [], json_encode(['name' => 'ok', 'roles' => []]) ?: ''
        ));
        expect($badShape->getStatusCode())->toBe(400); // empty roles / missing termination
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT upserts and GET/{name} returns raw; DELETE removes', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $body = json_encode([
            'description' => 'upserted',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => 2],
        ]) ?: '';

        $put = $fixture['handler']->updateDefinition(
            new ServerRequest('PUT', '/api/v1/loops/definitions/upsertme', ['Content-Type' => 'application/json'], $body),
            'upsertme'
        );
        expect($put->getStatusCode())->toBe(200);

        $got = $fixture['handler']->getDefinition(new ServerRequest('GET', '/api/v1/loops/definitions/upsertme'), 'upsertme');
        expect($got->getStatusCode())->toBe(200);
        expect(json_decode((string) $got->getBody(), true)['name'])->toBe('upsertme');

        $del = $fixture['handler']->deleteDefinition(new ServerRequest('DELETE', '/api/v1/loops/definitions/upsertme'), 'upsertme');
        expect($del->getStatusCode())->toBe(200);

        $missing = $fixture['handler']->getDefinition(new ServerRequest('GET', '/api/v1/loops/definitions/upsertme'), 'upsertme');
        expect($missing->getStatusCode())->toBe(404);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('definitions list marks builtin', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $body = json_decode((string) $fixture['handler']->definitions(
            new ServerRequest('GET', '/api/v1/loops/definitions')
        )->getBody(), true);
        $byName = [];
        foreach ($body['definitions'] as $d) { $byName[$d['name']] = $d; }
        // The fixture seeds a 'harness' definition into the workspace; it is a built-in.
        expect($byName['harness']['builtin'])->toBeTrue();
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler skips the current failed stage and reopens the iteration', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/loops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'definition' => 'harness',
                    'goal' => 'Skip a blocked stage',
                    'parameters' => ['subject' => 'loop recovery'],
                ]) ?: '',
            ),
        );
        $createdBody = json_decode((string) $createResponse->getBody(), true);
        $loopId = $createdBody['loop']['id'];
        $iterationId = $createdBody['iteration']['id'];
        $stages = $fixture['loopStore']->listStages($iterationId);

        $fixture['loopStore']->updateStage($stages[0]['id'], 'failed', taskId: 'task-plan', resultSummary: 'Planner got stuck');
        $fixture['loopStore']->updateIterationStatus($iterationId, 'failed', 'Planner got stuck');
        $fixture['loopStore']->updateLoopStatus($loopId, 'failed');

        $response = $fixture['handler']->skipStage(
            new ServerRequest('POST', '/api/v1/loops/' . $loopId . '/skip-stage'),
            $loopId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['loop']['status'])->toBe('running');
        expect($body['iteration']['status'])->toBe('running');
        expect($body['stages'][0]['status'])->toBe('completed');
        expect($body['stages'][0]['result_summary'])->toContain('SKIPPED');
        expect($body['stages'][1]['status'])->toBe('pending');
        expect($body['loop']['metadata']['dispatch']['status'])->toBe('pending');
        expect($body['loop']['metadata']['dispatch']['message'])->toContain('Operator skipped');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});