<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Session\GroupSessionEndpointHandlerInterface;
use CoquiBot\Coqui\Api\Session\GroupSessionTypeHandler;
use CoquiBot\Coqui\Api\Session\InteractiveSessionTypeHandler;
use CoquiBot\Coqui\Api\Session\SessionScopeResolver;
use CoquiBot\Coqui\Api\Session\SessionTypeOperationResult;
use CoquiBot\Coqui\Api\Session\SessionUpdateRequestResolver;
use CoquiBot\Coqui\Api\Session\SessionTypeRegistry;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Exception\SessionTypeException;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\GroupSessionService;
use CoquiBot\Coqui\Support\InteractiveSessionService;
use CoquiBot\Coqui\Support\PersonaSessionLifecycleManager;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Session CRUD endpoints.
 *
 * GET    /api/v1/sessions                    — list sessions
 * POST   /api/v1/sessions                    — create session
 * POST   /api/v1/sessions/resolve            — resolve or create scoped interactive session
 * GET    /api/v1/sessions/{id}               — get session detail
 * GET    /api/v1/sessions/{id}/summary       — get session summary counts
 * PATCH  /api/v1/sessions/{id}               — update session (title)
 * DELETE /api/v1/sessions/{id}               — delete session
 * GET    /api/v1/sessions/{id}/child-runs    — list child agent runs
 */
