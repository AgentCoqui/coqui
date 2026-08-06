<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\ArtifactHandler;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createArtifactHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-artifact-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());
    $artifactStore = artifactStoreForTest($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'projectStore' => $projectStore,
        'artifactStore' => $artifactStore,
        'handler' => new ArtifactHandler($artifactStore, $storage, $projectStore),
    ];
}

function cleanupArtifactHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['artifactStore'] = null;
    $fixture['projectStore'] = null;
    $fixture['storage'] = null;
    cleanupSqliteTestDb($fixture['dbPath']);
}

test('artifact handler creates and updates versioned artifacts with metadata', function () {
    $fixture = createArtifactHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $projectId = $fixture['projectStore']->createProject('Career Ops', 'career-ops');

        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/artifacts',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'API Contract',
                    'content' => 'Initial contract draft',
                    'type' => 'document',
                    'project_id' => $projectId,
                    'tags' => ['api', 'flutter'],
                    'summary' => 'App contract draft',
                ]) ?: '',
            ),
            $sessionId,
        );
        $createBody = json_decode((string) $createResponse->getBody(), true);
        $artifactId = $createBody['id'];

        $updateResponse = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId . '/artifacts/' . $artifactId,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'API Contract v2',
                    'content' => 'Expanded contract draft',
                    'tags' => ['api', 'flutter', 'crud'],
                    'summary' => 'Expanded app contract draft',
                ]) ?: '',
            ),
            $sessionId,
            $artifactId,
        );
        $updateBody = json_decode((string) $updateResponse->getBody(), true);

        expect($createResponse->getStatusCode())->toBe(201);
        expect($createBody['title'])->toBe('API Contract');
        expect($createBody['metadata']['tags'])->toBe(['api', 'flutter']);
        expect($createBody['metadata']['summary'])->toBe('App contract draft');
        expect($createBody['project_id'])->toBe($projectId);

        expect($updateResponse->getStatusCode())->toBe(200);
        expect($updateBody['title'])->toBe('API Contract v2');
        expect($updateBody['content'])->toBe('Expanded contract draft');
        expect($updateBody['metadata']['tags'])->toBe(['api', 'flutter', 'crud']);
        expect($updateBody['metadata']['summary'])->toBe('Expanded app contract draft');
        expect((int) $updateBody['version'])->toBe(2);
    } finally {
        cleanupArtifactHandlerFixture($fixture);
    }
});

test('artifact handler rejects a stage field in the PATCH body', function () {
    $fixture = createArtifactHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $artifactId = $fixture['artifactStore']->create($sessionId, 'Doc', 'body', 'document');

        $response = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId . '/artifacts/' . $artifactId,
                ['Content-Type' => 'application/json'],
                json_encode(['stage' => 'final']) ?: '',
            ),
            $sessionId,
            $artifactId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['error'])->toContain('stage');
    } finally {
        cleanupArtifactHandlerFixture($fixture);
    }
});

test('artifact handler ignores a ?stage= list filter', function () {
    $fixture = createArtifactHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['artifactStore']->create($sessionId, 'One', 'a', 'document');
        $fixture['artifactStore']->create($sessionId, 'Two', 'b', 'plan');

        $response = $fixture['handler']->list(
            new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/artifacts?stage=draft'),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body)->toHaveKeys(['data', 'next_cursor']);
        expect($body['data'])->toHaveCount(2);
    } finally {
        cleanupArtifactHandlerFixture($fixture);
    }
});

test('artifact handler deletes session scoped artifacts', function () {
    $fixture = createArtifactHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $artifactId = $fixture['artifactStore']->create($sessionId, 'Deletable', 'Content');

        $deleteResponse = $fixture['handler']->delete(
            new ServerRequest('DELETE', '/api/v1/sessions/' . $sessionId . '/artifacts/' . $artifactId),
            $sessionId,
            $artifactId,
        );

        expect($deleteResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $deleteResponse->getBody(), true)['deleted'])->toBeTrue();
        expect($fixture['artifactStore']->get($artifactId, $sessionId))->toBeNull();
    } finally {
        cleanupArtifactHandlerFixture($fixture);
    }
});

test('artifact handler rejects writes for closed sessions', function () {
    $fixture = createArtifactHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->closeSession($sessionId, 'history-rollover', true);

        $response = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/artifacts',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'Closed Session Artifact',
                    'content' => 'Should be rejected',
                ]) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('session_closed');
    } finally {
        cleanupArtifactHandlerFixture($fixture);
    }
});

test('artifact handler rejects reads for hidden sessions', function () {
    $fixture = createArtifactHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('learner', 'background-task', visibility: 'hidden');

        $response = $fixture['handler']->list(
            new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/artifacts'),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(404);
        expect($body['code'])->toBe('session_not_found');
    } finally {
        cleanupArtifactHandlerFixture($fixture);
    }
});