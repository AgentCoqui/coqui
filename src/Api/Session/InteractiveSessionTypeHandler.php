<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Exception\SessionTypeException;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\ProfileSessionLifecycleManager;

final readonly class InteractiveSessionTypeHandler implements SessionTypeHandlerInterface
{
    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
        private ProfileDiscovery $profileDiscovery,
        private ?ProfileSessionLifecycleManager $lifecycleManager = null,
    ) {}

    public function type(): SessionType
    {
        return SessionType::Interactive;
    }

    public function create(SessionScope $scope): SessionTypeOperationResult
    {
        if ($scope->profile !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($scope->profile);
            if ($activeSessions !== [] && !$scope->confirmCloseActiveProfileSession) {
                throw $this->profileSessionActiveConflict($scope->profile, $activeSessions);
            }

            if ($activeSessions !== []) {
                $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForProfile(
                    $scope->profile,
                    '',
                    sprintf('api_create_profile_session:%s', $scope->profile),
                );
            }
        }

        $model = $this->roleResolver->resolve($scope->modelRole, $scope->profile);
        $sessionId = $this->storage->createSession(
            modelRole: $scope->modelRole,
            model: $model,
            profile: $scope->profile,
            sessionType: SessionType::Interactive,
        );

        return new SessionTypeOperationResult($this->requireSession($sessionId), true);
    }

    public function resolve(SessionScope $scope): SessionTypeOperationResult
    {
        if ($scope->profile !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForProfile($scope->profile);
            if ($activeSessions !== []) {
                $sessionId = (string) ($activeSessions[0]['id'] ?? '');
                if ($sessionId !== '') {
                    $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForProfile(
                        $scope->profile,
                        $sessionId,
                        sprintf('api_profile_duplicate_cleanup:%s', $scope->profile),
                    );

                    $existing = $this->loadExistingSession($sessionId, $scope->profile);
                    if ($existing !== null) {
                        return $existing;
                    }
                }
            }
        }

        $sessionId = $scope->profile === null
            ? $this->storage->getLatestInteractiveUnprofiledSessionId()
            : $this->storage->getLatestInteractiveSessionIdForProfile($scope->profile);

        if ($sessionId !== null) {
            $existing = $this->loadExistingSession($sessionId, $scope->profile);
            if ($existing !== null) {
                return $existing;
            }
        }

        $model = $this->roleResolver->resolve($scope->modelRole, $scope->profile);
        $sessionId = $this->storage->createSession(
            modelRole: $scope->modelRole,
            model: $model,
            profile: $scope->profile,
            sessionType: SessionType::Interactive,
        );

        return new SessionTypeOperationResult($this->requireSession($sessionId), true);
    }

    private function loadExistingSession(string $sessionId, ?string $profile): ?SessionTypeOperationResult
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

        return new SessionTypeOperationResult($session, false);
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

    /**
     * @param array<int, array<string, mixed>> $activeSessions
     */
    private function profileSessionActiveConflict(string $profile, array $activeSessions): SessionTypeException
    {
        $primary = $activeSessions[0] ?? [];

        return new SessionTypeException(
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
     * @return array<string, mixed>
     */
    private function requireSession(string $sessionId): array
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            throw new SessionTypeException(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return $session;
    }
}