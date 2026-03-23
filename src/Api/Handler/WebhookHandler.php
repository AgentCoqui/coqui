<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Api\Webhook\WebhookVerifierRegistry;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\WebhookStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Handles incoming webhook deliveries.
 *
 * Route: POST /api/v1/webhooks/incoming/{name}
 *
 * Flow:
 * 1. Look up subscription by name
 * 2. Verify signature via the appropriate WebhookVerifierInterface
 * 3. Check event filter (if configured)
 * 4. Render prompt template with payload placeholders
 * 5. Create a background task
 * 6. Log delivery
 */
final readonly class WebhookHandler
{
    public function __construct(
        private WebhookStore $webhookStore,
        private SessionStorage $storage,
        private WebhookVerifierRegistry $verifierRegistry,
    ) {}

    /**
     * Register routes on the given router.
     */
    public function register(Router $router): void
    {
        $router->post('/api/v1/webhooks/incoming/{name}', $this->handleIncoming(...));
    }

    /**
     * Process an incoming webhook delivery.
     */
    private function handleIncoming(ServerRequestInterface $request, string $name): Response
    {
        $webhook = $this->webhookStore->getByName($name);
        if ($webhook === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Webhook not found');
        }

        if (!((int) $webhook['enabled'])) {
            $this->logDelivery($webhook, 'rejected_disabled', request: $request);
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Webhook is disabled');
        }

        // Get raw body
        $payload = (string) $request->getBody();
        if ($payload === '') {
            $this->logDelivery($webhook, 'rejected_empty', request: $request);
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Empty request body');
        }

        // Reject oversized payloads (1 MB limit)
        if (strlen($payload) > 1_048_576) {
            $this->logDelivery($webhook, 'rejected_too_large', request: $request);
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Payload exceeds 1 MB limit');
        }

        // Verify signature
        $headers = $this->normalizeHeaders($request);
        $verifier = $this->verifierRegistry->get((string) $webhook['source']);

        if (!$verifier->verify($payload, (string) $webhook['secret'], $headers)) {
            $this->logDelivery($webhook, 'rejected_signature', request: $request);
            // Return 401 but keep the error vague to not leak implementation details
            return Router::errorResponse(ApiErrorCode::UNAUTHORIZED, 'Invalid signature');
        }

        // Parse payload
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            // Accept non-JSON payloads — pass raw body as the payload
            $data = ['_raw' => $payload];
        }

        // Check event filter
        $eventType = $this->extractEventType($request, $data, (string) $webhook['source']);
        $eventFilter = $webhook['event_filter'] ?? null;

        if ($eventFilter !== null && $eventFilter !== '') {
            $allowedEvents = array_map('trim', explode(',', $eventFilter));
            if (!in_array($eventType, $allowedEvents, true)) {
                $this->logDelivery(
                    $webhook,
                    'filtered',
                    eventType: $eventType,
                    request: $request,
                );
                // Acknowledge but don't process
                return Router::jsonResponse(['status' => 'filtered', 'event_type' => $eventType]);
            }
        }

        // Render prompt template
        $prompt = $this->renderTemplate(
            (string) $webhook['prompt_template'],
            $payload,
            $eventType,
            $data,
        );

        // Create background task
        $role = (string) ($webhook['role'] ?? 'orchestrator');
        $sessionId = $this->storage->createSession($role, 'webhook');

        $taskId = $this->storage->createTask(
            sessionId: $sessionId,
            prompt: $prompt,
            role: $role,
            title: sprintf('[Webhook] %s — %s', $name, $eventType ?: 'delivery'),
            maxIterations: (int) ($webhook['max_iterations'] ?? 48),
        );

        // Update webhook stats
        $this->webhookStore->markTriggered((string) $webhook['id']);

        // Log successful delivery
        $payloadSummary = $this->summarizePayload($data, $eventType);
        $this->logDelivery(
            $webhook,
            'delivered',
            eventType: $eventType,
            payloadSummary: $payloadSummary,
            taskId: $taskId,
            request: $request,
        );

        return Router::jsonResponse([
            'status' => 'accepted',
            'task_id' => $taskId,
            'event_type' => $eventType,
        ]);
    }

    /**
     * Extract event type from request headers or payload based on source type.
     *
     * @param array<string, mixed> $data
     */
    private function extractEventType(
        ServerRequestInterface $request,
        array $data,
        string $source,
    ): string {
        return match ($source) {
            'github' => $request->getHeaderLine('X-GitHub-Event'),
            'slack' => (string) ($data['type'] ?? $data['event']['type'] ?? ''),
            default => $request->getHeaderLine('X-Event-Type'),
        };
    }

    /**
     * Render prompt template with webhook placeholders.
     *
     * Supported placeholders:
     *   {{payload}}    — full JSON payload
     *   {{event_type}} — extracted event type
     *   {{summary}}    — truncated payload summary
     *
     * All field-level references like {{field.name}} are resolved from the payload.
     *
     * @param array<string, mixed> $data
     */
    private function renderTemplate(
        string $template,
        string $rawPayload,
        string $eventType,
        array $data,
    ): string {
        // Truncate raw payload for the {{payload}} placeholder to avoid token waste
        $maxPayloadLength = 4096;
        $truncatedPayload = mb_strlen($rawPayload) > $maxPayloadLength
            ? mb_substr($rawPayload, 0, $maxPayloadLength) . "\n...[truncated]"
            : $rawPayload;

        $result = str_replace(
            ['{{payload}}', '{{event_type}}', '{{summary}}'],
            [$truncatedPayload, $eventType, $this->summarizePayload($data, $eventType)],
            $template,
        );

        // Resolve {{field.path}} references from the payload
        $result = preg_replace_callback('/\{\{(\w+(?:\.\w+)*)\}\}/', static function (array $matches) use ($data): string {
            $path = explode('.', $matches[1]);
            $current = $data;
            foreach ($path as $key) {
                if (!is_array($current) || !array_key_exists($key, $current)) {
                    return $matches[0]; // Leave unresolved placeholders as-is
                }
                $current = $current[$key];
            }
            return is_scalar($current) ? (string) $current : (string) json_encode($current, JSON_UNESCAPED_SLASHES);
        }, $result) ?? $result;

        return $result;
    }

    /**
     * Create a brief summary of the webhook payload for audit logging.
     *
     * @param array<string, mixed> $data
     */
    private function summarizePayload(array $data, string $eventType): string
    {
        $parts = [];
        if ($eventType !== '') {
            $parts[] = "event: {$eventType}";
        }

        // Extract common identifiers
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
     * Normalize request headers to lowercase keys.
     *
     * @return array<string, string>
     */
    private function normalizeHeaders(ServerRequestInterface $request): array
    {
        $normalized = [];
        foreach ($request->getHeaders() as $name => $values) {
            $normalized[strtolower($name)] = implode(', ', $values);
        }
        return $normalized;
    }

    /**
     * Log a delivery attempt.
     *
     * @param array<string, mixed> $webhook
     */
    private function logDelivery(
        array $webhook,
        string $status,
        ?string $eventType = null,
        ?string $payloadSummary = null,
        ?string $taskId = null,
        ?ServerRequestInterface $request = null,
    ): void {
        $sourceIp = null;
        if ($request !== null) {
            // Prefer X-Forwarded-For if behind a reverse proxy
            $sourceIp = $request->getHeaderLine('X-Forwarded-For') ?: null;
            if ($sourceIp === null) {
                $serverParams = $request->getServerParams();
                $sourceIp = $serverParams['REMOTE_ADDR'] ?? null;
            }
        }

        $this->webhookStore->logDelivery(
            webhookId: (string) $webhook['id'],
            status: $status,
            eventType: $eventType,
            payloadSummary: $payloadSummary,
            taskId: $taskId,
            sourceIp: $sourceIp,
        );
    }
}
