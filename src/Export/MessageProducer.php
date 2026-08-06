<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Export;

/**
 * Projects a coqui `messages` row to a CAP 0.5.0 `message.json` wire object.
 *
 * `role` is a closed set (user|assistant|tool|system); `tool_calls` and
 * `attachments` are `array|null`. coqui does not persist message attachments as a
 * column, so `attachments` is emitted as null unless the row already carries a
 * decoded list (the export assembler may inject content-addressed attachments).
 */
final class MessageProducer
{
    private const array ROLES = ['user', 'assistant', 'tool', 'system'];

    /**
     * @param array<string, mixed> $row A `messages` row.
     * @return array<string, mixed>
     */
    public static function toWire(array $row): array
    {
        $turnId = $row['turn_id'] ?? null;
        $toolCallId = $row['tool_call_id'] ?? null;
        $actorName = $row['actor_name'] ?? null;
        $actorRole = $row['actor_role'] ?? null;
        $role = (string) ($row['role'] ?? 'user');

        return [
            'id' => (string) ($row['id'] ?? ''),
            'session_id' => (string) ($row['session_id'] ?? ''),
            'turn_id' => is_string($turnId) && $turnId !== '' ? $turnId : null,
            'role' => in_array($role, self::ROLES, true) ? $role : 'assistant',
            'content' => (string) ($row['content'] ?? ''),
            'tool_calls' => WireFormat::array($row['tool_calls'] ?? null),
            'tool_call_id' => is_string($toolCallId) && $toolCallId !== '' ? $toolCallId : null,
            'actor_name' => is_string($actorName) && $actorName !== '' ? $actorName : null,
            'actor_role' => is_string($actorRole) && $actorRole !== '' ? $actorRole : null,
            'attachments' => WireFormat::array($row['attachments'] ?? null),
            'created_at' => WireFormat::timestamp($row['created_at'] ?? null) ?? '',
        ];
    }
}
