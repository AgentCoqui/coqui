<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Exception\SessionTypeException;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Support\GroupSessionService;
use CoquiBot\Coqui\Config\RoleResolver;

final readonly class GroupSessionTypeHandler implements SessionTypeHandlerInterface, GroupSessionEndpointHandlerInterface
{
    public function __construct(
        private GroupSessionService $groupSessions,
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
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

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function update(array $session, SessionUpdateRequest $request): array
    {
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId === '') {
            throw new SessionTypeException(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        if ($request->updatesGroupEnabled && $request->groupEnabled !== true) {
            throw new SessionTypeException(
                ApiErrorCode::VALIDATION_ERROR,
                'Use session creation for new group sessions. Existing sessions cannot change group mode via PATCH.',
            );
        }

        if ($request->includesMembers) {
            throw new SessionTypeException(
                ApiErrorCode::VALIDATION_ERROR,
                'Use the session group member endpoints to manage members.',
            );
        }

        if ($request->updatesTitle && $request->title !== null) {
            $this->storage->updateSessionTitle($sessionId, $request->title);
        }

        $resolvedRole = (string) ($session['model_role'] ?? SystemRole::Orchestrator->value);
        if ($request->updatesModelRole && $request->modelRole !== null) {
            if ($request->modelRole !== SystemRole::Orchestrator->value) {
                throw new SessionTypeException(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Group sessions must remain orchestrator-managed.',
                );
            }

            if (!$this->roleResolver->hasRole($request->modelRole)) {
                throw new SessionTypeException(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $request->modelRole),
                );
            }

            $resolvedRole = $request->modelRole;
        }

        if ($request->updatesProfile) {
            throw new SessionTypeException(
                ApiErrorCode::VALIDATION_ERROR,
                'Group sessions do not support a single active profile.',
            );
        }

        if ($request->updatesGroupMaxRounds) {
            $this->groupSessions->updateSessionMaxRounds($sessionId, $request->groupMaxRounds);
        }

        $resolvedModel = $this->roleResolver->resolve($resolvedRole, null);
        $this->storage->updateSessionRole($sessionId, $resolvedRole, $resolvedModel);

        return $this->requireSession($sessionId);
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function listMembers(array $session): array
    {
        $sessionId = $this->requireSessionId($session);
        $groupSession = $this->requireGroupSession($session);
        $members = $this->storage->listSessionGroupMembers($sessionId);

        return [
            'session_id' => $sessionId,
            'group_enabled' => true,
            'group_composition_key' => $groupSession['group_composition_key'] ?? null,
            'group_max_rounds' => $groupSession['group_max_rounds'] ?? null,
            'members' => $members,
            'count' => count($members),
        ];
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function replaceMembers(array $session, mixed $body): array
    {
        $sessionId = $this->requireSessionId($session);
        $groupSession = $this->requireGroupSession($session);
        $requestBody = $this->requireRequestBody($body);

        $members = $this->groupSessions->normalizeMembers($requestBody['members'] ?? null);
        $result = $this->groupSessions->replaceSessionMembers(
            sessionId: $sessionId,
            members: $members,
            groupMaxRounds: $this->resolveGroupMaxRounds($groupSession),
            confirmCloseActive: $this->confirmCloseActiveGroupSession($requestBody),
            closureReasonPrefix: 'api_group_membership_update',
        );

        return $result->session;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function addMember(array $session, mixed $body): array
    {
        $sessionId = $this->requireSessionId($session);
        $groupSession = $this->requireGroupSession($session);
        $requestBody = $this->requireRequestBody($body);

        $profile = $this->groupSessions->normalizeMember($requestBody['profile'] ?? null);
        $result = $this->groupSessions->addSessionMember(
            sessionId: $sessionId,
            profile: $profile,
            confirmCloseActive: $this->confirmCloseActiveGroupSession($requestBody),
            groupMaxRounds: $this->resolveGroupMaxRounds($groupSession),
            closureReasonPrefix: 'api_group_membership_update',
        );

        return $result->session;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    public function removeMember(array $session, string $profile, mixed $body): array
    {
        $sessionId = $this->requireSessionId($session);
        $groupSession = $this->requireGroupSession($session);
        $targetProfile = $this->groupSessions->normalizeMember($profile);
        $requestBody = is_array($body) ? $body : [];

        $result = $this->groupSessions->removeSessionMember(
            sessionId: $sessionId,
            profile: $targetProfile,
            confirmCloseActive: $this->confirmCloseActiveGroupSession($requestBody),
            groupMaxRounds: $this->resolveGroupMaxRounds($groupSession),
            closureReasonPrefix: 'api_group_membership_update',
        );

        return $result->session;
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

    /**
     * @param array<string, mixed> $session
     */
    private function requireSessionId(array $session): string
    {
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId === '') {
            throw new SessionTypeException(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $session
     * @return array<string, mixed>
     */
    private function requireGroupSession(array $session): array
    {
        if (SessionType::fromSessionRow($session) !== SessionType::Group) {
            throw new SessionTypeException(ApiErrorCode::VALIDATION_ERROR, 'Session is not a group session.');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRequestBody(mixed $body): array
    {
        if (!is_array($body)) {
            throw new SessionTypeException(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function confirmCloseActiveGroupSession(array $body): bool
    {
        return array_key_exists('confirm_close_active_group_session', $body)
            && filter_var($body['confirm_close_active_group_session'], FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $groupSession
     */
    private function resolveGroupMaxRounds(array $groupSession): int
    {
        return is_int($groupSession['group_max_rounds'] ?? null)
            ? $groupSession['group_max_rounds']
            : GroupSessionService::DEFAULT_MAX_ROUNDS;
    }
}