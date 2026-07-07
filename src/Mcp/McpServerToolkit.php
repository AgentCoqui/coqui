<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CoquiBot\Coqui\Contract\ToolkitLoadingKeyProvider;

/**
 * Server-scoped toolkit candidate for one connected MCP server.
 */
final class McpServerToolkit implements ToolkitInterface, ToolkitLoadingKeyProvider
{
    public function __construct(
        private readonly string $serverName,
        private readonly McpManagementService $service,
    ) {}

    public static function loadingKeyForServer(string $serverName): string
    {
        $sanitized = preg_replace('/[^a-z0-9_]+/', '_', strtolower($serverName)) ?? strtolower($serverName);
        $sanitized = trim($sanitized, '_');

        return 'McpServer:' . ($sanitized !== '' ? $sanitized : 'server');
    }

    public function toolkitLoadingKey(): string
    {
        return self::loadingKeyForServer($this->serverName);
    }

    /**
     * @return list<ToolInterface>
     */
    public function tools(): array
    {
        return $this->service->toolObjectsForServer($this->serverName);
    }

    public function guidelines(): string
    {
        $snapshot = $this->service->getServerSnapshot($this->serverName);
        $label = $snapshot['serverName'] ?? $this->serverName;
        $state = $snapshot['disabled']
            ? 'DISABLED'
            : ($snapshot['connected'] ? 'CONNECTED' : 'DISCONNECTED');

        $lines = [sprintf('MCP server "%s" tools.', $label), ''];
        $lines[] = sprintf('- Loading key: `%s`', $this->toolkitLoadingKey());
        $lines[] = sprintf('- Runtime state: %s', $state);
        $lines[] = sprintf('- Discovered tools: %d', $snapshot['toolCount']);
        $lines[] = '- Tool names are namespaced as `mcp_{server}_{tool}`.';

        if ($snapshot['instructions'] !== null && $snapshot['instructions'] !== '') {
            $lines[] = '';
            $lines[] = 'Server instructions:';
            $lines[] = $snapshot['instructions'];
        }

        return implode("\n", $lines);
    }
}