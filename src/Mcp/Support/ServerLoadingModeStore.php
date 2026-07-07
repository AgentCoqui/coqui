<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Mcp\Support;

use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Mcp\McpServerToolkit;

/**
 * Persists per-server MCP loading overrides in Coqui's toolkit-loading.json.
 */
final class ServerLoadingModeStore
{
    private const array PERSISTABLE_MODES = ['eager', 'deferred'];

    private string $filePath;

    /** @var array<string, string>|null */
    private ?array $cache = null;

    public function __construct(string $workspacePath)
    {
        $this->filePath = PathHelper::trimTrailingSlash($workspacePath) . '/toolkit-loading.json';
    }

    public function getMode(string $serverName): string
    {
        $data = $this->load();

        return match ($data[$this->key($serverName)] ?? null) {
            'eager' => 'eager',
            'deferred' => 'deferred',
            default => 'auto',
        };
    }

    public function promote(string $serverName): void
    {
        $data = $this->load();
        $data[$this->key($serverName)] = 'eager';
        $this->save($data);
    }

    public function demote(string $serverName): void
    {
        $data = $this->load();
        $data[$this->key($serverName)] = 'deferred';
        $this->save($data);
    }

    public function auto(string $serverName): void
    {
        $data = $this->load();
        unset($data[$this->key($serverName)]);
        $this->save($data);
    }

    public function forget(string $serverName): void
    {
        $this->auto($serverName);
    }

    public function rename(string $currentName, string $nextName): void
    {
        if ($currentName === $nextName) {
            return;
        }

        $data = $this->load();
        $currentKey = $this->key($currentName);
        $nextKey = $this->key($nextName);

        if (!isset($data[$currentKey])) {
            unset($data[$nextKey]);
            $this->save($data);

            return;
        }

        $data[$nextKey] = $data[$currentKey];
        unset($data[$currentKey]);
        $this->save($data);
    }

    /**
     * @return array<string, string>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        if (!file_exists($this->filePath)) {
            return $this->cache = [];
        }

        $raw = file_get_contents($this->filePath);
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($data)) {
            return $this->cache = [];
        }

        $filtered = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && is_string($value) && in_array($value, self::PERSISTABLE_MODES, true)) {
                $filtered[$key] = $value;
            }
        }

        return $this->cache = $filtered;
    }

    /**
     * @param array<string, string> $data
     */
    private function save(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return;
        }

        file_put_contents($this->filePath, $json . "\n");
        $this->cache = $data;
    }

    private function key(string $serverName): string
    {
        return McpServerToolkit::loadingKeyForServer($serverName);
    }
}