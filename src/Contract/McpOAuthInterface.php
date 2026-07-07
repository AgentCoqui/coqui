<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Pluggable OAuth provider for MCP servers.
 *
 * Core ships no OAuth implementation. The optional MCP management toolkit
 * provides one and registers it into the shared {@see \CoquiBot\Coqui\Mcp\McpRuntime}.
 */
interface McpOAuthInterface
{
    /**
     * @param array{authUrl?: string, tokenUrl?: string, clientId?: string, scopes?: list<string>} $authConfig
     * @return array{access_token: string, refresh_token?: string, expires_at?: int}
     */
    public function authorize(string $serverName, array $authConfig): array;

    /**
     * @param array{tokenUrl?: string, clientId?: string} $authConfig
     */
    public function getAccessToken(string $serverName, array $authConfig): ?string;

    public function hasTokens(string $serverName): bool;

    public function clearTokens(string $serverName): void;
}
