<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Session\GroupSessionTypeHandler;
use CoquiBot\Coqui\Api\Session\InteractiveSessionTypeHandler;
use CoquiBot\Coqui\Api\Session\SessionScopeResolver;
use CoquiBot\Coqui\Api\Session\SessionTypeRegistry;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Exception\GroupSessionException;
use CoquiBot\Coqui\Exception\SessionTypeException;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\GroupSessionService;
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

        $sessions = $this->storage->listSessions($limit, true, $status === null, $status, $profile, $unprofiledOnly);

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

        return Router::jsonResponse($session);
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

        $isGroupSession = SessionType::fromSessionRow($session) === SessionType::Group;

        if (array_key_exists('group_enabled', $body)) {
            $requestedGroupEnabled = filter_var($body['group_enabled'], FILTER_VALIDATE_BOOLEAN);
            if ($requestedGroupEnabled !== $isGroupSession) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Use session creation for new group sessions. Existing sessions cannot change group mode via PATCH.',
                );
            }
        }

        if (array_key_exists('members', $body)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Use the session group member endpoints to manage members.',
            );
        }

        if (isset($body['title'])) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Title cannot be empty');
            }
            $this->storage->updateSessionTitle($id, $title);
        }

        if (isset($body['model_role'])) {
            $role = trim((string) $body['model_role']);
            if ($role === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'model_role cannot be empty');
            }

            if ($isGroupSession && $role !== SystemRole::Orchestrator->value) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Group sessions must remain orchestrator-managed.',
                );
            }

            if (!$this->roleResolver->hasRole($role)) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $role),
                );
            }

            $session['model_role'] = $role;
        }

        $resolvedProfile = $this->normalizeProfileValue($session['profile'] ?? null);
        if (array_key_exists('profile', $body)) {
            if ($isGroupSession) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Group sessions do not support a single active profile.',
                );
            }

            $profile = strtolower(trim((string) ($body['profile'] ?? '')));

            if ($profile !== '' && !$this->profileDiscovery->profileExists($profile)) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile with an empty string.', $profile),
                );
            }

            $resolvedProfile = $profile !== '' ? $profile : null;
        }

        $resolvedRole = (string) ($session['model_role'] ?? 'orchestrator');
        if (!$isGroupSession) {
            $roleError = $this->validateProfileRole($resolvedProfile, $resolvedRole);
            if ($roleError instanceof Response) {
                return $roleError;
            }
        }

        if (!$isGroupSession && $resolvedProfile !== null && !$this->storage->isSessionClosed($id)) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($resolvedProfile);
            $conflicts = array_values(array_filter(
                $activeSessions,
                static fn(array $activeSession): bool => (string) ($activeSession['id'] ?? '') !== $id,
            ));

            if ($conflicts !== [] && !$this->confirmCloseActiveProfileSession($body)) {
                return $this->profileSessionActiveConflict($resolvedProfile, $conflicts);
            }

            if ($conflicts !== []) {
                $this->lifecycleManager()?->finalizeOtherActiveInteractiveSessionsForProfile(
                    $resolvedProfile,
                    $id,
                    sprintf('api_profile_reassignment:%s', $resolvedProfile),
                );
            }
        }

        if (array_key_exists('profile', $body)) {
            $this->storage->updateSessionProfile($id, $resolvedProfile);
        }

        if (array_key_exists('group_max_rounds', $body)) {
            if (!$isGroupSession) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'group_max_rounds is only valid for group sessions.',
                );
            }

            try {
                $this->groupSessions()->updateSessionMaxRounds($id, $body['group_max_rounds']);
            } catch (GroupSessionException $e) {
                return $this->groupSessionErrorResponse($e);
            }
        }

        $resolvedModel = $this->roleResolver->resolve($resolvedRole, $isGroupSession ? null : $resolvedProfile);
        $this->storage->updateSessionRole($id, $resolvedRole, $resolvedModel);

        // Return the updated session
        $updated = $this->storage->getSession($id);

        return Router::jsonResponse($updated ?? $session);
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

        $groupSession = $this->requireGroupSession($session);
        if ($groupSession instanceof Response) {
            return $groupSession;
        }

        $members = $this->storage->listSessionGroupMembers($id);

        return Router::jsonResponse([
            'session_id' => $id,
            'group_enabled' => true,
            'group_composition_key' => $groupSession['group_composition_key'] ?? null,
            'group_max_rounds' => $groupSession['group_max_rounds'] ?? null,
            'members' => $members,
            'count' => count($members),
        ]);
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

        $groupSession = $this->requireGroupSession($session);
        if ($groupSession instanceof Response) {
            return $groupSession;
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        try {
            $members = $this->groupSessions()->normalizeMembers($body['members'] ?? null);
            $result = $this->groupSessions()->replaceSessionMembers(
                sessionId: $id,
                members: $members,
                groupMaxRounds: is_int($groupSession['group_max_rounds'] ?? null)
                    ? $groupSession['group_max_rounds']
                    : GroupSessionService::DEFAULT_MAX_ROUNDS,
                confirmCloseActive: $this->confirmCloseActiveGroupSession($body),
                closureReasonPrefix: 'api_group_membership_update',
            );
        } catch (GroupSessionException $e) {
            return $this->groupSessionErrorResponse($e);
        }

        return Router::jsonResponse($result->session);
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

        $groupSession = $this->requireGroupSession($session);
        if ($groupSession instanceof Response) {
            return $groupSession;
        }

        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        try {
            $profile = $this->groupSessions()->normalizeMember($body['profile'] ?? null);
            $result = $this->groupSessions()->addSessionMember(
                sessionId: $id,
                profile: $profile,
                confirmCloseActive: $this->confirmCloseActiveGroupSession($body),
                groupMaxRounds: is_int($groupSession['group_max_rounds'] ?? null)
                    ? $groupSession['group_max_rounds']
                    : GroupSessionService::DEFAULT_MAX_ROUNDS,
                closureReasonPrefix: 'api_group_membership_update',
            );
        } catch (GroupSessionException $e) {
            return $this->groupSessionErrorResponse($e);
        }

        return Router::jsonResponse($result->session);
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

        $groupSession = $this->requireGroupSession($session);
        if ($groupSession instanceof Response) {
            return $groupSession;
        }

        try {
            $targetProfile = $this->groupSessions()->normalizeMember($profile);
            $result = $this->groupSessions()->removeSessionMember(
                sessionId: $id,
                profile: $targetProfile,
                confirmCloseActive: $this->confirmCloseActiveGroupSession($this->requestBody($request) ?? []),
                groupMaxRounds: is_int($groupSession['group_max_rounds'] ?? null)
                    ? $groupSession['group_max_rounds']
                    : GroupSessionService::DEFAULT_MAX_ROUNDS,
                closureReasonPrefix: 'api_group_membership_update',
            );
        } catch (GroupSessionException $e) {
            return $this->groupSessionErrorResponse($e);
        }

        return Router::jsonResponse($result->session);
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

    private function validateProfileRole(?string $profile, string $role): ?Response
    {
        $preferences = $this->loadProfilePreferences($profile);
        if ($preferences === null || $preferences->isRoleAllowed($role)) {
            return null;
        }

        return Router::errorResponse(
            ApiErrorCode::VALIDATION_ERROR,
            sprintf('Profile "%s" does not allow role "%s".', $profile, $role),
        );
    }

    private function loadProfilePreferences(?string $profile): ?ProfilePreferences
    {
        if ($profile === null || !$this->profileDiscovery->profileExists($profile)) {
            return null;
        }

        return ProfilePreferences::fromProfilePath($this->profileDiscovery->getProfilePath($profile));
    }

    private function normalizeProfileValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $profile = strtolower(trim($value));

        return $profile !== '' ? $profile : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>|Response
     */
    private function requireGroupSession(array $session): array|Response
    {
        if (SessionType::fromSessionRow($session) !== SessionType::Group) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Session is not a group session.');
        }

        return $session;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function confirmCloseActiveProfileSession(?array $body): bool
    {
        return is_array($body)
            && array_key_exists('confirm_close_active_profile_session', $body)
            && filter_var($body['confirm_close_active_profile_session'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function confirmCloseActiveGroupSession(?array $body): bool
    {
        return is_array($body)
            && array_key_exists('confirm_close_active_group_session', $body)
            && filter_var($body['confirm_close_active_group_session'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<int, array<string, mixed>> $activeSessions
     */
    private function profileSessionActiveConflict(string $profile, array $activeSessions): Response
    {
        $primary = $activeSessions[0] ?? [];

        return Router::errorResponse(
            ApiErrorCode::PROFILE_SESSION_ACTIVE,
            sprintf('Profile "%s" already has an active session. Confirm closure before starting or reassigning a fresh session.', $profile),
            [
                'profile' => $profile,
                'active_session_id' => $primary['id'] ?? null,
                'active_session_count' => count($activeSessions),
                'confirm_field' => 'confirm_close_active_profile_session',
            ],
        );
    }

    private function lifecycleManager(): ?ProfileSessionLifecycleManager
    {
        return $this->lifecycleManager;
    }

    private function groupSessions(): GroupSessionService
    {
        return $this->groupSessionService ?? new GroupSessionService(
            $this->storage,
            $this->roleResolver,
            $this->profileDiscovery,
        );
    }

    private function groupSessionErrorResponse(GroupSessionException $e): Response
    {
        return $this->sessionTypeErrorResponse($e);
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
            new InteractiveSessionTypeHandler(
                $this->storage,
                $this->roleResolver,
                $this->profileDiscovery,
                $this->lifecycleManager,
            ),
            new GroupSessionTypeHandler($this->groupSessions()),
        );
    }
}
