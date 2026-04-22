<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Support\GroupSessionService;

final readonly class GroupSessionTypeHandler implements SessionTypeHandlerInterface
{
    public function __construct(
        private GroupSessionService $groupSessions,
    ) {}

    public function type(): SessionType
    {
        return SessionType::Group;
    }

    public function create(SessionScope $scope): SessionTypeOperationResult
    {
        $result = $this->groupSessions->createFreshSession(
            modelRole: $scope->modelRole,
            members: $scope->groupMembers,
            groupMaxRounds: $scope->groupMaxRounds ?? GroupSessionService::DEFAULT_MAX_ROUNDS,
            confirmCloseActive: $scope->confirmCloseActiveGroupSession,
            closureReasonPrefix: 'api_create_group_session',
        );

        return new SessionTypeOperationResult($result->session, true);
    }

    public function resolve(SessionScope $scope): SessionTypeOperationResult
    {
        $result = $this->groupSessions->resolveOrCreateSession(
            modelRole: $scope->modelRole,
            members: $scope->groupMembers,
            groupMaxRounds: $scope->groupMaxRounds ?? GroupSessionService::DEFAULT_MAX_ROUNDS,
        );

        return new SessionTypeOperationResult($result->session, $result->created);
    }
}