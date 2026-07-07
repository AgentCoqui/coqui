<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\Parameter;
use CoquiBot\Coqui\Mcp\Config\EnvResolver;
use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\Exception\McpConnectionException;
use CoquiBot\Coqui\Mcp\Exception\McpToolCallException;
use CoquiBot\Coqui\Mcp\Schema\SchemaConverter;
use CoquiBot\Coqui\Mcp\Transport\StdioTransport;

/**
 * Manages multiple MCP server connections and their tools.
 *
 * Each configured server gets its own McpClient instance. Tools from all
 * enabled servers are discovered at connect time and namespaced as
 * mcp_{servername}_{toolname} to avoid collisions.
 *
 * The manager handles:
 * - Lazy connection (servers connect when connectAll() is called)
 * - Tool namespacing and routing
 * - Server lifecycle (connect, disconnect, reconnect)
 * - Status reporting per server
 */
final class McpServerManager
{
    /** @var array<string, McpClient> server name => client */
    private array $clients = [];

    /** @var array<string, list<array{name: string, description: string, inputSchema: array<string, mixed>}>> server name => tools */
    private array $serverTools = [];

    /** @var array<string, string> server name => error message (for servers that failed to connect) */
    private array $errors = [];

    private readonly EnvResolver $envResolver;
    private readonly SchemaConverter $schemaConverter;

    public function __construct(
        private readonly McpConfig $config,
        private readonly int $timeout = 30,
    ) {
        $this->envResolver = new EnvResolver();
        $this->schemaConverter = new SchemaConverter();
    }

    /**
     * Connect to all enabled MCP servers.
     *
     * Errors are collected but do not prevent other servers from connecting.
     * Check errors() after calling this to see which servers failed.
     */
    public function connectAll(): void
    {
        $this->config->load(force: true);

        foreach ($this->config->listEnabledServers() as $name => $serverConfig) {
            try {
                $this->connectServer($name);
            } catch (\Throwable $e) {
                $this->errors[$name] = $e->getMessage();
            }
        }
    }

    /**
     * Connect to a specific MCP server by name.
     *
     * @throws McpConnectionException If the server fails to start or initialize
     */
    public function connectServer(string $name): void
    {
        $this->config->load(force: true);
        $serverConfig = $this->config->getServer($name);

        if ($serverConfig === null) {
            throw McpConnectionException::disconnected($name);
        }

        if ($this->config->isDisabled($name)) {
            throw McpConnectionException::disconnected($name . ' (disabled)');
        }

        // Disconnect existing client if reconnecting
        if (isset($this->clients[$name])) {
            $this->clients[$name]->disconnect();
        }

        $command = $this->config->getCommand($name);

        if ($command === null || $command === '') {
            throw McpConnectionException::processStartFailed(
                $name,
                'No command configured for server',
            );
        }

        $args = $this->config->getArgs($name);
        $rawEnv = $this->config->getEnv($name);

        // Resolve ${VAR} placeholders
        $envResult = $this->envResolver->resolve($rawEnv);
        $resolvedEnv = $envResult['resolved'];

        // Create client with stdio transport
        $transport = new StdioTransport(timeout: $this->timeout);
        $client = new McpClient($transport);

        try {
            $client->connect($command, $args, $resolvedEnv);

            // Discover tools
            $tools = $client->listTools();
        } catch (\Throwable $e) {
            $client->disconnect();
            $wrapped = $this->wrapConnectionFailure($name, $command, $args, $transport->lastStderr(), $e);
            $this->errors[$name] = $wrapped->getMessage();

            throw $wrapped;
        }

        $this->clients[$name] = $client;
        $this->serverTools[$name] = array_values($tools);
        unset($this->errors[$name]);
    }

    /**
     * Disconnect a specific server.
     */
    public function disconnectServer(string $name): void
    {
        if (isset($this->clients[$name])) {
            $this->clients[$name]->disconnect();
            unset($this->clients[$name]);
            unset($this->serverTools[$name]);
        }
    }

    /**
     * Disconnect all servers.
     */
    public function disconnectAll(): void
    {
        foreach ($this->clients as $name => $client) {
            $client->disconnect();
        }

        $this->clients = [];
        $this->serverTools = [];
    }

    /**
     * Get all discovered tools across all connected servers as Coqui Tool objects.
     *
     * Tools are namespaced as mcp_{servername}_{toolname}.
     *
    * @return list<ToolInterface>
     */
    public function getTools(): array
    {
        $tools = [];

        foreach ($this->serverTools as $serverName => $serverToolDefs) {
            foreach ($serverToolDefs as $toolDef) {
                $namespacedName = $this->namespaceTool($serverName, $toolDef['name']);
                $parameters = $this->schemaConverter->convert($toolDef['inputSchema']);

                $tools[] = new Tool(
                    name: $namespacedName,
                    description: $this->buildToolDescription($serverName, $toolDef),
                    parameters: $parameters,
                    callback: $this->buildToolCallback($serverName, $toolDef['name']),
                );
            }
        }

        return $tools;
    }

