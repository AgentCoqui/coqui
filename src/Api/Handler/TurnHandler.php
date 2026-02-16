<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Turn inspection endpoints.
 *
 * GET /api/sessions/{id}/turns           — list turns for a session
 * GET /api/sessions/{id}/turns/{turnId}  — get turn detail with messages
 */
final readonly class TurnHandler
{
    public function __construct(
        private SessionStorage $storage,
    ) {}

    /**
     * GET /api/sessions/{id}/turns?limit=50
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::jsonResponse(['error' => 'Session not found'], 404);
        }

        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? min((int) $params['limit'], 200) : 50;

        $turns = $this->storage->getTurns($id, $limit);

        return Router::jsonResponse([
            'session_id' => $id,
            'turns' => $turns,
            'count' => count($turns),
        ]);
    }

    /**
     * GET /api/sessions/{id}/turns/{turnId}
     */
    public function get(ServerRequestInterface $request, string $id, string $turnId): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::jsonResponse(['error' => 'Session not found'], 404);
        }

        $turn = $this->storage->getTurnWithMessages($turnId);

        if ($turn === null) {
            return Router::jsonResponse(['error' => 'Turn not found'], 404);
        }

        // Verify the turn belongs to the requested session
        if ($turn['session_id'] !== $id) {
            return Router::jsonResponse(['error' => 'Turn does not belong to this session'], 404);
        }

        return Router::jsonResponse($turn);
    }
}
