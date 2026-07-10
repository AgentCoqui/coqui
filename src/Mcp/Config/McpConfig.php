<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Config;

use CarmeloSantana\PathHelper\PathHelper;

/**
 * Manages the .workspace/mcp.json configuration file.
 *
 * Uses the Claude Desktop mcpServers format:
 *
 *   {
 *     "mcpServers": {
 *       "github": {
 *         "command": "npx",
 *         "args": ["-y", "@modelcontextprotocol/server-github"],
 *         "env": { "GITHUB_TOKEN": "${GITHUB_TOKEN}" },
 *         "disabled": false
 *       }
 *     }
 *   }
 *
 * Secrets are never stored directly — env values use ${VAR_NAME} placeholders
 * that reference credentials stored in .env via Coqui's credential system.
 */
final class McpConfig
{
    private const string CONFIG_FILENAME = 'mcp.json';

    /** @var array<string, array<string, mixed>> server name => server config */
    private array $servers = [];

    private bool $loaded = false;

    public function __construct(
        private readonly string $workspacePath,
    ) {}

    /**
     * Get the path to the config file.
     */
    public function configPath(): string
    {
        return PathHelper::trimTrailingSlash($this->workspacePath) . '/' . self::CONFIG_FILENAME;
    }

    /**
     * Load configuration from disk. Idempotent — only reads once unless forced.
     */
    public function load(bool $force = false): void
    {
        if ($this->loaded && !$force) {
            return;
        }

        $path = $this->configPath();

        if (!file_exists($path)) {
            $this->servers = [];
            $this->loaded = true;

            return;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->servers = [];
            $this->loaded = true;

            return;
        }

        $data = json_decode($contents, true);

        if (!is_array($data)) {
            $this->servers = [];
            $this->loaded = true;

            return;
        }

        $mcpServers = $data['mcpServers'] ?? [];

        if (!is_array($mcpServers)) {
            $this->servers = [];
            $this->loaded = true;

            return;
        }

        /** @var array<string, array<string, mixed>> $mcpServers */
        $this->servers = $mcpServers;
        $this->loaded = true;
    }

    /**
     * Save current configuration to disk.
     */
    public function save(): void
    {
        $path = $this->configPath();
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $data = ['mcpServers' => (object) $this->servers];
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        file_put_contents($path, $json . "\n");
    }

    /**
     * Add or update a server configuration.
     *
     * @param array<string, mixed> $config Server config (command, args, env, etc.)
     */
    public function addServer(string $name, array $config): void
    {
        $this->ensureLoaded();
        $this->servers[$name] = $config;
    }

    /**
     * Rename a configured server while preserving insertion order.
     */
    public function renameServer(string $currentName, string $nextName): bool
    {
        $this->ensureLoaded();

        if (!isset($this->servers[$currentName])) {
            return false;
        }

        if ($currentName === $nextName) {
            return true;
        }

        if (isset($this->servers[$nextName])) {
            return false;
        }

        $renamed = [];

        foreach ($this->servers as $serverName => $config) {
            $renamed[$serverName === $currentName ? $nextName : $serverName] = $config;
        }

        $this->servers = $renamed;

        return true;
    }

    /**
     * Remove a server configuration.
     */
    public function removeServer(string $name): bool
    {
        $this->ensureLoaded();

        if (!isset($this->servers[$name])) {
            return false;
        }

        unset($this->servers[$name]);

        return true;
    }

    /**
     * Get a single server's configuration.
     *
     * @return array<string, mixed>|null
     */
    public function getServer(string $name): ?array
    {
        $this->ensureLoaded();

        return $this->servers[$name] ?? null;
    }

    /**
     * List all configured servers.
     *
     * @return array<string, array<string, mixed>>
     */
    public function listServers(): array
    {
        $this->ensureLoaded();

        return $this->servers;
    }

    /**
     * List only enabled servers (not disabled).
     *
     * @return array<string, array<string, mixed>>
     */
    public function listEnabledServers(): array
    {
        $this->ensureLoaded();

        return array_filter(
            $this->servers,
            static fn(array $config): bool => !($config['disabled'] ?? false),
        );
    }

    /**
     * Set an environment variable reference for a server.
     *
     * Stores ${KEY_NAME} in the config — the actual value should be set
     * via Coqui's credential system (CredentialResolver).
     */
    public function setServerEnv(string $serverName, string $key, string $placeholder): void
    {
        $this->ensureLoaded();

        if (!isset($this->servers[$serverName])) {
            return;
        }

        if (!isset($this->servers[$serverName]['env']) || !is_array($this->servers[$serverName]['env'])) {
            $this->servers[$serverName]['env'] = [];
        }

        $this->servers[$serverName]['env'][$key] = $placeholder;
    }

    /**
     * Disable a server (it won't be connected on boot).
     */
    public function disableServer(string $name): bool
    {
        $this->ensureLoaded();

        if (!isset($this->servers[$name])) {
            return false;
        }

        $this->servers[$name]['disabled'] = true;

        return true;
    }

    /**
     * Enable a previously disabled server.
     */
    public function enableServer(string $name): bool
    {
        $this->ensureLoaded();

        if (!isset($this->servers[$name])) {
            return false;
        }

        unset($this->servers[$name]['disabled']);

        return true;
    }

    /**
     * Get the command for a server.
     */
    public function getCommand(string $name): ?string
    {
        $config = $this->getServer($name);

        return isset($config['command']) ? (string) $config['command'] : null;
    }

    /**
     * Get the optional operator-facing description for a server.
     */
    public function getDescription(string $name): ?string
    {
        $config = $this->getServer($name);
        $description = isset($config['description']) ? trim((string) $config['description']) : '';

        return $description !== '' ? $description : null;
    }

    /**
     * Get the arguments for a server.
     *
     * @return list<string>
     */
    public function getArgs(string $name): array
    {
        $config = $this->getServer($name);
        $args = $config['args'] ?? [];

        if (!is_array($args)) {
            return [];
        }

        return array_values(array_map('strval', $args));
    }

    /**
     * Get the environment variables for a server (raw, with ${} placeholders).
     *
     * @return array<string, string>
     */
    public function getEnv(string $name): array
    {
        $config = $this->getServer($name);
        $env = $config['env'] ?? [];

        if (!is_array($env)) {
            return [];
        }

        $result = [];

        foreach ($env as $key => $value) {
            $result[(string) $key] = (string) $value;
        }

        return $result;
    }

    /**
     * Check if a server is disabled.
     */
    public function isDisabled(string $name): bool
    {
        $config = $this->getServer($name);

        return $config !== null && ($config['disabled'] ?? false) === true;
    }

    private function ensureLoaded(): void
    {
        if (!$this->loaded) {
            $this->load();
        }
    }
}
