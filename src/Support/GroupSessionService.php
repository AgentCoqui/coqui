<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Exception\GroupSessionException;
use CoquiBot\Coqui\Storage\SessionStorage;

final readonly class GroupSessionService
{
    public const int DEFAULT_MAX_ROUNDS = 3;

    public function __construct(
        private SessionStorage $storage,
        private RoleResolver $roleResolver,
        private PersonaDiscovery $profileDiscovery,
    ) {}

    /**
     * @param mixed $members
     * @return list<string>
     */
    public function normalizeMembers(mixed $members): array
    {
        if (!is_array($members)) {
            throw new GroupSessionException(ApiErrorCode::MISSING_FIELD, 'members is required for group sessions');
        }

        $normalized = [];
        foreach ($members as $member) {
            if (!is_string($member)) {
                throw new GroupSessionException(ApiErrorCode::VALIDATION_ERROR, 'Each group member must be a profile name string');
            }

            $profile = strtolower(trim($member));
            if ($profile === '') {
                throw new GroupSessionException(ApiErrorCode::VALIDATION_ERROR, 'Group member names cannot be empty');
            }

            if (!$this->profileDiscovery->profileExists($profile)) {
                throw new GroupSessionException(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile.', $profile),
                );
            }

            if (isset($normalized[$profile])) {
                throw new GroupSessionException(
                    ApiErrorCode::VALIDATION_ERROR,
                    sprintf('Duplicate group member "%s" is not allowed.', $profile),
                );
            }

            $normalized[$profile] = true;
        }

        $profiles = array_keys($normalized);
        if (count($profiles) < 2) {
            throw new GroupSessionException(ApiErrorCode::VALIDATION_ERROR, 'Group sessions must contain at least two members.');
        }

        sort($profiles, SORT_STRING);

        return $profiles;
    }

    public function normalizeMember(mixed $profile): string
    {
        if (!is_string($profile)) {
            throw new GroupSessionException(ApiErrorCode::MISSING_FIELD, 'profile is required');
        }

        $normalized = strtolower(trim($profile));
        if ($normalized === '') {
            throw new GroupSessionException(ApiErrorCode::MISSING_FIELD, 'profile is required');
        }

        if (!$this->profileDiscovery->profileExists($normalized)) {
            throw new GroupSessionException(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile.', $normalized),
            );
        }

        return $normalized;
    }

    public function resolveMaxRounds(mixed $value, int $default = self::DEFAULT_MAX_ROUNDS): int
    {
        if ($value === null) {
            return $default;
        }

        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            throw new GroupSessionException(ApiErrorCode::VALIDATION_ERROR, 'group_max_rounds must be a positive integer');
        }

        $groupMaxRounds = (int) $value;
        if ($groupMaxRounds < 1) {
            throw new GroupSessionException(ApiErrorCode::VALIDATION_ERROR, 'group_max_rounds must be greater than 0');
        }

        return $groupMaxRounds;
    }

    /**
     * @param list<string> $members
     */
    public function createFreshSession(
        string $modelRole,
        array $members,
        int $groupMaxRounds,
        bool $confirmCloseActive,
        string $closureReasonPrefix,
    ): GroupSessionOperationResult {
        $this->assertOrchestratorRole($modelRole);

        $compositionKey = $this->storage->buildGroupCompositionKey($members);
        $activeSessions = $this->storage->listActiveInteractiveGroupSessionsByCompositionKey($compositionKey);

        if ($activeSessions !== [] && !$confirmCloseActive) {
            throw $this->activeGroupConflict($compositionKey, $members, $activeSessions);
        }

        $closedSessionIds = [];
        if ($activeSessions !== []) {
            $closedSessionIds = $this->storage->closeOtherActiveInteractiveGroupSessionsByCompositionKey(
                $compositionKey,
                '',
                sprintf('%s:%s', $closureReasonPrefix, $compositionKey),
            );
        }

        $model = $this->roleResolver->resolve($modelRole, null);
        $sessionId = $this->storage->createGroupSession($modelRole, $model, $members, $groupMaxRounds);

        return new GroupSessionOperationResult(
            session: $this->requireSession($sessionId),
            created: true,
            closedSessionIds: $closedSessionIds,
        );
    }

    /**
     * @param list<string> $members
     */
    public function resolveOrCreateSession(
        string $modelRole,
        array $members,
        int $groupMaxRounds,
    ): GroupSessionOperationResult {
        $this->assertOrchestratorRole($modelRole);

        $compositionKey = $this->storage->buildGroupCompositionKey($members);
        $activeSessions = $this->storage->listActiveInteractiveGroupSessionsByCompositionKey($compositionKey);

        if ($activeSessions !== []) {
            $sessionId = (string) ($activeSessions[0]['id'] ?? '');
            return new GroupSessionOperationResult(
                session: $this->requireSession($sessionId),
                created: false,
            );
        }

        $model = $this->roleResolver->resolve($modelRole, null);
        $sessionId = $this->storage->createGroupSession($modelRole, $model, $members, $groupMaxRounds);

        return new GroupSessionOperationResult(
            session: $this->requireSession($sessionId),
            created: true,
        );
    }

    /**
     * @param list<string> $members
     */
    public function replaceSessionMembers(
        string $sessionId,
        array $members,
        int $groupMaxRounds,
        bool $confirmCloseActive,
        string $closureReasonPrefix,
    ): GroupSessionOperationResult {
        $compositionKey = $this->storage->buildGroupCompositionKey($members);
        $activeSessions = $this->storage->listActiveInteractiveGroupSessionsByCompositionKey($compositionKey);
        $conflicts = array_values(array_filter(
            $activeSessions,
            static fn(array $activeSession): bool => (string) ($activeSession['id'] ?? '') !== $sessionId,
        ));

        if ($conflicts !== [] && !$confirmCloseActive) {
            throw $this->activeGroupConflict($compositionKey, $members, $conflicts);
        }

        $closedSessionIds = [];
        if ($conflicts !== []) {
            $closedSessionIds = $this->storage->closeOtherActiveInteractiveGroupSessionsByCompositionKey(
                $compositionKey,
                $sessionId,
                sprintf('%s:%s', $closureReasonPrefix, $compositionKey),
            );
        }

        $this->storage->replaceSessionGroupMembers($sessionId, $members, $groupMaxRounds);

        return new GroupSessionOperationResult(
            session: $this->requireSession($sessionId),
            created: false,
            closedSessionIds: $closedSessionIds,
        );
    }

    public function addSessionMember(
        string $sessionId,
        string $profile,
        bool $confirmCloseActive,
        int $groupMaxRounds,
        string $closureReasonPrefix,
    ): GroupSessionOperationResult {
        $members = $this->storage->listSessionGroupMemberNames($sessionId);
        if (in_array($profile, $members, true)) {
            throw new GroupSessionException(ApiErrorCode::CONFLICT, sprintf('Profile "%s" is already a member of this session.', $profile));
        }

        $members[] = $profile;

        return $this->replaceSessionMembers($sessionId, $this->normalizeMembers($members), $groupMaxRounds, $confirmCloseActive, $closureReasonPrefix);
    }

    public function removeSessionMember(
        string $sessionId,
        string $profile,
        bool $confirmCloseActive,
        int $groupMaxRounds,
        string $closureReasonPrefix,
    ): GroupSessionOperationResult {
        $members = $this->storage->listSessionGroupMemberNames($sessionId);
        if (!in_array($profile, $members, true)) {
            throw new GroupSessionException(ApiErrorCode::NOT_FOUND, sprintf('Profile "%s" is not a member of this session.', $profile));
        }

        $updatedMembers = array_values(array_filter(
            $members,
            static fn(string $member): bool => $member !== $profile,
        ));

        if (count($updatedMembers) < 2) {
            throw new GroupSessionException(ApiErrorCode::VALIDATION_ERROR, 'Group sessions must contain at least two members.');
        }

        return $this->replaceSessionMembers($sessionId, $updatedMembers, $groupMaxRounds, $confirmCloseActive, $closureReasonPrefix);
    }

    public function updateSessionMaxRounds(string $sessionId, mixed $groupMaxRounds): GroupSessionOperationResult
    {
        $resolvedGroupMaxRounds = $this->resolveMaxRounds($groupMaxRounds);
        $this->storage->updateSessionGroupSettings($sessionId, $resolvedGroupMaxRounds);

        return new GroupSessionOperationResult(
            session: $this->requireSession($sessionId),
            created: false,
        );
    }

    /**
     * @param list<string> $members
     * @param array<int, array<string, mixed>> $activeSessions
     */
    private function activeGroupConflict(string $compositionKey, array $members, array $activeSessions): GroupSessionException
    {
        $primary = $activeSessions[0] ?? [];

        return new GroupSessionException(
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
     * @return array<string, mixed>
     */
    private function requireSession(string $sessionId): array
    {
        $session = $this->storage->getSession($sessionId);
        if ($session === null) {
            throw new GroupSessionException(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return $session;
    }

    private function assertOrchestratorRole(string $modelRole): void
    {
        if ($modelRole !== SystemRole::Orchestrator->value) {
            throw new GroupSessionException(ApiErrorCode::VALIDATION_ERROR, 'Only the orchestrator can manage group sessions.');
        }
    }
}