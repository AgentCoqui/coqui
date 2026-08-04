<?php

declare(strict_types=1);

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\Handler\LoopHandler;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ObjectVersionStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

/**
 * Build a PUT request for a loop definition, with optional precondition headers.
 *
 * @param array<string, mixed> $body
 */
function loopDefPutRequest(string $name, array $body, ?string $ifNoneMatch = null, ?int $ifMatch = null): ServerRequest
{
    $headers = ['Content-Type' => 'application/json'];
    if ($ifNoneMatch !== null) {
        $headers['If-None-Match'] = $ifNoneMatch;
    }
    if ($ifMatch !== null) {
        $headers['If-Match'] = (string) $ifMatch;
    }

    return new ServerRequest('PUT', '/api/v1/loops/definitions/' . $name, $headers, json_encode($body) ?: '');
}

/**
 * A minimal, valid loop-definition authoring body (loop-definition.put.json).
 *
 * @return array<string, mixed>
 */
function validLoopDefBody(string $name = 'ci'): array
{
    return [
        'name' => $name,
        'description' => 'CI loop',
        'roles' => [['role' => 'plan', 'prompt' => 'go']],
        'termination_condition' => ['type' => 'iteration_bound', 'value' => 2],
    ];
}

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

    // A loop definition whose sole role hard-requires a durable artifact. Used to
    // exercise the persona artifacts-capability gate at loop creation (CORE-22).
    file_put_contents(
        $workspacePath . '/loops/artifact-gated.json',
        json_encode([
            'name' => 'artifact-gated',
            'description' => 'A loop stage that must produce a durable artifact',
            'roles' => [
                ['role' => 'plan', 'prompt' => 'Produce a durable artifact.', 'artifact_required' => true],
            ],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => 2],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '',
    );

    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());
    $loopStore = new LoopStore($storage->getPdo());
    $discovery = new LoopDiscovery($workspacePath);
    $executor = new LoopExecutor($loopStore, $projectStore, $storage);
    $personaDiscovery = new PersonaDiscovery($workspacePath);
    $objectVersions = new ObjectVersionStore($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'workspacePath' => $workspacePath,
        'storage' => $storage,
        'projectStore' => $projectStore,
        'loopStore' => $loopStore,
        'personaDiscovery' => $personaDiscovery,
        'objectVersions' => $objectVersions,
        'handler' => new LoopHandler($loopStore, $discovery, $executor, $storage, $projectStore, $personaDiscovery, $objectVersions),
    ];
}

/**
 * Materialize a persona under the fixture workspace with an explicit artifacts
 * feature flag, returning the persona name (used as a session persona_id).
 */
