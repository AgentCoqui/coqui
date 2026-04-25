<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Handler\TodoHandler;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use React\Http\Message\ServerRequest;

function createTodoHandlerFixture(): array
{
    $dbPath = sys_get_temp_dir() . '/coqui-todo-handler-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $artifactStore = new ArtifactStore($storage->getPdo());
    $projectStore = new ProjectStore($storage->getPdo());
    $todoStore = new TodoStore($storage->getPdo());

    return [
        'dbPath' => $dbPath,
        'storage' => $storage,
        'artifactStore' => $artifactStore,
        'projectStore' => $projectStore,
        'todoStore' => $todoStore,
        'handler' => new TodoHandler($todoStore, $storage, $artifactStore, $projectStore),
    ];
}

function cleanupTodoHandlerFixture(array $fixture): void
{
    $fixture['handler'] = null;
    $fixture['todoStore'] = null;
    $fixture['projectStore'] = null;
    $fixture['artifactStore'] = null;
    $fixture['storage'] = null;
    cleanupSqliteTestDb($fixture['dbPath']);
}

test('todo handler creates and updates session scoped todos', function () {
    $fixture = createTodoHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $projectId = $fixture['projectStore']->createProject('Career Ops', 'career-ops');
        $sprintId = $fixture['projectStore']->createSprint($projectId, 'API Sprint');
        $artifactId = $fixture['artifactStore']->create(
            sessionId: $sessionId,
            title: 'API Plan',
            content: 'Initial plan',
            type: 'plan',
        );

        $createResponse = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/todos',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'Implement todo CRUD',
                    'priority' => 'high',
                    'notes' => 'Start with session-scoped routes.',
                    'artifact_id' => $artifactId,
                    'sprint_id' => $sprintId,
                    'sort_order' => 3,
                ]) ?: '',
            ),
            $sessionId,
        );
        $createBody = json_decode((string) $createResponse->getBody(), true);
        $todoId = $createBody['id'];

        $updateResponse = $fixture['handler']->update(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId . '/todos/' . $todoId,
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'Implement session todo CRUD',
                    'status' => 'in_progress',
                    'priority' => 'medium',
                    'notes' => 'PATCH and action routes wired.',
                ]) ?: '',
            ),
            $sessionId,
            $todoId,
        );
        $updateBody = json_decode((string) $updateResponse->getBody(), true);

        expect($createResponse->getStatusCode())->toBe(201);
        expect($createBody['title'])->toBe('Implement todo CRUD');
        expect($createBody['artifact_id'])->toBe($artifactId);
        expect($createBody['sprint_id'])->toBe($sprintId);
        expect($createBody['sort_order'])->toBe(3);
        expect($createBody['subtasks'])->toBe([]);

        expect($updateResponse->getStatusCode())->toBe(200);
        expect($updateBody['title'])->toBe('Implement session todo CRUD');
        expect($updateBody['status'])->toBe('in_progress');
        expect($updateBody['priority'])->toBe('medium');
        expect($updateBody['notes'])->toBe('PATCH and action routes wired.');
    } finally {
        cleanupTodoHandlerFixture($fixture);
    }
});

