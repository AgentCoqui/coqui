<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\SessionProjectHandler;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createSessionProjectHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-session-project-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'projectStore' => $projectStore,
        'handler' => new SessionProjectHandler($storage, $projectStore),
    ];
}

function cleanupSessionProjectHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['projectStore'] = null;
    $fixture['storage'] = null;
    cleanupSqliteTestDb($fixture['dbPath']);
}

test('session project handler sets active project by slug and reads it back', function () {
    $fixture = createSessionProjectHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $projectId = $fixture['projectStore']->createProject('Career Ops', 'career-ops');

        $updateRequest = new ServerRequest(
            'PATCH',
            '/api/v1/sessions/' . $sessionId . '/project',
            ['Content-Type' => 'application/json'],
            json_encode(['project_slug' => 'career-ops']) ?: '',
        );

        $updateResponse = $fixture['handler']->update($updateRequest, $sessionId);
        $updateBody = json_decode((string) $updateResponse->getBody(), true);
        $getResponse = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/project'), $sessionId);
        $getBody = json_decode((string) $getResponse->getBody(), true);

        expect($updateResponse->getStatusCode())->toBe(200);
        expect($updateBody['active_project_id'])->toBe($projectId);
        expect($getResponse->getStatusCode())->toBe(200);
        expect($getBody['project']['slug'])->toBe('career-ops');
    } finally {
        cleanupSessionProjectHandlerFixture($fixture);
    }
});

test('session project handler clears active project', function () {
    $fixture = createSessionProjectHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $projectId = $fixture['projectStore']->createProject('Career Ops', 'career-ops');
        $fixture['storage']->setActiveProject($sessionId, $projectId);

        $response = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId . '/project',
                ['Content-Type' => 'application/json'],
                json_encode(['clear' => true]) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['active_project_id'])->toBeNull();
        expect($fixture['storage']->getActiveProjectId($sessionId))->toBeNull();
    } finally {
        cleanupSessionProjectHandlerFixture($fixture);
    }
});

test('session project handler validates conflicting update inputs', function () {
    $fixture = createSessionProjectHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');

        $response = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId . '/project',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'project_id' => 'abc',
                    'project_slug' => 'career-ops',
                ]) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(400);
        expect($body['code'])->toBe('validation_error');
    } finally {
        cleanupSessionProjectHandlerFixture($fixture);
    }
});

test('session project handler rejects updates for archived sessions', function () {
    $fixture = createSessionProjectHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->closeSession($sessionId, 'history-rollover', true);

        $response = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId . '/project',
                ['Content-Type' => 'application/json'],
                json_encode(['clear' => true]) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('session_closed');
        expect($body['details']['status'])->toBe('archived');
    } finally {
        cleanupSessionProjectHandlerFixture($fixture);
    }
});