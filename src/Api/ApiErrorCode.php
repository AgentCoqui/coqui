<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

/**
 * Structured error codes for API responses.
 *
 * Every error response includes a machine-readable code alongside
 * the human-readable message, enabling clients to branch on error
 * types without parsing strings.
 */
enum ApiErrorCode: string
{
    case NOT_FOUND = 'not_found';
    case VALIDATION_ERROR = 'validation_error';
    case CONFLICT = 'conflict';
    case UNAUTHORIZED = 'unauthorized';
    case FORBIDDEN = 'forbidden';
    case INTERNAL_ERROR = 'internal_error';
    case AGENT_BUSY = 'agent_busy';
    case MISSING_FIELD = 'missing_field';
    case INVALID_FORMAT = 'invalid_format';
    case ROLE_NOT_FOUND = 'role_not_found';
    case ROLE_BUILTIN = 'role_builtin';
    case SESSION_NOT_FOUND = 'session_not_found';

    /**
     * Build a standard error response payload.
     *
     * @return array{error: string, code: string, details?: mixed}
     */
    public function toPayload(string $message, mixed $details = null): array
    {
        $payload = [
            'error' => $message,
            'code' => $this->value,
        ];

        if ($details !== null) {
            $payload['details'] = $details;
        }

        return $payload;
    }

    /**
     * Map error code to HTTP status code.
     */
    public function httpStatus(): int
    {
        return match ($this) {
            self::NOT_FOUND, self::ROLE_NOT_FOUND, self::SESSION_NOT_FOUND => 404,
            self::VALIDATION_ERROR, self::MISSING_FIELD, self::INVALID_FORMAT => 400,
            self::CONFLICT, self::ROLE_BUILTIN => 409,
            self::UNAUTHORIZED => 401,
            self::FORBIDDEN => 403,
            self::AGENT_BUSY => 429,
            self::INTERNAL_ERROR => 500,
        };
    }
}
