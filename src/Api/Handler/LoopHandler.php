<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Loop read-only API endpoints.
 *
 * GET    /api/v1/loops                    — list loops
 * GET    /api/v1/loops/definitions         — list available definitions
 * GET    /api/v1/loops/{id}               — get loop status
 * GET    /api/v1/loops/{id}/iterations     — list iterations
 * GET    /api/v1/loops/{id}/iterations/{iterationId} — get iteration with stages
 *
 * Mutating operations (create, delete, pause, resume, stop) are REPL-only.
 */
final readonly class LoopHandler
{
    public function __construct(
        private LoopStore $store,
        private LoopDiscovery $discovery,
    ) {}

    /**
     * GET /api/v1/loops?status=running
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $status = isset($params['status']) && trim((string) $params['status']) !== ''
            ? trim((string) $params['status'])
            : null;

        $loops = $this->store->listLoops($status);
        $activeCount = $this->store->countActive();

        return Router::jsonResponse([
            'loops' => $loops,
            'count' => count($loops),
            'active' => $activeCount,
        ]);
    }

    /**
     * GET /api/v1/loops/definitions
     */
    public function definitions(ServerRequestInterface $request): Response
    {
        $defs = $this->discovery->discoverAll();

        $result = [];
        foreach ($defs as $def) {
            $result[] = [
                'name' => $def->name,
                'description' => $def->description,
                'roles' => array_map(fn($r) => [
                    'role' => $r->role,
                    'prompt' => $r->prompt,
                    'max_iterations' => $r->maxIterations,
                ], $def->roles),
                'termination' => $def->terminationCondition->toArray(),
            ];
        }

        return Router::jsonResponse([
            'definitions' => $result,
            'count' => count($result),
        ]);
    }

    /**
     * GET /api/v1/loops/{id}
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        $state = $this->store->getCurrentState($id);
        if ($state === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        return Router::jsonResponse($state);
    }

    /**
     * GET /api/v1/loops/{id}/iterations
     */
    public function iterations(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $iterations = $this->store->listIterations($id);

        return Router::jsonResponse([
            'iterations' => $iterations,
            'count' => count($iterations),
        ]);
    }

    /**
     * GET /api/v1/loops/{id}/iterations/{iterationId}
     */
    public function iteration(ServerRequestInterface $request, string $id, string $iterationId): Response
    {
        $iteration = $this->store->getIteration($iterationId);
        if ($iteration === null || $iteration['loop_id'] !== $id) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Iteration not found');
        }

        $stages = $this->store->listStages($iterationId);

        return Router::jsonResponse([
            'iteration' => $iteration,
            'stages' => $stages,
        ]);
    }

    /**
     * Register read-only loop routes on the router.
     */
    public function register(Router $router): void
    {
        $v1 = '/api/v1';

        $router->get($v1 . '/loops', [$this, 'list']);
        $router->get($v1 . '/loops/definitions', [$this, 'definitions']);
        $router->get($v1 . '/loops/{id}', [$this, 'get']);
        $router->get($v1 . '/loops/{id}/iterations', [$this, 'iterations']);
        $router->get($v1 . '/loops/{id}/iterations/{iterationId}', [$this, 'iteration']);
    }
}