function writeLoopPersona(string $workspacePath, string $name, bool $artifactsEnabled): string
{
    $dir = $workspacePath . '/personas/' . $name;
    mkdir($dir, 0755, true);
    file_put_contents($dir . '/soul.md', "# {$name}\n\nA test persona.\n");
    file_put_contents(
        $dir . '/preferences.json',
        json_encode(['prompts' => ['features' => ['artifacts' => $artifactsEnabled]]]) ?: '',
    );

    return $name;
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

        $byName = [];
        foreach ($body['definitions'] as $definition) {
            $byName[$definition['name']] = $definition;
        }

        expect($response->getStatusCode())->toBe(200);
        expect($byName['harness']['parameters'])->toHaveCount(2);
        expect($byName['harness']['parameters'][0]['name'])->toBe('subject');
        expect($byName['harness']['parameters'][1]['default'])->toBe('php');
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
        // Project (D3) is no longer a loop column; the resolved project rides in configuration.
        expect($configuration['resolved_project_id'])->toBe($projectId);
        expect($body['iteration']['status'])->toBe('running');
        expect($body['stages'])->toHaveCount(2);
        expect((int) $storedLoop['max_iterations'])->toBe(2);
        expect($configuration['roles'][0]['prompt'])->toContain('loop lifecycle API');
        expect($configuration['roles'][0]['prompt'])->toContain('php');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler create rejects an artifact_required loop when the session persona disables artifacts', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $persona = writeLoopPersona($fixture['workspacePath'], 'capped', artifactsEnabled: false);
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', $persona);

        $request = new ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'artifact-gated',
                'goal' => 'Produce a durable artifact',
                'session_id' => $sessionId,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(422);
        expect($body['code'])->toBe('validation_error');
        expect($body['details']['capability'])->toBe('artifacts');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler accepts an artifact_required loop when the session persona enables artifacts', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $persona = writeLoopPersona($fixture['workspacePath'], 'creator', artifactsEnabled: true);
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest', $persona);

        $request = new ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'artifact-gated',
                'goal' => 'Produce a durable artifact',
                'session_id' => $sessionId,
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);

        expect($response->getStatusCode())->toBe(201);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('loop handler accepts an artifact_required loop with no session (ungated headless)', function () {
    $fixture = createLoopHandlerFixture();

    try {
        $request = new ServerRequest(
            'POST',
            '/api/v1/loops',
            ['Content-Type' => 'application/json'],
            json_encode([
                'definition' => 'artifact-gated',
                'goal' => 'Produce a durable artifact',
            ]) ?: '',
        );

        $response = $fixture['handler']->create($request);

        expect($response->getStatusCode())->toBe(201);
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

test('PUT create via If-None-Match:* seeds version 1', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $created = $fixture['handler']->putDefinition(
            loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'),
            'ci',
        );

        expect($created->getStatusCode())->toBe(201);
        $body = json_decode((string) $created->getBody(), true);
        expect($body['name'])->toBe('ci');
        expect($body['version'])->toBe(1);

        // The served definition (GET) also carries the version.
        $got = $fixture['handler']->getDefinition(new ServerRequest('GET', '/api/v1/loops/definitions/ci'), 'ci');
        expect($got->getStatusCode())->toBe(200);
        expect(json_decode((string) $got->getBody(), true)['version'])->toBe(1);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT create conflicts (409) when the definition already exists', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'), 'ci');

        $dup = $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'), 'ci');
        expect($dup->getStatusCode())->toBe(409);
        expect(json_decode((string) $dup->getBody(), true)['code'])->toBe('conflict');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT update via If-Match bumps to version 2', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'), 'ci');

        $updated = $fixture['handler']->putDefinition(
            loopDefPutRequest('ci', validLoopDefBody('ci') + ['description' => 'updated'], ifMatch: 1),
            'ci',
        );
        expect($updated->getStatusCode())->toBe(200);
        expect(json_decode((string) $updated->getBody(), true)['version'])->toBe(2);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT update with a stale If-Match returns 409 version_conflict', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'), 'ci');
        $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifMatch: 1), 'ci');

        $stale = $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifMatch: 1), 'ci');
        expect($stale->getStatusCode())->toBe(409);
        $body = json_decode((string) $stale->getBody(), true);
        expect($body['code'])->toBe('version_conflict');
        expect($body['details']['current_version'])->toBe(2);
        expect($body['details']['expected_version'])->toBe(1);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT with a body carrying a server-owned field is a 422', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $response = $fixture['handler']->putDefinition(
            loopDefPutRequest('ci', validLoopDefBody('ci') + ['version' => 7, 'id' => 'loopdef_ci'], ifNoneMatch: '*'),
            'ci',
        );
        expect($response->getStatusCode())->toBe(422);
        $body = json_decode((string) $response->getBody(), true);
        expect($body['code'])->toBe('validation_error');
        expect($body['details']['unexpected_fields'])->toContain('version');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT without a precondition header is a 409 (precondition required)', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $response = $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci')), 'ci');
        expect($response->getStatusCode())->toBe(409);
        $body = json_decode((string) $response->getBody(), true);
        expect($body['code'])->toBe('conflict');
        expect($body['details']['reason'])->toBe('precondition_required');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT update If-Match on a missing definition is a 404 content_not_found', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $response = $fixture['handler']->putDefinition(loopDefPutRequest('ghost', validLoopDefBody('ghost'), ifMatch: 1), 'ghost');
        expect($response->getStatusCode())->toBe(404);
        expect(json_decode((string) $response->getBody(), true)['code'])->toBe('content_not_found');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('create then delete then recreate seeds a fresh version 1', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $first = $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'), 'ci');
        expect(json_decode((string) $first->getBody(), true)['version'])->toBe(1);
        // Bump it to version 2 so the counter is non-trivial before deletion.
        $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifMatch: 1), 'ci');

        $del = $fixture['handler']->deleteDefinition(new ServerRequest('DELETE', '/api/v1/loops/definitions/ci'), 'ci');
        expect($del->getStatusCode())->toBe(200);

        // Recreate must succeed and seed a fresh counter at version 1 (the
        // delete cleared the version-counter row).
        $recreated = $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'), 'ci');
        expect($recreated->getStatusCode())->toBe(201);
        expect(json_decode((string) $recreated->getBody(), true)['version'])->toBe(1);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('the on-disk definition file never persists a version token', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $fixture['handler']->putDefinition(loopDefPutRequest('ci', validLoopDefBody('ci'), ifNoneMatch: '*'), 'ci');

        $onDisk = json_decode((string) file_get_contents($fixture['workspacePath'] . '/loops/ci.json'), true);
        expect($onDisk)->not->toHaveKey('version');
        expect($onDisk['name'])->toBe('ci');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('PUT rejects an invalid definition name (path traversal) with 422', function (): void {
    $fixture = createLoopHandlerFixture();
    try {
        $response = $fixture['handler']->putDefinition(
            loopDefPutRequest('../evil', validLoopDefBody('evil'), ifNoneMatch: '*'),
            '../evil',
        );
        expect($response->getStatusCode())->toBe(422);
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
test('GET /loops/{id}/live returns a snapshot for a known loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $loopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'do the thing',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 3,
        );

        $response = $fixture['handler']->live(
            new ServerRequest('GET', "/api/v1/loops/{$loopId}/live"),
            $loopId,
        );
        expect($response->getStatusCode())->toBe(200);

        $body = json_decode((string) $response->getBody(), true);
        expect($body['loop']['id'])->toBe($loopId);
        expect($body['loop']['goal'])->toBe('do the thing');
        expect($body['budget']['iterations'])->toBe(['used' => 0, 'max' => 3]);
        expect($body)->toHaveKeys(['loop', 'position', 'current_stage', 'budget', 'stages', 'recent_events']);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops flags and filters headless loops', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        // Headless loop (no session) and a conversation loop.
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/x');
        $rawHarness = [
            'name' => 'harness',
            'description' => 't',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => 2],
        ];
        $headlessLoop = (new CoquiBot\Coqui\Agent\LoopExecutor(
            $fixture['loopStore'], $fixture['projectStore'], $fixture['storage']
        ))->startLoop($rawHarness, 'headless goal');
        $convLoop = (new CoquiBot\Coqui\Agent\LoopExecutor(
            $fixture['loopStore'], $fixture['projectStore'], $fixture['storage']
        ))->startLoop($rawHarness, 'conv goal', $sessionId);

        $all = json_decode((string) $fixture['handler']->list(new ServerRequest('GET', '/api/v1/loops'))->getBody(), true);
        $byId = [];
        foreach ($all['loops'] as $l) { $byId[$l['id']] = $l; }
        expect($byId[$headlessLoop]['headless'])->toBeTrue();
        expect($byId[$convLoop]['headless'])->toBeFalse();

        $filtered = json_decode((string) $fixture['handler']->list(
            new ServerRequest('GET', '/api/v1/loops?headless=true')
        )->getBody(), true);
        $ids = array_map(static fn(array $l): string => $l['id'], $filtered['loops']);
        expect($ids)->toContain($headlessLoop);
        expect($ids)->not->toContain($convLoop);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops/{id}/live 404s for an unknown loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $response = $fixture['handler']->live(
            new ServerRequest('GET', '/api/v1/loops/nope/live'),
            'nope',
        );
        expect($response->getStatusCode())->toBe(404);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops/{id} includes origin', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $rawHarness = [
            'name' => 'harness', 'description' => 't',
            'roles' => [['role' => 'plan', 'prompt' => 'go']],
            'termination_condition' => ['type' => 'iteration_bound', 'value' => 2],
        ];
        $loopId = (new CoquiBot\Coqui\Agent\LoopExecutor(
            $fixture['loopStore'], $fixture['projectStore'], $fixture['storage']
        ))->startLoop($rawHarness, 'g');

        $body = json_decode((string) $fixture['handler']->get(
            new ServerRequest('GET', "/api/v1/loops/{$loopId}"), $loopId
        )->getBody(), true);
        expect($body['loop']['origin'])->toBe('headless');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops/{id}/events 404s for an unknown loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $response = $fixture['handler']->events(
            new ServerRequest('GET', '/api/v1/loops/nope/events'),
            'nope',
        );
        expect($response->getStatusCode())->toBe(404);
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});

test('GET /loops/{id}/events returns an SSE stream for a terminal loop', function (): void {
    $fixture = createLoopHandlerFixture();

    try {
        $loopId = $fixture['loopStore']->createLoop(
            definitionName: 'harness',
            goal: 'done already',
            configuration: ['roles' => [['role' => 'plan']]],
            maxIterations: 2,
        );
        // Terminal at open → connected + done, no timer registered.
        $fixture['loopStore']->updateLoopStatus($loopId, 'completed');

        $response = $fixture['handler']->events(
            new ServerRequest('GET', "/api/v1/loops/{$loopId}/events"),
            $loopId,
        );
        expect($response->getStatusCode())->toBe(200);
        expect($response->getHeaderLine('Content-Type'))->toBe('text/event-stream');
    } finally {
        cleanupLoopHandlerFixture($fixture);
    }
});
