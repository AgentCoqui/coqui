<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\SessionStorage;
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
    private const int DEFAULT_GROUP_MAX_ROUNDS = 3;

    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
        private ProfileDiscovery $profileDiscovery,
        private ?ProfileSessionLifecycleManager $lifecycleManager = null,
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
        [$modelRole, $profile, $groupEnabled, $groupMembers, $groupMaxRounds, $error] = $this->resolveRequestedSessionScope($body);
        if ($error instanceof Response) {
            return $error;
        }

        if ($groupEnabled) {
            $compositionKey = $this->storage->buildGroupCompositionKey($groupMembers);
            $activeSessions = $this->storage->listActiveInteractiveGroupSessionsByCompositionKey($compositionKey);

            if ($activeSessions !== [] && !$this->confirmCloseActiveGroupSession($body)) {
                return $this->groupSessionActiveConflict($compositionKey, $groupMembers, $activeSessions);
            }

            if ($activeSessions !== []) {
                $this->storage->closeOtherActiveInteractiveGroupSessionsByCompositionKey(
                    $compositionKey,
                    '',
                    sprintf('api_create_group_session:%s', $compositionKey),
                );
            }

            $model = $this->roleResolver->resolve($modelRole, null);
            $sessionId = $this->storage->createGroupSession($modelRole, $model, $groupMembers, $groupMaxRounds);

            return Router::jsonResponse($this->storage->getSession($sessionId) ?? [
                'id' => $sessionId,
                'model_role' => $modelRole,
                'model' => $model,
                'profile' => null,
                'group_enabled' => 1,
            ], 201);
        }

        if ($profile !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($profile);
            if ($activeSessions !== [] && !$this->confirmCloseActiveProfileSession($body)) {
                return $this->profileSessionActiveConflict($profile, $activeSessions);
            }

            if ($activeSessions !== []) {
                $this->lifecycleManager()?->finalizeOtherActiveInteractiveSessionsForProfile(
                    $profile,
                    '',
                    sprintf('api_create_profile_session:%s', $profile),
                );
            }
        }

        $model = $this->roleResolver->resolve($modelRole, $profile);
        $sessionId = $this->storage->createSession($modelRole, $model, $profile);

        return Router::jsonResponse($this->storage->getSession($sessionId) ?? [
            'id' => $sessionId,
            'model_role' => $modelRole,
            'model' => $model,
            'profile' => $profile,
            'active_project_id' => null,
        ], 201);
    }

    /**
     * POST /api/v1/sessions/resolve  { "model_role"?: "orchestrator", "profile"?: "caelum" }
     */
    public function resolve(ServerRequestInterface $request): Response
    {
        $body = $this->requestBody($request) ?? [];
        [$modelRole, $profile, $groupEnabled, $groupMembers, $groupMaxRounds, $error] = $this->resolveRequestedSessionScope($body);
        if ($error instanceof Response) {
            return $error;
        }

        if ($groupEnabled) {
            $compositionKey = $this->storage->buildGroupCompositionKey($groupMembers);
            $activeSessions = $this->storage->listActiveInteractiveGroupSessionsByCompositionKey($compositionKey);

            if ($activeSessions !== []) {
                $sessionId = (string) $activeSessions[0]['id'];
                $session = $this->storage->getSession($sessionId);

                if ($session !== null) {
                    return Router::jsonResponse($session + ['created' => false]);
                }
            }

            $model = $this->roleResolver->resolve($modelRole, null);
            $sessionId = $this->storage->createGroupSession($modelRole, $model, $groupMembers, $groupMaxRounds);

            return Router::jsonResponse(($this->storage->getSession($sessionId) ?? [
                'id' => $sessionId,
                'model_role' => $modelRole,
                'model' => $model,
                'group_enabled' => 1,
            ]) + ['created' => true], 201);
        }

        if ($profile !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($profile);
            if ($activeSessions !== []) {
                $sessionId = (string) $activeSessions[0]['id'];
                $this->lifecycleManager()?->finalizeOtherActiveInteractiveSessionsForProfile(
                    $profile,
                    $sessionId,
                    sprintf('api_profile_duplicate_cleanup:%s', $profile),
                );

                $session = $this->storage->getSession($sessionId);

                if ($session !== null) {
                    $effectiveRole = $this->normalizeRoleForProfile((string) $session['model_role'], $profile);
                    if ($effectiveRole !== (string) $session['model_role']) {
                        $effectiveModel = $this->roleResolver->resolve($effectiveRole, $profile);
                        $this->storage->updateSessionRole($sessionId, $effectiveRole, $effectiveModel);
                        $session = $this->storage->getSession($sessionId) ?? $session;
                    }

                    return Router::jsonResponse([
                        'id' => $session['id'],
                        'model_role' => $session['model_role'],
                        'model' => $session['model'],
                        'profile' => $session['profile'] ?? null,
                        'active_project_id' => $session['active_project_id'] ?? null,
                        'created' => false,
                    ]);
                }
            }
        }

        $sessionId = $profile === null
            ? $this->storage->getLatestInteractiveUnprofiledSessionId()
            : $this->storage->getLatestInteractiveSessionIdForProfile($profile);

        if ($sessionId !== null) {
            $session = $this->storage->getSession($sessionId);

            if ($session !== null) {
                $effectiveRole = $this->normalizeRoleForProfile((string) $session['model_role'], $profile);
                if ($effectiveRole !== (string) $session['model_role']) {
                    $effectiveModel = $this->roleResolver->resolve($effectiveRole, $profile);
                    $this->storage->updateSessionRole($sessionId, $effectiveRole, $effectiveModel);
                    $session = $this->storage->getSession($sessionId) ?? $session;
                }

                return Router::jsonResponse([
                    'id' => $session['id'],
                    'model_role' => $session['model_role'],
                    'model' => $session['model'],
                    'profile' => $session['profile'] ?? null,
                    'active_project_id' => $session['active_project_id'] ?? null,
                    'created' => false,
                ]);
            }
        }

        $model = $this->roleResolver->resolve($modelRole, $profile);
        $createdSessionId = $this->storage->createSession($modelRole, $model, $profile);

        return Router::jsonResponse([
            'id' => $createdSessionId,
            'model_role' => $modelRole,
            'model' => $model,
            'profile' => $profile,
            'active_project_id' => null,
            'created' => true,
        ], 201);
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

        $isGroupSession = ((int) ($session['group_enabled'] ?? 0)) === 1;

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

            $resolvedGroupMaxRounds = $this->resolveGroupMaxRounds($body['group_max_rounds']);
            if ($resolvedGroupMaxRounds instanceof Response) {
                return $resolvedGroupMaxRounds;
            }

            $this->storage->updateSessionGroupSettings($id, $resolvedGroupMaxRounds);
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

        $members = $this->validateGroupMembers($body['members'] ?? null);
        if ($members instanceof Response) {
            return $members;
        }

        $conflict = $this->resolveGroupMemberConflict($id, $members, $body);
        if ($conflict instanceof Response) {
            return $conflict;
        }

        $groupMaxRounds = is_int($groupSession['group_max_rounds'] ?? null)
            ? $groupSession['group_max_rounds']
            : self::DEFAULT_GROUP_MAX_ROUNDS;

        $this->storage->replaceSessionGroupMembers($id, $members, $groupMaxRounds);

        return Router::jsonResponse($this->storage->getSession($id) ?? $groupSession);
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

        $profile = $this->normalizeProfileValue($body['profile'] ?? null);
        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'profile is required');
        }

        if (!$this->profileDiscovery->profileExists($profile)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile.', $profile),
            );
        }

        $members = $this->storage->listSessionGroupMemberNames($id);
        if (in_array($profile, $members, true)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Profile "%s" is already a member of this session.', $profile));
        }

        $members[] = $profile;

        $conflict = $this->resolveGroupMemberConflict($id, $members, $body);
        if ($conflict instanceof Response) {
            return $conflict;
        }

        $groupMaxRounds = is_int($groupSession['group_max_rounds'] ?? null)
            ? $groupSession['group_max_rounds']
            : self::DEFAULT_GROUP_MAX_ROUNDS;

        $this->storage->replaceSessionGroupMembers($id, $members, $groupMaxRounds);

        return Router::jsonResponse($this->storage->getSession($id) ?? $groupSession);
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

        $body = $this->requestBody($request) ?? [];
        $targetProfile = $this->normalizeProfileValue($profile);
        if ($targetProfile === null) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'profile is required');
        }

        $members = $this->storage->listSessionGroupMemberNames($id);
        if (!in_array($targetProfile, $members, true)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Profile "%s" is not a member of this session.', $targetProfile));
        }

        $updatedMembers = array_values(array_filter(
            $members,
            static fn(string $member): bool => $member !== $targetProfile,
        ));

        if (count($updatedMembers) < 2) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Group sessions must contain at least two members.',
            );
        }

        $conflict = $this->resolveGroupMemberConflict($id, $updatedMembers, $body);
        if ($conflict instanceof Response) {
            return $conflict;
        }

        $groupMaxRounds = is_int($groupSession['group_max_rounds'] ?? null)
            ? $groupSession['group_max_rounds']
            : self::DEFAULT_GROUP_MAX_ROUNDS;

        $this->storage->replaceSessionGroupMembers($id, $updatedMembers, $groupMaxRounds);

        return Router::jsonResponse($this->storage->getSession($id) ?? $groupSession);
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
     * @param array<string, mixed>|null $body
     * @return array{0: string, 1: ?string, 2: bool, 3: list<string>, 4: ?int, 5: ?Response}
     */
    private function resolveRequestedSessionScope(?array $body): array
    {
        $modelRole = is_array($body) && isset($body['model_role'])
            ? trim((string) $body['model_role'])
            : 'orchestrator';

        if ($modelRole === '') {
            return ['orchestrator', null, false, [], null, Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'model_role cannot be empty')];
        }

        $profile = is_array($body) && array_key_exists('profile', $body)
            ? $this->normalizeProfileValue($body['profile'])
            : null;

        $groupEnabled = is_array($body)
            && array_key_exists('group_enabled', $body)
            && filter_var($body['group_enabled'], FILTER_VALIDATE_BOOLEAN);

        $groupMembers = [];
        $groupMaxRounds = null;

        if (!$this->roleResolver->hasRole($modelRole)) {
            return [
                $modelRole,
                $profile,
                false,
                [],
                null,
                Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $modelRole),
                ),
            ];
        }

        if ($groupEnabled) {
            if ($profile !== null) {
                return [
                    $modelRole,
                    $profile,
                    true,
                    [],
                    null,
                    Router::errorResponse(
                        ApiErrorCode::VALIDATION_ERROR,
                        'Group sessions do not support a single active profile.',
                    ),
                ];
            }

            if ($modelRole !== SystemRole::Orchestrator->value) {
                return [
                    $modelRole,
                    null,
                    true,
                    [],
                    null,
                    Router::errorResponse(
                        ApiErrorCode::VALIDATION_ERROR,
                        'Only the orchestrator can manage group sessions.',
                    ),
                ];
            }

            $groupMembers = $this->validateGroupMembers($body['members'] ?? null);
            if ($groupMembers instanceof Response) {
                return [$modelRole, null, true, [], null, $groupMembers];
            }

            $groupMaxRounds = $this->resolveGroupMaxRounds($body['group_max_rounds'] ?? self::DEFAULT_GROUP_MAX_ROUNDS);
            if ($groupMaxRounds instanceof Response) {
                return [$modelRole, null, true, [], null, $groupMaxRounds];
            }
        } elseif (is_array($body) && array_key_exists('members', $body)) {
            return [
                $modelRole,
                $profile,
                false,
                [],
                null,
                Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'members may only be provided when group_enabled is true.',
                ),
            ];
        }

        if ($profile !== null && !$this->profileDiscovery->profileExists($profile)) {
            return [
                $modelRole,
                $profile,
                false,
                [],
                null,
                Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile.', $profile),
                ),
            ];
        }

        $roleError = $this->validateProfileRole($profile, $modelRole);
        if ($roleError instanceof Response) {
            return [$modelRole, $profile, false, [], null, $roleError];
        }

        return [$modelRole, $profile, $groupEnabled, $groupMembers, $groupMaxRounds, null];
    }

    /**
     * @param mixed $value
     */
    private function resolveGroupMaxRounds(mixed $value): int|Response
    {
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'group_max_rounds must be a positive integer');
        }

        $groupMaxRounds = (int) $value;
        if ($groupMaxRounds < 1) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'group_max_rounds must be greater than 0');
        }

        return $groupMaxRounds;
    }

    /**
     * @param mixed $members
     * @return list<string>|Response
     */
    private function validateGroupMembers(mixed $members): array|Response
    {
        if (!is_array($members)) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'members is required for group sessions');
        }

        $normalized = [];
        foreach ($members as $member) {
            if (!is_string($member)) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Each group member must be a profile name string');
            }

            $profile = strtolower(trim($member));
            if ($profile === '') {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Group member names cannot be empty');
            }

            if (!$this->profileDiscovery->profileExists($profile)) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile.', $profile),
                );
            }

            if (isset($normalized[$profile])) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Duplicate group member "%s" is not allowed.', $profile),
                );
            }

            $normalized[$profile] = true;
        }

        $profiles = array_keys($normalized);
        if (count($profiles) < 2) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Group sessions must contain at least two members.');
        }

        sort($profiles, SORT_STRING);

        return $profiles;
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

    private function normalizeRoleForProfile(string $role, ?string $profile): string
    {
        $preferences = $this->loadProfilePreferences($profile);
        if ($preferences === null || $preferences->isRoleAllowed($role)) {
            return $role;
        }

        return SystemRole::Orchestrator->value;
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
        if (((int) ($session['group_enabled'] ?? 0)) !== 1) {
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

    /**
     * @param list<string> $members
     * @param array<int, array<string, mixed>> $activeSessions
     */
    private function groupSessionActiveConflict(string $compositionKey, array $members, array $activeSessions): Response
    {
        $primary = $activeSessions[0] ?? [];

        return Router::errorResponse(
            ApiErrorCode::GROUP_SESSION_ACTIVE,
            'An active group session already exists for this exact member composition. Confirm closure before creating or editing into a duplicate group.',
            [
                'group_composition_key' => $compositionKey,
                'members' => $members,
                'active_session_id' => $primary['id'] ?? null,
                'active_session_count' => count($activeSessions),
                'confirm_field' => 'confirm_close_active_group_session',
            ],
        );
    }

    /**
     * @param list<string> $members
     * @param array<string, mixed>|null $body
     */
    private function resolveGroupMemberConflict(string $sessionId, array $members, ?array $body): ?Response
    {
        $compositionKey = $this->storage->buildGroupCompositionKey($members);
        $activeSessions = $this->storage->listActiveInteractiveGroupSessionsByCompositionKey($compositionKey);
        $conflicts = array_values(array_filter(
            $activeSessions,
            static fn(array $activeSession): bool => (string) ($activeSession['id'] ?? '') !== $sessionId,
        ));

        if ($conflicts === []) {
            return null;
        }

        if (!$this->confirmCloseActiveGroupSession($body)) {
            return $this->groupSessionActiveConflict($compositionKey, $members, $conflicts);
        }

        $this->storage->closeOtherActiveInteractiveGroupSessionsByCompositionKey(
            $compositionKey,
            $sessionId,
            sprintf('api_group_membership_update:%s', $compositionKey),
        );

        return null;
    }

    private function lifecycleManager(): ?ProfileSessionLifecycleManager
    {
        return $this->lifecycleManager;
    }
}
