<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp;

use CoquiBot\Coqui\Contract\McpOAuthInterface;
use CoquiBot\Coqui\Mcp\Config\McpConfig;
use CoquiBot\Coqui\Mcp\Support\McpServerPolicy;
use CoquiBot\Coqui\Mcp\Support\ServerLoadingModeStore;

/**
 * Per-context MCP runtime: builds the engine + management service once and
 * shares it across the agent (per-server tool exposure), the HTTP API, and the
 * optional management toolkit.
 */
final class McpRuntime
{
    private ?McpOAuthInterface $oauth = null;

    public function __construct(
        private readonly McpConfig $config,
        private readonly McpServerManager $manager,
        private readonly ServerLoadingModeStore $loadingStore,
        private readonly ?McpServerPolicy $policy,
    ) {}

    /**
     * @param (callable(string): mixed)|null $configGet Resolves dotted config keys for stdio policy.
     */
    public static function fromWorkspace(string $workspacePath, ?callable $configGet = null): self
    {
        $config = new McpConfig($workspacePath);
        $manager = new McpServerManager($config);
        $loadingStore = new ServerLoadingModeStore($workspacePath);
        $policy = $configGet === null
            ? null
            : McpServerPolicy::fromConfigValues(
                $configGet('agents.defaults.mcp.allowedStdioCommands'),
                $configGet('agents.defaults.mcp.deniedStdioCommands'),
            );

        return new self($config, $manager, $loadingStore, $policy);
    }

    public function config(): McpConfig
    {
        return $this->config;
    }

    public function manager(): McpServerManager
    {
        return $this->manager;
    }

    public function registerOAuth(McpOAuthInterface $oauth): void
    {
        $this->oauth = $oauth;
    }

    public function managementService(): McpManagementService
    {
        return new McpManagementService(
            $this->config,
            $this->manager,
            $this->oauth,
            $this->loadingStore,
            $this->policy,
        );
    }

    public function connectEnabled(): void
    {
        $this->config->load();
        if ($this->config->listEnabledServers() !== []) {
            $this->manager->connectAll();
        }
    }

    /**
     * @return list<McpServerToolkit>
     */
    public function serverToolkits(): array
    {
        $this->config->load();
        $service = $this->managementService();
        $toolkits = [];
        foreach (array_keys($this->config->listEnabledServers()) as $serverName) {
            $toolkits[] = new McpServerToolkit((string) $serverName, $service);
        }

        return $toolkits;
    }
}
