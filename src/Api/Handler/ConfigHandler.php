<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\ModelMetadataResolver;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Support\FileSystemOperations;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;

/**
 * Configuration read endpoints plus narrow safe mutations.
 *
 * GET  /api/v1/config           — get full config (sanitized)
 * PATCH /api/v1/config/context  — update explicitly allowed context toggles
 * POST /api/v1/config/validate  — dry-run validation
 * GET  /api/v1/config/models    — list available models
 * GET  /api/v1/config/profiles  — list available profiles
 * POST /api/v1/profiles         — create a new profile
 * PATCH /api/v1/profiles/{name} — update a profile
 * DELETE /api/v1/profiles/{name} — delete a profile
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
        private ?ConfigManager $configManager = null,
        private ?ConfigGuard $configGuard = null,
    ) {}

    /**
     * GET /api/v1/config — return sanitized configuration.
     *
     * Strips all apiKey values to prevent credential leakage.
     */
    public function get(ServerRequestInterface $request): Response
    {
        $config = $this->currentConfig();
        $data = [
            'agents' => $config->get('agents'),
            'models' => $this->sanitizeModelsConfig(),
            'channels' => $config->get('channels'),
        ];

        return Router::jsonResponse($data);
    }

    /**
     * PATCH /api/v1/config/context — update explicitly allowed context toggles.
     */
    public function updateContext(ServerRequestInterface $request): Response
    {
        if ($this->configManager === null || $this->configGuard === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Config mutation support is not available');
        }

        $body = (string) $request->getBody();
        if ($body === '') {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Empty request body');
        }

        $decoded = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, 'Invalid JSON: ' . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, 'Context patch must be a JSON object');
        }

        if (!array_key_exists('conversationHistoryInSystemPrompt', $decoded)) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'conversationHistoryInSystemPrompt is required');
        }

        $value = $decoded['conversationHistoryInSystemPrompt'];
        if (!is_bool($value)) {
            return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, 'conversationHistoryInSystemPrompt must be a boolean');
        }

        $dotKey = 'agents.defaults.context.conversationHistoryInSystemPrompt';
        $denyReason = $this->configGuard->denyReason($dotKey);
        if ($denyReason !== null) {
            return Router::errorResponse(ApiErrorCode::FORBIDDEN, $denyReason);
        }

        $errors = $this->configManager->set($dotKey, $value);
        if ($errors !== []) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Validation failed', ['errors' => $errors]);
        }

        return Router::jsonResponse([
            'context' => [
                'conversationHistoryInSystemPrompt' => $this->configManager->config()->useConversationHistoryInSystemPrompt(),
            ],
            'updated' => ['conversationHistoryInSystemPrompt'],
            'restart_required' => true,
        ]);
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
        $config = $this->currentConfig();

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
            'primary' => $config->getPrimaryModel(),
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
            'default_profile' => $this->currentConfig()->getDefaultProfile(),
        ]);
    }

    /**
     * GET /api/v1/config/profile-preferences/schema — app-facing preference editor schema.
     */
    public function profilePreferenceSchema(ServerRequestInterface $request): Response
    {
        $availableRoles = $this->roleResolver !== null
            ? array_values($this->roleResolver->selectableRoles())
            : [];

        return Router::jsonResponse(ProfilePreferences::appSchema($availableRoles));
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
     * POST /api/v1/profiles — create a profile for app onboarding flows.
     */
    public function createProfile(ServerRequestInterface $request): Response
    {
        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $name = $this->normalizeProfileName($body['name'] ?? null);
        if ($name === null) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'name is required and must use lowercase letters, numbers, hyphens, or underscores.',
            );
        }

        if ($this->profileDiscovery->profileExists($name)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Profile "%s" already exists.', $name));
        }

        $description = $this->optionalString($body['description'] ?? null);
        $soul = $this->optionalString($body['soul'] ?? null);
        $backstory = $this->optionalString($body['backstory'] ?? null);

        if ($description === null && $soul === null) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Provide either description or soul to create a profile.',
            );
        }

        $preferencesPayload = $body['preferences'] ?? null;
        if ($preferencesPayload !== null && !is_array($preferencesPayload)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'preferences must be an object.');
        }

        $profileDir = 'profiles/' . $name;
        $preferences = $preferencesPayload !== null
            ? ProfilePreferences::fromArray($preferencesPayload, $this->workspacePath() . '/' . $profileDir)
            : ProfilePreferences::empty();

        if (!$preferences->isValid()) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Profile preferences failed validation.',
                ['errors' => $preferences->getValidationErrors()],
            );
        }

        try {
            $operations = $this->workspaceOperations();
            $operations->write($profileDir . '/soul.md', $this->buildSoulMarkdown($name, $description, $soul));

            if ($backstory !== null) {
                $operations->write($profileDir . '/backstory.md', $backstory . "\n");
            }

            if ($preferencesPayload !== null) {
                $encodedPreferences = json_encode(
                    $preferencesPayload,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
                $operations->write($profileDir . '/preferences.json', $encodedPreferences . "\n");
            }
        } catch (\JsonException | \Throwable $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to create profile', ['error' => $e->getMessage()]);
        }

        $this->profileDiscovery->invalidateCache();
        $profile = $this->profileDiscovery->discoverAll()[$name] ?? null;

        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Profile was created but could not be reloaded.');
        }

        return Router::jsonResponse($this->normalizeProfileDetail($profile), 201);
    }

    /**
     * PATCH /api/v1/profiles/{name} — update soul, backstory, and preferences.
     */
    public function updateProfile(ServerRequestInterface $request, string $name): Response
    {
        $body = $this->requestBody($request);
        if (!is_array($body)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Invalid JSON body');
        }

        $normalizedName = strtolower(trim($name));
        $profile = $this->profileDiscovery->discoverAll()[$normalizedName] ?? null;
        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Profile "%s" not found', $name));
        }

        if (array_key_exists('name', $body)) {
            $requestedName = $this->normalizeProfileName($body['name']);
            if ($requestedName === null || $requestedName !== $normalizedName) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Profile renaming is not supported by this endpoint.',
                );
            }
        }

        $updatesSoul = array_key_exists('description', $body) || array_key_exists('soul', $body);
        $updatesBackstory = array_key_exists('backstory', $body);
        $updatesPreferences = array_key_exists('preferences', $body);

        if (!$updatesSoul && !$updatesBackstory && !$updatesPreferences) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Provide at least one of description, soul, backstory, or preferences.',
            );
        }

        if ($updatesSoul) {
            if ((array_key_exists('description', $body) && $body['description'] !== null && !is_string($body['description']))
                || (array_key_exists('soul', $body) && $body['soul'] !== null && !is_string($body['soul']))) {
                return Router::errorResponse(
                    ApiErrorCode::VALIDATION_ERROR,
                    'description and soul must be strings when provided.',
                );
            }
        }

        if ($updatesBackstory && $body['backstory'] !== null && !is_string($body['backstory'])) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'backstory must be a string or null.');
        }

        $description = array_key_exists('description', $body)
            ? $this->optionalString($body['description'])
            : null;
        $soul = array_key_exists('soul', $body)
            ? $this->optionalString($body['soul'])
            : null;
        $backstory = $updatesBackstory
            ? $this->optionalString($body['backstory'])
            : null;

        if ($updatesSoul && $description === null && $soul === null) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Provide a non-empty description or soul when updating soul content.',
            );
        }

        $preferencesPayload = $body['preferences'] ?? null;
        if ($updatesPreferences && $preferencesPayload !== null && !is_array($preferencesPayload)) {
            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'preferences must be an object or null.');
        }

        $preferences = $updatesPreferences && $preferencesPayload !== null
            ? ProfilePreferences::fromArray($preferencesPayload, $profile['path'])
            : null;

        if ($preferences !== null && !$preferences->isValid()) {
            return Router::errorResponse(
                ApiErrorCode::VALIDATION_ERROR,
                'Profile preferences failed validation.',
                ['errors' => $preferences->getValidationErrors()],
            );
        }

        try {
            $operations = $this->workspaceOperations();
            $profileDir = 'profiles/' . $normalizedName;

            if ($updatesSoul) {
                $operations->write(
                    $profileDir . '/soul.md',
                    $this->buildSoulMarkdown(
                        $normalizedName,
                        $description,
                        $soul,
                        $this->readRawSoulMarkdown($profile['path']),
                    ),
                );
            }

            if ($updatesBackstory) {
                $this->writeOrDeleteOptionalFile($profileDir . '/backstory.md', $backstory);
            }

            if ($updatesPreferences) {
                if ($preferencesPayload === null) {
                    $this->writeOrDeleteOptionalFile($profileDir . '/preferences.json', null);
                } else {
                    $encodedPreferences = json_encode(
                        $preferencesPayload,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    );
                    $operations->write($profileDir . '/preferences.json', $encodedPreferences . "\n");
                }
            }
        } catch (\JsonException | \Throwable $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to update profile', ['error' => $e->getMessage()]);
        }

        $this->profileDiscovery->invalidateCache();
        $updatedProfile = $this->profileDiscovery->discoverAll()[$normalizedName] ?? null;

        if ($updatedProfile === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Profile was updated but could not be reloaded.');
        }

        return Router::jsonResponse($this->normalizeProfileDetail($updatedProfile));
    }

    /**
     * DELETE /api/v1/profiles/{name} — remove a profile directory.
     */
    public function deleteProfile(ServerRequestInterface $request, string $name): Response
    {
        $normalizedName = strtolower(trim($name));
        $profile = $this->profileDiscovery->discoverAll()[$normalizedName] ?? null;
        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Profile "%s" not found', $name));
        }

        if ($this->currentConfig()->getDefaultProfile() === $normalizedName) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                sprintf('Profile "%s" is the configured default profile and cannot be deleted yet.', $normalizedName),
            );
        }

        $this->deleteDirectory($profile['path']);
        $this->profileDiscovery->invalidateCache();

        return Router::jsonResponse([
            'deleted' => true,
            'name' => $normalizedName,
        ]);
    }

    /**
     * Strip apiKey values from provider configs.
     *
     * @return array<string, mixed>
     */
    private function sanitizeModelsConfig(): array
    {
        $modelsConfig = $this->currentConfig()->get('models', []);

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
            'is_default' => $this->currentConfig()->getDefaultProfile() === $profile['name'],
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
            'preference_values' => $preferences->editorValues(),
            'preference_document' => $this->readPreferenceDocument($profile['path']),
            'soul' => $this->profileDiscovery->readSoul($profile['name']),
        ];
    }

    private function currentConfig(): OpenClawConfig
    {
        return $this->configManager?->config() ?? $this->config;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requestBody(ServerRequestInterface $request): ?array
    {
        $decoded = json_decode((string) $request->getBody(), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeProfileName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }

        return preg_match('/^[a-z0-9](?:[a-z0-9_-]*[a-z0-9])?$/', $normalized) === 1
            ? $normalized
            : null;
    }

    private function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function buildSoulMarkdown(string $name, ?string $description, ?string $soul, ?string $existingSoul = null): string
    {
        $body = $soul;

        if ($body === null) {
            $heading = ucwords(str_replace(['-', '_'], ' ', $name));
            $body = sprintf("# %s\n\n%s", $heading, $description ?? '');
        }

        if ($existingSoul !== null && !$this->hasFrontmatter($body)) {
            $frontmatter = $this->extractFrontmatter($existingSoul);
            if ($frontmatter !== null) {
                return rtrim($frontmatter) . "\n\n" . ltrim(rtrim($body)) . "\n";
            }
        }

        return rtrim($body) . "\n";
    }

    private function workspacePath(): string
    {
        return dirname($this->profileDiscovery->profilesDir());
    }

    private function workspaceOperations(): FileSystemOperations
    {
        return new FileSystemOperations($this->workspacePath());
    }

    private function readRawSoulMarkdown(string $profilePath): ?string
    {
        $path = rtrim($profilePath, '/') . '/soul.md';
        $content = @file_get_contents($path);

        return is_string($content) ? $content : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPreferenceDocument(string $profilePath): array
    {
        $path = rtrim($profilePath, '/') . '/preferences.json';
        $content = @file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return [];
        }

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    private function hasFrontmatter(string $content): bool
    {
        return preg_match('/\A---\R.*?\R---(?:\R|\z)/s', $content) === 1;
    }

    private function extractFrontmatter(string $content): ?string
    {
        if (preg_match('/\A(---\R.*?\R---)(?:\R|\z)/s', $content, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function writeOrDeleteOptionalFile(string $relativePath, ?string $content): void
    {
        $absolutePath = $this->workspacePath() . '/' . ltrim($relativePath, '/');

        if ($content === null) {
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }

            return;
        }

        $this->workspaceOperations()->write($relativePath, $content . "\n");
    }

    private function deleteDirectory(string $path): void
    {
        $entries = scandir($path);
        if ($entries === false) {
            throw new \RuntimeException(sprintf('Failed to read profile directory "%s".', $path));
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $entryPath = $path . '/' . $entry;
            if (is_dir($entryPath)) {
                $this->deleteDirectory($entryPath);
                continue;
            }

            if (!@unlink($entryPath)) {
                throw new \RuntimeException(sprintf('Failed to delete profile file "%s".', $entryPath));
            }
        }

        if (!@rmdir($path)) {
            throw new \RuntimeException(sprintf('Failed to delete profile directory "%s".', $path));
        }
    }
}
