<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use React\Http\Message\Response;

final class SessionUpdateRequestResolver
{
    /**
     * @param array<string, mixed> $body
     */
    public function resolve(array $body): SessionUpdateRequest|Response
    {
        $title = null;
        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Title cannot be empty');
            }
        }

        $modelRole = null;
        if (array_key_exists('model_role', $body)) {
            $modelRole = trim((string) $body['model_role']);
            if ($modelRole === '') {
                return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'model_role cannot be empty');
            }
        }

        return new SessionUpdateRequest(
            updatesTitle: array_key_exists('title', $body),
            title: $title,
            updatesModelRole: array_key_exists('model_role', $body),
            modelRole: $modelRole,
            updatesPersona: array_key_exists('persona_id', $body),
            persona: $this->normalizePersonaValue($body['persona_id'] ?? null),
            updatesGroupEnabled: array_key_exists('group_enabled', $body),
            groupEnabled: array_key_exists('group_enabled', $body)
                ? filter_var($body['group_enabled'], FILTER_VALIDATE_BOOLEAN)
                : null,
            includesMembers: array_key_exists('members', $body),
            updatesGroupMaxRounds: array_key_exists('group_max_rounds', $body),
            groupMaxRounds: $body['group_max_rounds'] ?? null,
            confirmCloseActivePersonaSession: array_key_exists('confirm_close_active_persona_session', $body)
                && filter_var($body['confirm_close_active_persona_session'], FILTER_VALIDATE_BOOLEAN),
        );
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