test('todo handler supports complete reopen cancel bulk update and reorder', function () {
    $fixture = createTodoHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $todoOne = $fixture['todoStore']->create($sessionId, 'First task');
        $todoTwo = $fixture['todoStore']->create($sessionId, 'Second task');

        $completeResponse = $fixture['handler']->complete(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/todos/' . $todoOne . '/complete',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'completed_by' => 'api',
                    'notes' => 'Finished from the app.',
                ]) ?: '',
            ),
            $sessionId,
            $todoOne,
        );
        $reopenResponse = $fixture['handler']->reopen(
            new ServerRequest('POST', '/api/v1/sessions/' . $sessionId . '/todos/' . $todoOne . '/reopen'),
            $sessionId,
            $todoOne,
        );
        $cancelResponse = $fixture['handler']->cancel(
            new ServerRequest('POST', '/api/v1/sessions/' . $sessionId . '/todos/' . $todoTwo . '/cancel'),
            $sessionId,
            $todoTwo,
        );

        $bulkResponse = $fixture['handler']->bulkUpdate(
            new ServerRequest(
                'PATCH',
                '/api/v1/sessions/' . $sessionId . '/todos/bulk',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'updates' => [
                        ['id' => $todoOne, 'status' => 'in_progress', 'priority' => 'high'],
                        ['id' => $todoTwo, 'status' => 'pending', 'priority' => 'low'],
                    ],
                ]) ?: '',
            ),
            $sessionId,
        );

        $reorderResponse = $fixture['handler']->reorder(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/todos/reorder',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'ordering' => [
                        ['id' => $todoOne, 'sort_order' => 9],
                        ['id' => $todoTwo, 'sort_order' => 1],
                    ],
                ]) ?: '',
            ),
            $sessionId,
        );

        expect(json_decode((string) $completeResponse->getBody(), true)['status'])->toBe('completed');
        expect(json_decode((string) $reopenResponse->getBody(), true)['status'])->toBe('pending');
        expect(json_decode((string) $cancelResponse->getBody(), true)['status'])->toBe('cancelled');

        expect($bulkResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $bulkResponse->getBody(), true)['updated_count'])->toBe(2);

        expect($reorderResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $reorderResponse->getBody(), true)['reordered_count'])->toBe(2);
        expect($fixture['todoStore']->get($todoOne, $sessionId)['sort_order'])->toBe(9);
        expect($fixture['todoStore']->get($todoTwo, $sessionId)['sort_order'])->toBe(1);
        expect($fixture['todoStore']->get($todoOne, $sessionId)['status'])->toBe('in_progress');
        expect($fixture['todoStore']->get($todoTwo, $sessionId)['status'])->toBe('pending');
    } finally {
        cleanupTodoHandlerFixture($fixture);
    }
});

test('todo handler delete cascades to subtasks', function () {
    $fixture = createTodoHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $parentId = $fixture['todoStore']->create($sessionId, 'Parent task');
        $childId = $fixture['todoStore']->create($sessionId, 'Child task', parentId: $parentId);

        $deleteResponse = $fixture['handler']->delete(
            new ServerRequest('DELETE', '/api/v1/sessions/' . $sessionId . '/todos/' . $parentId),
            $sessionId,
            $parentId,
        );

        expect($deleteResponse->getStatusCode())->toBe(200);
        expect(json_decode((string) $deleteResponse->getBody(), true)['deleted'])->toBeTrue();
        expect($fixture['todoStore']->get($parentId, $sessionId))->toBeNull();
        expect($fixture['todoStore']->get($childId, $sessionId))->toBeNull();
    } finally {
        cleanupTodoHandlerFixture($fixture);
    }
});

test('todo handler rejects writes for closed sessions', function () {
    $fixture = createTodoHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('orchestrator', 'ollama/qwen3:latest');
        $fixture['storage']->closeSession($sessionId, 'history-rollover', true);

        $response = $fixture['handler']->create(
            new ServerRequest(
                'POST',
                '/api/v1/sessions/' . $sessionId . '/todos',
                ['Content-Type' => 'application/json'],
                json_encode([
                    'title' => 'Write todo from archived session',
                ]) ?: '',
            ),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(409);
        expect($body['code'])->toBe('session_closed');
    } finally {
        cleanupTodoHandlerFixture($fixture);
    }
});

test('todo handler rejects reads for hidden sessions', function () {
    $fixture = createTodoHandlerFixture();

    try {
        $sessionId = $fixture['storage']->createSession('learner', 'background-task', visibility: 'hidden');

        $response = $fixture['handler']->stats(
            new ServerRequest('GET', '/api/v1/sessions/' . $sessionId . '/todos/stats'),
            $sessionId,
        );
        $body = json_decode((string) $response->getBody(), true);

        expect($response->getStatusCode())->toBe(404);
        expect($body['code'])->toBe('session_not_found');
    } finally {
        cleanupTodoHandlerFixture($fixture);
    }
});