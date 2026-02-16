<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\RoleResolver;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Configuration endpoints.
 *
 * GET /api/config          — get full config (sanitized)
 * GET /api/config/roles    — get role→model mappings
 * GET /api/config/models   — list available models
 */
final readonly class ConfigHandler
{
    public function __construct(
        private OpenClawConfig $config,
        private RoleResolver $roleResolver,
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
     * GET /api/config/roles — return role→model mappings.
     */
    public function roles(ServerRequestInterface $request): Response
    {
        return Router::jsonResponse([
            'roles' => $this->roleResolver->toArray(),
            'available' => $this->roleResolver->availableRoles(),
        ]);
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