    /**
     * Get discovered tools for one connected MCP server as Tool objects.
     *
    * @return list<ToolInterface>
     */
    public function getToolsForServer(string $serverName): array
    {
        $tools = [];

        foreach ($this->serverTools[$serverName] ?? [] as $toolDef) {
            $namespacedName = $this->namespaceTool($serverName, $toolDef['name']);
            $parameters = $this->schemaConverter->convert($toolDef['inputSchema']);

            $tools[] = new Tool(
                name: $namespacedName,
                description: $this->buildToolDescription($serverName, $toolDef),
                parameters: $parameters,
                callback: $this->buildToolCallback($serverName, $toolDef['name']),
            );
        }

        return $tools;
    }

    /**
     * Call a namespaced tool (mcp_{server}_{tool}).
     *
     * @param string               $namespacedName Full namespaced tool name
     * @param array<string, mixed> $arguments      Tool arguments
     *
    * @return array<int, mixed> Content array
     */
    public function callTool(string $namespacedName, array $arguments): array
    {
        [$serverName, $toolName] = $this->parseNamespacedTool($namespacedName);

        if (!isset($this->clients[$serverName])) {
            throw McpConnectionException::disconnected($serverName);
        }

        return $this->clients[$serverName]->callTool($toolName, $arguments);
    }

    /**
     * Get status information for a specific server.
     *
     * @return array{connected: bool, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, instructions: ?string}
     */
    public function getServerStatus(string $name): array
    {
        $client = $this->clients[$name] ?? null;

        return [
            'connected' => $client !== null && $client->isConnected(),
            'serverName' => $client?->serverName(),
            'serverVersion' => $client?->serverVersion(),
            'toolCount' => count($this->serverTools[$name] ?? []),
            'error' => $this->errors[$name] ?? null,
            'instructions' => $client?->serverInstructions(),
        ];
    }

    /**
     * Get status for all configured servers.
     *
     * @return array<string, array{connected: bool, serverName: ?string, serverVersion: ?string, toolCount: int, error: ?string, disabled: bool}>
     */
    public function getAllStatus(): array
    {
        $this->config->load(force: true);
        $status = [];

        foreach ($this->config->listServers() as $name => $serverConfig) {
            $serverStatus = $this->getServerStatus($name);
            $status[$name] = [
                'connected' => $serverStatus['connected'],
                'serverName' => $serverStatus['serverName'],
                'serverVersion' => $serverStatus['serverVersion'],
                'toolCount' => $serverStatus['toolCount'],
                'error' => $serverStatus['error'],
                'disabled' => $this->config->isDisabled($name),
            ];
        }

        return $status;
    }

    /**
     * Get server instructions (guidelines) from all connected servers.
     *
     * @return array<string, string> server name => instructions
     */
    public function getServerInstructions(): array
    {
        $instructions = [];

        foreach ($this->clients as $name => $client) {
            $serverInstructions = $client->serverInstructions();

            if ($serverInstructions !== null && $serverInstructions !== '') {
                $instructions[$name] = $serverInstructions;
            }
        }

        return $instructions;
    }

    /**
     * @return list<string>
     */
    public function connectedServerNames(): array
    {
        return array_keys($this->clients);
    }

    /**
     * Get tool definitions for a specific server (raw MCP format).
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public function getServerToolDefs(string $name): array
    {
        return $this->serverTools[$name] ?? [];
    }

    /**
     * Get connection errors.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Refresh a server: disconnect, reconnect, and rediscover tools.
     */
    public function refreshServer(string $name): void
    {
        $this->disconnectServer($name);
        $this->connectServer($name);
    }

    /**
     * @param list<string> $args
     */
    private function wrapConnectionFailure(string $serverName, string $command, array $args, string $stderr, \Throwable $error): McpConnectionException
    {
        $stderr = trim($stderr);
        $reason = trim($error->getMessage());

        if ($stderr !== '' && !str_contains($reason, $stderr)) {
            $reason .= ($reason !== '' ? ' — stderr: ' : '') . $stderr;
        }

        if ($error instanceof McpConnectionException) {
            if (str_contains($error->getMessage(), 'did not respond within')) {
                return McpConnectionException::timeout($serverName, $this->timeout, $stderr);
            }

            if (str_contains($error->getMessage(), 'is not connected')) {
                return McpConnectionException::disconnected($serverName, $stderr);
            }

            if (str_starts_with($error->getMessage(), 'Failed to start MCP server process:')) {
                return McpConnectionException::processStartFailed(
                    $this->formatServerCommand($serverName, $command, $args),
                    $reason,
                );
            }
        }

        return McpConnectionException::initializeFailed($serverName, $reason);
    }

