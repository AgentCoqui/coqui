<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\ProjectHandler;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\ServerRequest;

function createApiProjectHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-project-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'projectStore' => $projectStore,
        'handler' => new ProjectHandler($projectStore, $storage),
    ];
}

function cleanupApiProjectHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['projectStore'] = null;
    $fixture['storage'] = null;
    cleanupSqliteTestDb($fixture['dbPath']);
}

test('project handler lists projects with status filters', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $activeProjectId = $fixture['projectStore']->createProject('Active Project', 'active-project');
        $archivedProjectId = $fixture['projectStore']->createProject('Archived Project', 'archived-project');
        $fixture['projectStore']->updateProject($archivedProjectId, status: 'archived');

        $response = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/projects?status=active&limit=10'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['count'])->toBe(1);
        expect($body['projects'][0]['id'])->toBe($activeProjectId);
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});

test('project handler get returns project detail', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $projectId = $fixture['projectStore']->createProject('Website Refresh', 'website-refresh');

        $response = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/projects/' . $projectId), $projectId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['project']['id'])->toBe($projectId);
        expect($body['project']['slug'])->toBe('website-refresh');
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});

test('project handler creates updates archives and deletes projects', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/projects',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'Career Ops',
                    'slug' => 'career-ops',
                    'description' => 'Career workflow system',
                ]) ?: '',
            ),
        );
        $createBody = json_decode((string) $createResponse->getBody(), true);
        $projectId = $createBody['project']['id'];

        $updateResponse = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/projects/career-ops',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'Career Ops Platform',
                    'description' => 'Career workflow system v2',
                ]) ?: '',
            ),
            'career-ops',
        );
        $archiveResponse = $fixture['handler']->archive(
            new ServerRequest('POST', '/api/v1/projects/' . $projectId . '/archive'),
            $projectId,
        );

        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->setActiveProject($sessionId, $projectId);

        $deleteResponse = $fixture['handler']->delete(
            new ServerRequest('DELETE', '/api/v1/projects/' . $projectId),
            $projectId,
        );
        $deleteBody = json_decode((string) $deleteResponse->getBody(), true);

        expect($createResponse->getStatusCode())->toBe(201);
        expect($updateResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $updateResponse->getBody(), true)['project']['title'])->toBe('Career Ops Platform');
        expect($archiveResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $archiveResponse->getBody(), true)['project']['status'])->toBe('archived');
        expect($deleteResponse->getStatusCode())->toBe(200);
        expect($deleteBody['deleted'])->toBeTrue();
        expect($fixture['projectStore']->getProject($projectId))->toBeNull();
        expect($fixture['storage']->getActiveProjectId($sessionId))->toBeNull();
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});
