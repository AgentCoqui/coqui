<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Webhook;

use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use Psr\Http\Message\ServerRequestInterface;

final readonly class WebhookDispatchService
{
    public function __construct(
        private WebhookStore $webhookStore,
        private SessionStorage $storage,
    ) {}

    /**
     * Dispatch a webhook into a background task and log the delivery.
     *
     * @param array<string, mixed> $webhook
     * @param array<string, mixed> $data
     * @return array{status: string, task_id: string, session_id: string, event_type: string, delivery_id: string, prompt: string}
     */
    public function dispatch(
        array $webhook,
        string $payload,
        string $eventType,
        array $data,
        ?ServerRequestInterface $request = null,
        bool $isTest = false,
    ): array {
        $prompt = $this->renderTemplate(
            (string) $webhook['prompt_template'],
            $payload,
            $eventType,
            $data,
        );

        $role = (string) ($webhook['role'] ?? 'orchestrator');
        $profile = is_string($webhook['profile'] ?? null) && $webhook['profile'] !== ''
            ? (string) $webhook['profile']
            : null;
        $sessionId = $this->storage->createSession($role, 'webhook', $profile, visibility: 'hidden');

        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $prompt,
            role: $role,
            title: sprintf('[Webhook%s] %s — %s', $isTest ? ' Test' : '', (string) ($webhook['name'] ?? 'webhook'), $eventType !== '' ? $eventType : 'delivery'),
            maxIterations: (int) ($webhook['max_iterations'] ?? 48),
        );

        if (!$isTest) {
            $this->webhookStore->markTriggered((string) $webhook['id']);
        }

        $deliveryId = $this->logDelivery(
            webhook: $webhook,
            status: $isTest ? 'test_delivered' : 'delivered',
            eventType: $eventType,
            payloadSummary: $this->summarizePayload($data, $eventType),
            taskId: $taskId,
            request: $request,
        );

        return [
            'status' => 'accepted',
            'task_id' => $taskId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'delivery_id' => $deliveryId,
            'prompt' => $prompt,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function summarizePayload(array $data, string $eventType): string
    {
        $parts = [];
        if ($eventType !== '') {
            $parts[] = "event: {$eventType}";
        }

        foreach (['action', 'repository.full_name', 'sender.login', 'channel', 'user'] as $path) {
            $keys = explode('.', $path);
            $current = $data;
            foreach ($keys as $key) {
                if (!is_array($current) || !array_key_exists($key, $current)) {
                    $current = null;
                    break;
                }
                $current = $current[$key];
            }
            if (is_string($current) && $current !== '') {
                $parts[] = "{$path}: {$current}";
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderTemplate(
        string $template,
        string $rawPayload,
        string $eventType,
        array $data,
    ): string {
        $maxPayloadLength = 4096;
        $truncatedPayload = mb_strlen($rawPayload) > $maxPayloadLength
            ? mb_substr($rawPayload, 0, $maxPayloadLength) . "\n...[truncated]"
            : $rawPayload;

        $result = str_replace(
            ['{{payload}}', '{{event_type}}', '{{summary}}'],
            [$truncatedPayload, $eventType, $this->summarizePayload($data, $eventType)],
            $template,
        );

        $result = preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', static function (array $matches) use ($data): string {
            $path = explode('.', $matches[1]);
            $current = $data;
            foreach ($path as $key) {
                if (!is_array($current) || !array_key_exists($key, $current)) {
                    return $matches[0];
                }
                $current = $current[$key];
            }

            return is_scalar($current) ? (string) $current : (string) json_encode($current, JSON_UNESCAPED_SLASHES);
        }, $result) ?? $result;

        return $result;
    }

    /**
     * @param array<string, mixed> $webhook
     */
    private function logDelivery(
        array $webhook,
        string $status,
        ?string $eventType = null,
        ?string $payloadSummary = null,
        ?string $taskId = null,
        ?ServerRequestInterface $request = null,
    ): string {
        $sourceIp = null;
        if ($request !== null) {
            $sourceIp = $request->getHeaderLine('X-Forwarded-For') ?: null;
            if ($sourceIp === null) {
                $serverParams = $request->getServerParams();
                $sourceIp = $serverParams['REMOTE_ADDR'] ?? null;
            }
        }

        return $this->webhookStore->logDelivery(
            webhookId: (string) $webhook['id'],
            status: $status,
            eventType: $eventType,
            payloadSummary: $payloadSummary,
            taskId: $taskId,
            sourceIp: $sourceIp,
        );
    }
}