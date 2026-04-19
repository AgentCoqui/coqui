<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Configuration read-only endpoints.
 *
 * GET  /api/v1/config           — get full config (sanitized)
 * POST /api/v1/config/validate  — dry-run validation
 * GET  /api/v1/config/models    — list available models
 * GET  /api/v1/config/profiles  — list available profiles
 *
 * Config updates (PUT /api/v1/config) are REPL-only.
 * Role management moved to RoleHandler (/api/v1/config/roles/*).
 */
final readonly class ConfigHandler
{
    public function __construct(
        private OpenClawConfig $config,
        private ConfigValidator $validator,
        private ProfileDiscovery $profileDiscovery,
    ) {}

    /**
     * GET /api/v1/config — return sanitized configuration.
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
     * POST /api/v1/config/validate — dry-run validation without saving.
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
     * GET /api/v1/config/models — list available models with metadata.
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
     * GET /api/v1/config/profiles — list available profiles with descriptions.
     */
    public function profiles(ServerRequestInterface $request): Response
    {
        $profiles = array_values(array_map(
            static fn(array $profile): array => [
                'name' => $profile['name'],
                'display_name' => $profile['display_name'],
                'description' => $profile['description'],
            ],
            $this->profileDiscovery->discoverAll(),
        ));

        return Router::jsonResponse([
            'profiles' => $profiles,
            'count' => count($profiles),
            'default_profile' => $this->config->getDefaultProfile(),
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
