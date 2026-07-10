<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CoquiBot\Coqui\Contract\McpOAuthInterface;
use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\Support\ArgumentTokenizer;
use CoquiBot\Coqui\Mcp\Support\McpServerPolicy;
use CoquiBot\Coqui\Mcp\Support\ServerLoadingModeStore;

/**
 * Shared MCP server management service used by tool, REPL, and API adapters.
 *
 * @phpstan-type McpAudit array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}
 * @phpstan-type McpServerSnapshot array{name: string, description: ?string, connected: bool, disabled: bool, loadingMode: string, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string, command: ?string, args: list<string>, env: array<string, string>, audit: McpAudit}
 * @phpstan-type McpServerList array<string, McpServerSnapshot>
 */
final class McpManagementService
{
    /**
     * @var array<string, McpAudit>
     */
    private array $audit = [];

    public function __construct(
        private readonly McpConfig $config,
        private readonly McpServerManager $manager,
        private readonly ?McpOAuthInterface $oauth = null,
        private readonly ?ServerLoadingModeStore $loadingStore = null,
        private readonly ?McpServerPolicy $policy = null,
    ) {}

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return $this->manager->getTools();
    }

    /**
     * @return list<ToolInterface>
     */
    public function toolObjectsForServer(string $name): array
    {
        $this->assertServerExists($name);

        return $this->manager->getToolsForServer($name);
    }

    /**
     * @return list<string>
     */
    public function configuredServerNames(): array
    {
        $this->config->load(force: true);

        return array_keys($this->config->listServers());
    }

    /**
     * @return array<string, string>
     */
    public function serverInstructions(): array
    {
        return $this->manager->getServerInstructions();
    }

    /**
        * @return McpServerList
     */
    public function listServers(): array
    {
        $this->config->load(force: true);
        $servers = [];

        foreach (array_keys($this->config->listServers()) as $name) {
            $servers[$name] = $this->getServerSnapshot($name);
        }

        return $servers;
    }

    /**
        * @return McpServerSnapshot
     */
    public function getServerSnapshot(string $name): array
    {
        $this->config->load(force: true);
        $config = $this->config->getServer($name);

        if ($config === null) {
            throw new \RuntimeException(sprintf('Server "%s" not found.', $name));
        }

        $status = $this->manager->getServerStatus($name);

        return [
            'name' => $name,
            'description' => $this->config->getDescription($name),
            'connected' => $status['connected'],
            'disabled' => $this->config->isDisabled($name),
            'loadingMode' => $this->loadingStore?->getMode($name) ?? 'auto',
            'serverName' => $status['serverName'],
            'serverVersion' => $status['serverVersion'],
            'toolCount' => $status['toolCount'],
            'error' => $status['error'],
            'instructions' => $status['instructions'],
            'command' => $this->config->getCommand($name),
            'args' => $this->config->getArgs($name),
            'env' => $this->config->getEnv($name),
            'audit' => $this->auditSnapshot($name),
        ];
    }

    /**
     * @param list<string> $args
     * @return array{name: string, command: string, args: list<string>, applied: string}
     */
    public function addServer(string $name, string $command, array $args = [], ?string $description = null): array
    {
        $name = trim($name);
        $command = trim($command);
        $args = $this->coerceStringList($args);
        $description = $description !== null ? trim($description) : null;

        if ($name === '') {
            throw new \InvalidArgumentException('Server name is required.');
        }

        if ($command === '') {
            throw new \InvalidArgumentException('Command is required.');
        }

        $this->validateStdioDefinition($command, $args);

        $this->config->load(force: true);

        if ($this->config->getServer($name) !== null) {
            throw new \RuntimeException(sprintf('Server "%s" already exists.', $name));
        }

        $serverConfig = [
            'command' => $command,
            'args' => $args,
        ];

        if ($description !== null && $description !== '') {
            $serverConfig['description'] = $description;
        }

        $this->config->addServer($name, $serverConfig);
        $this->config->save();

        return [
            'name' => $name,
            'command' => $command,
            'args' => $args,
            'applied' => 'next_turn',
        ];
    }

    /**
     * @param list<string>|null $args
     * @return array{name: string, command: string, args: list<string>, applied: string}
     */
    public function updateServer(
        string $name,
        ?string $command = null,
        ?array $args = null,
        ?string $newName = null,
        bool $descriptionProvided = false,
        ?string $description = null,
    ): array
    {
        $this->config->load(force: true);
        $existing = $this->config->getServer($name);

        if ($existing === null) {
            throw new \RuntimeException(sprintf('Server "%s" not found.', $name));
        }

        $nextConfig = $existing;
        $targetName = trim($newName ?? $name);

        if ($targetName === '') {
            throw new \InvalidArgumentException('Server name is required.');
        }

        if ($targetName !== $name && $this->config->getServer($targetName) !== null) {
            throw new \DomainException(sprintf('Server "%s" already exists.', $targetName));
        }

        if ($command !== null && trim($command) !== '') {
            $nextConfig['command'] = trim($command);
        }

        if ($args !== null) {
            $nextConfig['args'] = $args;
        }

        if ($descriptionProvided) {
            $nextDescription = trim($description ?? '');

            if ($nextDescription === '') {
                unset($nextConfig['description']);
            } else {
                $nextConfig['description'] = $nextDescription;
            }
        }

        $nextCommand = trim((string) ($nextConfig['command'] ?? ''));
        $nextArgs = $this->coerceStringList($nextConfig['args'] ?? []);
        $this->validateStdioDefinition($nextCommand, $nextArgs);

        $wasConnected = $this->manager->getServerStatus($name)['connected'];
        $renamed = $targetName !== $name;

        if ($renamed) {
            if ($wasConnected) {
                $this->manager->disconnectServer($name);
            }

            if (!$this->config->renameServer($name, $targetName)) {
                throw new \RuntimeException(sprintf('Server "%s" not found.', $name));
            }

            $this->migrateAudit($name, $targetName);
            $this->loadingStore?->rename($name, $targetName);
        }

        $this->config->addServer($targetName, $nextConfig);
        $this->config->save();

        if ($wasConnected) {
            try {
                if ($renamed) {
                    $this->reconnectUpdatedServer($targetName);
                } else {
                    $this->refreshServer($targetName);
                }

                return [
                    'name' => $targetName,
                    'command' => $nextCommand,
                    'args' => $nextArgs,
                    'applied' => 'live',
                ];
            } catch (\Throwable) {
                // Keep the config update. The caller can surface the runtime reconnect failure separately.
            }
        }

        return [
            'name' => $targetName,
            'command' => $nextCommand,
            'args' => $nextArgs,
            'applied' => 'next_turn',
        ];
    }

    /**
     * @param list<string> $args
     */
    private function validateStdioDefinition(string $command, array $args): void
    {
        $this->policy?->assertAllowedStdioCommand($command, $args);
    }

    /**
     * @return array{name: string, applied: string}
     */
    public function removeServer(string $name): array
    {
        $this->config->load(force: true);

        if (!$this->config->removeServer($name)) {
            throw new \RuntimeException(sprintf('Server "%s" not found.', $name));
        }

        $this->manager->disconnectServer($name);
    $this->loadingStore?->forget($name);
        $this->config->save();
        unset($this->audit[$name]);

        return [
            'name' => $name,
            'applied' => 'live',
        ];
    }

    /**
     * @return array{name: string, key: string, placeholder: string, applied: string}
     */
    public function setServerSecret(string $name, string $key, string $value): array
    {
        $key = trim($key);

        if ($key === '') {
            throw new \InvalidArgumentException('Environment variable key is required.');
        }

        $this->assertServerExists($name);
        putenv($key . '=' . $value);

        return $this->setServerEnvPlaceholder($name, $key, '${' . $key . '}');
    }

    /**
     * @return array{name: string, key: string, placeholder: string, applied: string}
     */
    public function setServerEnvPlaceholder(string $name, string $key, string $placeholder): array
    {
        $key = trim($key);
        $placeholder = trim($placeholder);

        if ($key === '') {
            throw new \InvalidArgumentException('Environment variable key is required.');
        }

        if ($placeholder === '') {
            throw new \InvalidArgumentException('Environment placeholder is required.');
        }

        $this->assertServerExists($name);
        $this->config->setServerEnv($name, $key, $placeholder);
        $this->config->save();

        return [
            'name' => $name,
            'key' => $key,
            'placeholder' => $placeholder,
            'applied' => $this->manager->getServerStatus($name)['connected'] ? 'reconnect_required' : 'next_turn',
        ];
    }

    /**
     * @return array{name: string, disabled: bool, applied: string}
     */
    public function enableServer(string $name): array
    {
        $this->config->load(force: true);

        if (!$this->config->enableServer($name)) {
            throw new \RuntimeException(sprintf('Server "%s" not found.', $name));
        }

        $this->config->save();

        return [
            'name' => $name,
            'disabled' => false,
            'applied' => 'live',
        ];
    }

    /**
     * @return array{name: string, disabled: bool, applied: string}
     */
    public function disableServer(string $name): array
    {
        $this->config->load(force: true);

        if (!$this->config->disableServer($name)) {
            throw new \RuntimeException(sprintf('Server "%s" not found.', $name));
        }

        $this->manager->disconnectServer($name);
        $this->recordDisconnect($name);
        $this->config->save();

        return [
            'name' => $name,
            'disabled' => true,
            'applied' => 'live',
        ];
    }

    /**
     * @return array{name: string, loading_mode: string, applied: string}
     */
    public function promoteServer(string $name): array
    {
        $this->assertServerExists($name);
        $this->requireLoadingStore()->promote($name);

        return [
            'name' => $name,
            'loading_mode' => 'eager',
            'applied' => 'next_turn',
        ];
    }

    /**
     * @return array{name: string, loading_mode: string, applied: string}
     */
    public function demoteServer(string $name): array
    {
        $this->assertServerExists($name);
        $this->requireLoadingStore()->demote($name);

        return [
            'name' => $name,
            'loading_mode' => 'deferred',
            'applied' => 'next_turn',
        ];
    }

    /**
     * @return array{name: string, loading_mode: string, applied: string}
     */
    public function autoServer(string $name): array
    {
        $this->assertServerExists($name);
        $this->requireLoadingStore()->auto($name);

        return [
            'name' => $name,
            'loading_mode' => 'auto',
            'applied' => 'next_turn',
        ];
    }

    /**
    * @return array{name: string, duration_ms: int, snapshot: McpServerSnapshot}
     */
    public function connectServer(string $name): array
    {
        $this->assertServerExists($name);
        $startedAt = microtime(true);

        try {
            $this->manager->connectServer($name);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $snapshot = $this->getServerSnapshot($name);
            $this->recordConnectionSuccess($name, $durationMs, $snapshot['toolCount']);

            return [
                'name' => $name,
                'duration_ms' => $durationMs,
                'snapshot' => $this->getServerSnapshot($name),
            ];
        } catch (\Throwable $e) {
            $this->recordConnectionFailure($name, (int) round((microtime(true) - $startedAt) * 1000), $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array{name: string, applied: string}
     */
    public function disconnectServer(string $name): array
    {
        $this->assertServerExists($name);
        $this->manager->disconnectServer($name);
        $this->recordDisconnect($name);

        return [
            'name' => $name,
            'applied' => 'live',
        ];
    }

    /**
    * @return array{name: string, duration_ms: int, snapshot: McpServerSnapshot}
     */
    public function refreshServer(string $name): array
    {
        $this->assertServerExists($name);
        $startedAt = microtime(true);

        try {
            $this->manager->refreshServer($name);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $snapshot = $this->getServerSnapshot($name);
            $this->recordConnectionSuccess($name, $durationMs, $snapshot['toolCount']);

            return [
                'name' => $name,
                'duration_ms' => $durationMs,
                'snapshot' => $snapshot,
            ];
        } catch (\Throwable $e) {
            $this->recordConnectionFailure($name, (int) round((microtime(true) - $startedAt) * 1000), $e->getMessage());
            throw $e;
        }
    }

    /**
    * @return array{name: string, ok: bool, duration_ms: int, snapshot: McpServerSnapshot}
     */
    public function testServer(string $name): array
    {
        $this->assertServerExists($name);
        $startedAt = microtime(true);

        try {
            $result = $this->refreshServer($name);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $snapshot = $result['snapshot'];
            $this->recordTest($name, true, $durationMs, null, $snapshot['toolCount']);

            return [
                'name' => $name,
                'ok' => true,
                'duration_ms' => $durationMs,
                'snapshot' => $this->getServerSnapshot($name),
            ];
        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->recordTest($name, false, $durationMs, $e->getMessage(), null);
            throw $e;
        }
    }

    /**
     * @return list<array{name: string, namespacedName: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function getServerTools(string $name): array
    {
        $this->assertServerExists($name);
        $tools = [];

        foreach ($this->manager->getServerToolDefs($name) as $tool) {
            $tools[] = [
                'name' => $tool['name'],
                'namespacedName' => $this->namespaceTool($name, $tool['name']),
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ];
        }

        return $tools;
    }

    /**
     * @return list<array{server: string, name: string, namespacedName: string, description: string}>
     */
    public function searchTools(string $query, ?string $server = null): array
    {
        $query = strtolower(trim($query));

        if ($query === '') {
            throw new \InvalidArgumentException('Search query is required.');
        }

        $servers = $server !== null && $server !== '' ? [$server] : $this->configuredServerNames();
        $results = [];

        foreach ($servers as $serverName) {
            foreach ($this->getServerTools($serverName) as $tool) {
                $haystack = strtolower(implode(' ', [
                    $serverName,
                    $tool['name'],
                    $tool['namespacedName'],
                    $tool['description'],
                ]));

                if (!str_contains($haystack, $query)) {
                    continue;
                }

                $results[] = [
                    'server' => $serverName,
                    'name' => $tool['name'],
                    'namespacedName' => $tool['namespacedName'],
                    'description' => $tool['description'],
                ];
            }
        }

        usort(
            $results,
            static fn(array $left, array $right): int => strcmp($left['namespacedName'], $right['namespacedName']),
        );

        return $results;
    }

    /**
    * @param list<string> $scopes
    * @return array{server: string, env_key: string, expires_at: ?int, applied: string}
     */
    public function authorizeServer(string $server, string $authUrl, string $tokenUrl, string $clientId = '', array $scopes = []): array
    {
        if ($this->oauth === null) {
            throw new \RuntimeException(
                'OAuth requires the management toolkit (coquibot/coqui-toolkit-mcp-client). Install it to authorize MCP servers.'
            );
        }

        $this->assertServerExists($server);

        if (trim($authUrl) === '' || trim($tokenUrl) === '') {
            throw new \InvalidArgumentException('Auth URL and token URL are required.');
        }

        $authConfig = [
            'authUrl' => trim($authUrl),
            'tokenUrl' => trim($tokenUrl),
        ];

        if (trim($clientId) !== '') {
            $authConfig['clientId'] = trim($clientId);
        }

        if ($scopes !== []) {
            $authConfig['scopes'] = $scopes;
        }

        $tokens = $this->oauth->authorize($server, $authConfig);
        $envKey = strtoupper(preg_replace('/[^a-zA-Z0-9_]/', '_', $server) ?? $server) . '_ACCESS_TOKEN';
        putenv($envKey . '=' . $tokens['access_token']);
        $this->config->setServerEnv($server, $envKey, '${' . $envKey . '}');
        $this->config->save();

        return [
            'server' => $server,
            'env_key' => $envKey,
            'expires_at' => isset($tokens['expires_at']) ? (int) $tokens['expires_at'] : null,
            'applied' => $this->manager->getServerStatus($server)['connected'] ? 'reconnect_required' : 'next_turn',
        ];
    }

    /**
     * @return list<string>
     */
    public function parseArgs(string $raw): array
    {
        return ArgumentTokenizer::split($raw);
    }

    private function requireLoadingStore(): ServerLoadingModeStore
    {
        if ($this->loadingStore === null) {
            throw new \RuntimeException('MCP loading-mode controls are unavailable in this runtime.');
        }

        return $this->loadingStore;
    }

    /**
     * @return list<string>
     */
    private function coerceStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];

        foreach ($value as $item) {
            $result[] = (string) $item;
        }

        return $result;
    }

    private function assertServerExists(string $name): void
    {
        $this->config->load(force: true);

        if ($this->config->getServer($name) === null) {
            throw new \RuntimeException(sprintf('Server "%s" not found.', $name));
        }
    }

    private function namespaceTool(string $serverName, string $toolName): string
    {
        $sanitized = preg_replace('/[^a-z0-9_]/', '_', strtolower($serverName)) ?? $serverName;

        return 'mcp_' . $sanitized . '_' . $toolName;
    }

    private function reconnectUpdatedServer(string $name): void
    {
        $startedAt = microtime(true);

        try {
            $this->manager->connectServer($name);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $snapshot = $this->getServerSnapshot($name);
            $this->recordConnectionSuccess($name, $durationMs, $snapshot['toolCount']);
        } catch (\Throwable $e) {
            $this->recordConnectionFailure($name, (int) round((microtime(true) - $startedAt) * 1000), $e->getMessage());
            throw $e;
        }
    }

    private function migrateAudit(string $currentName, string $nextName): void
    {
        if ($currentName === $nextName || !isset($this->audit[$currentName])) {
            return;
        }

        $this->audit[$nextName] = $this->audit[$currentName];
        unset($this->audit[$currentName]);
    }

    /**
     * @return array{last_connected_at: ?string, last_connection_error: ?string, last_connection_duration_ms: ?int, last_disconnected_at: ?string, last_tested_at: ?string, last_test_succeeded: ?bool, last_test_error: ?string, last_test_duration_ms: ?int, last_tool_discovery_count: ?int}
     */
    private function auditSnapshot(string $server): array
    {
        return $this->audit[$server] ?? [
            'last_connected_at' => null,
            'last_connection_error' => null,
            'last_connection_duration_ms' => null,
            'last_disconnected_at' => null,
            'last_tested_at' => null,
            'last_test_succeeded' => null,
            'last_test_error' => null,
            'last_test_duration_ms' => null,
            'last_tool_discovery_count' => null,
        ];
    }

    private function recordConnectionSuccess(string $server, int $durationMs, int $toolCount): void
    {
        $audit = $this->auditSnapshot($server);
        $audit['last_connected_at'] = gmdate(DATE_ATOM);
        $audit['last_connection_error'] = null;
        $audit['last_connection_duration_ms'] = $durationMs;
        $audit['last_tool_discovery_count'] = $toolCount;
        $this->audit[$server] = $audit;
    }

    private function recordConnectionFailure(string $server, int $durationMs, string $error): void
    {
        $audit = $this->auditSnapshot($server);
        $audit['last_connection_error'] = $error;
        $audit['last_connection_duration_ms'] = $durationMs;
        $this->audit[$server] = $audit;
    }

    private function recordDisconnect(string $server): void
    {
        $audit = $this->auditSnapshot($server);
        $audit['last_disconnected_at'] = gmdate(DATE_ATOM);
        $this->audit[$server] = $audit;
    }

    private function recordTest(string $server, bool $succeeded, int $durationMs, ?string $error, ?int $toolCount): void
    {
        $audit = $this->auditSnapshot($server);
        $audit['last_tested_at'] = gmdate(DATE_ATOM);
        $audit['last_test_succeeded'] = $succeeded;
        $audit['last_test_error'] = $error;
        $audit['last_test_duration_ms'] = $durationMs;
        if ($toolCount !== null) {
            $audit['last_tool_discovery_count'] = $toolCount;
        }
        $this->audit[$server] = $audit;
    }
}