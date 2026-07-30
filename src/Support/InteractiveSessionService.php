<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Session\SessionUpdateRequest;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaPreferences;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Exception\SessionTypeException;
use CoquiBot\Coqui\Storage\SessionStorage;

final readonly class InteractiveSessionService
{
    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
        private PersonaDiscovery $profileDiscovery,
        private ?PersonaSessionLifecycleManager $lifecycleManager = null,
    ) {}

    public function createSession(string $modelRole, ?string $profile = null): InteractiveSessionOperationResult
    {
        $model = $this->roleResolver->resolve($modelRole, $profile);
        $sessionId = $this->storage->createSession(
            modelRole: $modelRole,
            model: $model,
            profile: $profile,
            sessionType: SessionType::Interactive,
        );

        return new InteractiveSessionOperationResult($this->requireSession($sessionId), true);
    }

    public function createScopedSession(
        string $modelRole,
        ?string $profile = null,
        bool $confirmCloseActiveProfileSession = false,
        string $closureReasonPrefix = 'api_create_profile_session',
    ): InteractiveSessionOperationResult {
        if ($profile !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($profile);
            if ($activeSessions !== [] && !$confirmCloseActiveProfileSession) {
                throw $this->personaSessionActiveConflict($profile, $activeSessions);
            }

            $closedSessionIds = [];
            if ($activeSessions !== []) {
                $closedSessionIds = $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForProfile(
                    $profile,
                    '',
                    sprintf('%s:%s', $closureReasonPrefix, $profile),
                ) ?? [];

                $result = $this->createSession($modelRole, $profile);

                return new InteractiveSessionOperationResult($result->session, $result->created, $closedSessionIds);
            }
        }

        return $this->createSession($modelRole, $profile);
    }

    public function resolveScopedSession(
        string $modelRole,
        ?string $profile = null,
        string $duplicateCleanupReasonPrefix = 'api_profile_duplicate_cleanup',
    ): InteractiveSessionOperationResult {
        if ($profile !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($profile);
            if ($activeSessions !== []) {
                $sessionId = (string) ($activeSessions[0]['id'] ?? '');
                if ($sessionId !== '') {
                    $closedSessionIds = $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForProfile(
                        $profile,
                        $sessionId,
                        sprintf('%s:%s', $duplicateCleanupReasonPrefix, $profile),
                    ) ?? [];

                    $session = $this->loadExistingSession($sessionId, $profile);
                    if ($session !== null) {
                        return new InteractiveSessionOperationResult($session, false, $closedSessionIds);
                    }
                }
            }
        }

        $sessionId = $profile === null
            ? $this->storage->getLatestInteractiveUnprofiledSessionId()
            : $this->storage->getLatestInteractiveSessionIdForProfile($profile);

        if ($sessionId !== null) {
            $session = $this->loadExistingSession($sessionId, $profile);
            if ($session !== null) {
                return new InteractiveSessionOperationResult($session, false);
            }
        }

        return $this->createSession($modelRole, $profile);
    }

    public function createFreshProfileSession(
        string $currentSessionId,
        string $profile,
        string $modelRole = SystemRole::Orchestrator->value,
        string $closureReasonPrefix = 'repl_new_profile_session',
    ): InteractiveSessionOperationResult {
        $closedSessionIds = [];
        if ($this->lifecycleManager !== null) {
            $this->lifecycleManager->finalizeSession(
                $currentSessionId,
                sprintf('%s:%s', $closureReasonPrefix, $profile),
            );
            $closedSessionIds[] = $currentSessionId;
        }

        $result = $this->createSession($modelRole, $profile);

        return new InteractiveSessionOperationResult($result->session, true, $closedSessionIds);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function updateSession(array $session, SessionUpdateRequest $request): array
    {
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId === '') {
            throw new SessionTypeException(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        if ($request->updatesGroupEnabled && $request->groupEnabled !== false) {
            throw new SessionTypeException(
                ApiErrorCode::VALIDATION_ERROR,
                'Use session creation for new group sessions. Existing sessions cannot change group mode via PATCH.',
            );
        }

        if ($request->includesMembers) {
            throw new SessionTypeException(
                ApiErrorCode::VALIDATION_ERROR,
                'members may only be provided when group_enabled is true.',
            );
        }

        if ($request->updatesGroupMaxRounds) {
            throw new SessionTypeException(
                ApiErrorCode::VALIDATION_ERROR,
                'group_max_rounds is only valid for group sessions.',
            );
        }

        if ($request->updatesTitle && $request->title !== null) {
            $this->storage->updateSessionTitle($sessionId, $request->title);
        }

        $resolvedRole = (string) ($session['model_role'] ?? SystemRole::Orchestrator->value);
        if ($request->updatesModelRole && $request->modelRole !== null) {
            if (!$this->roleResolver->hasRole($request->modelRole)) {
                throw new SessionTypeException(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $request->modelRole),
                );
            }

            $resolvedRole = $request->modelRole;
        }

        $resolvedProfile = $request->updatesProfile
            ? $request->profile
            : $this->normalizeProfileValue($session['persona_id'] ?? null);

        $this->assertProfileRoleAllowed($resolvedProfile, $resolvedRole);

        if ($resolvedProfile !== null && !$this->storage->isSessionClosed($sessionId)) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($resolvedProfile);
            $conflicts = array_values(array_filter(
                $activeSessions,
                static fn(array $activeSession): bool => (string) ($activeSession['id'] ?? '') !== $sessionId,
            ));

            if ($conflicts !== [] && !$request->confirmCloseActiveProfileSession) {
                throw $this->personaSessionActiveConflict($resolvedProfile, $conflicts);
            }

            if ($conflicts !== []) {
                $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForProfile(
                    $resolvedProfile,
                    $sessionId,
                    sprintf('api_profile_reassignment:%s', $resolvedProfile),
                );
            }
        }

        if ($request->updatesProfile) {
            $this->storage->updateSessionPersona($sessionId, $resolvedProfile);
        }

        $resolvedModel = $this->roleResolver->resolve($resolvedRole, $resolvedProfile);
        $this->storage->updateSessionRole($sessionId, $resolvedRole, $resolvedModel);

        return $this->requireSession($sessionId);
    }

    public function enforceProfileRolePolicy(string $sessionId, ?string $profile): string
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return SystemRole::Orchestrator->value;
        }

        $currentRole = (string) ($session['model_role'] ?? SystemRole::Orchestrator->value);
        if ($currentRole === '') {
            $currentRole = SystemRole::Orchestrator->value;
        }

        if ($profile === null || !$this->profileDiscovery->profileExists($profile)) {
            return $currentRole;
        }

        $effectiveRole = $this->normalizeRoleForProfile($currentRole, $profile);
        if ($effectiveRole === $currentRole) {
            return $currentRole;
        }

        $modelString = $this->roleResolver->resolve($effectiveRole, $profile);
        $this->storage->updateSessionRole($sessionId, $effectiveRole, $modelString);

        return $effectiveRole;
    }

    public function assertProfileRoleAllowed(?string $profile, string $role): void
    {
        $preferences = $this->loadProfilePreferences($profile);
        if ($preferences === null || $preferences->isRoleAllowed($role)) {
            return;
        }

        throw new SessionTypeException(
            ApiErrorCode::VALIDATION_ERROR,
            sprintf('Profile "%s" does not allow role "%s".', $profile, $role),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $activeSessions
     */
    public function personaSessionActiveConflict(string $profile, array $activeSessions): SessionTypeException
    {
        $primary = $activeSessions[0] ?? [];

        return new SessionTypeException(
            ApiErrorCode::PERSONA_SESSION_ACTIVE,
            sprintf('Profile "%s" already has an active session. Confirm closure before starting or reassigning a fresh session.', $profile),
            [
                'profile' => $profile,
                'active_session_id' => $primary['id'] ?? null,
                'active_session_count' => count($activeSessions),
                'confirm_field' => 'confirm_close_active_persona_session',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function requireSession(string $sessionId): array
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            throw new SessionTypeException(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadExistingSession(string $sessionId, ?string $profile): ?array
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return null;
        }

        $effectiveRole = $this->normalizeRoleForProfile((string) ($session['model_role'] ?? SystemRole::Orchestrator->value), $profile);
        if ($effectiveRole !== (string) ($session['model_role'] ?? '')) {
            $effectiveModel = $this->roleResolver->resolve($effectiveRole, $profile);
            $this->storage->updateSessionRole($sessionId, $effectiveRole, $effectiveModel);
            $session = $this->storage->getSession($sessionId) ?? $session;
        }

        return $session;
    }

    private function normalizeRoleForProfile(string $role, ?string $profile): string
    {
        $preferences = $this->loadProfilePreferences($profile);
        if ($preferences === null || $preferences->isRoleAllowed($role)) {
            return $role;
        }

        return SystemRole::Orchestrator->value;
    }

    private function loadProfilePreferences(?string $profile): ?PersonaPreferences
    {
        if ($profile === null || !$this->profileDiscovery->profileExists($profile)) {
            return null;
        }

        return PersonaPreferences::fromProfilePath($this->profileDiscovery->getProfilePath($profile));
    }

    private function normalizeProfileValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $profile = strtolower(trim($value));

        return $profile !== '' ? $profile : null;
    }
}