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
        'handler' => new ProjectHandler($projectStore),
    ];
}

function cleanupApiProjectHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['projectStore'] = null;
    $fixture['storage'] = null;
    cleanupSqliteTestDb($fixture['dbPath']);
}

test('project handler lists projects with summary counts and status filters', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $activeProjectId = $fixture['projectStore']->createProject('Active Project', 'active-project');
        $archivedProjectId = $fixture['projectStore']->createProject('Archived Project', 'archived-project');
        $fixture['projectStore']->updateProject($archivedProjectId, status: 'archived');
        $fixture['projectStore']->createSprint($activeProjectId, 'Sprint 1');
        $fixture['projectStore']->createSprint($activeProjectId, 'Sprint 2');

        $response = $fixture['handler']->list(new ServerRequest('GET', '/api/v1/projects?status=active&limit=10'));
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['count'])->toBe(1);
        expect($body['projects'][0]['id'])->toBe($activeProjectId);
        expect($body['projects'][0]['sprint_count'])->toBe(2);
        expect($body['projects'][0]['sprints_completed'])->toBe(0);
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});

test('project handler get returns active sprint summary', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $projectId = $fixture['projectStore']->createProject('Website Refresh', 'website-refresh');
        $plannedSprintId = $fixture['projectStore']->createSprint($projectId, 'Plan Scope');
        $activeSprintId = $fixture['projectStore']->createSprint($projectId, 'Implement Homepage');
        $fixture['projectStore']->transitionSprint($activeSprintId, 'in_progress');

        $response = $fixture['handler']->get(new ServerRequest('GET', '/api/v1/projects/' . $projectId), $projectId);
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(200);
        expect($body['project']['id'])->toBe($projectId);
        expect($body['project']['sprint_count'])->toBe(2);
        expect($body['project']['active_sprint_id'])->toBe($activeSprintId);
        expect($body['active_sprint']['id'])->toBe($activeSprintId);
        expect($plannedSprintId)->not->toBe('');
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});

test('project handler returns project sprint listing and sprint detail', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $projectId = $fixture['projectStore']->createProject('Mobile App', 'mobile-app');
        $sprintId = $fixture['projectStore']->createSprint($projectId, 'MVP Sprint');

        $listResponse = $fixture['handler']->sprints(new ServerRequest('GET', '/api/v1/projects/mobile-app/sprints'), 'mobile-app');
        $listBody = json_decode((string) $listResponse->getBody(), true);
        $getResponse = $fixture['handler']->sprint(new ServerRequest('GET', '/api/v1/sprints/' . $sprintId), $sprintId);
        $getBody = json_decode((string) $getResponse->getBody(), true);

        expect($listResponse->getStatusCode())->toBe(200);
        expect($listBody['project']['slug'])->toBe('mobile-app');
        expect($listBody['count'])->toBe(1);
        expect($listBody['sprints'][0]['id'])->toBe($sprintId);

        expect($getResponse->getStatusCode())->toBe(200);
        expect($getBody['sprint']['id'])->toBe($sprintId);
        expect($getBody['project']['id'])->toBe($projectId);
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});