<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\TodoStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Todo read-only API endpoints.
 *
 * GET    /api/v1/sessions/{id}/todos                    — list todos
 * GET    /api/v1/sessions/{id}/todos/stats               — session todo stats
 * GET    /api/v1/sessions/{id}/todos/{todoId}            — get todo
 *
 * Mutating operations (create, update, complete, delete, bulk ops) are REPL-only.
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
        $todo = $this->store->get($todoId, sessionId: $id);

        if ($todo === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $subtasks = $this->store->getSubtasks($todoId, sessionId: $id);

        return Router::jsonResponse([
            ...$todo,
            'subtasks' => $subtasks,
        ]);
    }
}
