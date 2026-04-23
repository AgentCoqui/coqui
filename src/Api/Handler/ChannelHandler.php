<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\ApiLifecycleController;
use CoquiBot\Coqui\Api\ChannelManager;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Channel\ChannelConfigurationEditor;
use CoquiBot\Coqui\Channel\ChannelDiscovery;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Storage\ChannelStore;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Channel management endpoints.
 */
final readonly class ChannelHandler
{
    public function __construct(
        private ChannelStore $channelStore,
        private ChannelManager $channelManager,
        private ChannelConfigurationEditor $configEditor,
        private ChannelDiscovery $channelDiscovery,
        private ProfileDiscovery $profileDiscovery,
        private ApiLifecycleController $lifecycle,
    ) {}

    public function register(Router $router): void
    {
        $router->get('/api/v1/channels', $this->handleList(...));
        $router->post('/api/v1/channels', $this->handleCreate(...));
        $router->get('/api/v1/channels/drivers', $this->handleDrivers(...));
        $router->get('/api/v1/channels/{id}', $this->handleGet(...));
        $router->patch('/api/v1/channels/{id}', $this->handleUpdate(...));
        $router->delete('/api/v1/channels/{id}', $this->handleDelete(...));
        $router->post('/api/v1/channels/{id}/enable', $this->handleEnable(...));
        $router->post('/api/v1/channels/{id}/disable', $this->handleDisable(...));
        $router->post('/api/v1/channels/{id}/test', $this->handleTest(...));
        $router->get('/api/v1/channels/{id}/health', $this->handleHealth(...));
        $router->get('/api/v1/channels/{id}/links', $this->handleLinks(...));
        $router->post('/api/v1/channels/{id}/links', $this->handleCreateLink(...));
        $router->delete('/api/v1/channels/{id}/links/{linkId}', $this->handleDeleteLink(...));
        $router->get('/api/v1/channels/{id}/conversations', $this->handleConversations(...));
        $router->get('/api/v1/channels/{id}/events', $this->handleEvents(...));
        $router->get('/api/v1/channels/{id}/deliveries', $this->handleDeliveries(...));
    }

    private function handleList(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $enabled = isset($params['enabled']) ? filter_var($params['enabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
        $driver = isset($params['driver']) ? trim((string) $params['driver']) : null;
        $channels = $this->channelStore->listInstances();

        if (is_bool($enabled)) {
            $channels = array_values(array_filter($channels, static fn(array $channel): bool => (bool) ($channel['enabled'] ?? false) === $enabled));
        }

        if (is_string($driver) && $driver !== '') {
            $channels = array_values(array_filter($channels, static fn(array $channel): bool => (string) ($channel['driver'] ?? '') === $driver));
        }

        return Router::jsonResponse([
            'channels' => $channels,
            'stats' => $this->channelStore->getStats(),
            'manager' => $this->channelManager->stats(),
        ]);
    }

    private function handleDrivers(ServerRequestInterface $request): Response
    {
        $packages = $this->channelDiscovery->packages();
        $drivers = [];

        foreach ($this->channelDiscovery->drivers() as $name => $driver) {
            $drivers[] = [
                'name' => $name,
                'display_name' => $driver->displayName(),
                'capabilities' => $driver->capabilities(),
                'package' => $packages[$name] ?? 'unknown',
            ];
        }

        usort($drivers, static fn(array $left, array $right): int => strcmp((string) $left['name'], (string) $right['name']));

        return Router::jsonResponse(['drivers' => $drivers]);
    }

    private function handleCreate(ServerRequestInterface $request): Response
    {
        $body = $this->decodeBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'name is required');
        }

        if ($this->configEditor->get($name) !== null) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Channel "%s" already exists.', $name));
        }

        $errors = $this->configEditor->create($name, $body);
        if ($errors !== []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid channel configuration.', $errors);
        }

        return Router::jsonResponse([
            'channel' => $this->syncAndFetch($name),
            'message' => 'Channel created.',
            'restart' => $this->lifecycle->markRestartRequired(
                'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
                'api.channels.create',
                ['channel_name' => $name, 'operation' => 'create'],
            ),
        ], 201);
    }

    private function handleGet(ServerRequestInterface $request, string $id): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        return Router::jsonResponse(['channel' => $channel]);
    }

    private function handleUpdate(ServerRequestInterface $request, string $id): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        $body = $this->decodeBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        $errors = $this->configEditor->update((string) $channel['name'], $body);
        if ($errors !== []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid channel configuration.', $errors);
        }

        return Router::jsonResponse([
            'channel' => $this->syncAndFetch((string) $channel['name']),
            'message' => 'Channel updated.',
            'restart' => $this->lifecycle->markRestartRequired(
                'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
                'api.channels.update',
                ['channel_name' => (string) $channel['name'], 'operation' => 'update'],
            ),
        ]);
    }

    private function handleDelete(ServerRequestInterface $request, string $id): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        if (!$this->configEditor->delete((string) $channel['name'])) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        $this->channelManager->reconcile();
        $this->channelManager->tick();

        return Router::jsonResponse([
            'deleted' => true,
            'restart' => $this->lifecycle->markRestartRequired(
                'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
                'api.channels.delete',
                ['channel_name' => (string) $channel['name'], 'operation' => 'delete'],
            ),
        ]);
    }

    private function handleEnable(ServerRequestInterface $request, string $id): Response
    {
        return $this->toggleChannel($id, true, 'Channel enabled.');
    }

    private function handleDisable(ServerRequestInterface $request, string $id): Response
    {
        return $this->toggleChannel($id, false, 'Channel disabled.');
    }

    private function handleTest(ServerRequestInterface $request, string $id): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        return Router::jsonResponse([
            'channel' => $this->syncAndFetch((string) $channel['name']),
            'message' => 'Channel reconcile completed.',
        ]);
    }

    private function handleHealth(ServerRequestInterface $request, string $id): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        return Router::jsonResponse([
            'channel' => $channel,
            'healthy' => (bool) ($channel['ready'] ?? false),
            'worker_status' => $channel['worker_status'] ?? 'missing',
        ]);
    }

    private function handleLinks(ServerRequestInterface $request, string $id): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        return Router::jsonResponse([
            'links' => $this->channelStore->listLinks((string) $channel['id'], $this->readLimit($request, 100)),
        ]);
    }

    private function handleCreateLink(ServerRequestInterface $request, string $id): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        $body = $this->decodeBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        $remoteUserKey = trim((string) ($body['remote_user_key'] ?? ''));
        $profile = trim((string) ($body['profile'] ?? ''));
        if ($remoteUserKey === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'remote_user_key is required');
        }
        if ($profile === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'profile is required');
        }
        if (!$this->profileDiscovery->profileExists($profile)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, sprintf('Unknown profile "%s".', $profile));
        }

        $linkId = $this->channelStore->createLink(
            (string) $channel['id'],
            $remoteUserKey,
            $profile,
            isset($body['remote_scope_key']) ? trim((string) $body['remote_scope_key']) : null,
            isset($body['trust_level']) ? trim((string) $body['trust_level']) : 'linked',
            isset($body['metadata']) && is_array($body['metadata']) ? $body['metadata'] : [],
        );

        return Router::jsonResponse([
            'link' => $this->findLink((string) $channel['id'], $linkId),
            'message' => 'Link created.',
        ], 201);
    }

    private function handleDeleteLink(ServerRequestInterface $request, string $id, string $linkId): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        if (!$this->channelStore->deleteLink((string) $channel['id'], $linkId)) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel link not found');
        }

        return Router::jsonResponse(['deleted' => true]);
    }

    private function handleConversations(ServerRequestInterface $request, string $id): Response
    {
        return $this->handleInstanceCollection($request, $id, $this->channelStore->listConversations(...), 'conversations');
    }

    private function handleEvents(ServerRequestInterface $request, string $id): Response
    {
        return $this->handleInstanceCollection($request, $id, $this->channelStore->listEvents(...), 'events');
    }

    private function handleDeliveries(ServerRequestInterface $request, string $id): Response
    {
        return $this->handleInstanceCollection($request, $id, $this->channelStore->listDeliveries(...), 'deliveries');
    }

    private function toggleChannel(string $id, bool $enabled, string $message): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        $errors = $this->configEditor->setEnabled((string) $channel['name'], $enabled);
        if ($errors !== []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Unable to update channel.', $errors);
        }

        return Router::jsonResponse([
            'channel' => $this->syncAndFetch((string) $channel['name']),
            'message' => $message,
            'restart' => $this->lifecycle->markRestartRequired(
                'Channel configuration changed. Restart the API server to ensure channel runtimes reload cleanly.',
                $enabled ? 'api.channels.enable' : 'api.channels.disable',
                ['channel_name' => (string) $channel['name'], 'operation' => $enabled ? 'enable' : 'disable'],
            ),
        ]);
    }

    private function handleInstanceCollection(ServerRequestInterface $request, string $id, callable $reader, string $key): Response
    {
        $channel = $this->channelStore->getByIdOrName($id);
        if ($channel === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, 'Channel not found');
        }

        return Router::jsonResponse([
            $key => $reader((string) $channel['id'], $this->readLimit($request, 100)),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function syncAndFetch(string $name): ?array
    {
        $this->channelManager->reconcile();
        $this->channelManager->tick();

        return $this->channelStore->getByName($name);
    }

    private function readLimit(ServerRequestInterface $request, int $default): int
    {
        $params = $request->getQueryParams();

        return isset($params['limit']) ? max(1, min(500, (int) $params['limit'])) : $default;
    }

    /**
     * @return array<string, mixed>|Response
     */
    private function decodeBody(ServerRequestInterface $request): array|Response
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLink(string $channelInstanceId, string $linkId): ?array
    {
        foreach ($this->channelStore->listLinks($channelInstanceId, 200) as $link) {
            if (($link['id'] ?? null) === $linkId) {
                return $link;
            }
        }

        return null;
    }
}