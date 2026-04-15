<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Session CRUD endpoints.
 *
 * GET    /api/v1/sessions                    — list sessions
 * POST   /api/v1/sessions                    — create session
 * GET    /api/v1/sessions/{id}               — get session detail
 * PATCH  /api/v1/sessions/{id}               — update session (title)
 * DELETE /api/v1/sessions/{id}               — delete session
 * GET    /api/v1/sessions/{id}/child-runs    — list child agent runs
 */
final readonly class SessionHandler
{
    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
    ) {}

    /**
     * GET /api/v1/sessions?limit=50
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? min((int) $params['limit'], 200) : 50;

        $sessions = $this->storage->listSessions($limit);

        return Router::jsonResponse([
            'sessions' => $sessions,
            'count' => count($sessions),
        ]);
    }

    /**
     * POST /api/v1/sessions  { "model_role"?: "orchestrator" }
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $modelRole = is_array($body) && isset($body['model_role'])
            ? (string) $body['model_role']
            : 'orchestrator';

        $profile = is_array($body) && isset($body['profile'])
            ? (string) $body['profile']
            : null;

        // Validate that the role exists
        if (!$this->roleResolver->hasRole($modelRole)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $modelRole),
            );
        }

        $model = $this->roleResolver->resolve($modelRole);
        $sessionId = $this->storage->createSession($modelRole, $model, $profile);

        return Router::jsonResponse([
            'id' => $sessionId,
            'model_role' => $modelRole,
            'model' => $model,
            'profile' => $profile,
        ], 201);
    }

    /**
     * GET /api/v1/sessions/{id}
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return Router::jsonResponse($session);
    }

    /**
     * PATCH /api/v1/sessions/{id}  { "title": "..." }
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        if (isset($body['title'])) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Title cannot be empty');
            }
            $this->storage->updateSessionTitle($id, $title);
        }

        if (isset($body['model_role'])) {
            $role = trim((string) $body['model_role']);
            if ($role === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'model_role cannot be empty');
            }
            $modelString = $this->roleResolver->resolve($role);
            $this->storage->updateSessionRole($id, $role, $modelString);
        }

        // Return the updated session
        $updated = $this->storage->getSession($id);

        return Router::jsonResponse($updated ?? $session);
    }

    /**
     * DELETE /api/v1/sessions/{id}
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $this->storage->deleteSession($id);

        return Router::jsonResponse(['deleted' => true, 'id' => $id]);
    }

    /**
     * GET /api/v1/sessions/{id}/child-runs
     */
    public function childRuns(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $runs = $this->storage->getChildRuns($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'child_runs' => $runs,
            'count' => count($runs),
        ]);
    }
}
