<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\SessionType;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Exception\GroupSessionException;
use CoquiBot\Coqui\Support\GroupSessionService;
use React\Http\Message\Response;

final readonly class SessionScopeResolver
{
    public function __construct(
        private RoleResolver $roleResolver,
        private ProfileDiscovery $profileDiscovery,
        private GroupSessionService $groupSessions,
    ) {}

    /**
     * @param array<string, mixed>|null $body
     */
    public function resolve(?array $body): SessionScope|Response
    {
        $modelRole = is_array($body) && isset($body['model_role'])
            ? trim((string) $body['model_role'])
            : SystemRole::Orchestrator->value;

        if ($modelRole === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'model_role cannot be empty');
        }

        if (!$this->roleResolver->hasRole($modelRole)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown role "%s". Use GET /api/v1/config/roles to see available roles.', $modelRole),
            );
        }

        $profile = is_array($body) && array_key_exists('profile', $body)
            ? $this->normalizeProfileValue($body['profile'])
            : null;

        $type = is_array($body)
            && array_key_exists('group_enabled', $body)
            && filter_var($body['group_enabled'], FILTER_VALIDATE_BOOLEAN)
                ? SessionType::Group
                : SessionType::Interactive;

        if ($type === SessionType::Group) {
            if ($profile !== null) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Group sessions do not support a single active profile.',
                );
            }

            if ($modelRole !== SystemRole::Orchestrator->value) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Only the orchestrator can manage group sessions.',
                );
            }

            try {
                return new SessionScope(
                    type: SessionType::Group,
                    modelRole: $modelRole,
                    groupMembers: $this->groupSessions->normalizeMembers($body['members'] ?? null),
                    groupMaxRounds: $this->groupSessions->resolveMaxRounds($body['group_max_rounds'] ?? GroupSessionService::DEFAULT_MAX_ROUNDS),
                    confirmCloseActiveGroupSession: $this->confirmFlag($body, 'confirm_close_active_group_session'),
                );
            } catch (GroupSessionException $e) {
                return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details);
            }
        }

        if (is_array($body) && array_key_exists('members', $body)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'members may only be provided when group_enabled is true.',
            );
        }

        if ($profile !== null && !$this->profileDiscovery->profileExists($profile)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown profile "%s". Create profiles/{name}/soul.md in the workspace or clear the profile.', $profile),
            );
        }

        $roleError = $this->validateProfileRole($profile, $modelRole);
        if ($roleError instanceof Response) {
            return $roleError;
        }

        return new SessionScope(
            type: SessionType::Interactive,
            modelRole: $modelRole,
            profile: $profile,
            confirmCloseActiveProfileSession: $this->confirmFlag($body, 'confirm_close_active_profile_session'),
        );
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
     * @param array<string, mixed>|null $body
     */
    private function confirmFlag(?array $body, string $field): bool
    {
        return is_array($body)
            && array_key_exists($field, $body)
            && filter_var($body[$field], FILTER_VALIDATE_BOOLEAN);
    }
}