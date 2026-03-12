<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CarmeloSantana\PHPAgents\Config\OpenClawConfig;
use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Configuration endpoints.
 *
 * GET  /api/config           — get full config (sanitized)
 * PUT  /api/config           — write openclaw.json
 * POST /api/config/validate  — dry-run validation
 * GET  /api/config/models    — list available models
 *
 * Role management moved to RoleHandler (/api/config/roles/*).
 */
final class ConfigHandler
{
    public function __construct(
        private readonly OpenClawConfig $config,
        private readonly ConfigManager $configManager,
        private readonly ConfigValidator $validator,
        private readonly ?BootManager $boot = null,
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
     * PUT /api/config — write openclaw.json and reload.
     */
    public function update(ServerRequestInterface $request): Response
    {
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

        if (!is_array($decoded)) {
            return Router::jsonResponse(['error' => 'Config must be a JSON object'], 400);
        }

        try {
            $errors = $this->configManager->save($decoded);
        } catch (\RuntimeException $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, $e->getMessage());
        }

        if (!empty($errors)) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Config validation failed',
                $errors,
            );
        }

        // Auto-reload if BootManager is available
        $this->boot?->reloadConfig();

        return Router::jsonResponse([
            'success' => true,
            'path' => $this->configManager->path(),
        ]);
    }

    /**
     * POST /api/config/validate — dry-run validation without saving.
     */
    public function validate(ServerRequestInterface $request): Response
    {
        $body = (string) $request->getBody();

        if ($body === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Empty request body');
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return Router::errorResponse(
                ApiErrorCode::INVALID_FORMAT,
                'Invalid JSON: ' . json_last_error_msg(),
            );
        }

        if (!is_array($decoded)) {
            return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, 'Config must be a JSON object');
        }

        $errors = $this->validator->validate($decoded);

        if (!empty($errors)) {
            return Router::jsonResponse([
                'valid' => false,
                'errors' => $errors,
            ]);
        }

        return Router::jsonResponse(['valid' => true]);
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
