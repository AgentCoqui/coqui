<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\TodoStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Todo CRUD API endpoints.
 *
 * GET    /api/v1/sessions/{id}/todos              — list todos
 * POST   /api/v1/sessions/{id}/todos              — create todo
 * GET    /api/v1/sessions/{id}/todos/stats         — session todo stats
 * GET    /api/v1/sessions/{id}/todos/{todoId}      — get todo
 * PATCH  /api/v1/sessions/{id}/todos/{todoId}      — update todo
 * POST   /api/v1/sessions/{id}/todos/{todoId}/complete — complete todo
 * DELETE /api/v1/sessions/{id}/todos/{todoId}      — delete todo
 */
final readonly class TodoHandler
{
    public function __construct(
        private TodoStore $store,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/todos?status=pending&priority=high&artifact_id=...
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $params = $request->getQueryParams();
        $status = isset($params['status']) && trim((string) $params['status']) !== ''
            ? trim((string) $params['status']) : null;
        $priority = isset($params['priority']) && trim((string) $params['priority']) !== ''
            ? trim((string) $params['priority']) : null;
        $artifactId = isset($params['artifact_id']) && trim((string) $params['artifact_id']) !== ''
            ? trim((string) $params['artifact_id']) : null;
        $includeCompleted = ($params['include_completed'] ?? '1') !== '0';

        $todos = $this->store->list(
            sessionId: $id,
            artifactId: $artifactId,
            status: $status,
            priority: $priority,
            includeCompleted: $includeCompleted,
        );
        $stats = $this->store->getStats($id);

        return Router::jsonResponse([
            'todos' => $todos,
            'count' => count($todos),
            'stats' => $stats,
        ]);
    }

    /**
     * POST /api/v1/sessions/{id}/todos
     * { "title": "...", "priority"?: "high", "artifact_id"?: "...", "parent_id"?: "...", "notes"?: "...", "created_by"?: "..." }
     */
    public function create(ServerRequestInterface $request, string $id): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $title = isset($body['title']) ? trim((string) $body['title']) : '';
        if ($title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title is required');
        }

        $todoId = $this->store->create(
            sessionId: $id,
            title: $title,
            priority: (string) ($body['priority'] ?? 'medium'),
            artifactId: isset($body['artifact_id']) ? trim((string) $body['artifact_id']) : null,
            parentId: isset($body['parent_id']) ? trim((string) $body['parent_id']) : null,
            createdBy: isset($body['created_by']) ? trim((string) $body['created_by']) : null,
            notes: isset($body['notes']) ? trim((string) $body['notes']) : null,
        );

        $todo = $this->store->get($todoId);

        return Router::jsonResponse($todo ?? ['id' => $todoId], 201);
    }

    /**
     * GET /api/v1/sessions/{id}/todos/stats
     */
    public function stats(ServerRequestInterface $request, string $id): Response
    {
        $params = $request->getQueryParams();
        $artifactId = isset($params['artifact_id']) ? trim((string) $params['artifact_id']) : null;

        $stats = $this->store->getStats($id, $artifactId !== '' ? $artifactId : null);

        return Router::jsonResponse($stats);
    }

    /**
     * GET /api/v1/sessions/{id}/todos/{todoId}
     */
    public function get(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        $todo = $this->store->get($todoId);

        if ($todo === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $subtasks = $this->store->getSubtasks($todoId);

        return Router::jsonResponse([
            ...$todo,
            'subtasks' => $subtasks,
        ]);
    }

    /**
     * PATCH /api/v1/sessions/{id}/todos/{todoId}
     * { "title"?: "...", "status"?: "in_progress", "priority"?: "high", "notes"?: "..." }
     */
    public function update(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        $todo = $this->store->get($todoId);
        if ($todo === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $this->store->update(
            id: $todoId,
            title: isset($body['title']) ? trim((string) $body['title']) : null,
            status: isset($body['status']) ? trim((string) $body['status']) : null,
            priority: isset($body['priority']) ? trim((string) $body['priority']) : null,
            notes: isset($body['notes']) ? trim((string) $body['notes']) : null,
        );

        $updated = $this->store->get($todoId);

        return Router::jsonResponse($updated ?? $todo);
    }

    /**
     * POST /api/v1/sessions/{id}/todos/{todoId}/complete
     * { "completed_by"?: "coder", "notes"?: "..." }
     */
    public function complete(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        $todo = $this->store->get($todoId);
        if ($todo === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $body = json_decode((string) $request->getBody(), true);

        $this->store->complete(
            id: $todoId,
            completedBy: is_array($body) && isset($body['completed_by']) ? trim((string) $body['completed_by']) : null,
            notes: is_array($body) && isset($body['notes']) ? trim((string) $body['notes']) : null,
        );

        $updated = $this->store->get($todoId);

        return Router::jsonResponse($updated ?? $todo);
    }

    /**
     * DELETE /api/v1/sessions/{id}/todos/{todoId}
     */
    public function delete(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        $deleted = $this->store->delete($todoId);

        if (!$deleted) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        return Router::jsonResponse(['deleted' => true, 'id' => $todoId]);
    }
}
