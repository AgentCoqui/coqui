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
 * POST   /api/v1/sessions/{id}/todos/bulk          — bulk create todos
 * GET    /api/v1/sessions/{id}/todos/stats         — session todo stats
 * GET    /api/v1/sessions/{id}/todos/{todoId}      — get todo
 * PATCH  /api/v1/sessions/{id}/todos/{todoId}      — update todo
 * PATCH  /api/v1/sessions/{id}/todos/bulk           — bulk update todos
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
        if (mb_strlen($title) > 200) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Title must be 200 characters or less');
        }

        $priority = isset($body['priority']) ? trim((string) $body['priority']) : 'medium';
        if (!in_array($priority, ['high', 'medium', 'low'], true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid priority: must be high, medium, or low');
        }

        $todoId = $this->store->create(
            sessionId: $id,
            title: $title,
            priority: $priority,
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

        $title = isset($body['title']) ? trim((string) $body['title']) : null;
        if ($title !== null && mb_strlen($title) > 200) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Title must be 200 characters or less');
        }

        $status = isset($body['status']) ? trim((string) $body['status']) : null;
        if ($status !== null && !in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'], true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid status: must be pending, in_progress, completed, or cancelled');
        }

        $priority = isset($body['priority']) ? trim((string) $body['priority']) : null;
        if ($priority !== null && !in_array($priority, ['high', 'medium', 'low'], true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid priority: must be high, medium, or low');
        }

        $this->store->update(
            id: $todoId,
            title: $title,
            status: $status,
            priority: $priority,
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

    /**
     * POST /api/v1/sessions/{id}/todos/bulk
     * { "items": [{"title": "...", "priority"?: "high", "notes"?: "..."}], "artifact_id"?: "...", "created_by"?: "..." }
     */
    public function bulkCreate(ServerRequestInterface $request, string $id): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $items = $body['items'] ?? null;
        if (!is_array($items) || $items === []) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'items array is required and must be non-empty');
        }
        if (count($items) > 25) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Maximum 25 items per bulk create');
        }

        $validPriorities = ['high', 'medium', 'low'];
        foreach ($items as $i => $item) {
            if (!is_array($item)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Item %d: must be an object', $i + 1));
            }
            $title = isset($item['title']) ? trim((string) $item['title']) : '';
            if ($title === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, sprintf('Item %d: title is required', $i + 1));
            }
            if (mb_strlen($title) > 200) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Item %d: title must be 200 characters or less', $i + 1));
            }
            if (isset($item['priority']) && !in_array($item['priority'], $validPriorities, true)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Item %d: invalid priority', $i + 1));
            }
        }

        $normalized = array_values(array_map(function (array $item): array {
            $result = [
                'title' => trim((string) $item['title']),
                'priority' => $item['priority'] ?? 'medium',
            ];
            if (isset($item['notes']) && trim((string) $item['notes']) !== '') {
                $result['notes'] = trim((string) $item['notes']);
            }

            return $result;
        }, $items));

        $artifactId = isset($body['artifact_id']) ? trim((string) $body['artifact_id']) : null;
        $createdBy = isset($body['created_by']) ? trim((string) $body['created_by']) : null;

        $ids = $this->store->bulkCreate(
            sessionId: $id,
            items: $normalized,
            createdBy: $createdBy !== '' ? $createdBy : null,
            artifactId: $artifactId !== '' ? $artifactId : null,
        );

        return Router::jsonResponse([
            'created' => count($ids),
            'ids' => $ids,
        ], 201);
    }

    /**
     * PATCH /api/v1/sessions/{id}/todos/bulk
     * { "updates": [{"id": "...", "status"?: "...", "priority"?: "...", "title"?: "...", "notes"?: "..."}] }
     */
    public function bulkUpdate(ServerRequestInterface $request, string $id): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $updates = $body['updates'] ?? null;
        if (!is_array($updates) || $updates === []) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'updates array is required and must be non-empty');
        }
        if (count($updates) > 25) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Maximum 25 items per bulk update');
        }

        $validStatuses = ['pending', 'in_progress', 'completed', 'cancelled'];
        $validPriorities = ['high', 'medium', 'low'];
        /** @var list<array{id: string, title?: string, status?: string, priority?: string, notes?: string}> $typedUpdates */
        $typedUpdates = [];
        foreach ($updates as $i => $update) {
            if (!is_array($update)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Item %d: must be an object', $i + 1));
            }
            if (!isset($update['id']) || trim((string) $update['id']) === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, sprintf('Item %d: id is required', $i + 1));
            }
            if (isset($update['status']) && !in_array($update['status'], $validStatuses, true)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Item %d: invalid status', $i + 1));
            }
            if (isset($update['priority']) && !in_array($update['priority'], $validPriorities, true)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Item %d: invalid priority', $i + 1));
            }
            if (isset($update['title']) && mb_strlen(trim((string) $update['title'])) > 200) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Item %d: title must be 200 characters or less', $i + 1));
            }

            $typed = ['id' => (string) $update['id']];
            if (isset($update['title'])) {
                $typed['title'] = (string) $update['title'];
            }
            if (isset($update['status'])) {
                $typed['status'] = (string) $update['status'];
            }
            if (isset($update['priority'])) {
                $typed['priority'] = (string) $update['priority'];
            }
            if (isset($update['notes'])) {
                $typed['notes'] = (string) $update['notes'];
            }
            $typedUpdates[] = $typed;
        }

        $count = $this->store->bulkUpdate($typedUpdates);

        $stats = $this->store->getStats($id);

        return Router::jsonResponse([
            'updated' => $count,
            'total_requested' => count($updates),
            'stats' => $stats,
        ]);
    }
}
