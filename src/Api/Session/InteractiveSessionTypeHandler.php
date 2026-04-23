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
            profile: $scope->profile,
            confirmCloseActiveProfileSession: $scope->confirmCloseActiveProfileSession,
            closureReasonPrefix: 'api_create_profile_session',
        );

        return new SessionTypeOperationResult($result->session, $result->created);
    }

    public function resolve(SessionScope $scope): SessionTypeOperationResult
    {
        $result = $this->interactiveSessions->resolveScopedSession(
            modelRole: $scope->modelRole,
            profile: $scope->profile,
            duplicateCleanupReasonPrefix: 'api_profile_duplicate_cleanup',
        );

        return new SessionTypeOperationResult($result->session, $result->created);
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