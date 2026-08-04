<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Session;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use React\Http\Message\Response;

/**
 * Coerces an already-validated session PATCH body into a {@see SessionUpdateRequest}.
 *
 * The CAP 0.5.0 `session-patch.json` allow-set (`title, pinned, status, model,
 * workspace`) and the empty-`{}` rejection are enforced upstream by
 * {@see \CoquiBot\Coqui\Api\Handler\DecodesRequestBody::decodePatchBody()}; this
 * resolver assumes that contract holds and only coerces/validates field values.
 * `model` and `workspace` are nullable — an explicit null clears the field
 * (model ⇒ inherit; workspace ⇒ no rooted workspace) while an omitted key leaves
 * it untouched, a distinction preserved via `array_key_exists`.
 */
final class SessionUpdateRequestResolver
{
    /**
     * Mirrors `schema/session.json#/properties/status`. The vendored schema is not
     * readable at runtime, so this literal must be kept in sync with it by hand.
     */
    private const STATUS_ENUM = ['active', 'archived', 'closed'];

    /**
     * @param array<string, mixed> $body
     */
    public function resolve(array $body): SessionUpdateRequest|Response
    {
        $title = null;
        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return $this->reject('title cannot be empty', ['field' => 'title']);
            }
        }

        $model = null;
        if (array_key_exists('model', $body) && $body['model'] !== null) {
            if (!is_string($body['model']) || trim($body['model']) === '') {
                return $this->reject('model must be a non-empty string or null', ['field' => 'model']);
            }
            $model = trim($body['model']);
        }

        $workspace = null;
        if (array_key_exists('workspace', $body) && $body['workspace'] !== null) {
            if (!is_string($body['workspace'])) {
                return $this->reject('workspace must be a string or null', ['field' => 'workspace']);
            }
            $trimmed = trim($body['workspace']);
            $workspace = $trimmed === '' ? null : $trimmed;
        }

        $pinned = null;
        if (array_key_exists('pinned', $body)) {
            $pinned = filter_var($body['pinned'], FILTER_VALIDATE_BOOLEAN);
        }

        $status = null;
        if (array_key_exists('status', $body)) {
            $status = (string) $body['status'];
            if (!in_array($status, self::STATUS_ENUM, true)) {
                return $this->reject(
                    sprintf('Invalid status "%s"', $status),
                    ['field' => 'status', 'allowed' => self::STATUS_ENUM],
                );
            }
        }

        return new SessionUpdateRequest(
            updatesTitle: array_key_exists('title', $body),
            title: $title,
            updatesModel: array_key_exists('model', $body),
            model: $model,
            updatesWorkspace: array_key_exists('workspace', $body),
            workspace: $workspace,
            updatesPinned: array_key_exists('pinned', $body),
            pinned: $pinned,
            updatesStatus: array_key_exists('status', $body),
            status: $status,
        );
    }

    /**
     * @param array<string, mixed> $details
     */
    private function reject(string $message, array $details): Response
    {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $message, $details, 422);
    }
}
