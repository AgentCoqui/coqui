<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use CoquiBot\Coqui\Api\Router;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Configuration endpoints.
 *
 * GET /api/config          — get full config (sanitized)
 * PUT /api/config          — write openclaw.json
 * GET /api/config/models   — list available models
 *
 * Role management moved to RoleHandler (/api/config/roles/*).
 */
final class ConfigHandler
{
    public function __construct(
        private readonly OpenClawConfig $config,
        private readonly string $configPath = '',
    ) {}

    /**
     * GET /api/config — return sanitized configuration.
     *
     * Strips all apiKey values to prevent credential leakage.
     */
    public function get(ServerRequestInterface $request): Response
    {
        $data = [
            'agents' => $this->config->get('agents'),
            'models' => $this->sanitizeModelsConfig(),
        ];

        return Router::jsonResponse($data);
    }

    /**
     * PUT /api/config — write openclaw.json.
     */
    public function update(ServerRequestInterface $request): Response
    {
        if ($this->configPath === '') {
            return Router::jsonResponse(['error' => 'Config path not available'], 500);
        }

        $body = (string) $request->getBody();

        if ($body === '') {
            return Router::jsonResponse(['error' => 'Empty request body'], 400);
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return Router::jsonResponse(
                ['error' => 'Invalid JSON', 'details' => json_last_error_msg()],
                400,
            );
        }

        $formatted = json_encode(
            $decoded,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        if ($formatted === false) {
            return Router::jsonResponse(['error' => 'Failed to encode JSON'], 500);
        }

        $result = file_put_contents($this->configPath, $formatted . "\n");

        if ($result === false) {
            return Router::jsonResponse(['error' => 'Failed to write config file'], 500);
        }

        return Router::jsonResponse(['success' => true, 'path' => $this->configPath]);
    }

    /**
     * GET /api/config/models — list available models with metadata.
     */
    public function models(ServerRequestInterface $request): Response
    {
        $providers = $this->config->get('models.providers', []);
        $models = [];

        if (is_array($providers)) {
            foreach ($providers as $providerName => $providerConfig) {
                if (!is_array($providerConfig) || !isset($providerConfig['models'])) {
                    continue;
                }

                foreach ($providerConfig['models'] as $model) {
                    if (!is_array($model) || !isset($model['id'])) {
                        continue;
                    }

                    $models[] = [
                        'provider' => $providerName,
                        'id' => "{$providerName}/{$model['id']}",
                        'name' => $model['name'] ?? $model['id'],
                        'reasoning' => $model['reasoning'] ?? false,
                        'input' => $model['input'] ?? ['text'],
                    ];
                }
            }
        }

        return Router::jsonResponse([
            'models' => $models,
            'count' => count($models),
            'primary' => $this->config->getPrimaryModel(),
        ]);
    }

    /**
     * Strip apiKey values from provider configs.
     *
     * @return array<string, mixed>
     */
    private function sanitizeModelsConfig(): array
    {
        $modelsConfig = $this->config->get('models', []);

        if (!is_array($modelsConfig) || !isset($modelsConfig['providers'])) {
            return is_array($modelsConfig) ? $modelsConfig : [];
        }

        $providers = $modelsConfig['providers'];

        if (is_array($providers)) {
            foreach ($providers as $name => $provider) {
                if (is_array($provider) && isset($provider['apiKey'])) {
                    $providers[$name]['apiKey'] = '***';
                }
            }
        }

        $modelsConfig['providers'] = $providers;

        return $modelsConfig;
    }
}
