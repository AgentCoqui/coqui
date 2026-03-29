<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Loop CRUD API endpoints.
 *
 * GET    /api/v1/loops                    — list loops
 * POST   /api/v1/loops                    — start a loop
 * GET    /api/v1/loops/definitions         — list available definitions
 * GET    /api/v1/loops/{id}               — get loop status
 * DELETE /api/v1/loops/{id}               — delete loop
 * POST   /api/v1/loops/{id}/pause         — pause loop
 * POST   /api/v1/loops/{id}/resume        — resume loop
 * POST   /api/v1/loops/{id}/stop          — cancel loop
 * GET    /api/v1/loops/{id}/iterations     — list iterations
 * GET    /api/v1/loops/{id}/iterations/{iterationId} — get iteration with stages
 */
final readonly class LoopHandler
{
    public function __construct(
        private LoopStore $store,
        private LoopDiscovery $discovery,
        private ?LoopExecutor $executor = null,
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
     * POST /api/v1/loops
     *
     * Body: { definition, goal, max_iterations? }
     * Note: This creates the loop record. Actual execution is driven by LoopManager.
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $defName = isset($body['definition']) ? trim((string) $body['definition']) : '';
        if ($defName === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'definition is required');
        }

        if (!$this->discovery->exists($defName)) {
            return Router::errorResponse(
                ApiErrorCode::NOT_FOUND,
                sprintf('Loop definition "%s" not found', $defName),
                ['available' => $this->discovery->availableLoops()],
            );
        }

        $goal = isset($body['goal']) ? trim((string) $body['goal']) : '';
        if ($goal === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'goal is required');
        }

        $definition = $this->discovery->get($defName);
        $maxIterations = isset($body['max_iterations'])
            ? max(0, (int) $body['max_iterations'])
            : $definition->terminationCondition->maxIterations;

        // Use LoopExecutor to properly initialize the loop with project, iteration, and stages
        if ($this->executor !== null) {
            try {
                $loopId = $this->executor->startLoop(
                    definition: $definition,
                    goal: $goal,
                );
            } catch (\Throwable $e) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Failed to start loop: %s', $e->getMessage()),
                );
            }
        } else {
            // Fallback: create loop record directly (LoopManager will need to initialize it)
            $loopId = $this->store->createLoop(
                definitionName: $defName,
                goal: $goal,
                configuration: $definition->toArray(),
                maxIterations: $maxIterations,
                terminationCriteria: $definition->terminationCondition->criteria,
            );
        }

        $loop = $this->store->getLoop($loopId);

        return Router::jsonResponse($loop ?? ['id' => $loopId], 201);
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
     * DELETE /api/v1/loops/{id}
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        if (!$this->store->deleteLoop($id)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        return Router::jsonResponse(['deleted' => true]);
    }

    /**
     * POST /api/v1/loops/{id}/pause
     */
    public function pause(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        if ($loop['status'] !== 'running') {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Cannot pause loop — current status is "%s"', $loop['status']),
            );
        }

        $this->store->updateLoopStatus($id, 'paused');

        return Router::jsonResponse(['id' => $id, 'status' => 'paused']);
    }

    /**
     * POST /api/v1/loops/{id}/resume
     */
    public function resume(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        if ($loop['status'] !== 'paused') {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Cannot resume loop — current status is "%s"', $loop['status']),
            );
        }

        $this->store->updateLoopStatus($id, 'running');

        return Router::jsonResponse(['id' => $id, 'status' => 'running']);
    }

    /**
     * POST /api/v1/loops/{id}/stop
     */
    public function stop(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        if (!in_array($loop['status'], ['running', 'paused'], true)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Cannot stop loop — current status is "%s"', $loop['status']),
            );
        }

        $this->store->updateLoopStatus($id, 'cancelled');

        return Router::jsonResponse(['id' => $id, 'status' => 'cancelled']);
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
     * Register all loop routes on the router.
     */
    public function register(Router $router): void
    {
        $v1 = '/api/v1';

        $router->get($v1 . '/loops', [$this, 'list']);
        $router->post($v1 . '/loops', [$this, 'create']);
        $router->get($v1 . '/loops/definitions', [$this, 'definitions']);
        $router->get($v1 . '/loops/{id}', [$this, 'get']);
        $router->delete($v1 . '/loops/{id}', [$this, 'delete']);
        $router->post($v1 . '/loops/{id}/pause', [$this, 'pause']);
        $router->post($v1 . '/loops/{id}/resume', [$this, 'resume']);
        $router->post($v1 . '/loops/{id}/stop', [$this, 'stop']);
        $router->get($v1 . '/loops/{id}/iterations', [$this, 'iterations']);
        $router->get($v1 . '/loops/{id}/iterations/{iterationId}', [$this, 'iteration']);
    }
}
