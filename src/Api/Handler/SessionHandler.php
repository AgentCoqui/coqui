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

        $sessions = $this->storage->listSessions($limit, true, $status === null, $status);

        return Router::jsonResponse([
            'sessions' => $sessions,
            'count' => count($sessions),
            'status' => $status ?? 'active',
            'counts' => $this->storage->getSessionStatusCounts(),
        ]);
    }

    /**
     * POST /api/v1/sessions  { "model_role"?: "orchestrator" }
     */
    public function create(ServerRequestInterface $request): Response
    {
        [$modelRole, $profile, $error] = $this->resolveRequestedSessionScope($request);
        if ($error instanceof Response) {
            return $error;
        }

        $body = $this->requestBody($request);

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

        return Router::jsonResponse([
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
        [$modelRole, $profile, $error] = $this->resolveRequestedSessionScope($request);
        if ($error instanceof Response) {
            return $error;
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
        $roleError = $this->validateProfileRole($resolvedProfile, $resolvedRole);
        if ($roleError instanceof Response) {
            return $roleError;
        }

        if ($resolvedProfile !== null && !$this->storage->isSessionClosed($id)) {
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

        $resolvedModel = $this->roleResolver->resolve($resolvedRole, $resolvedProfile);
        $this->storage->updateSessionRole($id, $resolvedRole, $resolvedModel);

        // Return the updated session
        $updated = $this->storage->getSession($id);

        return Router::jsonResponse($updated ?? $session);
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
     * @return array{0: string, 1: ?string, 2: ?Response}
     */
    private function resolveRequestedSessionScope(ServerRequestInterface $request): array
    {
        $body = json_decode((string) $request->getBody(), true);
        $modelRole = is_array($body) && isset($body['model_role'])
            ? trim((string) $body['model_role'])
            : 'orchestrator';

        if ($modelRole === '') {
            return ['orchestrator', null, Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'model_role cannot be empty')];
        }

        $profile = is_array($body) && array_key_exists('profile', $body)
            ? $this->normalizeProfileValue($body['profile'])
            : null;

        if (!$this->roleResolver->hasRole($modelRole)) {
            return [
                $modelRole,
                $profile,
                Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $modelRole),
                ),
            ];
        }

        if ($profile !== null && !$this->profileDiscovery->profileExists($profile)) {
            return [
                $modelRole,
                $profile,
                Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile.', $profile),
                ),
            ];
        }

        $roleError = $this->validateProfileRole($profile, $modelRole);
        if ($roleError instanceof Response) {
            return [$modelRole, $profile, $roleError];
        }

        return [$modelRole, $profile, null];
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
     * @param array<string, mixed>|null $body
     */
    private function confirmCloseActiveProfileSession(?array $body): bool
    {
        return is_array($body)
            && array_key_exists('confirm_close_active_profile_session', $body)
            && filter_var($body['confirm_close_active_profile_session'], FILTER_VALIDATE_BOOLEAN);
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
}
