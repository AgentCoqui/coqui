<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Transport;

use CoquiBot\Coqui\Mcp\JsonRpc\Message;

/**
 * Transport layer for MCP JSON-RPC communication.
 *
 * Implementations handle the underlying I/O mechanism (stdio, HTTP, etc.)
 * while the McpClient handles protocol semantics.
 */
interface TransportInterface
{
    /**
     * Start the transport connection.
     *
     * @param string               $command  Executable to launch (for stdio) or URL (for HTTP)
     * @param list<string>         $args     Command-line arguments
     * @param array<string, string> $env     Environment variables for the subprocess
     *
     * @throws \CoquiBot\Coqui\Mcp\Exception\McpConnectionException
     */
    public function start(string $command, array $args = [], array $env = []): void;

    /**
     * Send a request and wait for its response.
     *
     * @throws \CoquiBot\Coqui\Mcp\Exception\McpConnectionException  On connection failure
     * @throws \CoquiBot\Coqui\Mcp\Exception\McpProtocolException    On invalid response
     */
    public function send(Message $request): Message;

    /**
     * Send a notification (fire-and-forget, no response expected).
     */
    public function sendNotification(Message $notification): void;

    /**
     * Close the transport and clean up resources.
     */
    public function close(): void;

    /**
     * Whether the transport is currently connected and operational.
     */
    public function isConnected(): bool;
}
