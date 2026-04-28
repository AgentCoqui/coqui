<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Toolkits\Mcp\McpManagementService;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * MCP server management endpoints.
 */
final readonly class McpServerHandler
{
    public function __construct(
        private McpManagementService $service,
    ) {}

    public function register(Router $router): void
    {
        $router->get('/api/v1/mcp/servers', $this->handleList(...));
        $router->post('/api/v1/mcp/servers', $this->handleCreate(...));
        $router->get('/api/v1/mcp/servers/{name}', $this->handleGet(...));
        $router->patch('/api/v1/mcp/servers/{name}', $this->handleUpdate(...));
        $router->delete('/api/v1/mcp/servers/{name}', $this->handleDelete(...));
        $router->post('/api/v1/mcp/servers/{name}/enable', $this->handleEnable(...));
        $router->post('/api/v1/mcp/servers/{name}/disable', $this->handleDisable(...));
        $router->post('/api/v1/mcp/servers/{name}/promote', $this->handlePromote(...));
        $router->post('/api/v1/mcp/servers/{name}/demote', $this->handleDemote(...));
        $router->post('/api/v1/mcp/servers/{name}/auto', $this->handleAuto(...));
        $router->post('/api/v1/mcp/servers/{name}/connect', $this->handleConnect(...));
        $router->post('/api/v1/mcp/servers/{name}/disconnect', $this->handleDisconnect(...));
        $router->post('/api/v1/mcp/servers/{name}/refresh', $this->handleRefresh(...));
        $router->post('/api/v1/mcp/servers/{name}/test', $this->handleTest(...));
        $router->get('/api/v1/mcp/servers/{name}/tools', $this->handleTools(...));
        $router->post('/api/v1/mcp/servers/{name}/env', $this->handleSetEnv(...));
        $router->post('/api/v1/mcp/servers/{name}/auth', $this->handleAuth(...));
        $router->get('/api/v1/mcp/tools/search', $this->handleSearch(...));
    }

    private function handleList(ServerRequestInterface $request): Response
    {
        return Router::jsonResponse([
            'servers' => array_values($this->service->listServers()),
        ]);
    }

    private function handleCreate(ServerRequestInterface $request): Response
    {
        $body = $this->decodeBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        $name = trim((string) ($body['name'] ?? ''));
        $command = trim((string) ($body['command'] ?? ''));
        $description = array_key_exists('description', $body)
            ? trim((string) $body['description'])
            : null;

        if ($name === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'name is required');
        }

        if ($command === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'command is required');
        }

        try {
            $result = $this->service->addServer($name, $command, $this->readArgs($body['args'] ?? []), $description);

            return Router::jsonResponse([
                'server' => $this->service->getServerSnapshot($result['name']),
                'runtime' => ['applied' => $result['applied']],
                'message' => 'MCP server created.',
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, $e->getMessage());
        }
    }

    private function handleGet(ServerRequestInterface $request, string $name): Response
    {
        try {
            return Router::jsonResponse([
                'server' => $this->service->getServerSnapshot($name),
            ]);
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function handleUpdate(ServerRequestInterface $request, string $name): Response
    {
        $body = $this->decodeBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        $command = array_key_exists('command', $body) ? trim((string) $body['command']) : null;
        $args = array_key_exists('args', $body) ? $this->readArgs($body['args']) : null;
        $newName = array_key_exists('name', $body) ? trim((string) $body['name']) : null;
        $descriptionProvided = array_key_exists('description', $body);
        $description = $descriptionProvided ? trim((string) $body['description']) : null;

        try {
            $result = $this->service->updateServer($name, $command, $args, $newName, $descriptionProvided, $description);

            return Router::jsonResponse([
                'server' => $this->service->getServerSnapshot($result['name']),
                'runtime' => ['applied' => $result['applied']],
                'message' => 'MCP server updated.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\DomainException $e) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function handleDelete(ServerRequestInterface $request, string $name): Response
    {
        try {
            $result = $this->service->removeServer($name);

            return Router::jsonResponse([
                'deleted' => true,
                'name' => $result['name'],
                'runtime' => ['applied' => $result['applied']],
            ]);
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function handleEnable(ServerRequestInterface $request, string $name): Response
    {
        return $this->toggle($name, true);
    }

    private function handleDisable(ServerRequestInterface $request, string $name): Response
    {
        return $this->toggle($name, false);
    }

    private function handleConnect(ServerRequestInterface $request, string $name): Response
    {
        return $this->handleRuntimeAction(fn(): array => $this->service->connectServer($name), 'MCP server connected.');
    }

    private function handlePromote(ServerRequestInterface $request, string $name): Response
    {
        return $this->handleLoadingAction(fn(): array => $this->service->promoteServer($name), 'MCP server loading mode set to eager.');
    }

    private function handleDemote(ServerRequestInterface $request, string $name): Response
    {
        return $this->handleLoadingAction(fn(): array => $this->service->demoteServer($name), 'MCP server loading mode set to deferred.');
    }

    private function handleAuto(ServerRequestInterface $request, string $name): Response
    {
        return $this->handleLoadingAction(fn(): array => $this->service->autoServer($name), 'MCP server loading mode reset to auto.');
    }

    private function handleDisconnect(ServerRequestInterface $request, string $name): Response
    {
        try {
            $result = $this->service->disconnectServer($name);

            return Router::jsonResponse([
                'name' => $result['name'],
                'runtime' => ['applied' => $result['applied']],
                'message' => 'MCP server disconnected.',
            ]);
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function handleRefresh(ServerRequestInterface $request, string $name): Response
    {
        return $this->handleRuntimeAction(fn(): array => $this->service->refreshServer($name), 'MCP server refreshed.');
    }

    private function handleTest(ServerRequestInterface $request, string $name): Response
    {
        return $this->handleRuntimeAction(fn(): array => $this->service->testServer($name), 'MCP server connectivity test succeeded.');
    }

    private function handleTools(ServerRequestInterface $request, string $name): Response
    {
        try {
            return Router::jsonResponse([
                'server' => $this->service->getServerSnapshot($name),
                'tools' => $this->service->getServerTools($name),
            ]);
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function handleSearch(ServerRequestInterface $request): Response
    {
        $params = $request->getQueryParams();
        $query = trim((string) ($params['query'] ?? ''));
        $server = trim((string) ($params['server'] ?? ''));

        if ($query === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'query is required');
        }

        try {
            return Router::jsonResponse([
                'query' => $query,
                'server' => $server !== '' ? $server : null,
                'results' => $this->service->searchTools($query, $server !== '' ? $server : null),
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function handleSetEnv(ServerRequestInterface $request, string $name): Response
    {
        $body = $this->decodeBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        $key = trim((string) ($body['key'] ?? ''));
        $value = array_key_exists('value', $body) ? (string) $body['value'] : null;
        $placeholder = array_key_exists('placeholder', $body) ? trim((string) $body['placeholder']) : null;

        if ($key === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'key is required');
        }

        try {
            $result = $value !== null
                ? $this->service->setServerSecret($name, $key, $value)
                : $this->service->setServerEnvPlaceholder($name, $key, $placeholder ?? '');

            return Router::jsonResponse([
                'name' => $result['name'],
                'key' => $result['key'],
                'placeholder' => $result['placeholder'],
                'runtime' => ['applied' => $result['applied']],
                'message' => 'MCP server environment link updated.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function handleAuth(ServerRequestInterface $request, string $name): Response
    {
        $body = $this->decodeBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        $authUrl = trim((string) ($body['auth_url'] ?? ''));
        $tokenUrl = trim((string) ($body['token_url'] ?? ''));
        $clientId = trim((string) ($body['client_id'] ?? ''));
        $scopes = $this->readArgs($body['scopes'] ?? []);

        try {
            $result = $this->service->authorizeServer($name, $authUrl, $tokenUrl, $clientId, $scopes);

            return Router::jsonResponse([
                'server' => $result['server'],
                'env_key' => $result['env_key'],
                'expires_at' => $result['expires_at'],
                'runtime' => ['applied' => $result['applied']],
                'message' => 'MCP server OAuth completed.',
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    private function toggle(string $name, bool $enabled): Response
    {
        try {
            $result = $enabled ? $this->service->enableServer($name) : $this->service->disableServer($name);

            return Router::jsonResponse([
                'server' => $this->service->getServerSnapshot($result['name']),
                'runtime' => ['applied' => $result['applied']],
                'message' => $enabled ? 'MCP server enabled.' : 'MCP server disabled.',
            ]);
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
    }

    /**
     * @param callable(): array{name: string, duration_ms: int, snapshot: array<string, mixed>} $callback
     */
    private function handleRuntimeAction(callable $callback, string $message): Response
    {
        try {
            $result = $callback();

            return Router::jsonResponse([
                'server' => $result['snapshot'],
                'duration_ms' => $result['duration_ms'],
                'message' => $message,
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        } catch (\Throwable $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, $e->getMessage());
        }
    }

    /**
     * @param callable(): array{name: string, loading_mode: string, applied: string} $callback
     */
    private function handleLoadingAction(callable $callback, string $message): Response
    {
        try {
            $result = $callback();

            return Router::jsonResponse([
                'server' => $this->service->getServerSnapshot($result['name']),
                'runtime' => ['applied' => $result['applied']],
                'loading_mode' => $result['loading_mode'],
                'message' => $message,
            ]);
        } catch (\InvalidArgumentException $e) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $e->getMessage());
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, $e->getMessage());
        }
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
     * @param mixed $value
     * @return list<string>
     */
    private function readArgs(mixed $value): array
    {
        if (is_string($value)) {
            return $this->service->parseArgs($value);
        }

        if (!is_array($value)) {
            return [];
        }

        return array_values(array_map('strval', $value));
    }
}