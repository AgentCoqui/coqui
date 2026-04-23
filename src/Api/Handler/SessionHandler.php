<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Session\GroupSessionEndpointHandlerInterface;
use CoquiBot\Coqui\Api\Session\GroupSessionTypeHandler;
use CoquiBot\Coqui\Api\Session\InteractiveSessionTypeHandler;
use CoquiBot\Coqui\Api\Session\SessionScopeResolver;
use CoquiBot\Coqui\Api\Session\SessionUpdateRequestResolver;
use CoquiBot\Coqui\Api\Session\SessionTypeRegistry;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Exception\SessionTypeException;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\GroupSessionService;
use CoquiBot\Coqui\Support\InteractiveSessionService;
use CoquiBot\Coqui\Support\ProfileSessionLifecycleManager;
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
    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
        private ProfileDiscovery $profileDiscovery,
        private ?ProfileSessionLifecycleManager $lifecycleManager = null,
        private ?GroupSessionService $groupSessionService = null,
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
        $profileFilterSpecified = array_key_exists('profile', $params);
        $profileParam = $profileFilterSpecified ? strtolower(trim((string) ($params['profile'] ?? ''))) : null;

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

        $profile = null;
        $unprofiledOnly = false;
        if ($profileFilterSpecified) {
            if ($profileParam === null || $profileParam === '' || $profileParam === 'none') {
                $unprofiledOnly = true;
            } else {
                if (!$this->profileDiscovery->profileExists($profileParam)) {
                    return Router::errorResponse(
                        ApiErrorCode::VALIDATION_ERROR,
                        sprintf('Unknown profile "%s". Use GET /api/v1/profiles to see available profiles.', $profileParam),
                    );
                }
                $profile = $profileParam;
            }
        }

        $sessions = array_map(
            fn(array $session): array => $this->normalizeSessionForResponse($session),
            $this->storage->listSessions($limit, true, $status === null, $status, $profile, $unprofiledOnly),
        );

        return Router::jsonResponse([
            'sessions' => $sessions,
            'count' => count($sessions),
            'status' => $status ?? 'active',
            'profile' => $profileFilterSpecified ? ($profile ?? 'none') : null,
            'counts' => $this->storage->getSessionStatusCounts(),
        ]);
    }

    /**
     * POST /api/v1/sessions  { "model_role"?: "orchestrator" }
     */
    public function create(ServerRequestInterface $request): Response
    {
        $body = $this->requestBody($request) ?? [];
        $scope = $this->sessionScopeResolver()->resolve($body);
        if ($scope instanceof Response) {
            return $scope;
        }

        try {
            $result = $this->sessionTypeRegistry()->handlerFor($scope->type)->create($scope);
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($result->session, 201);
    }

    /**
     * POST /api/v1/sessions/resolve  { "model_role"?: "orchestrator", "profile"?: "caelum" }
     */
    public function resolve(ServerRequestInterface $request): Response
    {
        $body = $this->requestBody($request) ?? [];
        $scope = $this->sessionScopeResolver()->resolve($body);
        if ($scope instanceof Response) {
            return $scope;
        }

        try {
            $result = $this->sessionTypeRegistry()->handlerFor($scope->type)->resolve($scope);
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($result->session + ['created' => $result->created], $result->created ? 201 : 200);
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

        $body = $this->requestBody($request);
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
            $updated = $this->groupSessionEndpointHandler($session)->replaceMembers($session, $this->requestBody($request));
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
            $updated = $this->groupSessionEndpointHandler($session)->addMember($session, $this->requestBody($request));
        } catch (SessionTypeException $e) {
            return $this->sessionTypeErrorResponse($e);
        }

        return Router::jsonResponse($updated);
    }

    /**
     * DELETE /api/v1/sessions/{id}/members/{profile}
     */
    public function removeMember(ServerRequestInterface $request, string $id, string $profile): Response
    {
        $session = SessionAccess::requireWritableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        try {
            $updated = $this->groupSessionEndpointHandler($session)->removeMember($session, $profile, $this->requestBody($request));
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
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $this->storage->deleteSession($id);

        return Router::jsonResponse(['deleted' => true, 'id' => $id]);
    }

    /**
     * GET /api/v1/sessions/{id}/child-runs
     */
    public function childRuns(ServerRequestInterface $request, string $id): Response
    {
        $session = $this->storage->getSession($id);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        $runs = $this->storage->getChildRuns($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'child_runs' => $runs,
            'count' => count($runs),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function interactiveSessions(): InteractiveSessionService
    {
        return new InteractiveSessionService(
            $this->storage,
            $this->roleResolver,
            $this->profileDiscovery,
            $this->lifecycleManager,
        );
    }

    private function groupSessions(): GroupSessionService
    {
        return $this->groupSessionService ?? new GroupSessionService(
            $this->storage,
            $this->roleResolver,
            $this->profileDiscovery,
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

    private function sessionScopeResolver(): SessionScopeResolver
    {
        return new SessionScopeResolver(
            $this->roleResolver,
            $this->profileDiscovery,
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
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function normalizeSessionForResponse(array $session): array
    {
        $model = is_string($session['model'] ?? null) ? trim((string) $session['model']) : '';
        $role = is_string($session['model_role'] ?? null) ? trim((string) $session['model_role']) : '';

        if ($model !== '' && !str_starts_with($model, 'channel:')) {
            return $session;
        }

        if ($role === '') {
            return $session;
        }

        $profile = is_string($session['profile'] ?? null) ? trim((string) $session['profile']) : null;
        if ($profile === '') {
            $profile = null;
        }

        $session['model'] = $this->roleResolver->resolve($role, $profile);

        return $session;
    }
}
