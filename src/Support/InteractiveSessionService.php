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
        private PersonaDiscovery $personaDiscovery,
        private ?PersonaSessionLifecycleManager $lifecycleManager = null,
    ) {}

    public function createSession(string $modelRole, ?string $persona = null): InteractiveSessionOperationResult
    {
        $model = $this->roleResolver->resolve($modelRole, $persona);
        $sessionId = $this->storage->createSession(
            modelRole: $modelRole,
            model: $model,
            persona: $persona,
            sessionType: SessionType::Interactive,
        );

        return new InteractiveSessionOperationResult($this->requireSession($sessionId), true);
    }

    public function createScopedSession(
        string $modelRole,
        ?string $persona = null,
        bool $confirmCloseActivePersonaSession = false,
        string $closureReasonPrefix = 'api_create_persona_session',
    ): InteractiveSessionOperationResult {
        if ($persona !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForPersona($persona);
            if ($activeSessions !== [] && !$confirmCloseActivePersonaSession) {
                throw $this->personaSessionActiveConflict($persona, $activeSessions);
            }

            $closedSessionIds = [];
            if ($activeSessions !== []) {
                $closedSessionIds = $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForPersona(
                    $persona,
                    '',
                    sprintf('%s:%s', $closureReasonPrefix, $persona),
                ) ?? [];

                $result = $this->createSession($modelRole, $persona);

                return new InteractiveSessionOperationResult($result->session, $result->created, $closedSessionIds);
            }
        }

        return $this->createSession($modelRole, $persona);
    }

    public function resolveScopedSession(
        string $modelRole,
        ?string $persona = null,
        string $duplicateCleanupReasonPrefix = 'api_persona_duplicate_cleanup',
    ): InteractiveSessionOperationResult {
        if ($persona !== null) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForPersona($persona);
            if ($activeSessions !== []) {
                $sessionId = (string) ($activeSessions[0]['id'] ?? '');
                if ($sessionId !== '') {
                    $closedSessionIds = $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForPersona(
                        $persona,
                        $sessionId,
                        sprintf('%s:%s', $duplicateCleanupReasonPrefix, $persona),
                    ) ?? [];

                    $session = $this->loadExistingSession($sessionId, $persona);
                    if ($session !== null) {
                        return new InteractiveSessionOperationResult($session, false, $closedSessionIds);
                    }
                }
            }
        }

        $sessionId = $persona === null
            ? $this->storage->getLatestInteractiveUnpersonaScopedSessionId()
            : $this->storage->getLatestInteractiveSessionIdForPersona($persona);

        if ($sessionId !== null) {
            $session = $this->loadExistingSession($sessionId, $persona);
            if ($session !== null) {
                return new InteractiveSessionOperationResult($session, false);
            }
        }

        return $this->createSession($modelRole, $persona);
    }

    public function createFreshPersonaSession(
        string $currentSessionId,
        string $persona,
        string $modelRole = SystemRole::Orchestrator->value,
        string $closureReasonPrefix = 'repl_new_persona_session',
    ): InteractiveSessionOperationResult {
        $closedSessionIds = [];
        if ($this->lifecycleManager !== null) {
            $this->lifecycleManager->finalizeSession(
                $currentSessionId,
                sprintf('%s:%s', $closureReasonPrefix, $persona),
            );
            $closedSessionIds[] = $currentSessionId;
        }

        $result = $this->createSession($modelRole, $persona);

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

        $resolvedPersona = $request->updatesPersona
            ? $request->persona
            : $this->normalizePersonaValue($session['persona_id'] ?? null);

        $this->assertPersonaRoleAllowed($resolvedPersona, $resolvedRole);

        if ($resolvedPersona !== null && !$this->storage->isSessionClosed($sessionId)) {
            $activeSessions = $this->storage->listActiveInteractiveSessionsForPersona($resolvedPersona);
            $conflicts = array_values(array_filter(
                $activeSessions,
                static fn(array $activeSession): bool => (string) ($activeSession['id'] ?? '') !== $sessionId,
            ));

            if ($conflicts !== [] && !$request->confirmCloseActivePersonaSession) {
                throw $this->personaSessionActiveConflict($resolvedPersona, $conflicts);
            }

            if ($conflicts !== []) {
                $this->lifecycleManager?->finalizeOtherActiveInteractiveSessionsForPersona(
                    $resolvedPersona,
                    $sessionId,
                    sprintf('api_persona_reassignment:%s', $resolvedPersona),
                );
            }
        }

        if ($request->updatesPersona) {
            $this->storage->updateSessionPersona($sessionId, $resolvedPersona);
        }

        $resolvedModel = $this->roleResolver->resolve($resolvedRole, $resolvedPersona);
        $this->storage->updateSessionRole($sessionId, $resolvedRole, $resolvedModel);

        return $this->requireSession($sessionId);
    }

    public function enforcePersonaRolePolicy(string $sessionId, ?string $persona): string
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return SystemRole::Orchestrator->value;
        }

        $currentRole = (string) ($session['model_role'] ?? SystemRole::Orchestrator->value);
        if ($currentRole === '') {
            $currentRole = SystemRole::Orchestrator->value;
        }

        if ($persona === null || !$this->personaDiscovery->personaExists($persona)) {
            return $currentRole;
        }

        $effectiveRole = $this->normalizeRoleForPersona($currentRole, $persona);
        if ($effectiveRole === $currentRole) {
            return $currentRole;
        }

        $modelString = $this->roleResolver->resolve($effectiveRole, $persona);
        $this->storage->updateSessionRole($sessionId, $effectiveRole, $modelString);

        return $effectiveRole;
    }

    public function assertPersonaRoleAllowed(?string $persona, string $role): void
    {
        $preferences = $this->loadPersonaPreferences($persona);
        if ($preferences === null || $preferences->isRoleAllowed($role)) {
            return;
        }

        throw new SessionTypeException(
            ApiErrorCode::VALIDATION_ERROR,
            sprintf('Persona "%s" does not allow role "%s".', $persona, $role),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $activeSessions
     */
    public function personaSessionActiveConflict(string $persona, array $activeSessions): SessionTypeException
    {
        $primary = $activeSessions[0] ?? [];

        return new SessionTypeException(
            ApiErrorCode::CONFLICT,
            sprintf('Persona "%s" already has an active session. Confirm closure before starting or reassigning a fresh session.', $persona),
            [
                'persona_id' => $persona,
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
    private function loadExistingSession(string $sessionId, ?string $persona): ?array
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            return null;
        }

        $effectiveRole = $this->normalizeRoleForPersona((string) ($session['model_role'] ?? SystemRole::Orchestrator->value), $persona);
        if ($effectiveRole !== (string) ($session['model_role'] ?? '')) {
            $effectiveModel = $this->roleResolver->resolve($effectiveRole, $persona);
            $this->storage->updateSessionRole($sessionId, $effectiveRole, $effectiveModel);
            $session = $this->storage->getSession($sessionId) ?? $session;
        }

        return $session;
    }

    private function normalizeRoleForPersona(string $role, ?string $persona): string
    {
        $preferences = $this->loadPersonaPreferences($persona);
        if ($preferences === null || $preferences->isRoleAllowed($role)) {
            return $role;
        }

        return SystemRole::Orchestrator->value;
    }

    private function loadPersonaPreferences(?string $persona): ?PersonaPreferences
    {
        if ($persona === null || !$this->personaDiscovery->personaExists($persona)) {
            return null;
        }

        return PersonaPreferences::fromPersonaPath($this->personaDiscovery->getPersonaPath($persona));
    }

    private function normalizePersonaValue(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $persona = strtolower(trim($value));

        return $persona !== '' ? $persona : null;
    }
}