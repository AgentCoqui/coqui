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
use CoquiBot\Coqui\Support\Clock;
use CoquiBot\Coqui\Support\JsonHelper;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Loop API endpoints.
 *
 * POST   /api/v1/loops                    — create loop
 * GET    /api/v1/loops                    — list loops
 * GET    /api/v1/loops/definitions        — list available definitions
 * GET    /api/v1/loops/definitions/{name} — get one raw definition
 * POST   /api/v1/loops/definitions        — create a definition
 * PUT    /api/v1/loops/definitions/{name} — upsert a definition
 * DELETE /api/v1/loops/definitions/{name} — delete a definition
 * GET    /api/v1/loops/{id}               — get loop status
 * GET    /api/v1/loops/{id}/history       — get full loop iteration history
 * GET    /api/v1/loops/{id}/metrics       — get aggregate loop metrics
 * POST   /api/v1/loops/{id}/skip-stage    — skip current non-running stage
 * POST   /api/v1/loops/{id}/pause         — pause loop
 * POST   /api/v1/loops/{id}/resume        — resume loop
 * POST   /api/v1/loops/{id}/stop          — cancel loop
 * GET    /api/v1/loops/{id}/iterations    — list iterations
 * GET    /api/v1/loops/{id}/iterations/{iterationId} — get iteration with stages
 * POST   /api/v1/loops/{id}/iterations/{iterationId}/retry — retry latest failed iteration
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

        if ($this->projectStore !== null) {
            if ($projectId !== null && $this->projectStore->getProject($projectId) === null) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
            }

            if ($projectSlug !== null && $this->projectStore->getProject($projectSlug) === null) {
                return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Project not found');
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
     * GET /api/v1/loops/active/count
     */
    public function activeCount(ServerRequestInterface $request): Response
    {
        return Router::jsonResponse([
            'active' => $this->store->countActive(),
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
                'builtin' => $this->discovery->isBuiltin($def->name),
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
     * GET /api/v1/loops/definitions/{name}
     */
    public function getDefinition(ServerRequestInterface $request, string $name): Response
    {
        if (!$this->discovery->isValidDefinitionName($name)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid loop definition name');
        }
        if (!$this->discovery->exists($name)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop definition not found');
        }

        return Router::jsonResponse($this->discovery->getRawDefinition($name));
    }

    /**
     * POST /api/v1/loops/definitions
     */
    public function createDefinition(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $name = isset($body['name']) ? trim((string) $body['name']) : '';
        if (!$this->discovery->isValidDefinitionName($name)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid or missing loop definition name');
        }
        if ($this->discovery->exists($name)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Loop definition "%s" already exists', $name));
        }

        try {
            $this->discovery->saveDefinition($name, $body);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return Router::jsonResponse($this->discovery->getRawDefinition($name), 201);
    }

    /**
     * PUT /api/v1/loops/definitions/{name}
     */
    public function updateDefinition(ServerRequestInterface $request, string $name): Response
    {
        if (!$this->discovery->isValidDefinitionName($name)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid loop definition name');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        try {
            $this->discovery->saveDefinition($name, $body);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        }

        return Router::jsonResponse($this->discovery->getRawDefinition($name));
    }

    /**
     * DELETE /api/v1/loops/definitions/{name}
     */
    public function deleteDefinition(ServerRequestInterface $request, string $name): Response
    {
        if (!$this->discovery->isValidDefinitionName($name)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid loop definition name');
        }
        if (!$this->discovery->deleteDefinition($name)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop definition not found');
        }

        return Router::jsonResponse(['deleted' => true, 'name' => $name]);
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
     * GET /api/v1/loops/{id}/history
     */
    public function history(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $iterations = $this->store->listIterations($id);
        $history = array_map(function (array $iteration): array {
            $stages = array_map(
                fn(array $stage): array => $this->normalizeStage($stage),
                $this->store->listStages((string) $iteration['id']),
            );

            return [
                ...$iteration,
                'duration_seconds' => $this->durationSeconds($iteration['started_at'] ?? null, $iteration['completed_at'] ?? null),
                'stage_count' => count($stages),
                'completed_stage_count' => count(array_filter($stages, static fn(array $stage): bool => ($stage['status'] ?? null) === 'completed')),
                'stages' => $stages,
            ];
        }, $iterations);

        return Router::jsonResponse([
            'loop' => $this->normalizeLoop($loop),
            'history' => $history,
            'count' => count($history),
        ]);
    }

    /**
     * GET /api/v1/loops/{id}/metrics
     */
    public function metrics(ServerRequestInterface $request, string $id): Response
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $iterations = $this->store->listIterations($id);
        $iterationCounts = [];
        $stageCounts = [];
        $stageRoleCounts = [];
        $stagesTotal = 0;

        foreach ($iterations as $iteration) {
            $iterationStatus = (string) ($iteration['status'] ?? 'unknown');
            $iterationCounts[$iterationStatus] = ($iterationCounts[$iterationStatus] ?? 0) + 1;

            foreach ($this->store->listStages((string) $iteration['id']) as $stage) {
                $stagesTotal++;
                $stageStatus = (string) ($stage['status'] ?? 'unknown');
                $stageCounts[$stageStatus] = ($stageCounts[$stageStatus] ?? 0) + 1;

                $role = (string) ($stage['role'] ?? 'unknown');
                $stageRoleCounts[$role] = ($stageRoleCounts[$role] ?? 0) + 1;
            }
        }

        $timings = $this->store->getIterationTimings($id);
        $totalIterationSeconds = array_sum(array_map(static fn(array $timing): float => (float) $timing['duration_seconds'], $timings));
        $loopDuration = $this->durationSeconds($loop['started_at'] ?? null, $loop['completed_at'] ?? null);

        return Router::jsonResponse([
            'loop_id' => $id,
            'status' => $loop['status'] ?? null,
            'current_iteration' => (int) ($loop['current_iteration'] ?? 0),
            'duration_seconds' => $loopDuration,
            'iterations' => [
                'total' => count($iterations),
                'by_status' => $iterationCounts,
            ],
            'stages' => [
                'total' => $stagesTotal,
                'by_status' => $stageCounts,
                'by_role' => $stageRoleCounts,
            ],
            'timings' => [
                'total_iteration_seconds' => $totalIterationSeconds,
                'average_iteration_seconds' => count($timings) > 0 ? $totalIterationSeconds / count($timings) : 0.0,
                'iteration_timings' => $timings,
            ],
        ]);
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
     * POST /api/v1/loops/{id}/skip-stage
     */
    public function skipStage(ServerRequestInterface $request, string $id): Response
    {
        if ($this->executor === null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Loop execution is not available');
        }

        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $loopStatus = (string) ($loop['status'] ?? '');
        if ($loopStatus === 'running') {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Pause or recover the loop before skipping a stage.');
        }

        $state = $this->store->getCurrentState($id);
        if ($state === null || $state['iteration'] === null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Loop has no active iteration to recover.');
        }

        $iteration = $state['iteration'];
        $candidate = null;
        foreach ($state['stages'] as $stage) {
            if (($stage['status'] ?? null) !== 'completed') {
                $candidate = $stage;
                break;
            }
        }

        if ($candidate === null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'No actionable stage is available to skip.');
        }

        if (($candidate['status'] ?? null) === 'running') {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Cannot skip a stage while it is actively running.');
        }

        $this->store->updateStage(
            id: (string) $candidate['id'],
            status: 'completed',
            taskId: is_string($candidate['task_id'] ?? null) ? $candidate['task_id'] : null,
            artifactId: is_string($candidate['artifact_id'] ?? null) ? $candidate['artifact_id'] : null,
            resultSummary: sprintf('SKIPPED: operator skipped stage from %s state.', (string) ($candidate['status'] ?? 'unknown')),
        );
        $this->store->reopenIteration((string) $iteration['id']);
        $this->store->updateLoopStatus($id, 'running');

        $stages = $this->store->listStages((string) $iteration['id']);
        $nextPendingStage = null;
        foreach ($stages as $stage) {
            if (($stage['status'] ?? null) !== 'completed') {
                $nextPendingStage = $stage;
                break;
            }
        }

        if ($nextPendingStage !== null) {
            $this->store->updateLoopProgress($id, (int) ($iteration['iteration_number'] ?? 0), (int) ($nextPendingStage['stage_index'] ?? 0));
            $this->store->updateLoopMetadata($id, [
                'dispatch' => [
                    'status' => 'pending',
                    'message' => 'Operator skipped a stage. The loop manager will dispatch the next stage on the next tick.',
                    'stage_id' => (string) $nextPendingStage['id'],
                    'stage_index' => (int) ($nextPendingStage['stage_index'] ?? 0),
                    'updated_at' => Clock::nowUtc(),
                ],
            ]);
        } else {
            $this->store->updateLoopMetadata($id, [
                'dispatch' => [
                    'status' => 'pending',
                    'message' => 'Operator skipped the final actionable stage. The loop will be re-evaluated now.',
                    'updated_at' => Clock::nowUtc(),
                ],
            ]);
            $this->executor->evaluateIteration($id);
        }

        $updatedState = $this->normalizedState($id);
        if ($updatedState === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Loop recovered but state could not be loaded');
        }

        return Router::jsonResponse($updatedState);
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
     * POST /api/v1/loops/{id}/iterations/{iterationId}/retry
     */
    public function retryIteration(ServerRequestInterface $request, string $id, string $iterationId): Response
    {
        if ($this->executor === null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Loop execution is not available');
        }

        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $iteration = $this->store->getIteration($iterationId);
        if ($iteration === null || (string) ($iteration['loop_id'] ?? '') !== $id) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Iteration not found');
        }

        $iterations = $this->store->listIterations($id);
        $latestIteration = $iterations === [] ? null : $iterations[array_key_last($iterations)];
        if (!is_array($latestIteration) || (string) ($latestIteration['id'] ?? '') !== $iterationId) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Only the latest iteration can be retried.');
        }

        $iterationStatus = (string) ($iteration['status'] ?? '');
        if (!in_array($iterationStatus, ['failed', 'needs_rework'], true)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Cannot retry iteration while status is "%s".', $iterationStatus));
        }

        if ((string) ($loop['status'] ?? '') === 'running') {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Pause or stop the loop before retrying an iteration.');
        }

        $this->store->resetStagesForIteration($iterationId);
        $this->store->resetIterationForRetry($iterationId);
        $this->store->updateLoopStatus($id, 'running');
        $this->store->updateLoopProgress($id, (int) ($iteration['iteration_number'] ?? 0), 0);
        $this->store->updateLoopMetadata($id, [
            'dispatch' => [
                'status' => 'pending',
                'message' => 'Operator retried the latest iteration. The loop manager will dispatch stage 0 on the next tick.',
                'iteration_id' => $iterationId,
                'stage_index' => 0,
                'updated_at' => Clock::nowUtc(),
            ],
        ]);

        $updatedState = $this->normalizedState($id);
        if ($updatedState === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Loop retried but state could not be loaded');
        }

        return Router::jsonResponse($updatedState);
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
        $router->get($v1 . '/loops/active/count', [$this, 'activeCount']);
        $router->get($v1 . '/loops/definitions', [$this, 'definitions']);
        $router->get($v1 . '/loops/definitions/{name}', [$this, 'getDefinition']);
        $router->post($v1 . '/loops/definitions', [$this, 'createDefinition']);
        $router->put($v1 . '/loops/definitions/{name}', [$this, 'updateDefinition']);
        $router->delete($v1 . '/loops/definitions/{name}', [$this, 'deleteDefinition']);
        $router->get($v1 . '/loops/{id}', [$this, 'get']);
        $router->get($v1 . '/loops/{id}/history', [$this, 'history']);
        $router->get($v1 . '/loops/{id}/metrics', [$this, 'metrics']);
        if ($this->executor !== null) {
            $router->post($v1 . '/loops/{id}/pause', [$this, 'pause']);
            $router->post($v1 . '/loops/{id}/resume', [$this, 'resume']);
            $router->post($v1 . '/loops/{id}/stop', [$this, 'stop']);
            $router->post($v1 . '/loops/{id}/skip-stage', [$this, 'skipStage']);
            $router->post($v1 . '/loops/{id}/iterations/{iterationId}/retry', [$this, 'retryIteration']);
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

    private function durationSeconds(mixed $startedAt, mixed $completedAt): ?float
    {
        if (!is_string($startedAt) || $startedAt === '') {
            return null;
        }

        try {
            $start = new \DateTimeImmutable($startedAt);
            $end = is_string($completedAt) && $completedAt !== ''
                ? new \DateTimeImmutable($completedAt)
                : new \DateTimeImmutable('now');

            return max(0.0, (float) ($end->getTimestamp() - $start->getTimestamp()));
        } catch (\Throwable) {
            return null;
        }
    }

}
