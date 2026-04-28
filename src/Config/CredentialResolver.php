<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

use CoquiBot\Coqui\Contract\CredentialResolverInterface;

/**
 * Resolves credentials from workspace .env file and process environment.
 *
 * Reads the workspace .env on every get() call (no caching) to ensure
 * hot-reload after set(). Also calls putenv() on set() so that toolkit
 * fromEnv() factories and lazy getenv() calls see updated values immediately.
 */
final class CredentialResolver implements CredentialResolverInterface
{
    private readonly string $envPath;

    public function __construct(
        private readonly string $workspacePath,
    ) {
        $this->envPath = PathHelper::trimTrailingSlash($this->workspacePath) . '/.env';
    }

    public function get(string $key): ?string
    {
        // Priority 1: workspace .env file (re-read every time for hot-reload)
        $entries = $this->loadEnvFile();
        if (isset($entries[$key]) && $entries[$key] !== '') {
            return $entries[$key];
        }

        // Priority 2: process environment (system-level or previously putenv'd)
        $envValue = getenv($key);
        if ($envValue !== false && $envValue !== '') {
            return $envValue;
        }

        return null;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function set(string $key, string $value): void
    {
        $entries = $this->loadEnvFile();
        $entries[$key] = $value;
        $this->saveEnvFile($entries);

        // Hot-reload: make it available via getenv() immediately
        putenv("{$key}={$value}");
    }

    public function delete(string $key): void
    {
        $entries = $this->loadEnvFile();

        if (!isset($entries[$key])) {
            return;
        }

        unset($entries[$key]);
        $this->saveEnvFile($entries);

        // Remove from process environment
        putenv($key);
    }

    public function loadIntoProcessEnv(): void
    {
        $entries = $this->loadEnvFile();

        foreach ($entries as $key => $value) {
            putenv("{$key}={$value}");
        }
    }

    /**
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->loadEnvFile());
    }

    public function envPath(): string
    {
        return $this->envPath;
    }

    /**
     * Parse the .env file into key-value pairs.
     *
     * @return array<string, string>
     */
    private function loadEnvFile(): array
    {
        if (!file_exists($this->envPath)) {
            return [];
        }

        $content = file_get_contents($this->envPath);
        if ($content === false) {
            return [];
        }

        $entries = [];

        foreach (explode("\n", $content) as $line) {
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $equalsPos = strpos($line, '=');
            if ($equalsPos === false) {
                continue;
            }

            $key = trim(substr($line, 0, $equalsPos));
            $value = trim(substr($line, $equalsPos + 1));

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            if ($key !== '') {
                $entries[$key] = $value;
            }
        }

        return $entries;
    }

    /**
     * Write key-value pairs to the .env file.
     *
     * @param array<string, string> $entries
     */
    private function saveEnvFile(array $entries): void
    {
        $dir = dirname($this->envPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $lines = ["# Coqui workspace credentials — managed by CredentialTool\n"];

        foreach ($entries as $key => $value) {
            // Quote values containing spaces or special characters
            if (preg_match('/[\s#"\'\\\\]/', $value)) {
                $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
                $lines[] = "{$key}=\"{$escaped}\"";
            } else {
                $lines[] = "{$key}={$value}";
            }
        }

        file_put_contents($this->envPath, implode("\n", $lines) . "\n");

        // Restrict file permissions (owner read/write only)
        chmod($this->envPath, 0600);
    }
}
