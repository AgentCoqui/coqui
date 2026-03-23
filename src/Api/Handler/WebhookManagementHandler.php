<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;

use CoquiBot\Coqui\Storage\WebhookStore;
use CoquiBot\Coqui\Utility\SecretMasker;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * CRUD API endpoints for managing webhook subscriptions.
 *
 * Routes:
 *   GET    /api/v1/webhooks               - List subscriptions
 *   POST   /api/v1/webhooks               - Create subscription
 *   GET    /api/v1/webhooks/{id}           - Get subscription (secrets masked)
 *   PUT    /api/v1/webhooks/{id}           - Update subscription
 *   DELETE /api/v1/webhooks/{id}           - Delete subscription
 *   POST   /api/v1/webhooks/{id}/rotate    - Rotate signing secret
 *   GET    /api/v1/webhooks/{id}/deliveries - Recent deliveries
 */
final readonly class WebhookManagementHandler
{

    public function __construct(
        private WebhookStore $webhookStore,

    ) {}

    /**
     * Register routes on the given router.
     */
    public function register(Router $router): void
    {
        $router->get('/api/v1/webhooks', $this->handleList(...));
        $router->post('/api/v1/webhooks', $this->handleCreate(...));
        $router->get('/api/v1/webhooks/{id}', $this->handleGet(...));
        $router->put('/api/v1/webhooks/{id}', $this->handleUpdate(...));
        $router->delete('/api/v1/webhooks/{id}', $this->handleDelete(...));
        $router->post('/api/v1/webhooks/{id}/rotate', $this->handleRotateSecret(...));
        $router->get('/api/v1/webhooks/{id}/deliveries', $this->handleDeliveries(...));
    }

    private function handleList(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $enabled = isset($params['enabled']) ? filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN) : null;
        $limit = isset($params['limit']) ? max(1, min(500, (int) $params['limit'])) : 100;

        $webhooks = $this->webhookStore->list($enabled, $limit);

        // Mask secrets in list view
        $webhooks = array_map($this->maskSecret(...), $webhooks);

        return Router::jsonResponse([
            'webhooks' => $webhooks,
            'stats' => $this->webhookStore->getStats(),
        ]);
    }

    private function handleCreate(ServerRequestInterface $request): Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        // Validate required fields
        $name = trim((string) ($body['name'] ?? ''));
        $promptTemplate = trim((string) ($body['prompt_template'] ?? ''));

        if ($name === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'name is required');
        }
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}$/', $name)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Name must be 1-64 alphanumeric characters, hyphens, or underscores (must start with alphanumeric)',
            );
        }
        if ($promptTemplate === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'prompt_template is required');
        }

        // Check name uniqueness
        if ($this->webhookStore->getByName($name) !== null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, "Webhook name '{$name}' already exists");
        }

        // Validate source type
        $source = (string) ($body['source'] ?? 'generic');
        if (!in_array($source, WebhookStore::VALID_SOURCES, true)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Invalid source type. Supported: ' . implode(', ', WebhookStore::VALID_SOURCES),
            );
        }

        $id = $this->webhookStore->create(
            name: $name,
            promptTemplate: $promptTemplate,
            source: $source,
            role: (string) ($body['role'] ?? 'orchestrator'),
            maxIterations: max(1, (int) ($body['max_iterations'] ?? 48)),
            description: isset($body['description']) ? trim((string) $body['description']) : null,
            secret: isset($body['secret']) ? (string) $body['secret'] : null,
            eventFilter: isset($body['event_filter']) ? trim((string) $body['event_filter']) : null,
            createdBy: 'api',
        );

        $webhook = $this->webhookStore->get($id);

        return Router::jsonResponse([
            'webhook' => $webhook,
            'message' => 'Webhook created. Store the secret — it will be masked in future responses.',
        ], 201);
    }

    private function handleGet(ServerRequestInterface $request, string $id): Response
    {
        $webhook = $this->webhookStore->get($id);
        if ($webhook === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Webhook not found');
        }

        return Router::jsonResponse(['webhook' => $this->maskSecret($webhook)]);
    }

    private function handleUpdate(ServerRequestInterface $request, string $id): Response
    {
        $webhook = $this->webhookStore->get($id);
        if ($webhook === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Webhook not found');
        }

        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        // Validate name uniqueness if changing
        if (isset($body['name'])) {
            $newName = trim((string) $body['name']);
            $existing = $this->webhookStore->getByName($newName);
            if ($existing !== null && $existing['id'] !== $id) {
                return Router::errorResponse(ApiErrorCode::CONFLICT, "Webhook name '{$newName}' already exists");
            }
        }

        // Validate source type if changing
        if (isset($body['source'])) {
            $source = (string) $body['source'];
            if (!in_array($source, WebhookStore::VALID_SOURCES, true)) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Invalid source type. Supported: ' . implode(', ', WebhookStore::VALID_SOURCES),
                );
            }
        }

        $this->webhookStore->update(
            id: $id,
            name: isset($body['name']) ? trim((string) $body['name']) : null,
            description: isset($body['description']) ? trim((string) $body['description']) : null,
            source: $body['source'] ?? null,
            promptTemplate: isset($body['prompt_template']) ? trim((string) $body['prompt_template']) : null,
            role: $body['role'] ?? null,
            maxIterations: isset($body['max_iterations']) ? max(1, (int) $body['max_iterations']) : null,
            enabled: isset($body['enabled']) ? (bool) $body['enabled'] : null,
            eventFilter: isset($body['event_filter']) ? trim((string) $body['event_filter']) : null,
        );

        $updated = $this->webhookStore->get($id);

        return Router::jsonResponse(['webhook' => $this->maskSecret($updated ?? [])]);
    }

    private function handleDelete(ServerRequestInterface $request, string $id): Response
    {
        if (!$this->webhookStore->delete($id)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Webhook not found');
        }

        return Router::jsonResponse(['deleted' => true]);
    }

    private function handleRotateSecret(ServerRequestInterface $request, string $id): Response
    {
        $newSecret = $this->webhookStore->rotateSecret($id);
        if ($newSecret === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Webhook not found');
        }

        return Router::jsonResponse([
            'secret' => $newSecret,
            'message' => 'Secret rotated. Store the new secret — it will be masked in future responses.',
        ]);
    }

    private function handleDeliveries(ServerRequestInterface $request, string $id): Response
    {
        $webhook = $this->webhookStore->get($id);
        if ($webhook === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Webhook not found');
        }

        $params = $request->getQueryParams();
        $limit = isset($params['limit']) ? max(1, min(500, (int) $params['limit'])) : 50;

        $deliveries = $this->webhookStore->getDeliveries($id, $limit);

        return Router::jsonResponse(['deliveries' => $deliveries]);
    }

    /**
     * Mask the secret field in a webhook record for API responses.
     *
     * @param array<string, mixed> $webhook
     * @return array<string, mixed>
     */
    private function maskSecret(array $webhook): array
    {
        $secret = (string) ($webhook['secret'] ?? '');
        $webhook['secret'] = SecretMasker::mask($secret);
        return $webhook;
    }
}
