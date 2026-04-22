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

test('project handler creates updates and transitions sprint lifecycle', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $projectId = $fixture['projectStore']->createProject('Mobile App', 'mobile-app');

        $createResponse = $fixture['handler']->createSprint(
            new ServerRequest(
                'POST',
                '/api/v1/projects/mobile-app/sprints',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'MVP Sprint',
                    'acceptance_criteria' => 'Core app shell is navigable.',
                    'contract_artifact_id' => 'artifact-contract',
                    'max_review_rounds' => 4,
                ]) ?: '',
            ),
            'mobile-app',
        );
        $createBody = json_decode((string) $createResponse->getBody(), true);
        $sprintId = $createBody['sprint']['id'];

        $updateResponse = $fixture['handler']->updateSprint(
            new ServerRequest(
                'PATCH',
                '/api/v1/sprints/' . $sprintId,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'MVP Sprint Alpha',
                    'max_review_rounds' => 5,
                ]) ?: '',
            ),
            $sprintId,
        );

        $startResponse = $fixture['handler']->startSprint(new ServerRequest('POST', '/api/v1/sprints/' . $sprintId . '/start'), $sprintId);
        $reviewResponse = $fixture['handler']->submitReview(new ServerRequest('POST', '/api/v1/sprints/' . $sprintId . '/submit-review'), $sprintId);
        $rejectResponse = $fixture['handler']->rejectSprint(
            new ServerRequest(
                'POST',
                '/api/v1/sprints/' . $sprintId . '/reject',
                ['Content-Type' => 'application/json'],
                json_encode(['reviewer_notes' => 'Needs stronger acceptance coverage.']) ?: '',
            ),
            $sprintId,
        );
        $restartResponse = $fixture['handler']->startSprint(new ServerRequest('POST', '/api/v1/sprints/' . $sprintId . '/start'), $sprintId);
        $resubmitResponse = $fixture['handler']->submitReview(new ServerRequest('POST', '/api/v1/sprints/' . $sprintId . '/submit-review'), $sprintId);
        $completeResponse = $fixture['handler']->completeSprint(new ServerRequest('POST', '/api/v1/sprints/' . $sprintId . '/complete'), $sprintId);

        expect($createResponse->getStatusCode())->toBe(201);
        expect($createBody['sprint']['status'])->toBe('planned');
        expect((int) $createBody['sprint']['max_review_rounds'])->toBe(4);

        $updatedBody = json_decode((string) $updateResponse->getBody(), true);
        expect($updateResponse->getStatusCode())->toBe(200);
        expect($updatedBody['sprint']['title'])->toBe('MVP Sprint Alpha');
        expect((int) $updatedBody['sprint']['max_review_rounds'])->toBe(5);

        expect(json_decode((string) $startResponse->getBody(), true)['sprint']['status'])->toBe('in_progress');
        expect(json_decode((string) $reviewResponse->getBody(), true)['sprint']['status'])->toBe('review');

        $rejectBody = json_decode((string) $rejectResponse->getBody(), true);
        expect($rejectBody['sprint']['status'])->toBe('rejected');
        expect($rejectBody['sprint']['reviewer_notes'])->toBe('Needs stronger acceptance coverage.');
        expect((int) $rejectBody['sprint']['review_round'])->toBe(1);

        expect(json_decode((string) $restartResponse->getBody(), true)['sprint']['status'])->toBe('in_progress');
        expect(json_decode((string) $resubmitResponse->getBody(), true)['sprint']['status'])->toBe('review');

        $completeBody = json_decode((string) $completeResponse->getBody(), true);
        expect($completeBody['sprint']['status'])->toBe('complete');
        expect($completeBody['project']['id'])->toBe($projectId);
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});

test('project handler only deletes planned sprints', function () {
    $fixture = createApiProjectHandlerFixture();

    try {
        $projectId = $fixture['projectStore']->createProject('Website Refresh', 'website-refresh');
        $plannedSprintId = $fixture['projectStore']->createSprint($projectId, 'Plan Scope');
        $activeSprintId = $fixture['projectStore']->createSprint($projectId, 'Implement Homepage');
        $fixture['projectStore']->transitionSprint($activeSprintId, 'in_progress');

        $plannedDelete = $fixture['handler']->deleteSprint(
            new ServerRequest('DELETE', '/api/v1/sprints/' . $plannedSprintId),
            $plannedSprintId,
        );
        $activeDelete = $fixture['handler']->deleteSprint(
            new ServerRequest('DELETE', '/api/v1/sprints/' . $activeSprintId),
            $activeSprintId,
        );

        expect($plannedDelete->getStatusCode())->toBe(200);
        expect($fixture['projectStore']->getSprint($plannedSprintId))->toBeNull();
        expect($activeDelete->getStatusCode())->toBe(409);
        expect($fixture['projectStore']->getSprint($activeSprintId))->not->toBeNull();
    } finally {
        cleanupApiProjectHandlerFixture($fixture);
    }
});