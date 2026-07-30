<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api\Handler;

use CoquiBot\Coqui\Api\ApiErrorCode;
use CoquiBot\Coqui\Api\ApiLifecycleController;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ConfigGuard;
use CoquiBot\Coqui\Config\ConfigManager;
use CoquiBot\Coqui\Config\ConfigValidator;
use CoquiBot\Coqui\Config\ModelMetadataResolver;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaPreferences;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\CoquiDefaults;
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
 * GET  /api/v1/config/personas  — list available personas
 * POST /api/v1/personas         — create a new persona
 * PATCH /api/v1/personas/{name} — update a persona
 * DELETE /api/v1/personas/{name} — delete a persona
 * Role management moved to RoleHandler (/api/v1/config/roles/*).
 */
final readonly class ConfigHandler
{
    use DecodesRequestBody;

    private const string CONTEXT_RESTART_REASON = 'Agent context configuration changed. Restart the API server to apply the new behavior cleanly.';

    /** @var array<string, array{dotKey: string, type: string, label: string, description: string, resettable: bool, restart_required: bool, options?: list<string>, minimum?: int|float, maximum?: int|float, presentation?: string}> */
    private const array CONTEXT_FIELDS = [
        'conversationHistoryInSystemPrompt' => [
            'dotKey' => 'agents.defaults.context.conversationHistoryInSystemPrompt',
            'type' => 'boolean',
            'label' => 'Conversation History In System Prompt',
            'description' => 'Render prior active messages into a compact Conversation History system-prompt block in addition to normal message replay.',
            'resettable' => true,
            'restart_required' => true,
        ],
        'autoSummarizeMode' => [
            'dotKey' => 'agents.defaults.context.autoSummarizeMode',
            'type' => 'enum',
            'label' => 'Auto-Summarize Mode',
            'description' => 'Choose whether Coqui summarizes based on token budget, turn count, or only when you request it manually.',
            'resettable' => true,
            'restart_required' => true,
            'options' => ['token', 'turn', 'manual'],
        ],
        'autoSummarizeThreshold' => [
            'dotKey' => 'agents.defaults.context.autoSummarizeThreshold',
            'type' => 'number',
            'label' => 'Auto-Summarize Threshold',
            'description' => 'Token usage percentage that triggers auto-summarization when Auto-Summarize Mode is set to token.',
            'resettable' => true,
            'restart_required' => true,
            'minimum' => 0.0,
            'maximum' => 100.0,
            'presentation' => 'percent',
        ],
        'autoSummarizeTurnThreshold' => [
            'dotKey' => 'agents.defaults.context.autoSummarizeTurnThreshold',
            'type' => 'integer',
            'label' => 'Auto-Summarize Turn Threshold',
            'description' => 'Number of user turns that triggers auto-summarization when Auto-Summarize Mode is set to turn.',
            'resettable' => true,
            'restart_required' => true,
            'minimum' => 1,
        ],
        'autoSummarizeKeepRecent' => [
            'dotKey' => 'agents.defaults.context.autoSummarizeKeepRecent',
            'type' => 'integer',
            'label' => 'Auto-Summarize Keep Recent',
            'description' => 'How many recent turns are preserved when Coqui auto-summarizes a conversation.',
            'resettable' => true,
            'restart_required' => true,
            'minimum' => 1,
            'maximum' => 20,
        ],
    ];

    public function __construct(
        private OpenClawConfig $config,
        private ConfigValidator $validator,
        private PersonaDiscovery $profileDiscovery,
        private ?ModelMetadataResolver $modelMetadataResolver = null,
        private ?RoleResolver $roleResolver = null,
        private ?ConfigManager $configManager = null,
        private ?ConfigGuard $configGuard = null,
        private ?ApiLifecycleController $lifecycle = null,
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
        ];

        return Router::jsonResponse($data);
    }

    /**
     * GET /api/v1/config/context — return supported context settings with metadata.
     */
    public function getContext(ServerRequestInterface $request): Response
    {
        $config = $this->currentConfig();
        $stored = $this->configManager?->toArray() ?? [];

        $fields = [];
        $values = [];
        $defaults = [];

        foreach (self::CONTEXT_FIELDS as $apiKey => $definition) {
            $dotKey = $definition['dotKey'];
            $current = $this->contextValue($apiKey, $config);
            $default = $this->contextDefault($apiKey);
            $isConfigured = $this->nestedValueExists($stored, $dotKey);

            $values[$apiKey] = $current;
            $defaults[$apiKey] = $default;
            $field = [
                'key' => $apiKey,
                'dot_key' => $dotKey,
                'label' => $definition['label'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'resettable' => $definition['resettable'],
                'restart_required' => $definition['restart_required'],
                'configured' => $isConfigured,
                'default' => $default,
                'value' => $current,
                'requires_restart' => true,
            ];

            if (isset($definition['options'])) {
                $field['options'] = $definition['options'];
            }

            if (isset($definition['minimum'])) {
                $field['minimum'] = $definition['minimum'];
            }

            if (isset($definition['maximum'])) {
                $field['maximum'] = $definition['maximum'];
            }

            if (isset($definition['presentation'])) {
                $field['presentation'] = $definition['presentation'];
            }

            $fields[$apiKey] = $field;
        }

        return Router::jsonResponse([
            'context' => $values,
            'defaults' => $defaults,
            'fields' => $fields,
            'restart' => $this->restartState(),
        ]);
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

        if ($decoded === []) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Provide at least one supported context setting or reset entry');
        }

        $reset = [];
        if (array_key_exists('reset', $decoded)) {
            $resetPayload = $decoded['reset'];
            if (!is_array($resetPayload) || !array_is_list($resetPayload)) {
                return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, 'reset must be an array of supported context keys');
            }

            foreach ($resetPayload as $entry) {
                if (!is_string($entry) || !isset(self::CONTEXT_FIELDS[$entry])) {
                    return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, 'reset contains an unsupported context key');
                }

                $reset[] = $entry;
            }

            unset($decoded['reset']);
        }

        $updates = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key) || !isset(self::CONTEXT_FIELDS[$key])) {
                return Router::errorResponse(ApiErrorCode::INVALID_FORMAT, sprintf('Unsupported context key "%s"', (string) $key));
            }

            $updates[$key] = $value;
        }

        if ($updates === [] && $reset === []) {
            return Router::errorResponse(ApiErrorCode::MISSING_FIELD, 'Provide at least one supported context setting or reset entry');
        }

        foreach (array_keys($updates) as $key) {
            $dotKey = self::CONTEXT_FIELDS[$key]['dotKey'];
            $denyReason = $this->configGuard->denyReason($dotKey);
            if ($denyReason !== null) {
                return Router::errorResponse(ApiErrorCode::FORBIDDEN, $denyReason);
            }
        }

        foreach ($reset as $key) {
            $dotKey = self::CONTEXT_FIELDS[$key]['dotKey'];
            $denyReason = $this->configGuard->denyReason($dotKey);
            if ($denyReason !== null) {
                return Router::errorResponse(ApiErrorCode::FORBIDDEN, $denyReason);
            }
        }

        /** @var list<string> $validationErrors */
        $validationErrors = [];
        $normalized = [];

        foreach ($updates as $key => $value) {
            [$valid, $normalizedValue, $error] = $this->normalizeContextValue($key, $value);
            if (!$valid) {
                $validationErrors[] = $error ?? sprintf('Invalid value for "%s"', $key);
                continue;
            }

            $normalized[$key] = $normalizedValue;
        }

        if ($validationErrors !== []) {
            $message = count($validationErrors) === 1 ? $validationErrors[0] : 'Validation failed';

            return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $message, ['errors' => $validationErrors]);
        }

        foreach ($normalized as $key => $value) {
            $errors = $this->configManager->set(self::CONTEXT_FIELDS[$key]['dotKey'], $value);
            if ($errors !== []) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Validation failed', ['errors' => $errors]);
            }
        }

        foreach ($reset as $key) {
            $errors = $this->configManager->remove(self::CONTEXT_FIELDS[$key]['dotKey']);
            if ($errors !== []) {
                return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, 'Validation failed', ['errors' => $errors]);
            }
        }

        $updatedKeys = array_values(array_unique([...array_keys($normalized), ...$reset]));
        $config = $this->currentConfig();
        $context = [];
        foreach (array_keys(self::CONTEXT_FIELDS) as $key) {
            $context[$key] = $this->contextValue($key, $config);
        }

        return Router::jsonResponse([
            'context' => $context,
            'updated' => array_values(array_intersect(array_keys(self::CONTEXT_FIELDS), array_keys($normalized))),
            'reset' => array_values(array_intersect(array_keys(self::CONTEXT_FIELDS), $reset)),
            'restart_required' => true,
            'restart' => $this->markRestartRequired($updatedKeys),
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
     * GET /api/v1/config/personas — list available personas with descriptions.
     */
    public function personas(ServerRequestInterface $request): Response
    {
        $personas = array_values(array_map(
            fn(array $profile): array => $this->normalizePersonaSummary($profile),
            $this->profileDiscovery->discoverAll(),
        ));

        return Router::jsonResponse([
            'personas' => $personas,
            'count' => count($personas),
            'default_persona' => $this->currentConfig()->getDefaultPersona(),
        ]);
    }

    /**
     * GET /api/v1/config/persona-preferences/schema — app-facing preference editor schema.
     */
    public function personaPreferenceSchema(ServerRequestInterface $request): Response
    {
        $availableRoles = $this->roleResolver !== null
            ? array_values($this->roleResolver->selectableRoles())
            : [];

        return Router::jsonResponse(PersonaPreferences::appSchema($availableRoles));
    }

    /**
     * GET /api/v1/config/personas/{name} — get persona detail for picker UIs.
     */
    public function persona(ServerRequestInterface $request, string $name): Response
    {
        $profile = $this->profileDiscovery->discoverAll()[strtolower($name)] ?? null;
        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Profile "%s" not found', $name));
        }

        return Router::jsonResponse($this->normalizePersonaDetail($profile));
    }

    /**
     * POST /api/v1/personas — create a persona for app onboarding flows.
     */
    public function createPersona(ServerRequestInterface $request): Response
    {
        $body = $this->decodeJsonObjectOrNull($request);
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

        $profileDir = 'personas/' . $name;
        $preferences = $preferencesPayload !== null
            ? PersonaPreferences::fromArray($preferencesPayload, $this->workspacePath() . '/' . $profileDir)
            : PersonaPreferences::empty();

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
        } catch (\Throwable $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to create profile', ['error' => $e->getMessage()]);
        }

        $this->profileDiscovery->invalidateCache();
        $profile = $this->profileDiscovery->discoverAll()[$name] ?? null;

        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Profile was created but could not be reloaded.');
        }

        return Router::jsonResponse($this->normalizePersonaDetail($profile), 201);
    }

    /**
     * PATCH /api/v1/personas/{name} — update soul, backstory, and preferences.
     */
    public function updatePersona(ServerRequestInterface $request, string $name): Response
    {
        $body = $this->decodeJsonObjectOrNull($request);
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
            ? PersonaPreferences::fromArray($preferencesPayload, $profile['path'])
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
            $profileDir = 'personas/' . $normalizedName;

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
        } catch (\Throwable $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to update profile', ['error' => $e->getMessage()]);
        }

        $this->profileDiscovery->invalidateCache();
        $updatedProfile = $this->profileDiscovery->discoverAll()[$normalizedName] ?? null;

        if ($updatedProfile === null) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Profile was updated but could not be reloaded.');
        }

        return Router::jsonResponse($this->normalizePersonaDetail($updatedProfile));
    }

    /**
     * DELETE /api/v1/personas/{name} — remove a persona directory.
     */
    public function deletePersona(ServerRequestInterface $request, string $name): Response
    {
        $normalizedName = strtolower(trim($name));
        $profile = $this->profileDiscovery->discoverAll()[$normalizedName] ?? null;
        if ($profile === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Profile "%s" not found', $name));
        }

        if ($this->currentConfig()->getDefaultPersona() === $normalizedName) {
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
    private function normalizePersonaSummary(array $profile): array
    {
        $preferences = PersonaPreferences::fromProfilePath($profile['path']);
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
            'is_default' => $this->currentConfig()->getDefaultPersona() === $profile['name'],
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
    private function normalizePersonaDetail(array $profile): array
    {
        $preferences = PersonaPreferences::fromProfilePath($profile['path']);

        return [
            ...$this->normalizePersonaSummary($profile),
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
     * @return array{0: bool, 1: mixed, 2: ?string}
     */
    private function normalizeContextValue(string $key, mixed $value): array
    {
        return match ($key) {
            'conversationHistoryInSystemPrompt' => is_bool($value)
                ? [true, $value, null]
                : [false, null, 'conversationHistoryInSystemPrompt must be a boolean'],
            'autoSummarizeMode' => is_string($value) && in_array($value, ['token', 'turn', 'manual'], true)
                ? [true, $value, null]
                : [false, null, 'autoSummarizeMode must be one of: token, turn, manual'],
            'autoSummarizeThreshold' => $this->normalizeAutoSummarizeThreshold($value),
            'autoSummarizeTurnThreshold' => $this->normalizePositiveInteger(
                $value,
                'autoSummarizeTurnThreshold must be an integer greater than or equal to 1',
            ),
            'autoSummarizeKeepRecent' => $this->normalizeBoundedInteger(
                $value,
                1,
                20,
                'autoSummarizeKeepRecent must be an integer between 1 and 20',
            ),
            default => [false, null, sprintf('Unsupported context key "%s"', $key)],
        };
    }

    /**
     * @return array{0: bool, 1: mixed, 2: ?string}
     */
    private function normalizeAutoSummarizeThreshold(mixed $value): array
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return [false, null, 'autoSummarizeThreshold must be numeric'];
        }

        if (!is_numeric($value)) {
            return [false, null, 'autoSummarizeThreshold must be numeric'];
        }

        $float = (float) $value;
        if ($float > 0.0 && $float <= 1.0) {
            return [true, $float, null];
        }

        if ($float >= 1.0 && $float <= 100.0) {
            return [true, $float, null];
        }

        return [false, null, 'autoSummarizeThreshold must be between 0.0 and 1.0, or between 1 and 100'];
    }

    /**
     * @return array{0: bool, 1: mixed, 2: ?string}
     */
    private function normalizePositiveInteger(mixed $value, string $error): array
    {
        if (!is_int($value)) {
            return [false, null, $error];
        }

        return $value >= 1
            ? [true, $value, null]
            : [false, null, $error];
    }

    /**
     * @return array{0: bool, 1: mixed, 2: ?string}
     */
    private function normalizeBoundedInteger(mixed $value, int $minimum, int $maximum, string $error): array
    {
        if (!is_int($value)) {
            return [false, null, $error];
        }

        return $value >= $minimum && $value <= $maximum
            ? [true, $value, null]
            : [false, null, $error];
    }

    private function contextValue(string $key, OpenClawConfig $config): mixed
    {
        return match ($key) {
            'conversationHistoryInSystemPrompt' => $config->useConversationHistoryInSystemPrompt(),
            'autoSummarizeMode' => $this->normalizedMode($config->get('agents.defaults.context.autoSummarizeMode')),
            'autoSummarizeThreshold' => $this->normalizedThreshold($config->get('agents.defaults.context.autoSummarizeThreshold')),
            'autoSummarizeTurnThreshold' => $this->normalizedInteger(
                $config->get('agents.defaults.context.autoSummarizeTurnThreshold'),
                CoquiDefaults::AUTO_SUMMARIZE_TURN_THRESHOLD,
            ),
            'autoSummarizeKeepRecent' => $this->normalizedInteger(
                $config->get('agents.defaults.context.autoSummarizeKeepRecent'),
                CoquiDefaults::AUTO_SUMMARIZE_KEEP_RECENT,
            ),
            default => null,
        };
    }

    private function contextDefault(string $key): mixed
    {
        return match ($key) {
            'conversationHistoryInSystemPrompt' => CoquiDefaults::CONVERSATION_HISTORY_IN_SYSTEM_PROMPT,
            'autoSummarizeMode' => CoquiDefaults::AUTO_SUMMARIZE_MODE,
            'autoSummarizeThreshold' => CoquiDefaults::AUTO_SUMMARIZE_THRESHOLD,
            'autoSummarizeTurnThreshold' => CoquiDefaults::AUTO_SUMMARIZE_TURN_THRESHOLD,
            'autoSummarizeKeepRecent' => CoquiDefaults::AUTO_SUMMARIZE_KEEP_RECENT,
            default => null,
        };
    }

    private function normalizedMode(mixed $value): string
    {
        return is_string($value) && in_array($value, ['token', 'turn', 'manual'], true)
            ? $value
            : CoquiDefaults::AUTO_SUMMARIZE_MODE;
    }

    private function normalizedThreshold(mixed $value): float
    {
        $threshold = is_numeric($value) ? (float) $value : CoquiDefaults::AUTO_SUMMARIZE_THRESHOLD;

        return $threshold > 0.0 && $threshold <= 1.0
            ? $threshold * 100.0
            : $threshold;
    }

    private function normalizedInteger(mixed $value, int $default): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function nestedValueExists(array $data, string $dotKey): bool
    {
        $value = $data;

        foreach (explode('.', $dotKey) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }

            $value = $value[$segment];
        }

        return true;
    }

    /**
     * @param list<string> $updatedKeys
     * @return array<string, mixed>
     */
    private function markRestartRequired(array $updatedKeys): array
    {
        if ($this->lifecycle === null) {
            return $this->restartState();
        }

        return $this->lifecycle->markRestartRequired(
            self::CONTEXT_RESTART_REASON,
            'api.config.context.update',
            ['updated_keys' => $updatedKeys],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function restartState(): array
    {
        return $this->lifecycle?->restartState() ?? [
            'required' => false,
            'reason' => null,
            'source' => null,
            'required_at' => null,
            'context' => [],
            'supported' => false,
            'managed_by_launcher' => false,
            'pid' => null,
            'started_at' => null,
        ];
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