    /**
     * @param list<string> $args
     */
    private function formatServerCommand(string $serverName, string $command, array $args): string
    {
        $parts = array_filter([$command, ...$args], static fn(string $part): bool => trim($part) !== '');

        return sprintf('%s [%s]', $serverName, implode(' ', $parts));
    }

    /**
     * Build the namespaced tool name: mcp_{servername}_{toolname}.
     */
    private function namespaceTool(string $serverName, string $toolName): string
    {
        // Sanitize server name: only lowercase alphanumeric and underscore
        $sanitized = preg_replace('/[^a-z0-9_]/', '_', strtolower($serverName)) ?? $serverName;

        return 'mcp_' . $sanitized . '_' . $toolName;
    }

    /**
     * Parse a namespaced tool name back to [serverName, toolName].
     *
     * @return array{0: string, 1: string}
     */
    private function parseNamespacedTool(string $namespacedName): array
    {
        // Remove `mcp_` prefix
        $rest = substr($namespacedName, 4); // Remove "mcp_"

        // Find the server name by checking which connected server matches
        foreach ($this->serverTools as $serverName => $tools) {
            $sanitized = preg_replace('/[^a-z0-9_]/', '_', strtolower($serverName)) ?? $serverName;
            $prefix = $sanitized . '_';

            if (str_starts_with($rest, $prefix)) {
                $toolName = substr($rest, strlen($prefix));

                return [$serverName, $toolName];
            }
        }

        // Fallback: split on first underscore after the server name
        $firstUnderscore = strpos($rest, '_');

        if ($firstUnderscore !== false) {
            return [
                substr($rest, 0, $firstUnderscore),
                substr($rest, $firstUnderscore + 1),
            ];
        }

        throw McpToolCallException::notFound($namespacedName, 'unknown');
    }

    /**
     * Build a tool description including server origin.
     *
     * @param array{name: string, description: string, inputSchema: array<string, mixed>} $toolDef
     */
    private function buildToolDescription(string $serverName, array $toolDef): string
    {
        $desc = $toolDef['description'];

        if ($desc === '') {
            $desc = sprintf('Tool "%s" from MCP server "%s"', $toolDef['name'], $serverName);
        } else {
            $desc = sprintf('%s [MCP server: %s]', $desc, $serverName);
        }

        return $desc;
    }

    /**
     * Build the callback closure for a proxied MCP tool.
     */
    private function buildToolCallback(string $serverName, string $toolName): \Closure
    {
        return function (array $args) use ($serverName, $toolName): ToolResult {
            try {
                if (!isset($this->clients[$serverName]) || !$this->clients[$serverName]->isConnected()) {
                    return ToolResult::error(sprintf(
                        'MCP server "%s" is not connected. Use mcp(action: "connect", server: "%s") to reconnect.',
                        $serverName,
                        $serverName,
                    ));
                }

                $content = $this->clients[$serverName]->callTool($toolName, $args);

                return ToolResult::success($this->formatContent($content));
            } catch (McpToolCallException $e) {
                return ToolResult::error($e->getMessage());
            } catch (McpConnectionException $e) {
                return ToolResult::error(sprintf(
                    'MCP server "%s" connection lost: %s. Use mcp(action: "connect", server: "%s") to reconnect.',
                    $serverName,
                    $e->getMessage(),
                    $serverName,
                ));
            } catch (\Throwable $e) {
                return ToolResult::error(sprintf(
                    'MCP tool "%s" on server "%s" failed: %s',
                    $toolName,
                    $serverName,
                    $e->getMessage(),
                ));
            }
        };
    }

    /**
     * Format MCP content array into a string for ToolResult.
     *
     * @param array<int, mixed> $content
     */
    private function formatContent(array $content): string
    {
        $parts = [];

        foreach ($content as $item) {
            if (!is_array($item)) {
                continue;
            }

            $type = isset($item['type']) ? (string) $item['type'] : 'text';

            if ($type === 'text' && isset($item['text'])) {
                $parts[] = (string) $item['text'];
            } elseif ($type === 'image' && isset($item['data'])) {
                $mimeType = isset($item['mimeType']) ? (string) $item['mimeType'] : 'unknown';
                $parts[] = sprintf('[Image: %s, %d bytes]', $mimeType, strlen((string) $item['data']));
            } elseif ($type === 'resource' && isset($item['resource'])) {
                $resource = is_array($item['resource']) ? $item['resource'] : [];
                $text = $resource['text'] ?? null;
                $uri = $resource['uri'] ?? 'unknown';

                if ($text !== null) {
                    $parts[] = sprintf("Resource: %s\n%s", $uri, $text);
                } else {
                    $parts[] = sprintf('[Resource: %s]', $uri);
                }
            } else {
                // Fallback: JSON encode unknown content types
                $parts[] = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '';
            }
        }

        return $parts !== [] ? implode("\n\n", $parts) : 'No content returned';
    }

    public function __destruct()
    {
        $this->disconnectAll();
    }
}
