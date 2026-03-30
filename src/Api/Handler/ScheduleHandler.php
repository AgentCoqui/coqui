<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Storage\ScheduleStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Schedule read-only API endpoints.
 *
 * GET    /api/v1/schedules              — list schedules
 * GET    /api/v1/schedules/{id}         — get schedule
 *
 * Mutating operations (create, update, delete, trigger, enable, disable) are REPL-only.
 */
final readonly class ScheduleHandler
{
    public function __construct(
        private ScheduleStore $store,
    ) {}

    /**
     * GET /api/v1/schedules?enabled=1&created_by=agent
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();

        $enabled = isset($params['enabled'])
            ? ((string) $params['enabled'] === '1')
            : null;
        $createdBy = isset($params['created_by']) && trim((string) $params['created_by']) !== ''
            ? trim((string) $params['created_by'])
            : null;

        $schedules = $this->store->list(enabled: $enabled, createdBy: $createdBy);
        $stats = $this->store->getStats();

        return Router::jsonResponse([
            'schedules' => $schedules,
            'count' => count($schedules),
            'stats' => $stats,
        ]);
    }

    /**
     * GET /api/v1/schedules/{id}
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        $schedule = $this->store->get($id);
        if ($schedule === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Schedule not found');
        }

        return Router::jsonResponse($schedule);
    }
}
