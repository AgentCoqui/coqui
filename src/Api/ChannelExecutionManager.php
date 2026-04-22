<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Storage\ChannelStore;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Converts inbound channel events into Coqui background tasks and outbound deliveries.
 */
final readonly class ChannelExecutionManager
{
    private const INBOUND_BATCH_LIMIT = 25;
    private const TASK_RECONCILE_BATCH_LIMIT = 25;
    private const DELIVERY_RECONCILE_BATCH_LIMIT = 25;

    public function __construct(
        private OpenClawConfig $config,
        private ChannelStore $channelStore,
        private SessionStorage $storage,
    ) {}

    public function tick(): void
    {
        $this->processReceivedInboundEvents();
        $this->reconcileQueuedTasks();
        $this->reconcileQueuedDeliveries();
    }

    private function processReceivedInboundEvents(): void
    {
        foreach ($this->channelStore->listInboundEventsByStatus('received', self::INBOUND_BATCH_LIMIT) as $event) {
            $channel = $this->channelStore->get((string) $event['channel_instance_id']);
            if ($channel === null || !(bool) ($channel['enabled'] ?? false)) {
                $this->rejectEvent((string) $event['id'], 'Channel is unavailable.');
                continue;
            }

            $conversationId = $this->stringOrNull($event['conversation_id'] ?? null);
            $conversation = $conversationId !== null ? $this->channelStore->getConversation($conversationId) : null;
            if ($conversation === null) {
                $this->failEvent((string) $event['id'], 'Channel conversation is missing.');
                continue;
            }

            $normalized = is_array($event['normalized'] ?? null) ? $event['normalized'] : [];
            $scopeKey = $this->stringOrNull($normalized['group_id'] ?? null);
            if (!$this->isAllowedScope($channel, $scopeKey)) {
                $this->rejectEvent((string) $event['id'], 'Inbound message scope is not allowed for this channel.');
                continue;
            }

            $remoteUserKey = $this->stringOrNull($event['remote_user_key'] ?? null);
            $link = $remoteUserKey !== null
                ? $this->channelStore->findLinkForRemoteIdentity((string) $channel['id'], $remoteUserKey, $scopeKey)
                : null;

            $security = is_array($channel['security'] ?? null) ? $channel['security'] : [];
            $linkRequired = (bool) ($security['linkRequired'] ?? false);
            $defaults = $this->config->getChannelConfig()['defaults'];
            $unknownUserPolicy = is_string($defaults['unknownUserPolicy'] ?? null)
                ? (string) $defaults['unknownUserPolicy']
                : 'deny';

            if ($link === null && ($linkRequired || $unknownUserPolicy === 'deny')) {
                $this->rejectEvent((string) $event['id'], 'Inbound sender is not linked to a Coqui profile.');
                continue;
            }

            $profile = $this->resolveProfile($channel, $conversation, $link, $defaults);
            $sessionId = $this->ensureConversationSession($channel, $conversation, $profile);
            $taskId = $this->storage->createTask(
                sessionId: $sessionId,
                prompt: $this->buildPrompt($channel, $conversation, $event, $link, $profile),
                role: SystemRole::Orchestrator->value,
                title: sprintf('[Channel] %s — %s', (string) $channel['name'], (string) ($conversation['remote_conversation_key'] ?? 'conversation')),
                maxIterations: 48,
                metadata: [
                    'channel' => [
                        'channel_instance_id' => (string) $channel['id'],
                        'channel_name' => (string) $channel['name'],
                        'driver' => (string) $channel['driver'],
                        'conversation_id' => (string) $conversation['id'],
                        'inbound_event_id' => (string) $event['id'],
                        'remote_user_key' => $remoteUserKey,
                        'remote_scope_key' => $scopeKey,
                    ],
                ],
            );

            $this->channelStore->upsertConversation(
                channelInstanceId: (string) $channel['id'],
                remoteConversationKey: (string) $conversation['remote_conversation_key'],
                remoteThreadKey: $this->stringOrNull($conversation['remote_thread_key'] ?? null),
                sessionId: $sessionId,
                profile: $profile,
                lastInboundEventId: (string) $event['id'],
                lastMessageAt: $this->stringOrNull($event['received_at'] ?? null),
                metadata: is_array($conversation['metadata'] ?? null) ? $conversation['metadata'] : [],
            );
            $this->channelStore->updateInboundEventState(
                eventId: (string) $event['id'],
                status: 'task_queued',
                sessionId: $sessionId,
                taskId: $taskId,
            );
        }
    }

    private function reconcileQueuedTasks(): void
    {
        foreach ($this->channelStore->listInboundEventsByStatus('task_queued', self::TASK_RECONCILE_BATCH_LIMIT) as $event) {
            $taskId = $this->stringOrNull($event['task_id'] ?? null);
            if ($taskId === null) {
                $this->failEvent((string) $event['id'], 'Inbound event is missing its queued task reference.');
                continue;
            }

            $task = $this->storage->getTask($taskId);
            if ($task === null) {
                $this->failEvent((string) $event['id'], 'Background task record is missing.');
                continue;
            }

            $taskStatus = (string) ($task['status'] ?? 'unknown');
            if (in_array($taskStatus, ['pending', 'running', 'cancelling'], true)) {
                continue;
            }

            if ($taskStatus !== 'completed') {
                $error = $this->stringOrNull($task['error'] ?? null) ?? sprintf('Background task ended with status %s.', $taskStatus);
                $this->failEvent((string) $event['id'], $error, $task['completed_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
                continue;
            }

            $channel = $this->channelStore->get((string) $event['channel_instance_id']);
            $conversationId = $this->stringOrNull($event['conversation_id'] ?? null);
            $conversation = $conversationId !== null ? $this->channelStore->getConversation($conversationId) : null;
            if ($channel === null || $conversation === null) {
                $this->failEvent((string) $event['id'], 'Channel context disappeared before reply delivery could be queued.');
                continue;
            }

            $result = trim((string) ($task['result'] ?? ''));
            if ($result === '') {
                $this->failEvent((string) $event['id'], 'Background task completed without reply content.', $task['completed_at'] ?? gmdate('Y-m-d\TH:i:s\Z'));
                continue;
            }

            $normalized = is_array($event['normalized'] ?? null) ? $event['normalized'] : [];
            $this->channelStore->queueDelivery(
                channelInstanceId: (string) $channel['id'],
                conversationId: (string) $conversation['id'],
                sessionId: $this->stringOrNull($task['session_id'] ?? null),
                replyToEventId: (string) $event['id'],
                idempotencyKey: 'task:' . $taskId,
                payload: [
                    'message' => $this->normalizeOutboundMessage($result),
                    'recipient' => $this->stringOrNull($event['remote_user_key'] ?? null),
                    'group_id' => $this->stringOrNull($normalized['group_id'] ?? null),
                    'conversation_key' => (string) ($conversation['remote_conversation_key'] ?? ''),
                    'task_id' => $taskId,
                    'event_id' => (string) $event['id'],
                    'driver' => (string) $channel['driver'],
                ],
            );
            $this->channelStore->updateInboundEventState(
                eventId: (string) $event['id'],
                status: 'delivery_queued',
                sessionId: $this->stringOrNull($task['session_id'] ?? null),
                taskId: $taskId,
            );
        }
    }

    private function reconcileQueuedDeliveries(): void
    {
        foreach ($this->channelStore->listInboundEventsByStatus('delivery_queued', self::DELIVERY_RECONCILE_BATCH_LIMIT) as $event) {
            $delivery = $this->channelStore->getDeliveryByReplyToEventId((string) $event['id']);
            if ($delivery === null) {
                continue;
            }

            $status = (string) ($delivery['status'] ?? 'queued');
            if ($status === 'sent') {
                $this->channelStore->updateInboundEventState(
                    eventId: (string) $event['id'],
                    status: 'processed',
                    processedAt: $this->stringOrNull($delivery['sent_at'] ?? null) ?? gmdate('Y-m-d\TH:i:s\Z'),
                    sessionId: $this->stringOrNull($event['session_id'] ?? null),
                    taskId: $this->stringOrNull($event['task_id'] ?? null),
                );
                continue;
            }

            if ($status === 'failed') {
                $this->failEvent(
                    (string) $event['id'],
                    $this->stringOrNull($delivery['last_error'] ?? null) ?? 'Reply delivery failed.',
                    $this->stringOrNull($delivery['failed_at'] ?? null) ?? gmdate('Y-m-d\TH:i:s\Z'),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $channel
     * @param array<string, mixed> $conversation
     * @param array<string, mixed> $event
     * @param array<string, mixed>|null $link
     */
    private function buildPrompt(array $channel, array $conversation, array $event, ?array $link, ?string $profile): string
    {
        $normalized = is_array($event['normalized'] ?? null) ? $event['normalized'] : [];
        $sourceName = $this->stringOrNull($normalized['source_name'] ?? null);
        $remoteUserKey = $this->stringOrNull($event['remote_user_key'] ?? null) ?? 'unknown';
        $message = trim((string) ($normalized['message'] ?? ''));
        $attachmentCount = (int) ($normalized['attachment_count'] ?? 0);
        $groupId = $this->stringOrNull($normalized['group_id'] ?? null);

        $lines = [
            'You are replying to an inbound external channel message for Coqui.',
            sprintf('Channel driver: %s', (string) ($channel['driver'] ?? 'unknown')),
            sprintf('Channel instance: %s', (string) ($channel['name'] ?? 'unknown')),
            sprintf('Conversation key: %s', (string) ($conversation['remote_conversation_key'] ?? 'unknown')),
            sprintf('Remote sender: %s', $sourceName !== null ? sprintf('%s (%s)', $sourceName, $remoteUserKey) : $remoteUserKey),
        ];

        if ($groupId !== null) {
            $lines[] = sprintf('Group scope: %s', $groupId);
        }

        if ($profile !== null) {
            $lines[] = sprintf('Active profile: %s', $profile);
        }

        if ($link !== null) {
            $lines[] = sprintf('Identity link trust level: %s', (string) ($link['trust_level'] ?? 'linked'));
        }

        if ($attachmentCount > 0) {
            $lines[] = sprintf('Attachments present: %d (attachment contents are not yet available in this first channel integration).', $attachmentCount);
        }

        $lines[] = 'Respond with the assistant message that should be sent back over the channel.';
        $lines[] = 'Keep the reply concise and suitable for a Signal chat unless the user explicitly asks for more detail.';
        $lines[] = '';
        $lines[] = 'Inbound message:';
        $lines[] = $message !== '' ? $message : '[No text body was provided in the incoming event.]';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $channel
     * @param array<string, mixed> $conversation
     * @param array<string, mixed>|null $link
     * @param array<string, mixed> $defaults
     */
    private function resolveProfile(array $channel, array $conversation, ?array $link, array $defaults): ?string
    {
        return $this->stringOrNull($link['profile'] ?? null)
            ?? $this->stringOrNull($conversation['profile'] ?? null)
            ?? $this->stringOrNull($channel['default_profile'] ?? null)
            ?? $this->stringOrNull($defaults['defaultProfile'] ?? null);
    }

    /**
     * @param array<string, mixed> $channel
     * @param array<string, mixed> $conversation
     */
    private function ensureConversationSession(array $channel, array $conversation, ?string $profile): string
    {
        $sessionId = $this->stringOrNull($conversation['session_id'] ?? null);
        if ($sessionId !== null) {
            if ($profile !== null) {
                $this->storage->updateSessionProfile($sessionId, $profile);
            }

            return $sessionId;
        }

        $sessionId = $this->storage->createSession(SystemRole::Orchestrator->value, 'channel:' . (string) $channel['driver'], $profile);
        $this->storage->updateSessionTitle(
            $sessionId,
            sprintf('Channel · %s · %s', (string) $channel['name'], (string) ($conversation['remote_conversation_key'] ?? 'conversation')),
        );

        return $sessionId;
    }

    /**
     * @param array<string, mixed> $channel
     */
    private function isAllowedScope(array $channel, ?string $scopeKey): bool
    {
        $allowedScopes = is_array($channel['allowed_scopes'] ?? null) ? $channel['allowed_scopes'] : [];
        if ($allowedScopes === [] || $scopeKey === null) {
            return true;
        }

        foreach ($allowedScopes as $allowedScope) {
            if (is_string($allowedScope) && trim($allowedScope) === $scopeKey) {
                return true;
            }
        }

        return false;
    }

    private function normalizeOutboundMessage(string $message): string
    {
        $normalized = trim($message);
        if ($normalized === '') {
            return '';
        }

        return mb_strlen($normalized) > 8000
            ? mb_substr($normalized, 0, 7997) . '...'
            : $normalized;
    }

    private function rejectEvent(string $eventId, string $reason): void
    {
        $this->channelStore->updateInboundEventState(
            eventId: $eventId,
            status: 'rejected',
            error: $reason,
            processedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    private function failEvent(string $eventId, string $reason, ?string $processedAt = null): void
    {
        $this->channelStore->updateInboundEventState(
            eventId: $eventId,
            status: 'failed',
            error: $reason,
            processedAt: $processedAt ?? gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
