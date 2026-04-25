<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Turn inspection endpoints.
 *
 * GET /api/v1/sessions/{id}/turns           — list turns for a session
 * GET /api/v1/sessions/{id}/turns/{turnId}  — get turn detail with messages
 * GET /api/v1/sessions/{id}/turns/{turnId}/events — list replayable turn events
 */
final readonly class TurnHandler
{
    public function __construct(
        private SessionStorage $storage,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/turns?limit=50
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
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
     * GET /api/v1/sessions/{id}/turns/{turnId}
     */
    public function get(ServerRequestInterface $request, string $id, string $turnId): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $turn = $this->storage->getTurnWithMessages($turnId);

        if ($turn === null) {
            return Router::errorResponse(ApiErrorCode::TURN_NOT_FOUND, 'Turn not found');
        }

        // Verify the turn belongs to the requested session
        if ($turn['session_id'] !== $id) {
            return Router::errorResponse(ApiErrorCode::TURN_NOT_FOUND, 'Turn does not belong to this session');
        }

        return Router::jsonResponse($turn);
    }

    /**
     * GET /api/v1/sessions/{id}/turns/{turnId}/events
     */
    public function events(ServerRequestInterface $request, string $id, string $turnId): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $turn = $this->storage->getTurn($turnId);

        if ($turn === null || ($turn['session_id'] ?? null) !== $id) {
            return Router::errorResponse(ApiErrorCode::TURN_NOT_FOUND, 'Turn not found');
        }

        $events = isset($turn['turn_process_id']) && is_string($turn['turn_process_id']) && $turn['turn_process_id'] !== ''
            ? $this->storage->getDecodedTurnEvents($turn['turn_process_id'])
            : [];

        return Router::jsonResponse([
            'session_id' => $id,
            'turn_id' => $turnId,
            'events' => $events,
            'count' => count($events),
        ]);
    }
}
