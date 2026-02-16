<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Session CRUD endpoints.
 *
 * GET    /api/sessions          — list sessions
 * POST   /api/sessions          — create session
 * GET    /api/sessions/{id}     — get session detail
 * DELETE /api/sessions/{id}     — delete session
 */
final readonly class SessionHandler
{
    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
    ) {}

    /**
     * GET /api/sessions?limit=50
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
     * POST /api/sessions  { "model_role"?: "orchestrator" }
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        $modelRole = is_array($body) && isset($body['model_role'])
            ? (string) $body['model_role']
            : 'orchestrator';

        $model = $this->roleResolver->resolve($modelRole);
        $sessionId = $this->storage->createSession($modelRole, $model);

        return Router::jsonResponse([
            'id' => $sessionId,
            'model_role' => $modelRole,
            'model' => $model,
        ], 201);
    }

    /**
     * GET /api/sessions/{id}
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::jsonResponse(['error' => 'Session not found'], 404);
        }

        return Router::jsonResponse($session);
    }

    /**
     * DELETE /api/sessions/{id}
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::jsonResponse(['error' => 'Session not found'], 404);
        }

        $this->storage->deleteSession($id);

        return Router::jsonResponse(['deleted' => true, 'id' => $id]);
    }
}
