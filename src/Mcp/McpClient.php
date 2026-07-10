<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp;

use CoquiBot\Coqui\Mcp\Exception\McpConnectionException;
use CoquiBot\Coqui\Mcp\Exception\McpProtocolException;
use CoquiBot\Coqui\Mcp\Exception\McpToolCallException;
use CoquiBot\Coqui\Mcp\JsonRpc\IdGenerator;
use CoquiBot\Coqui\Mcp\JsonRpc\Message;
use CoquiBot\Coqui\Mcp\Transport\TransportInterface;

/**
 * MCP client implementing the Model Context Protocol lifecycle.
 *
 * Wraps a TransportInterface to provide high-level MCP operations:
 * initialization handshake, tool listing with cursor pagination,
 * and tool invocation. Targets MCP spec version 2025-06-18.
 *
 * Usage:
 *   $client = new McpClient(new StdioTransport());
 *   $client->connect('npx', ['-y', '@modelcontextprotocol/server-github'], ['GITHUB_TOKEN' => '...']);
 *   $tools = $client->listTools();
 *   $result = $client->callTool('create_issue', ['repo' => '...', 'title' => '...']);
 *   $client->disconnect();
 */
final class McpClient
{
    private const string PROTOCOL_VERSION = '2025-11-25';
    private const string CLIENT_NAME = 'coqui';
    private const string CLIENT_VERSION = '0.1.0';

    private IdGenerator $idGen;
    private bool $connected = false;

    /** Server info returned during initialization. */
    private ?string $serverName = null;
    private ?string $serverVersion = null;
    private ?string $serverInstructions = null;

    /** @var array<string, mixed> Server capabilities from initialize response */
    private array $serverCapabilities = [];

    /** @var array<int, array{name: string, description: string, inputSchema: array<string, mixed>}> */
    private array $cachedTools = [];
    private bool $toolsCacheDirty = true;

    public function __construct(
        private readonly TransportInterface $transport,
    ) {
        $this->idGen = new IdGenerator();
    }

    /**
     * Connect to an MCP server: start the transport and perform the
     * initialize handshake.
     *
     * @param string               $command  The executable to launch
     * @param list<string>         $args     Command-line arguments
     * @param array<string, string> $env     Environment variables
     *
     * @throws McpConnectionException If the server fails to start
     * @throws McpProtocolException   If the handshake fails
     */
    public function connect(string $command, array $args = [], array $env = []): void
    {
        $this->transport->start($command, $args, $env);
        $this->initialize();
        $this->connected = true;
    }

    /**
     * List all tools available on the connected MCP server.
     *
     * Handles cursor-based pagination automatically. Results are cached
     * until invalidated by a tools/list_changed notification.
     *
     * @return array<int, array{name: string, description: string, inputSchema: array<string, mixed>}>
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     */
    public function listTools(): array
    {
        $this->assertConnected();

        if (!$this->toolsCacheDirty && $this->cachedTools !== []) {
            return $this->cachedTools;
        }

        $tools = [];
        $cursor = null;

        do {
            $params = [];

            if ($cursor !== null) {
                $params['cursor'] = $cursor;
            }

            $response = $this->request('tools/list', $params);
            $result = $response->result ?? [];

            if (isset($result['tools']) && is_array($result['tools'])) {
                foreach ($result['tools'] as $tool) {
                    if (!is_array($tool) || !isset($tool['name'])) {
                        continue;
                    }

                    $tools[] = [
                        'name' => (string) $tool['name'],
                        'description' => (string) ($tool['description'] ?? ''),
                        'inputSchema' => is_array($tool['inputSchema'] ?? null) ? $tool['inputSchema'] : [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ];
                }
            }

            $cursor = isset($result['nextCursor']) && is_string($result['nextCursor'])
                ? $result['nextCursor']
                : null;
        } while ($cursor !== null);

        $this->cachedTools = $tools;
        $this->toolsCacheDirty = false;

        return $tools;
    }

    /**
     * Call a tool on the MCP server.
     *
     * @param string               $name      Tool name (as returned by listTools)
     * @param array<string, mixed> $arguments Tool arguments
     *
    * @return array<int, mixed> Content array from the server
     *
     * @throws McpConnectionException
     * @throws McpProtocolException
     * @throws McpToolCallException If the tool returned isError: true
     */
    public function callTool(string $name, array $arguments = []): array
    {
        $this->assertConnected();

        $response = $this->request('tools/call', [
            'name' => $name,
            'arguments' => (object) $arguments,
        ]);

        $result = $response->result ?? [];

        $content = is_array($result['content'] ?? null) ? $result['content'] : [];
        $isError = (bool) ($result['isError'] ?? false);

        if ($isError) {
            throw McpToolCallException::fromErrorContent($name, $content);
        }

        return $content;
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        $this->transport->close();
        $this->connected = false;
        $this->cachedTools = [];
        $this->toolsCacheDirty = true;
        $this->serverName = null;
        $this->serverVersion = null;
        $this->serverInstructions = null;
        $this->serverCapabilities = [];
    }

    /**
     * Whether the client is connected to an MCP server.
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->transport->isConnected();
    }

    /**
     * Invalidate the tool cache (e.g., after receiving tools/list_changed).
     */
    public function invalidateToolCache(): void
    {
        $this->toolsCacheDirty = true;
    }

    public function serverName(): ?string
    {
        return $this->serverName;
    }

    public function serverVersion(): ?string
    {
        return $this->serverVersion;
    }

    /**
     * Server-provided instructions for the LLM (from initialize response).
     */
    public function serverInstructions(): ?string
    {
        return $this->serverInstructions;
    }

    /**
     * @return array<string, mixed>
     */
    public function serverCapabilities(): array
    {
        return $this->serverCapabilities;
    }

    /**
     * Perform the MCP initialize handshake.
     *
     * Sends initialize → validates response → sends notifications/initialized.
     */
    private function initialize(): void
    {
        $response = $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => (object) [],
            'clientInfo' => (object) [
                'name' => self::CLIENT_NAME,
                'version' => self::CLIENT_VERSION,
            ],
        ]);

        $result = $response->result;

        if ($result === null) {
            throw McpProtocolException::unexpectedResponse(
                'initialize result',
                'null',
            );
        }

        // Extract server info
        $this->serverName = isset($result['serverInfo']['name'])
            ? (string) $result['serverInfo']['name']
            : null;
        $this->serverVersion = isset($result['serverInfo']['version'])
            ? (string) $result['serverInfo']['version']
            : null;
        $this->serverInstructions = isset($result['instructions'])
            ? (string) $result['instructions']
            : null;
        $this->serverCapabilities = is_array($result['capabilities'] ?? null)
            ? $result['capabilities']
            : [];

        // Send initialized notification
        $this->transport->sendNotification(
            Message::notification('notifications/initialized'),
        );
    }

    /**
     * Send a JSON-RPC request and return the response.
     *
     * @param array<string, mixed> $params
     */
    private function request(string $method, array $params = []): Message
    {
        $id = $this->idGen->next();
        $request = Message::request($id, $method, $params);

        return $this->transport->send($request);
    }

    private function assertConnected(): void
    {
        if (!$this->isConnected()) {
            throw McpConnectionException::disconnected('mcp-client');
        }
    }
}
