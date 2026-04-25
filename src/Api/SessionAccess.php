<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Storage\SessionStorage;
use React\Http\Message\Response;

final class SessionAccess
{
    /**
     * @return array<string, mixed>|Response
     */
    public static function requireReadableSession(SessionStorage $storage, string $sessionId): array|Response
    {
        $session = $storage->getSurfacedSession($sessionId);

        if ($session === null) {
            return Router::errorResponse(ApiErrorCode::SESSION_NOT_FOUND, 'Session not found');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>|Response
     */
    public static function requireWritableSession(SessionStorage $storage, string $sessionId): array|Response
    {
        $session = self::requireReadableSession($storage, $sessionId);

        if ($session instanceof Response) {
            return $session;
        }

        if (((int) ($session['is_closed'] ?? 0)) === 1) {
            $status = (string) ($session['status'] ?? 'closed');
            $message = $status === 'archived'
                ? 'Session is archived and closed. Historical sessions are read-only.'
                : 'Session is closed and cannot be modified.';

            return Router::errorResponse(
                ApiErrorCode::SESSION_CLOSED,
                $message,
                [
                    'session_id' => $sessionId,
                    'status' => $status,
                    'closure_reason' => $session['closure_reason'] ?? null,
                ],
            );
        }

        return $session;
    }
}