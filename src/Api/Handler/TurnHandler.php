<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\SessionAccess;
use CoquiBot\Coqui\Storage\SessionStorage;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Turn inspection endpoints.
 *
 * GET /api/v1/sessions/{id}/turns           — list turns for a session
 * GET /api/v1/sessions/{id}/turns/{turnId}  — get turn detail with messages
 * GET /api/v1/sessions/{id}/turns/{turnId}/events — list replayable turn events
 */
final readonly class TurnHandler
{
    public function __construct(
        private SessionStorage $storage,
    ) {}

    /**
     * GET /api/v1/sessions/{id}/turns?limit=50
     */
    public function list(ServerRequestInterface $request, string $id): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? min((int) $params['limit'], 200) : 50;

        $turns = $this->storage->getTurns($id, $limit);

        return Router::jsonResponse([
            'session_id' => $id,
            'turns' => $turns,
            'count' => count($turns),
        ]);
    }

    /**
     * GET /api/v1/sessions/{id}/turns/{turnId}
     */
    public function get(ServerRequestInterface $request, string $id, string $turnId): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $turn = $this->storage->getTurnWithMessages($turnId);

        if ($turn === null) {
            return Router::errorResponse(ApiErrorCode::TURN_NOT_FOUND, 'Turn not found');
        }

        // Verify the turn belongs to the requested session
        if ($turn['session_id'] !== $id) {
            return Router::errorResponse(ApiErrorCode::TURN_NOT_FOUND, 'Turn does not belong to this session');
        }

        return Router::jsonResponse($turn);
    }

    /**
     * GET /api/v1/sessions/{id}/turns/{turnId}/events
     */
    public function events(ServerRequestInterface $request, string $id, string $turnId): Response
    {
        $session = SessionAccess::requireReadableSession($this->storage, $id);
        if ($session instanceof Response) {
            return $session;
        }

        $turn = $this->storage->getTurn($turnId);

        if ($turn === null || ($turn['session_id'] ?? null) !== $id) {
            return Router::errorResponse(ApiErrorCode::TURN_NOT_FOUND, 'Turn not found');
        }

        $events = isset($turn['turn_process_id']) && is_string($turn['turn_process_id']) && $turn['turn_process_id'] !== ''
            ? $this->storage->getDecodedTurnEvents($turn['turn_process_id'])
            : [];

        return Router::jsonResponse([
            'session_id' => $id,
            'turn_id' => $turnId,
            'events' => $events,
            'count' => count($events),
        ]);
    }

    /**
     * Produce a strict CAP 0.5.0 `turn.json` wire object from a normalized turn
     * row (as returned by {@see SessionStorage::getTurn()}).
     *
     * This is the conformance producer: it emits exactly the schema's property
     * set (no rich extras), so it is `additionalProperties:false`-clean. It
     * carries `actor_persona_id` (nullable), derives a safe `status`, decodes
     * `tools_used`, omits `model` when null (the schema type is a non-null
     * string, not nullable), and Z-normalizes the timestamps.
     *
     * @param array<string, mixed> $turn
     * @return array<string, mixed>
     */
    public static function toWire(array $turn): array
    {
        $completedAt = $turn['completed_at'] ?? null;
        $completedAt = is_string($completedAt) && $completedAt !== '' ? $completedAt : null;

        $actorPersonaId = $turn['actor_persona_id'] ?? null;
        $model = $turn['model'] ?? null;
        $responseText = $turn['response_text'] ?? null;

        $wire = [
            'id' => (string) ($turn['id'] ?? ''),
            'session_id' => (string) ($turn['session_id'] ?? ''),
            'actor_persona_id' => is_string($actorPersonaId) && $actorPersonaId !== '' ? $actorPersonaId : null,
            'turn_number' => max(1, (int) ($turn['turn_number'] ?? 1)),
            'user_prompt' => (string) ($turn['user_prompt'] ?? ''),
            'response_text' => is_string($responseText) ? $responseText : null,
            'prompt_tokens' => max(0, (int) ($turn['prompt_tokens'] ?? 0)),
            'completion_tokens' => max(0, (int) ($turn['completion_tokens'] ?? 0)),
            'total_tokens' => max(0, (int) ($turn['total_tokens'] ?? 0)),
            'iterations' => max(0, (int) ($turn['iterations'] ?? 0)),
            'duration_ms' => max(0, (int) ($turn['duration_ms'] ?? 0)),
            'tools_used' => self::toolsUsed($turn['tools_used'] ?? null),
            'status' => self::deriveStatus($turn, $completedAt),
            'created_at' => self::toUtcZ($turn['created_at'] ?? null),
            'completed_at' => $completedAt === null ? null : self::toUtcZ($completedAt),
        ];

        // model is a non-null ModelId string and is not required: omit the key
        // when null rather than emit a schema-invalid `model: null`.
        if (is_string($model) && $model !== '') {
            $wire['model'] = $model;
        }

        return $wire;
    }

    /**
     * Derive a schema-enum status. Prefers the stored lifecycle value; falls back
     * to 'completed' when a turn carries a completion timestamp but its stored
     * status is still 'running' (defensive against pre-status rows).
     *
     * @param array<string, mixed> $turn
     */
    private static function deriveStatus(array $turn, ?string $completedAt): string
    {
        $stored = $turn['status'] ?? null;
        if (is_string($stored) && in_array($stored, ['running', 'completed', 'failed', 'cancelled'], true)) {
            if ($stored === 'running' && $completedAt !== null) {
                return 'completed';
            }

            return $stored;
        }

        return $completedAt !== null ? 'completed' : 'running';
    }

    /**
     * Normalize tools_used to a nullable string array. Absent/empty ⇒ null.
     *
     * @return list<string>|null
     */
    private static function toolsUsed(mixed $value): ?array
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }

        if (!is_array($value)) {
            return null;
        }

        $strings = array_values(array_filter($value, is_string(...)));

        return $strings === [] ? null : $strings;
    }

    /**
     * Normalize an RFC-3339 timestamp to UTC with a Z suffix (CAP Timestamp).
     */
    private static function toUtcZ(mixed $value): string
    {
        if (!is_string($value) || $value === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $value;
        }
    }
}
