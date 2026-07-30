<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaPreferences;
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
        private PersonaDiscovery $personaDiscovery,
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

        $persona = is_array($body) && array_key_exists('persona_id', $body)
            ? $this->normalizePersonaValue($body['persona_id'])
            : null;

        $type = is_array($body)
            && array_key_exists('group_enabled', $body)
            && filter_var($body['group_enabled'], FILTER_VALIDATE_BOOLEAN)
                ? SessionType::Group
                : SessionType::Interactive;

        if ($type === SessionType::Group) {
            if ($persona !== null) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Group sessions do not support a single active persona.',
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

        if ($persona !== null && !$this->personaDiscovery->personaExists($persona)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                sprintf('Unknown persona "%s". Create personas/{name}/soul.md in the workspace or clear the persona.', $persona),
            );
        }

        $roleError = $this->validatePersonaRole($persona, $modelRole);
        if ($roleError instanceof Response) {
            return $roleError;
        }

        return new SessionScope(
            type: SessionType::Interactive,
            modelRole: $modelRole,
            persona: $persona,
            confirmCloseActivePersonaSession: $this->confirmFlag($body, 'confirm_close_active_persona_session'),
        );
    }

    private function validatePersonaRole(?string $persona, string $role): ?Response
    {
        $preferences = $this->loadPersonaPreferences($persona);
        if ($preferences === null || $preferences->isRoleAllowed($role)) {
            return null;
        }

        return Router::errorResponse(
            ApiErrorCode::VALIDATION_ERROR,
            sprintf('Persona "%s" does not allow role "%s".', $persona, $role),
        );
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