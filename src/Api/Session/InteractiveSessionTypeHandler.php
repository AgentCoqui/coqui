<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Support\InteractiveSessionService;

final readonly class InteractiveSessionTypeHandler implements SessionTypeHandlerInterface
{
    public function __construct(
        private InteractiveSessionService $interactiveSessions,
    ) {}

    public function type(): SessionType
    {
        return SessionType::Interactive;
    }

    public function create(SessionScope $scope): SessionTypeOperationResult
    {
        $result = $this->interactiveSessions->createScopedSession(
            modelRole: $scope->modelRole,
            persona: $scope->persona,
            confirmCloseActivePersonaSession: $scope->confirmCloseActivePersonaSession,
            closureReasonPrefix: 'api_create_persona_session',
        );

        return new SessionTypeOperationResult($result->session, $result->created, $result->closedSessionIds);
    }

    public function resolve(SessionScope $scope): SessionTypeOperationResult
    {
        $result = $this->interactiveSessions->resolveScopedSession(
            modelRole: $scope->modelRole,
            persona: $scope->persona,
            duplicateCleanupReasonPrefix: 'api_persona_duplicate_cleanup',
        );

        return new SessionTypeOperationResult($result->session, $result->created, $result->closedSessionIds);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function update(array $session, SessionUpdateRequest $request): array
    {
        return $this->interactiveSessions->updateSession($session, $request);
    }
}