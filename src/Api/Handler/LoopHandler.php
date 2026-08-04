<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Loop\LoopLiveProducer;
use CoquiBot\Coqui\Api\LoopStreamTracker;
use CoquiBot\Coqui\Api\CursorPage;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Api\SseStream;
use CoquiBot\Coqui\Contract\LoopStreamState;
use CoquiBot\Coqui\Agent\LoopExecutor;
use CoquiBot\Coqui\Config\LoopDiscovery;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaPreferences;
use CoquiBot\Coqui\Contract\LoopDefinition;
use CoquiBot\Coqui\Exception\RequestBodyException;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ObjectVersionStore;
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
 * GET    /api/v1/loops/definitions/{name} — get one definition (with version)
 * PUT    /api/v1/loops/definitions/{name} — create (If-None-Match: *) or update (If-Match: <version>) a definition
 * DELETE /api/v1/loops/definitions/{name} — delete a definition
 * GET    /api/v1/loops/{id}               — get loop status
 * GET    /api/v1/loops/{id}/history       — get full loop iteration history
 * GET    /api/v1/loops/{id}/metrics       — get aggregate loop metrics
 * GET    /api/v1/loops/{id}/live          — get rich live snapshot (current stage, model, budget, events)
 * GET    /api/v1/loops/{id}/events        — SSE live nudge stream
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
    use DecodesRequestBody;

    private const float POLL_INTERVAL = 1.0;

    /**
     * Object type key used for loop-definition version counters in ObjectVersionStore.
     */
    private const string LOOP_DEFINITION_OBJECT_TYPE = 'loop_definition';

    public function __construct(
        private LoopStore $store,
        private LoopDiscovery $discovery,
        private ?LoopExecutor $executor = null,
        private ?SessionStorage $storage = null,
        private ?ProjectStore $projectStore = null,
        private ?PersonaDiscovery $personaDiscovery = null,
        private ?ObjectVersionStore $objectVersions = null,
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

        $sessionPersona = null;
        if ($sessionId !== null) {
            if ($this->storage === null) {
                return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
            }

            $session = SessionAccess::requireWritableSession($this->storage, $sessionId);
            if ($session instanceof Response) {
                return $session;
            }

            $rawPersona = $session['persona_id'] ?? null;
            $sessionPersona = is_string($rawPersona) && $rawPersona !== '' ? $rawPersona : null;
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

        // CORE-22: a loop stage that hard-requires a durable artifact cannot run
        // under a persona that has the artifacts capability disabled. Reject at
        // creation (422) rather than discovering the conflict mid-run. Headless
        // loops (no session persona) are ungated here; persona threading for that
        // path is a separate concern.
        if ($this->definitionRequiresArtifact($definition) && !$this->personaArtifactsEnabled($sessionPersona)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'This loop definition requires durable artifacts, but the session persona has the artifacts capability disabled.',
                ['capability' => 'artifacts'],
                422,
            );
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

        $params = $request->getQueryParams();
        $headlessFilter = null;
        if (isset($params['headless'])) {
            $raw = strtolower(trim((string) $params['headless']));
            if ($raw === 'true' || $raw === '1') {
                $headlessFilter = true;
            } elseif ($raw === 'false' || $raw === '0') {
                $headlessFilter = false;
            }
        }

        $loops = array_map(function (array $loop): array {
            $loop['headless'] = $this->loopOrigin($loop) === 'headless';
            $loop['escalation'] = $this->decodeEscalation($loop);
            return $loop;
        }, $loops);

        if ($headlessFilter !== null) {
            $loops = array_values(array_filter($loops, static fn(array $l): bool => $l['headless'] === $headlessFilter));
        }

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
                'version' => $this->loopDefinitionVersion($def->name),
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

        // Declared default sort: name ascending (scandir order is not
        // deterministic). The definition name is the stable, unique cursor key.
        usort($result, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

        $params = $request->getQueryParams();

        return Router::jsonResponse(CursorPage::build(
            $result,
            CursorPage::limitFrom($params['limit'] ?? null),
            static fn(array $definition): string => (string) $definition['name'],
            CursorPage::decode(isset($params['cursor']) ? (string) $params['cursor'] : null),
        ));
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

        return Router::jsonResponse($this->servedDefinitionWire($name));
    }

    /**
     * PUT /api/v1/loops/definitions/{name}
     *
     * The single write path for a loop definition, branching on the CAP 0.5.0
     * optimistic-concurrency preconditions:
     *
     *  - `If-None-Match: *`      — create; 409 conflict if it already exists.
     *  - `If-Match: <version>`   — update; 409 version_conflict on a mismatch,
     *                              404 content_not_found if it does not exist.
     *  - neither header          — 409 conflict; a precondition is mandatory.
     *
     * The authoring body is strict (loop-definition.put.json): the server-owned
     * `version` lives in the ObjectVersionStore, never in the on-disk file, so a
     * body carrying `version`/`id` is a 422 validation_error.
     */
    public function putDefinition(ServerRequestInterface $request, string $name): Response
    {
        if (!$this->discovery->isValidDefinitionName($name)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid loop definition name', ['name' => $name], 422);
        }

        try {
            $body = $this->decodeAuthoringBody(
                $request,
                ['name', 'roles', 'termination_condition'],
                ['description', 'parameters', 'max_rework_attempts'],
            );
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
        }

        $precondition = $this->readPrecondition($request);
        $current = $this->objectVersions?->current(self::LOOP_DEFINITION_OBJECT_TYPE, $name) ?? 0;
        $exists = $this->discovery->exists($name);

        if ($precondition->isCreate) {
            if ($exists || $current !== 0) {
                return Router::errorResponse(
                    ApiErrorCode::CONFLICT,
                    sprintf('Loop definition "%s" already exists.', $name),
                    ['name' => $name],
                    409,
                );
            }

            $saved = $this->persistDefinition($name, $body);
            if ($saved instanceof Response) {
                return $saved;
            }

            $this->objectVersions?->create(self::LOOP_DEFINITION_OBJECT_TYPE, $name);

            return Router::jsonResponse($this->servedDefinitionWire($name), 201);
        }

        if ($precondition->expectedVersion !== null) {
            if (!$exists) {
                return Router::errorResponse(
                    ApiErrorCode::CONTENT_NOT_FOUND,
                    sprintf('Loop definition "%s" not found.', $name),
                    ['name' => $name],
                    404,
                );
            }

            $currentVersion = max(1, $current);
            if ($precondition->expectedVersion !== $currentVersion) {
                return Router::errorResponse(
                    ApiErrorCode::VERSION_CONFLICT,
                    sprintf('Loop definition "%s" has changed; expected version %d.', $name, $currentVersion),
                    ['expected_version' => $precondition->expectedVersion, 'current_version' => $currentVersion],
                    409,
                );
            }

            $saved = $this->persistDefinition($name, $body);
            if ($saved instanceof Response) {
                return $saved;
            }

            $this->objectVersions?->bump(self::LOOP_DEFINITION_OBJECT_TYPE, $name);

            return Router::jsonResponse($this->servedDefinitionWire($name));
        }

        return Router::errorResponse(
            ApiErrorCode::CONFLICT,
            'A precondition is required: use If-None-Match: * to create or If-Match: <version> to update.',
            ['reason' => 'precondition_required'],
            409,
        );
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

        // Clear the version-counter row so a later recreate of the same name
        // seeds cleanly at version 1 instead of colliding with an orphaned row.
        $this->objectVersions?->delete(self::LOOP_DEFINITION_OBJECT_TYPE, $name);

        return Router::jsonResponse(['deleted' => true, 'name' => $name]);
    }

    /**
     * Persist an authoring body to disk, or a 422 Response on a structural error.
     *
     * @param array<string, mixed> $body
     */
    private function persistDefinition(string $name, array $body): Response|true
    {
        try {
            $this->discovery->saveDefinition($name, $body);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage(), ['name' => $name], 422);
        }

        return true;
    }

    /**
     * The served loop-definition wire: the on-disk authoring source plus the
     * server-assigned `version` from the ObjectVersionStore.
     *
     * @return array<string, mixed>
     */
    private function servedDefinitionWire(string $name): array
    {
        $raw = $this->discovery->getRawDefinition($name);
        $raw['version'] = $this->loopDefinitionVersion($name);

        return $raw;
    }

    /**
     * Current loop-definition version, defaulting to 1 for a pre-existing file
     * definition that has never been written through the versioned API.
     */
    private function loopDefinitionVersion(string $name): int
    {
        $current = $this->objectVersions?->current(self::LOOP_DEFINITION_OBJECT_TYPE, $name) ?? 0;

        return max(1, $current);
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
     * GET /api/v1/loops/{id}/live
     *
     * Returns the strictly-typed loop-live.json snapshot (CAP 0.5.0 CORE-6).
     */
    public function live(ServerRequestInterface $request, string $id): Response
    {
        if ($this->store->getLoop($id) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        return Router::jsonResponse((new LoopLiveProducer($this->store))->toWire($id));
    }

    /**
     * GET /api/v1/loops/{id}/events
     *
     * SSE stream of thin nudges (connected, stage_changed, activity, done) for a
     * running loop. Clients refetch GET /loops/{id}/live on each nudge. Mirrors
     * the task-events long-poll: a ReactPHP timer diffs cheap loop state per tick.
     */
    public function events(ServerRequestInterface $request, string $id): Response
    {
        if ($this->store->getLoop($id) === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Loop not found');
        }

        $sse = new SseStream();
        $sse->connected(['loop_id' => $id]);

        $prev = null;
        $emit = function (LoopStreamState $now) use ($sse, &$prev): bool {
            $event = LoopStreamTracker::diff($prev, $now);
            $prev = $now;
            if ($event === null) {
                return false;
            }
            if ($event->type === 'done') {
                $sse->done($event->data);

                return true;
            }
            $sse->event($event->type, $event->data, $now->latestActivityId);

            return false;
        };

        // Initial emit: current position, or done if already terminal.
        $closed = $emit($this->readStreamState($id));

        if (!$closed) {
            $timer = \React\EventLoop\Loop::addPeriodicTimer(self::POLL_INTERVAL, function () use (&$timer, $sse, $id, $emit): void {
                try {
                    if ($emit($this->readStreamState($id))) {
                        if ($timer instanceof \React\EventLoop\TimerInterface) {
                            \React\EventLoop\Loop::cancelTimer($timer);
                        }
                    }
                } catch (\Throwable) {
                    try {
                        $sse->end();
                        if ($timer instanceof \React\EventLoop\TimerInterface) {
                            \React\EventLoop\Loop::cancelTimer($timer);
                        }
                    } catch (\Throwable) {
                        // Already closed.
                    }
                }
            });

            $sse->onClose(function () use (&$timer): void {
                /** @phpstan-ignore instanceof.alwaysTrue */
                if ($timer instanceof \React\EventLoop\TimerInterface) {
                    \React\EventLoop\Loop::cancelTimer($timer);
                }
            });
        }

        return $sse->response();
    }

    /**
     * Read the minimal loop state a stream tick observes. A vanished loop row
     * (deleted mid-stream) is treated as terminal 'cancelled'.
     */
    private function readStreamState(string $id): LoopStreamState
    {
        $loop = $this->store->getLoop($id);
        if ($loop === null) {
            return new LoopStreamState('cancelled', 0, 0, null);
        }

        return new LoopStreamState(
            status: (string) ($loop['status'] ?? 'cancelled'),
            currentIteration: (int) ($loop['current_iteration'] ?? 0),
            currentStage: (int) ($loop['current_stage'] ?? 0),
            latestActivityId: $this->store->latestActivityId($id),
        );
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

        // A `running` loop must be paused/stopped first; blocked | paused |
        // cancelled | failed iterations are all retryable.
        if ((string) ($loop['status'] ?? '') === 'running') {
            return Router::errorResponse(ApiErrorCode::CONFLICT, 'Pause or stop the loop before retrying an iteration.');
        }

        $body = json_decode((string) $request->getBody(), true);
        $note = is_array($body) && is_string($body['note'] ?? null) && trim($body['note']) !== ''
            ? trim((string) $body['note'])
            : null;

        $this->store->resetStagesForIteration($iterationId);
        $this->store->resetIterationForRetry($iterationId);
        $this->store->updateLoopStatus($id, 'running');
        $this->store->updateLoopProgress($id, (int) ($iteration['iteration_number'] ?? 0), 0);
        $this->store->setReworkAttempts($id, 0);
        $this->store->setDispatchState($id, 'pending');
        $this->store->updateLoopMetadata($id, [
            'pending_guidance' => $note,
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
        $router->put($v1 . '/loops/definitions/{name}', [$this, 'putDefinition']);
        $router->delete($v1 . '/loops/definitions/{name}', [$this, 'deleteDefinition']);
        $router->get($v1 . '/loops/{id}', [$this, 'get']);
        $router->get($v1 . '/loops/{id}/history', [$this, 'history']);
        $router->get($v1 . '/loops/{id}/metrics', [$this, 'metrics']);
        $router->get($v1 . '/loops/{id}/live', [$this, 'live']);
        $router->get($v1 . '/loops/{id}/events', [$this, 'events']);
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
        $origin = $this->loopOrigin($loop);
        $escalation = $this->decodeEscalation($loop);
        $loop['metadata'] = JsonHelper::decodeJsonObject($loop['metadata'] ?? null);
        $loop['origin'] = $origin;
        $loop['escalation'] = $escalation;

        return $loop;
    }

    /**
     * Decode a loop's escalation record from its raw metadata JSON.
     *
     * @param array<string, mixed> $loop
     * @return array<string, mixed>|null
     */
    private function decodeEscalation(array $loop): ?array
    {
        if (!is_string($loop['metadata'] ?? null) || $loop['metadata'] === '') {
            return null;
        }
        $meta = json_decode($loop['metadata'], true);

        return is_array($meta) && is_array($meta['escalation'] ?? null) ? $meta['escalation'] : null;
    }

    /**
     * Resolve a loop's origin ('headless' or 'conversation') from its column.
     *
     * @param array<string, mixed> $loop
     */
    private function loopOrigin(array $loop): string
    {
        return ($loop['origin'] ?? null) === 'headless' ? 'headless' : 'conversation';
    }

    /**
     * @param array<string, mixed> $stage
     * @return array<string, mixed>
     */
    private function normalizeStage(array $stage): array
    {
        $stage['metadata'] = JsonHelper::decodeJsonObject($stage['metadata'] ?? null);
        $stage['verdict'] = (isset($stage['verdict']) && $stage['verdict'] !== '')
            ? json_decode((string) $stage['verdict'], true)
            : null;

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

    /**
     * Whether any role stage in the named definition hard-requires an artifact.
     *
     * getRawDefinition() already returns the decoded definition array, so it is
     * parsed directly (no json_decode). A malformed definition surfaces later as
     * a startLoop validation error rather than being gated here.
     */
    private function definitionRequiresArtifact(string $definition): bool
    {
        $parsed = LoopDefinition::fromArray($this->discovery->getRawDefinition($definition));
        foreach ($parsed->roles as $roleDef) {
            if ($roleDef->artifactRequired) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the given session persona has the artifacts capability enabled.
     *
     * No persona bound (headless), or no PersonaDiscovery wired, means ungated
     * (return true). Feature flags default to enabled when unset.
     */
    private function personaArtifactsEnabled(?string $persona): bool
    {
        if ($persona === null || $persona === '' || $this->personaDiscovery === null) {
            return true;
        }

        if (!$this->personaDiscovery->personaExists($persona)) {
            return true;
        }

        return PersonaPreferences::fromPersonaPath($this->personaDiscovery->getPersonaPath($persona))
            ->isFeatureEnabled('artifacts', true);
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
