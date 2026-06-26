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
    $artifactStore = new ArtifactStore($storage->getPdo(), null, $projectStore);

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
                    'change_summary' => 'Added CRUD endpoint coverage',
                    'stage' => 'review',
                    'language' => 'markdown',
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
        expect($updateBody['stage'])->toBe('review');
        expect($updateBody['language'])->toBe('markdown');
        expect($updateBody['metadata']['tags'])->toBe(['api', 'flutter', 'crud']);
        expect($updateBody['metadata']['summary'])->toBe('Expanded app contract draft');
        expect((int) $updateBody['version'])->toBe(2);
    } finally {
        cleanupArtifactHandlerFixture($fixture);
    }
});

test('artifact handler creates versions and restores an older version', function () {
    $fixture = createArtifactHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $artifactId = $fixture['artifactStore']->create($sessionId, 'Plan', 'Version 1 content');

        $versionResponse = $fixture['handler']->createVersion(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/artifacts/' . $artifactId . '/versions',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'content' => 'Version 2 content',
                    'change_summary' => 'Expanded the plan',
                ]) ?: '',
            ),
            $sessionId,
            $artifactId,
        );
        $versionsResponse = $fixture['handler']->versions(
            new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/artifacts/' . $artifactId . '/versions'),
            $sessionId,
            $artifactId,
        );
        $versionsBody = json_decode((string) $versionsResponse->getBody(), true);
        $versionOneId = $versionsBody['versions'][1]['id'];

        $restoreResponse = $fixture['handler']->restoreVersion(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/artifacts/' . $artifactId . '/versions/' . $versionOneId . '/restore',
            ),
            $sessionId,
            $artifactId,
            $versionOneId,
        );
        $restoreBody = json_decode((string) $restoreResponse->getBody(), true);

        expect($versionResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $versionResponse->getBody(), true)['content'])->toBe('Version 2 content');
        expect($versionsResponse->getStatusCode())->toBe(200);
        expect($versionsBody['count'])->toBe(2);

        expect($restoreResponse->getStatusCode())->toBe(200);
        expect($restoreBody['content'])->toBe('Version 1 content');
        expect((int) $restoreBody['version'])->toBe(3);
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