final readonly class SessionHandler
{
    use DecodesRequestBody;

    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
        private PersonaDiscovery $personaDiscovery,
        private ?PersonaSessionLifecycleManager $lifecycleManager = null,
        private ?GroupSessionService $groupSessionService = null,
        private ?ArtifactStore $artifactStore = null,
    ) {}

    /**
     * GET /api/v1/sessions?limit=50
     */
    public function list(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? min((int) $params['limit'], 200) : 50;
        $includeClosed = filter_var($params['include_closed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $status = isset($params['status']) ? strtolower(trim((string) $params['status'])) : null;
        $personaFilterSpecified = array_key_exists('persona_id', $params);
        $personaParam = $personaFilterSpecified ? strtolower(trim((string) ($params['persona_id'] ?? ''))) : null;

        if ($status === '') {
            $status = null;
        }

        if ($status !== null && !in_array($status, ['active', 'closed', 'archived', 'all'], true)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Invalid status filter. Use active, closed, archived, or all.',
            );
        }

        if ($status === null && $includeClosed) {
            $status = 'all';
        }

        $persona = null;
        $unpersonaScopedOnly = false;
        if ($personaFilterSpecified) {
            if ($personaParam === null || $personaParam === '' || $personaParam === 'none') {
                $unpersonaScopedOnly = true;
            } else {
                if (!$this->personaDiscovery->personaExists($personaParam)) {
                    return Router::errorResponse(
                        ApiErrorCode::VALIDATION_ERROR,
                        sprintf('Unknown persona "%s". Use GET /api/v1/personas to see available personas.', $personaParam),
                    );
                }
                $persona = $personaParam;
            }
        }

        $sessions = array_map(
            fn(array $session): array => $this->normalizeSessionForResponse($session),
            $this->storage->listSessions($limit, true, $status === null, $status, $persona, $unpersonaScopedOnly),
        );

        return Router::jsonResponse([
            'sessions' => $sessions,
            'count' => count($sessions),
            'status' => $status ?? 'active',
            'persona_id' => $personaFilterSpecified ? ($persona ?? 'none') : null,
            'counts' => $this->storage->getSessionStatusCounts(),
        ]);
    }

    /**
     * POST /api/v1/sessions  { "model_role"?: "orchestrator" }
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = $this->decodeJsonObjectOrNull($request) ?? [];
        $scope = $this->sessionScopeResolver()->resolve($body);
        if ($scope instanceof Response) {
            return $scope;
        }

        try {
            $result = $this->sessionTypeRegistry()->handlerFor($scope->type)->create($scope);
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($this->operationResponseBody($result), 201);
    }

    /**
     * POST /api/v1/sessions/resolve  { "model_role"?: "orchestrator", "persona_id"?: "caelum" }
     */
    public function resolve(ServerRequestInterface $request): Response
    {
        $body = $this->decodeJsonObjectOrNull($request) ?? [];
        $scope = $this->sessionScopeResolver()->resolve($body);
        if ($scope instanceof Response) {
            return $scope;
        }

        try {
            $result = $this->sessionTypeRegistry()->handlerFor($scope->type)->resolve($scope);
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($this->operationResponseBody($result), $result->created ? 201 : 200);
    }

    /**
     * GET /api/v1/sessions/{id}
     */
    public function get(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        return Router::jsonResponse($this->normalizeSessionForResponse($session));
    }

    /**
     * GET /api/v1/sessions/{id}/summary
     */
    public function summary(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $summary = $this->storage->getSessionSummary($id);
        if ($summary === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return Router::jsonResponse($summary);
    }

    /**
     * PATCH /api/v1/sessions/{id}  { "title": "..." }
     */
    public function update(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $body = $this->decodeJsonObjectOrNull($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $updateRequest = $this->sessionUpdateRequestResolver()->resolve($body);
        if ($updateRequest instanceof Response) {
            return $updateRequest;
        }

        try {
            $updated = $this->sessionTypeRegistry()->handlerFor(SessionType::fromSessionRow($session))->update($session, $updateRequest);
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($updated);
    }

    /**
     * GET /api/v1/sessions/{id}/members
     */
    public function members(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        try {
            $members = $this->groupSessionEndpointHandler($session)->listMembers($session);
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($members);
    }

    /**
     * PUT /api/v1/sessions/{id}/members
     */
    public function replaceMembers(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        try {
            $updated = $this->groupSessionEndpointHandler($session)->replaceMembers($session, $this->decodeJsonObjectOrNull($request));
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($updated);
    }

    /**
     * POST /api/v1/sessions/{id}/members
     */
    public function addMember(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        try {
            $updated = $this->groupSessionEndpointHandler($session)->addMember($session, $this->decodeJsonObjectOrNull($request));
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($updated);
    }

    /**
     * DELETE /api/v1/sessions/{id}/members/{persona}
     */
    public function removeMember(ServerRequestInterface $request, string $id, string $persona): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        try {
            $updated = $this->groupSessionEndpointHandler($session)->removeMember($session, $persona, $this->decodeJsonObjectOrNull($request));
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($updated);
    }

    /**
     * DELETE /api/v1/sessions/{id}
     */
    public function delete(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        // Project-linked artifacts persist and block session deletion — reject up
        // front so nothing is mutated on the rejected path.
        if ($this->artifactStore?->hasProjectLinkedArtifacts($id)) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                'Session has project-linked artifacts. Detach them from the project first.',
            );
        }

        // Ownership-based cleanup: remove session-only artifact files (their rows
        // cascade-delete with the session).
        $this->artifactStore?->cleanupSessionArtifacts($id);

        $this->storage->deleteSession($id);

        return Router::jsonResponse(['deleted' => true, 'id' => $id]);
    }

    /**
     * GET /api/v1/sessions/{id}/child-runs
     */
    public function childRuns(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $runs = $this->storage->getChildRuns($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'child_runs' => $runs,
            'count' => count($runs),
        ]);
    }

    private function interactiveSessions(): InteractiveSessionService
    {
        return new InteractiveSessionService(
            $this->storage,
            $this->roleResolver,
            $this->personaDiscovery,
            $this->lifecycleManager,
        );
    }

    private function groupSessions(): GroupSessionService
    {
        return $this->groupSessionService ?? new GroupSessionService(
            $this->storage,
            $this->roleResolver,
            $this->personaDiscovery,
        );
    }

    /**
     * @param array<string, mixed> $session
     */
    private function groupSessionEndpointHandler(array $session): GroupSessionEndpointHandlerInterface
    {
        $handler = $this->sessionTypeRegistry()->handlerFor(SessionType::fromSessionRow($session));
        if (!$handler instanceof GroupSessionEndpointHandlerInterface) {
            throw new SessionTypeException(ApiErrorCode::VALIDATION_ERROR, 'Session is not a group session.');
        }

        return $handler;
    }

    private function sessionTypeErrorResponse(SessionTypeException $e): Response
    {
        return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details);
    }

    /**
     * @return array<string, mixed>
     */
    private function operationResponseBody(SessionTypeOperationResult $result): array
    {
        /** @var array{session?: array<string, mixed>, created?: bool, closedSessionIds?: list<string>} $resultData */
        $resultData = get_object_vars($result);

        return $result->session + [
            'created' => $result->created,
            'closed_session_ids' => $resultData['closedSessionIds'] ?? [],
        ];
    }

    private function sessionScopeResolver(): SessionScopeResolver
    {
        return new SessionScopeResolver(
            $this->roleResolver,
            $this->personaDiscovery,
            $this->groupSessions(),
        );
    }

    private function sessionTypeRegistry(): SessionTypeRegistry
    {
        return new SessionTypeRegistry(
            new InteractiveSessionTypeHandler($this->interactiveSessions()),
            new GroupSessionTypeHandler($this->groupSessions(), $this->storage, $this->roleResolver),
        );
    }

    private function sessionUpdateRequestResolver(): SessionUpdateRequestResolver
    {
        return new SessionUpdateRequestResolver();
    }

    /**
     * Layer CAP 0.5.0 session fields onto the rich app-facing row.
     *
     * The former role-resolver back-fill is gone: model passes through nullable,
     * so a stored null (⇒ inherit per Personas §5) survives to the wire — CORE-15.
     * The opaque workspace, pinned, version, kind and derived members[] surface
     * here — CORE-19. Rich fields (session_type, group_members, active_project_id,
     * …) are retained additively for the application layer.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function normalizeSessionForResponse(array $session): array
    {
        $session['members'] = self::deriveMembers($session);
        $session['kind'] = is_string($session['kind'] ?? null) && $session['kind'] !== ''
            ? (string) $session['kind']
            : 'chat';
        $session['pinned'] = (bool) ($session['pinned'] ?? false);
        $session['version'] = is_scalar($session['version'] ?? null) ? max(1, (int) $session['version']) : 1;
        $session['workspace'] = is_string($session['workspace'] ?? null) && $session['workspace'] !== ''
            ? (string) $session['workspace']
            : null;
        $session['model'] = is_string($session['model'] ?? null) && $session['model'] !== ''
            ? (string) $session['model']
            : null;

        return $session;
    }

    /**
     * Produce a strict CAP 0.5.0 `session.json` wire object from a normalized
     * session row (as returned by {@see SessionStorage::getSession()}).
     *
     * This is the conformance producer: it emits exactly the schema's property
     * set (no rich extras), derives `status`/`members`, passes `model`/`workspace`
     * through nullable, and Z-normalizes the timestamps.
     *
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public static function toWire(array $session): array
    {
        $isArchived = (int) ($session['is_archived'] ?? 0) === 1;
        $isClosed = (int) ($session['is_closed'] ?? 0) === 1;
        $title = $session['title'] ?? null;

        return [
            'id' => (string) ($session['id'] ?? ''),
            'persona_id' => (string) ($session['persona_id'] ?? ''),
            'members' => self::deriveMembers($session),
            'kind' => is_string($session['kind'] ?? null) && $session['kind'] !== ''
                ? (string) $session['kind']
                : 'chat',
            'status' => $isArchived ? 'archived' : ($isClosed ? 'closed' : 'active'),
            'pinned' => (bool) ($session['pinned'] ?? false),
            'version' => is_scalar($session['version'] ?? null) ? max(1, (int) $session['version']) : 1,
            'model' => is_string($session['model'] ?? null) && $session['model'] !== ''
                ? (string) $session['model']
                : null,
            'workspace' => is_string($session['workspace'] ?? null) && $session['workspace'] !== ''
                ? (string) $session['workspace']
                : null,
            'title' => is_string($title) ? $title : null,
            'token_count' => (int) ($session['token_count'] ?? 0),
            'created_at' => self::toUtcZ($session['created_at'] ?? null),
            'updated_at' => self::toUtcZ($session['updated_at'] ?? null),
        ];
    }

    /**
     * Produce a strict CAP 0.5.0 `child-run.json` wire object from a child-run row
     * (as returned by {@see SessionStorage::getChildRuns()}).
     *
     * This is the conformance producer: it emits exactly the schema's property set
     * (no rich extras), so it is `additionalProperties:false`-clean. `model` is
     * NULLABLE (`oneOf[ModelId,null]`) — unlike turn.json's non-null model it is
     * emitted as null-or-string (null ⇒ inherit), never omitted or coerced to ''.
     * `result`, `parent_turn_id` and `completed_at` pass through nullable; `status`
     * is a closed-set string; the token triad defaults to 0.
     *
     * @param array<string, mixed> $run
     * @return array<string, mixed>
     */
    public static function childRunToWire(array $run): array
    {
        $model = $run['model'] ?? null;
        $result = $run['result'] ?? null;
        $parentTurnId = $run['parent_turn_id'] ?? null;
        $completedAt = $run['completed_at'] ?? null;
        $completedAt = is_string($completedAt) && $completedAt !== '' ? $completedAt : null;

        return [
            'id' => (string) ($run['id'] ?? ''),
            'parent_session_id' => (string) ($run['parent_session_id'] ?? ''),
            'parent_turn_id' => is_string($parentTurnId) && $parentTurnId !== '' ? $parentTurnId : null,
            'role' => (string) ($run['role'] ?? ''),
            'model' => is_string($model) && $model !== '' ? $model : null,
            'prompt' => (string) ($run['prompt'] ?? ''),
            'result' => is_string($result) ? $result : null,
            'status' => (string) ($run['status'] ?? 'completed'),
            'prompt_tokens' => max(0, (int) ($run['prompt_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int) ($run['completion_tokens'] ?? 0)),
            'total_tokens' => max(0, (int) ($run['total_tokens'] ?? 0)),
            'created_at' => self::toUtcZ($run['created_at'] ?? null),
            'completed_at' => $completedAt === null ? null : self::toUtcZ($completedAt),
        ];
    }

    /**
     * Owner persona unioned with any group members, as a unique id list.
     *
     * @param array<string, mixed> $session
     * @return list<string>
     */
    private static function deriveMembers(array $session): array
    {
        $members = [];

        $owner = $session['persona_id'] ?? null;
        if (is_string($owner) && $owner !== '') {
            $members[] = $owner;
        }

        $groupMembers = $session['group_members'] ?? [];
        if (is_array($groupMembers)) {
            foreach ($groupMembers as $member) {
                $pid = is_array($member) ? ($member['persona_id'] ?? null) : $member;
                if (is_string($pid) && $pid !== '') {
                    $members[] = $pid;
                }
            }
        }

        return array_values(array_unique($members));
    }

    /**
     * Normalize an RFC-3339 timestamp to UTC with a Z suffix (CAP Timestamp).
     */
    private static function toUtcZ(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $value;
        }
    }
}
