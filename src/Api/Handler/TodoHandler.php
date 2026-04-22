<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
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
    /** @var list<string> */
    private const array ALLOWED_STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    /** @var list<string> */
    private const array ALLOWED_PRIORITIES = ['high', 'medium', 'low'];

    public function __construct(
        private TodoStore $store,
        private ?SessionStorage $sessionStorage = null,
        private ?ArtifactStore $artifactStore = null,
        private ?ProjectStore $projectStore = null,
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
        return $this->todoDetailResponse($id, $todoId);
    }

    /**
     * POST /api/v1/sessions/{id}/todos
     */
    public function create(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title is required');
        }

        $priority = array_key_exists('priority', $body)
            ? strtolower(trim((string) $body['priority']))
            : 'medium';
        if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'priority must be high, medium, or low');
        }

        $notes = array_key_exists('notes', $body) ? $this->nullableString($body['notes']) : null;
        $artifactId = array_key_exists('artifact_id', $body) ? $this->nullableId($body['artifact_id']) : null;
        $parentId = array_key_exists('parent_id', $body) ? $this->nullableId($body['parent_id']) : null;
        $sprintId = array_key_exists('sprint_id', $body) ? $this->nullableId($body['sprint_id']) : null;
        $createdBy = array_key_exists('created_by', $body) ? $this->nullableString($body['created_by']) : null;
        $sortOrder = null;

        if (array_key_exists('sort_order', $body)) {
            if (!is_int($body['sort_order']) && !is_string($body['sort_order']) && !is_float($body['sort_order'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'sort_order must be a non-negative integer');
            }

            $sortOrder = (int) $body['sort_order'];
            if ($sortOrder < 0) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'sort_order must be a non-negative integer');
            }
        }

        if ($artifactId !== null) {
            $artifactResponse = $this->validateArtifactLink($id, $artifactId);
            if ($artifactResponse instanceof Response) {
                return $artifactResponse;
            }
        }

        if ($parentId !== null && $this->store->get($parentId, sessionId: $id) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Parent todo not found');
        }

        if ($sprintId !== null) {
            $sprintResponse = $this->validateSprintLink($sprintId);
            if ($sprintResponse instanceof Response) {
                return $sprintResponse;
            }
        }

        $todoId = $this->store->create(
            sessionId: $id,
            title: $title,
            priority: $priority,
            artifactId: $artifactId,
            parentId: $parentId,
            createdBy: $createdBy,
            notes: $notes,
            sortOrder: $sortOrder,
            sprintId: $sprintId,
        );

        return $this->todoDetailResponse($id, $todoId, 201);
    }

    /**
     * PATCH /api/v1/sessions/{id}/todos/{todoId}
     */
    public function update(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $todo = $this->store->get($todoId, sessionId: $id);
        if ($todo === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $allowedKeys = ['title', 'priority', 'notes', 'status', 'artifact_id', 'parent_id', 'sprint_id', 'sort_order'];
        $unknownKeys = array_values(array_filter(
            array_keys($body),
            static fn(string $key): bool => !in_array($key, $allowedKeys, true),
        ));
        if ($unknownKeys !== []) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown todo patch fields: %s', implode(', ', $unknownKeys)),
            );
        }

        if ($body === []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'At least one patch field is required');
        }

        $patch = [];

        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'title cannot be empty');
            }

            $patch['title'] = $title;
        }

        if (array_key_exists('priority', $body)) {
            $priority = strtolower(trim((string) $body['priority']));
            if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'priority must be high, medium, or low');
            }

            $patch['priority'] = $priority;
        }

        if (array_key_exists('notes', $body)) {
            $patch['notes'] = $this->nullableString($body['notes']);
        }

        if (array_key_exists('status', $body)) {
            $status = strtolower(trim((string) $body['status']));
            if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'status must be pending, in_progress, completed, or cancelled');
            }

            $patch['status'] = $status;
        }

        if (array_key_exists('artifact_id', $body)) {
            $artifactId = $this->nullableId($body['artifact_id']);
            if ($artifactId !== null) {
                $artifactResponse = $this->validateArtifactLink($id, $artifactId);
                if ($artifactResponse instanceof Response) {
                    return $artifactResponse;
                }
            }

            $patch['artifact_id'] = $artifactId;
        }

        if (array_key_exists('parent_id', $body)) {
            $parentId = $this->nullableId($body['parent_id']);
            if ($parentId !== null) {
                if ($parentId === $todoId) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'A todo cannot be its own parent');
                }

                if ($this->store->get($parentId, sessionId: $id) === null) {
                    return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Parent todo not found');
                }
            }

            $patch['parent_id'] = $parentId;
        }

        if (array_key_exists('sprint_id', $body)) {
            $sprintId = $this->nullableId($body['sprint_id']);
            if ($sprintId !== null) {
                $sprintResponse = $this->validateSprintLink($sprintId);
                if ($sprintResponse instanceof Response) {
                    return $sprintResponse;
                }
            }

            $patch['sprint_id'] = $sprintId;
        }

        if (array_key_exists('sort_order', $body)) {
            if (!is_int($body['sort_order']) && !is_string($body['sort_order']) && !is_float($body['sort_order'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'sort_order must be a non-negative integer');
            }

            $sortOrder = (int) $body['sort_order'];
            if ($sortOrder < 0) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'sort_order must be a non-negative integer');
            }

            $patch['sort_order'] = $sortOrder;
        }

        $this->store->patch($todoId, $patch, $id);

        return $this->todoDetailResponse($id, $todoId);
    }

    /**
     * DELETE /api/v1/sessions/{id}/todos/{todoId}
     */
    public function delete(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        if ($this->store->get($todoId, sessionId: $id) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $this->store->delete($todoId, $id);

        return Router::jsonResponse([
            'deleted' => true,
            'id' => $todoId,
        ]);
    }

    /**
     * POST /api/v1/sessions/{id}/todos/{todoId}/complete
     */
    public function complete(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        return $this->todoActionResponse($request, $id, $todoId, 'completed');
    }

    /**
     * POST /api/v1/sessions/{id}/todos/{todoId}/reopen
     */
    public function reopen(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        return $this->todoActionResponse($request, $id, $todoId, 'pending');
    }

    /**
     * POST /api/v1/sessions/{id}/todos/{todoId}/cancel
     */
    public function cancel(ServerRequestInterface $request, string $id, string $todoId): Response
    {
        return $this->todoActionResponse($request, $id, $todoId, 'cancelled');
    }

    /**
     * PATCH /api/v1/sessions/{id}/todos/bulk
     */
    public function bulkUpdate(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $updates = $body['updates'] ?? null;
        if (!is_array($updates) || $updates === []) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'updates is required');
        }

        if (count($updates) > 25) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'updates cannot contain more than 25 items');
        }

        $normalized = [];
        foreach ($updates as $update) {
            if (!is_array($update)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Each update must be an object');
            }

            $todoId = trim((string) ($update['id'] ?? ''));
            if ($todoId === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Each update requires an id');
            }

            $entry = ['id' => $todoId];
            $fieldCount = 0;

            if (array_key_exists('title', $update)) {
                $title = trim((string) $update['title']);
                if ($title === '') {
                    return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Bulk update titles cannot be empty');
                }

                $entry['title'] = $title;
                $fieldCount++;
            }

            if (array_key_exists('priority', $update)) {
                $priority = strtolower(trim((string) $update['priority']));
                if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'priority must be high, medium, or low');
                }

                $entry['priority'] = $priority;
                $fieldCount++;
            }

            if (array_key_exists('notes', $update)) {
                $entry['notes'] = $this->nullableString($update['notes']);
                $fieldCount++;
            }

            if (array_key_exists('status', $update)) {
                $status = strtolower(trim((string) $update['status']));
                if (!in_array($status, self::ALLOWED_STATUSES, true)) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'status must be pending, in_progress, completed, or cancelled');
                }

                $entry['status'] = $status;
                $fieldCount++;
            }

            if ($fieldCount === 0) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Each bulk update requires at least one mutable field');
            }

            $normalized[] = $entry;
        }

        $updatedCount = $this->store->bulkUpdate($normalized, $id);

        return Router::jsonResponse([
            'updated_count' => $updatedCount,
        ]);
    }

    /**
     * POST /api/v1/sessions/{id}/todos/reorder
     */
    public function reorder(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->requireWritableSession($id);
        if ($session instanceof Response) {
            return $session;
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $ordering = $body['ordering'] ?? null;
        if (!is_array($ordering) || $ordering === []) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'ordering is required');
        }

        $map = [];
        foreach ($ordering as $item) {
            if (!is_array($item)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Each ordering entry must be an object');
            }

            $todoId = trim((string) ($item['id'] ?? ''));
            if ($todoId === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Each ordering entry requires an id');
            }

            if (!array_key_exists('sort_order', $item) || (!is_int($item['sort_order']) && !is_string($item['sort_order']) && !is_float($item['sort_order']))) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Each ordering entry requires a non-negative sort_order');
            }

            $sortOrder = (int) $item['sort_order'];
            if ($sortOrder < 0) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Each ordering entry requires a non-negative sort_order');
            }

            $map[$todoId] = $sortOrder;
        }

        $this->store->reorder($map, $id);

        return Router::jsonResponse([
            'reordered_count' => count($map),
        ]);
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function requireWritableSession(string $sessionId): array|Response
    {
        if ($this->sessionStorage === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return SessionAccess::requireWritableSession($this->sessionStorage, $sessionId);
    }

    private function todoDetailResponse(string $sessionId, string $todoId, int $status = 200): Response
    {
        $todo = $this->store->get($todoId, sessionId: $sessionId);

        if ($todo === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $subtasks = $this->store->getSubtasks($todoId, sessionId: $sessionId);

        return Router::jsonResponse([
            ...$todo,
            'subtasks' => $subtasks,
        ], $status);
    }

    private function todoActionResponse(ServerRequestInterface $request, string $sessionId, string $todoId, string $targetStatus): Response
    {
        $session = $this->requireWritableSession($sessionId);
        if ($session instanceof Response) {
            return $session;
        }

        $todo = $this->store->get($todoId, sessionId: $sessionId);
        if ($todo === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Todo not found');
        }

        $currentStatus = (string) ($todo['status'] ?? 'pending');

        if ($targetStatus === 'completed' && !in_array($currentStatus, ['pending', 'in_progress'], true)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Cannot complete todo while status is "%s".', $currentStatus));
        }

        if ($targetStatus === 'pending' && !in_array($currentStatus, ['completed', 'cancelled'], true)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Cannot reopen todo while status is "%s".', $currentStatus));
        }

        if ($targetStatus === 'cancelled' && !in_array($currentStatus, ['pending', 'in_progress'], true)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Cannot cancel todo while status is "%s".', $currentStatus));
        }

        $body = $this->requestBody($request);
        $notes = is_array($body) && array_key_exists('notes', $body) ? $this->nullableString($body['notes']) : null;
        $completedBy = is_array($body) && array_key_exists('completed_by', $body) ? $this->nullableString($body['completed_by']) : null;

        if ($targetStatus === 'completed') {
            $this->store->complete($todoId, $completedBy, $notes, $sessionId);
        } else {
            $patch = ['status' => $targetStatus];
            if ($notes !== null || (is_array($body) && array_key_exists('notes', $body))) {
                $patch['notes'] = $notes;
            }

            $this->store->patch($todoId, $patch, $sessionId);
        }

        return $this->todoDetailResponse($sessionId, $todoId);
    }

    private function validateArtifactLink(string $sessionId, string $artifactId): ?Response
    {
        if ($this->artifactStore !== null && $this->artifactStore->get($artifactId, $sessionId) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Artifact not found');
        }

        return null;
    }

    private function validateSprintLink(string $sprintId): ?Response
    {
        if ($this->projectStore !== null && $this->projectStore->getSprint($sprintId) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function nullableId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
