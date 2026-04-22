<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\ModelMetadataResolver;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\RoleResolver;
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
        private ?ModelMetadataResolver $modelMetadataResolver = null,
        private ?RoleResolver $roleResolver = null,
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
            'channels' => $this->config->get('channels'),
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
        $models = [];

        if ($this->modelMetadataResolver !== null) {
            foreach ($this->modelMetadataResolver->configuredModels() as $fullId => $definition) {
                $entry = $definition->toArray();
                $models[] = [
                    'provider' => $definition->provider,
                    'id' => $fullId,
                    'name' => $definition->name,
                    'reasoning' => $definition->reasoning,
                    'input' => $entry['input'] ?? ($definition->vision ? ['text', 'image'] : ['text']),
                    'contextWindow' => $definition->contextWindow,
                    'maxTokens' => $definition->maxTokens,
                    'family' => $definition->family,
                    'toolCalls' => $definition->toolCalls,
                    'vision' => $definition->vision,
                    'thinking' => $definition->thinking,
                    'metadataSource' => $definition->metadataSource,
                ];
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
            fn(array $profile): array => $this->normalizeProfileSummary($profile),
            $this->profileDiscovery->discoverAll(),
        ));

        return Router::jsonResponse([
            'profiles' => $profiles,
            'count' => count($profiles),
            'default_profile' => $this->config->getDefaultProfile(),
        ]);
    }

    /**
     * GET /api/v1/config/profiles/{name} — get profile detail for picker UIs.
     */
    public function profile(ServerRequestInterface $request, string $name): Response
    {
        $profile = $this->profileDiscovery->discoverAll()[strtolower($name)] ?? null;
        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Profile "%s" not found', $name));
        }

        return Router::jsonResponse($this->normalizeProfileDetail($profile));
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

    /**
     * @param array{name: string, display_name: string, description: string, path: string} $profile
     * @return array<string, mixed>
     */
    private function normalizeProfileSummary(array $profile): array
    {
        $preferences = ProfilePreferences::fromProfilePath($profile['path']);
        $selectableRoles = $this->roleResolver !== null
            ? array_values($this->roleResolver->selectableRoles())
            : [];
        $allowedRoles = $this->roleResolver !== null
            ? $preferences->filterAllowedRoles($selectableRoles)
            : [];

        return [
            'name' => $profile['name'],
            'display_name' => $profile['display_name'],
            'description' => $profile['description'],
            'model' => $this->profileDiscovery->readProfileModel($profile['name']),
            'is_default' => $this->config->getDefaultProfile() === $profile['name'],
            'allowed_roles' => $allowedRoles,
            'role_restrictions' => [
                'allow' => $preferences->allowedRoles(),
                'deny' => $preferences->deniedRoles(),
            ],
            'has_role_restrictions' => $preferences->hasRoleRestrictions(),
        ];
    }

    /**
     * @param array{name: string, display_name: string, description: string, path: string} $profile
     * @return array<string, mixed>
     */
    private function normalizeProfileDetail(array $profile): array
    {
        $preferences = ProfilePreferences::fromProfilePath($profile['path']);

        return [
            ...$this->normalizeProfileSummary($profile),
            'preferences' => $preferences->inspectionSummary(),
            'soul' => $this->profileDiscovery->readSoul($profile['name']),
        ];
    }
}
