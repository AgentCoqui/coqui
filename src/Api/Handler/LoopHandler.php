<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\JsonHelper;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Loop API endpoints.
 *
 * POST   /api/v1/loops                    — create loop
 * GET    /api/v1/loops                    — list loops
 * GET    /api/v1/loops/definitions        — list available definitions
 * GET    /api/v1/loops/{id}               — get loop status
 * POST   /api/v1/loops/{id}/pause         — pause loop
 * POST   /api/v1/loops/{id}/resume        — resume loop
 * POST   /api/v1/loops/{id}/stop          — cancel loop
 * GET    /api/v1/loops/{id}/iterations    — list iterations
 * GET    /api/v1/loops/{id}/iterations/{iterationId} — get iteration with stages
 */
final readonly class LoopHandler
{
    public function __construct(
        private LoopStore $store,
        private LoopDiscovery $discovery,
        private ?LoopExecutor $executor = null,
        private ?SessionStorage $storage = null,
        private ?ProjectStore $projectStore = null,
    ) {}

    /**
     * POST /api/v1/loops
     */
    public function create(ServerRequestInterface $request): Response
    {
        if ($this->executor === null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Loop execution is not available');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $definition = trim((string) ($body['definition'] ?? ''));
        $goal = trim((string) ($body['goal'] ?? ''));

        if ($definition === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'definition is required');
        }

        if ($goal === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'goal is required');
        }

        if (!$this->discovery->exists($definition)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop definition not found');
        }

        $sessionId = isset($body['session_id']) ? trim((string) $body['session_id']) : null;
        if ($sessionId === '') {
            $sessionId = null;
        }

        if ($sessionId !== null) {
            if ($this->storage === null) {
                return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
            }

            $session = SessionAccess::requireWritableSession($this->storage, $sessionId);
            if ($session instanceof Response) {
                return $session;
            }
        }

        $projectId = isset($body['project_id']) ? trim((string) $body['project_id']) : null;
        if ($projectId === '') {
            $projectId = null;
        }

        $projectSlug = isset($body['project_slug']) ? trim((string) $body['project_slug']) : null;
        if ($projectSlug === '') {
            $projectSlug = null;
        }

        if ($projectId !== null && $projectSlug !== null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Specify either project_id or project_slug, not both');
        }

        $sprintId = isset($body['sprint_id']) ? trim((string) $body['sprint_id']) : null;
        if ($sprintId === '') {
            $sprintId = null;
        }

        if ($this->projectStore !== null) {
            if ($projectId !== null && $this->projectStore->getProject($projectId) === null) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
            }

            if ($projectSlug !== null && $this->projectStore->getProject($projectSlug) === null) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
            }

            if ($sprintId !== null && $this->projectStore->getSprint($sprintId) === null) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Sprint not found');
            }
        }

        $rawParameters = $body['parameters'] ?? [];
        if (!is_array($rawParameters)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'parameters must be an object');
        }

        $parameters = [];
        foreach ($rawParameters as $key => $value) {
            if (!is_string($key)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'parameters must be an object');
            }

            if (is_array($value) || is_object($value)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'parameter values must be scalar');
            }

            $parameters[$key] = (string) $value;
        }

        $maxIterations = null;
        if (array_key_exists('max_iterations', $body) && $body['max_iterations'] !== null && $body['max_iterations'] !== '') {
            $maxIterations = (int) $body['max_iterations'];
            if ($maxIterations < 1) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_iterations must be greater than 0');
            }
        }

        try {
            $loopId = $this->executor->startLoop(
                rawDefinition: $this->discovery->getRawDefinition($definition),
                goal: $goal,
                sessionId: $sessionId,
                parameters: $parameters,
                projectId: $projectId,
                projectSlug: $projectSlug,
                sprintId: $sprintId,
                maxIterationsOverride: $maxIterations,
            );
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }

        $state = $this->normalizedState($loopId);
        if ($state === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Loop created but state could not be loaded');
        }

        return Router::jsonResponse($state, 201);
    }

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
                'parameters' => array_map(
                    static fn($parameter) => $parameter->toArray(),
                    $def->parameters,
                ),
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
        $state = $this->normalizedState($id);
        if ($state === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        return Router::jsonResponse($state);
    }

    /**
     * PATCH /api/v1/loops/{id}
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $allowedKeys = ['goal', 'max_iterations', 'metadata', 'labels'];
        $unknownKeys = array_values(array_filter(
            array_keys($body),
            static fn(mixed $key): bool => is_string($key) && !in_array($key, $allowedKeys, true),
        ));

        if ($unknownKeys !== []) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown loop patch fields: %s', implode(', ', $unknownKeys)),
            );
        }

        if ($body === []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'At least one patch field is required');
        }

        $fieldPatch = [];

        if (array_key_exists('goal', $body)) {
            $goal = trim((string) $body['goal']);
            if ($goal === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'goal cannot be empty');
            }

            $fieldPatch['goal'] = $goal;
        }

        if (array_key_exists('max_iterations', $body)) {
            $rawMaxIterations = $body['max_iterations'];

            if ($rawMaxIterations === null || $rawMaxIterations === '') {
                $fieldPatch['max_iterations'] = null;
            } else {
                if (!is_int($rawMaxIterations) && !is_string($rawMaxIterations) && !is_float($rawMaxIterations)) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_iterations must be a positive integer or null');
                }

                $maxIterations = (int) $rawMaxIterations;
                if ($maxIterations < 1) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'max_iterations must be greater than 0');
                }

                $currentIteration = max(1, (int) ($loop['current_iteration'] ?? 0));
                if ($maxIterations < $currentIteration) {
                    return Router::errorResponse(
                        ApiErrorCode::CONFLICT,
                        sprintf('max_iterations cannot be less than the current iteration (%d).', $currentIteration),
                    );
                }

                $fieldPatch['max_iterations'] = $maxIterations;
            }
        }

        $metadataPatch = [];
        if (array_key_exists('metadata', $body)) {
            if (!is_array($body['metadata'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'metadata must be an object');
            }

            $metadataPatch = $body['metadata'];
        }

        if (array_key_exists('labels', $body)) {
            if (!is_array($body['labels'])) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'labels must be an array of strings');
            }

            $labels = [];
            foreach ($body['labels'] as $label) {
                if (!is_string($label)) {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'labels must be an array of strings');
                }

                $trimmed = trim($label);
                if ($trimmed === '') {
                    return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'labels cannot contain empty values');
                }

                $labels[] = $trimmed;
            }

            $metadataPatch['labels'] = array_values(array_unique($labels));
        }

        if ($fieldPatch === [] && $metadataPatch === []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'At least one patch field is required');
        }

        if ($fieldPatch !== []) {
            $this->store->updateLoop($id, $fieldPatch);
        }

        if ($metadataPatch !== []) {
            $this->store->updateLoopMetadata($id, $metadataPatch);
        }

        $state = $this->normalizedState($id);
        if ($state === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Loop updated but state could not be loaded');
        }

        return Router::jsonResponse($state);
    }

    /**
     * DELETE /api/v1/loops/{id}
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $status = (string) ($loop['status'] ?? '');
        if (in_array($status, ['running', 'paused'], true)) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                sprintf('Cannot delete loop while status is "%s".', $status),
            );
        }

        $this->store->deleteLoop($id);

        return Router::jsonResponse([
            'deleted' => true,
            'id' => $id,
        ]);
    }

    /**
     * POST /api/v1/loops/{id}/pause
     */
    public function pause(ServerRequestInterface $request, string $id): Response
    {
        return $this->transitionLoop($id, 'running', 'paused');
    }

    /**
     * POST /api/v1/loops/{id}/resume
     */
    public function resume(ServerRequestInterface $request, string $id): Response
    {
        return $this->transitionLoop($id, 'paused', 'running');
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

        $status = (string) $loop['status'];
        if (!in_array($status, ['running', 'paused'], true)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Cannot stop loop while status is "%s".', $status));
        }

        if ($this->executor !== null) {
            $this->executor->cancelLoop($id);
        } else {
            $this->store->updateLoopStatus($id, 'cancelled');
        }

        return Router::jsonResponse([
            'id' => $id,
            'status' => 'cancelled',
        ]);
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
        $stages = array_map(fn(array $stage): array => $this->normalizeStage($stage), $stages);

        return Router::jsonResponse([
            'iteration' => $iteration,
            'stages' => $stages,
        ]);
    }

    /**
     * Register loop routes on the router.
     */
    public function register(Router $router): void
    {
        $v1 = '/api/v1';

        if ($this->executor !== null) {
            $router->post($v1 . '/loops', [$this, 'create']);
            $router->patch($v1 . '/loops/{id}', [$this, 'update']);
            $router->delete($v1 . '/loops/{id}', [$this, 'delete']);
        }
        $router->get($v1 . '/loops', [$this, 'list']);
        $router->get($v1 . '/loops/definitions', [$this, 'definitions']);
        $router->get($v1 . '/loops/{id}', [$this, 'get']);
        if ($this->executor !== null) {
            $router->post($v1 . '/loops/{id}/pause', [$this, 'pause']);
            $router->post($v1 . '/loops/{id}/resume', [$this, 'resume']);
            $router->post($v1 . '/loops/{id}/stop', [$this, 'stop']);
        }
        $router->get($v1 . '/loops/{id}/iterations', [$this, 'iterations']);
        $router->get($v1 . '/loops/{id}/iterations/{iterationId}', [$this, 'iteration']);
    }

    private function transitionLoop(string $id, string $expectedStatus, string $newStatus): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $status = (string) $loop['status'];
        if ($status !== $expectedStatus) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                sprintf('Cannot transition loop from "%s" to "%s".', $status, $newStatus),
            );
        }

        if ($this->executor !== null) {
            if ($newStatus === 'paused') {
                $this->executor->pauseLoop($id);
            } else {
                $this->executor->resumeLoop($id);
            }
        } else {
            $this->store->updateLoopStatus($id, $newStatus);
        }

        return Router::jsonResponse([
            'id' => $id,
            'status' => $newStatus,
        ]);
    }

    /**
     * @param array<string, mixed> $loop
     * @return array<string, mixed>
     */
    private function normalizeLoop(array $loop): array
    {
        $loop['metadata'] = JsonHelper::decodeJsonObject($loop['metadata'] ?? null);

        return $loop;
    }

    /**
     * @param array<string, mixed> $stage
     * @return array<string, mixed>
     */
    private function normalizeStage(array $stage): array
    {
        $stage['metadata'] = JsonHelper::decodeJsonObject($stage['metadata'] ?? null);

        return $stage;
    }

    /**
     * @return array{loop: array<string, mixed>, iteration: array<string, mixed>|null, stages: list<array<string, mixed>>}|null
     */
    private function normalizedState(string $id): ?array
    {
        $state = $this->store->getCurrentState($id);
        if ($state === null) {
            return null;
        }

        $state['loop'] = $this->normalizeLoop($state['loop']);
        $state['stages'] = array_map(fn(array $stage): array => $this->normalizeStage($stage), $state['stages']);

        return $state;
    }

}
