<?php

declare(strict_types=1);

namespace CoquiBot\Dashboard\Controller;

/**
 * JSON API endpoints for openclaw.json config and credential management.
 */
final class ConfigController
{
    public function __construct(
        private readonly string $configPath,
        private readonly string $workspacePath,
    ) {}

    /**
     * GET /api/config — read openclaw.json.
     */
    public function getConfig(): void
    {
        if (!is_file($this->configPath)) {
            $this->json(['error' => 'Config file not found', 'path' => $this->configPath], 404);
            return;
        }

        $content = file_get_contents($this->configPath);

        if ($content === false) {
            $this->json(['error' => 'Failed to read config file'], 500);
            return;
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->json(['error' => 'Invalid JSON in config file', 'details' => json_last_error_msg()], 500);
            return;
        }

        $this->json([
            'path' => $this->configPath,
            'config' => $decoded,
            'raw' => $content,
        ]);
    }

    /**
     * PUT /api/config — write openclaw.json.
     */
    public function updateConfig(): void
    {
        $body = file_get_contents('php://input');

        if ($body === false || $body === '') {
            $this->json(['error' => 'Empty request body'], 400);
            return;
        }

        // Validate JSON
        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->json(['error' => 'Invalid JSON', 'details' => json_last_error_msg()], 400);
            return;
        }

        // Pretty print for readability
        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($formatted === false) {
            $this->json(['error' => 'Failed to encode JSON'], 500);
            return;
        }

        $result = file_put_contents($this->configPath, $formatted . "\n");

        if ($result === false) {
            $this->json(['error' => 'Failed to write config file'], 500);
            return;
        }

        $this->json(['success' => true, 'path' => $this->configPath]);
    }

    /**
     * GET /api/credentials — list credential keys (names only).
     */
    public function listCredentials(): void
    {
        $envPath = rtrim($this->workspacePath, '/') . '/.env';
        $keys = [];

        if (is_file($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            if ($lines !== false) {
                foreach ($lines as $line) {
                    $line = trim($line);

                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }

                    if (str_contains($line, '=')) {
                        $key = trim(explode('=', $line, 2)[0]);

                        if ($key !== '') {
                            $keys[] = [
                                'key' => $key,
                                'is_set' => true,
                            ];
                        }
                    }
                }
            }
        }

        $this->json([
            'credentials' => $keys,
            'env_path' => $envPath,
        ]);
    }

    /**
     * POST /api/credentials — set a credential.
     */
    public function setCredential(): void
    {
        $body = json_decode(file_get_contents('php://input') ?: '', true);

        if (!is_array($body) || !isset($body['key'], $body['value'])) {
            $this->json(['error' => 'Missing key or value'], 400);
            return;
        }

        $key = trim((string) $body['key']);
        $value = (string) $body['value'];

        if ($key === '') {
            $this->json(['error' => 'Key cannot be empty'], 400);
            return;
        }

        $envPath = rtrim($this->workspacePath, '/') . '/.env';
        $entries = $this->loadEnvFile($envPath);
        $entries[$key] = $value;
        $this->saveEnvFile($envPath, $entries);

        $this->json(['success' => true, 'key' => $key]);
    }

    /**
     * DELETE /api/credentials/{key} — remove a credential.
     */
    public function deleteCredential(string $key): void
    {
        $envPath = rtrim($this->workspacePath, '/') . '/.env';
        $entries = $this->loadEnvFile($envPath);

        if (!isset($entries[$key])) {
            $this->json(['error' => 'Credential not found'], 404);
            return;
        }

        unset($entries[$key]);
        $this->saveEnvFile($envPath, $entries);

        $this->json(['success' => true, 'key' => $key]);
    }

    /**
     * GET /api/env — read raw .env file.
     */
    public function getEnv(): void
    {
        $envPath = rtrim($this->workspacePath, '/') . '/.env';

        if (!is_file($envPath)) {
            $this->json(['content' => '', 'path' => $envPath, 'exists' => false]);
            return;
        }

        $content = file_get_contents($envPath);

        $this->json([
            'content' => $content !== false ? $content : '',
            'path' => $envPath,
            'exists' => true,
        ]);
    }

    /**
     * PUT /api/env — write raw .env file.
     */
    public function updateEnv(): void
    {
        $envPath = rtrim($this->workspacePath, '/') . '/.env';
        $body = file_get_contents('php://input');

        if ($body === false) {
            $this->json(['error' => 'Empty request body'], 400);
            return;
        }

        $dir = dirname($envPath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $result = file_put_contents($envPath, $body);

        if ($result === false) {
            $this->json(['error' => 'Failed to write .env file'], 500);
            return;
        }

        // Restrict permissions
        chmod($envPath, 0600);

        $this->json(['success' => true, 'path' => $envPath]);
    }

    /**
     * @return array<string, string>
     */
    private function loadEnvFile(string $path): array
    {
        $entries = [];

        if (!is_file($path)) {
            return $entries;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $entries;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);

                // Strip surrounding quotes
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"'))
                    || (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }

                $entries[$key] = $value;
            }
        }

        return $entries;
    }

    /**
     * @param array<string, string> $entries
     */
    private function saveEnvFile(string $path, array $entries): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $lines = [];

        foreach ($entries as $key => $value) {
            // Quote values that contain spaces or special characters
            if (preg_match('/[\s#"\'\\\\]/', $value)) {
                $value = '"' . addcslashes($value, '"\\') . '"';
            }

            $lines[] = "{$key}={$value}";
        }

        file_put_contents($path, implode("\n", $lines) . "\n");
        chmod($path, 0600);
    }

    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
