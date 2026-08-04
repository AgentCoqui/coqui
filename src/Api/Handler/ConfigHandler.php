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
use CoquiBot\Coqui\Config\PersonaParser;
use CoquiBot\Coqui\Config\PersonaPreferences;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Exception\RequestBodyException;
use CoquiBot\Coqui\Persona\PersonaSnapshotStore;
use CoquiBot\Coqui\Storage\ObjectVersionStore;
use CoquiBot\Coqui\Support\FileSystemOperations;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\Message\Response;
use stdClass;

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

    /**
     * Object type key used for persona version counters in ObjectVersionStore.
     */
    private const string PERSONA_OBJECT_TYPE = 'persona';

    /**
     * Sidecar file holding the structured CAP authoring fields (avatar,
     * allowed_roles, context, preferences) that do not map onto the markdown
     * authoring files. soul.md (soul + model frontmatter) and backstory.md
     * remain the markdown authoring source; identity.json carries the rest so a
     * served persona.json round-trips without polluting the internal
     * preferences.json editor shape.
     */
    private const string IDENTITY_SIDECAR = 'identity.json';

    /**
     * Model echoed when a persona's soul frontmatter declares none. Mirrors
     * PersonaSnapshotStore's fallback so the served wire always carries a
     * non-empty ModelId.
     */
    private const string FALLBACK_MODEL = 'anthropic/claude-sonnet-4';

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
        private PersonaDiscovery $personaDiscovery,
        private ?ModelMetadataResolver $modelMetadataResolver = null,
        private ?RoleResolver $roleResolver = null,
        private ?ConfigManager $configManager = null,
        private ?ConfigGuard $configGuard = null,
        private ?ApiLifecycleController $lifecycle = null,
        private ?ObjectVersionStore $objectVersions = null,
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
            fn(array $persona): array => $this->normalizePersonaSummary($persona),
            $this->personaDiscovery->discoverAll(),
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
        $persona = $this->personaDiscovery->discoverAll()[strtolower($name)] ?? null;
        if ($persona === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Persona "%s" not found', $name));
        }

        return Router::jsonResponse($this->normalizePersonaDetail($persona));
    }

    /**
     * POST /api/v1/personas — create a persona for app onboarding flows.
     */
    public function createPersona(ServerRequestInterface $request): Response
    {
        try {
            $body = $this->decodeAuthoringBody(
                $request,
                ['name', 'avatar', 'model', 'allowed_roles', 'soul'],
                ['backstory', 'context', 'preferences'],
            );
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
        }

        $name = $this->normalizePersonaName($body['name'] ?? null);
        if ($name === null) {
            return $this->validation('name is required and must use lowercase letters, numbers, hyphens, or underscores.');
        }

        $avatar = $this->validateAvatar($body['avatar']);
        if ($avatar === null) {
            return $this->validation('avatar must be a JSON object.');
        }

        $model = $this->validateModel($body['model']);
        if ($model === null) {
            return $this->validation('model must be a non-empty string.');
        }

        $allowedRoles = $this->validateAllowedRoles($body['allowed_roles']);
        if ($allowedRoles === null) {
            return $this->validation('allowed_roles must be a non-empty array of role names that includes "orchestrator".');
        }

        if (!is_string($body['soul'])) {
            return $this->validation('soul must be a string.');
        }
        $soul = $body['soul'];

        $backstory = $this->validateBackstory($body['backstory'] ?? null);
        if ($backstory === false) {
            return $this->validation('backstory must be a string or null.');
        }

        $context = $this->validateContext($body['context'] ?? null);
        if ($context === false) {
            return $this->validation('context must be an array of strings or null.');
        }

        $preferences = $this->validatePreferences($body['preferences'] ?? null);
        if ($preferences === false) {
            return $this->validation('preferences must be an object or null.');
        }

        if ($this->personaDiscovery->personaExists($name)) {
            return Router::errorResponse(ApiErrorCode::CONFLICT, sprintf('Persona "%s" already exists.', $name));
        }

        try {
            $operations = $this->workspaceOperations();
            $personaDir = 'personas/' . $name;

            $operations->write($personaDir . '/soul.md', $this->composeSoulMarkdown($soul, $model));

            if ($backstory !== null) {
                $operations->write($personaDir . '/backstory.md', rtrim($backstory) . "\n");
            }

            $operations->write(
                $personaDir . '/' . self::IDENTITY_SIDECAR,
                $this->encodeIdentity($avatar, $allowedRoles, $context, $preferences),
            );
        } catch (\Throwable $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to create persona', ['error' => $e->getMessage()]);
        }

        $this->personaDiscovery->invalidateCache();
        if (!$this->personaDiscovery->personaExists($name)) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Persona was created but could not be reloaded.');
        }

        $this->objectVersions?->create(self::PERSONA_OBJECT_TYPE, $name);

        return Router::jsonResponse($this->servedPersonaWire($name), 201);
    }

    /**
     * PATCH /api/v1/personas/{name} — update soul, backstory, and preferences.
     */
    public function updatePersona(ServerRequestInterface $request, ?string $name = null): Response
    {
        try {
            $body = $this->decodePatchBody(
                $request,
                ['name', 'avatar', 'model', 'allowed_roles', 'soul', 'backstory', 'context', 'preferences'],
            );
        } catch (RequestBodyException $e) {
            return Router::errorResponse($e->errorCode, $e->getMessage(), $e->details, $e->status);
        }

        $rawName = $name ?? basename($request->getUri()->getPath());
        $normalizedName = strtolower(trim($rawName));
        $persona = $this->personaDiscovery->discoverAll()[$normalizedName] ?? null;
        if ($persona === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Persona "%s" not found', $rawName));
        }

        if (array_key_exists('name', $body)) {
            $requestedName = $this->normalizePersonaName($body['name']);
            if ($requestedName === null || $requestedName !== $normalizedName) {
                return $this->validation('Persona renaming is not supported by this endpoint.');
            }
        }

        // Optimistic-concurrency guard: If-Match must match the current version.
        $precondition = $this->readPrecondition($request);
        $currentVersion = $this->personaVersion($normalizedName);
        if ($precondition->expectedVersion !== null && $precondition->expectedVersion !== $currentVersion) {
            return Router::errorResponse(
                ApiErrorCode::VERSION_CONFLICT,
                sprintf('Persona "%s" has changed; expected version %d.', $normalizedName, $currentVersion),
                ['expected_version' => $precondition->expectedVersion, 'current_version' => $currentVersion],
                409,
            );
        }

        $identity = $this->readIdentity($persona['path']);

        // Validate each present field before writing anything.
        if (array_key_exists('avatar', $body)) {
            $avatar = $this->validateAvatar($body['avatar']);
            if ($avatar === null) {
                return $this->validation('avatar must be a JSON object.');
            }
            $identity['avatar'] = $avatar;
        }

        $model = null;
        if (array_key_exists('model', $body)) {
            $model = $this->validateModel($body['model']);
            if ($model === null) {
                return $this->validation('model must be a non-empty string.');
            }
        }

        if (array_key_exists('allowed_roles', $body)) {
            $allowedRoles = $this->validateAllowedRoles($body['allowed_roles']);
            if ($allowedRoles === null) {
                return $this->validation('allowed_roles must be a non-empty array of role names that includes "orchestrator".');
            }
            $identity['allowed_roles'] = $allowedRoles;
        }

        if (array_key_exists('soul', $body) && !is_string($body['soul'])) {
            return $this->validation('soul must be a string.');
        }

        $backstory = null;
        $updatesBackstory = array_key_exists('backstory', $body);
        if ($updatesBackstory) {
            $backstory = $this->validateBackstory($body['backstory']);
            if ($backstory === false) {
                return $this->validation('backstory must be a string or null.');
            }
        }

        if (array_key_exists('context', $body)) {
            $context = $this->validateContext($body['context']);
            if ($context === false) {
                return $this->validation('context must be an array of strings or null.');
            }
            $identity['context'] = $context;
        }

        if (array_key_exists('preferences', $body)) {
            $preferences = $this->validatePreferences($body['preferences']);
            if ($preferences === false) {
                return $this->validation('preferences must be an object or null.');
            }
            $identity['preferences'] = $preferences;
        }

        try {
            $operations = $this->workspaceOperations();
            $personaDir = 'personas/' . $normalizedName;

            if (array_key_exists('soul', $body) || array_key_exists('model', $body)) {
                $soul = array_key_exists('soul', $body)
                    ? (string) $body['soul']
                    : $this->personaDiscovery->readSoul($normalizedName);
                $effectiveModel = array_key_exists('model', $body)
                    ? $model
                    : $this->personaDiscovery->readPersonaModel($normalizedName);
                $operations->write($personaDir . '/soul.md', $this->composeSoulMarkdown($soul, $effectiveModel));
            }

            if ($updatesBackstory) {
                $this->writeOrDeleteOptionalFile(
                    $personaDir . '/backstory.md',
                    $backstory !== null ? rtrim($backstory) : null,
                );
            }

            $operations->write(
                $personaDir . '/' . self::IDENTITY_SIDECAR,
                $this->encodeIdentity(
                    $identity['avatar'],
                    $identity['allowed_roles'],
                    $identity['context'],
                    $identity['preferences'],
                ),
            );
        } catch (\Throwable $e) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Failed to update persona', ['error' => $e->getMessage()]);
        }

        $this->personaDiscovery->invalidateCache();
        if (!$this->personaDiscovery->personaExists($normalizedName)) {
            return Router::errorResponse(ApiErrorCode::INTERNAL_ERROR, 'Persona was updated but could not be reloaded.');
        }

        $this->objectVersions?->bump(self::PERSONA_OBJECT_TYPE, $normalizedName);

        return Router::jsonResponse($this->servedPersonaWire($normalizedName));
    }

    /**
     * DELETE /api/v1/personas/{name} — remove a persona directory.
     */
    public function deletePersona(ServerRequestInterface $request, string $name): Response
    {
        $normalizedName = strtolower(trim($name));
        $persona = $this->personaDiscovery->discoverAll()[$normalizedName] ?? null;
        if ($persona === null) {
            return Router::errorResponse(ApiErrorCode::NOT_FOUND, sprintf('Persona "%s" not found', $name));
        }

        if ($this->currentConfig()->getDefaultPersona() === $normalizedName) {
            return Router::errorResponse(
                ApiErrorCode::CONFLICT,
                sprintf('Persona "%s" is the configured default persona and cannot be deleted yet.', $normalizedName),
            );
        }

        $this->deleteDirectory($persona['path']);
        $this->personaDiscovery->invalidateCache();

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
     * @param array{name: string, display_name: string, description: string, path: string} $persona
     * @return array<string, mixed>
     */
    private function normalizePersonaSummary(array $persona): array
    {
        $preferences = PersonaPreferences::fromPersonaPath($persona['path']);
        $selectableRoles = $this->roleResolver !== null
            ? array_values($this->roleResolver->selectableRoles())
            : [];
        $allowedRoles = $this->roleResolver !== null
            ? $preferences->filterAllowedRoles($selectableRoles)
            : [];

        return [
            'name' => $persona['name'],
            'display_name' => $persona['display_name'],
            'description' => $persona['description'],
            'version' => $this->personaVersion($persona['name']),
            'model' => $this->personaDiscovery->readPersonaModel($persona['name']),
            'is_default' => $this->currentConfig()->getDefaultPersona() === $persona['name'],
            'allowed_roles' => $allowedRoles,
            'role_restrictions' => [
                'allow' => $preferences->allowedRoles(),
                'deny' => $preferences->deniedRoles(),
            ],
            'has_role_restrictions' => $preferences->hasRoleRestrictions(),
        ];
    }

    /**
     * @param array{name: string, display_name: string, description: string, path: string} $persona
     * @return array<string, mixed>
     */
    private function normalizePersonaDetail(array $persona): array
    {
        $preferences = PersonaPreferences::fromPersonaPath($persona['path']);

        return [
            ...$this->normalizePersonaSummary($persona),
            'preferences' => $preferences->inspectionSummary(),
            'preference_values' => $preferences->editorValues(),
            'preference_document' => $this->readPreferenceDocument($persona['path']),
            'soul' => $this->personaDiscovery->readSoul($persona['name']),
        ];
    }

    /**
     * Serialize a file-authored persona into a strict CAP `persona.json` wire.
     *
     * Reuses PersonaSnapshotStore::toWire so the served shape and its
     * empty-object normalization stay identical to the DB-snapshot producer.
     * The persona `version` is served from the ObjectVersionStore counter,
     * defaulting to 1 for a pre-existing file persona with no counter row yet.
     *
     * @return array<string, mixed>
     */
    public function servedPersonaWire(string $name): array
    {
        $normalized = strtolower(trim($name));
        $persona = $this->personaDiscovery->discoverAll()[$normalized] ?? null;
        if ($persona === null) {
            throw new \InvalidArgumentException(sprintf('Persona "%s" not found.', $name));
        }

        $identity = $this->readIdentity($persona['path']);
        $soulPath = rtrim($persona['path'], '/') . '/soul.md';
        $parsed = (new PersonaParser())->readFile($soulPath);
        $metadataModel = $parsed['metadata']['model'] ?? null;
        $model = is_string($metadataModel) && trim($metadataModel) !== ''
            ? trim($metadataModel)
            : self::FALLBACK_MODEL;

        $timestamp = $this->personaTimestamp($soulPath);
        $preferences = $identity['preferences'];

        return PersonaSnapshotStore::toWire([
            'id' => 'persona_' . $normalized,
            'name' => $persona['display_name'],
            'avatar' => json_encode(
                $identity['avatar'] === [] ? new stdClass() : $identity['avatar'],
                JSON_THROW_ON_ERROR,
            ),
            'model' => $model,
            'allowed_roles' => json_encode($identity['allowed_roles'], JSON_THROW_ON_ERROR),
            'soul' => $parsed['body'],
            'backstory' => $this->readBackstory($persona['path']),
            'context' => $identity['context'] !== null
                ? json_encode($identity['context'], JSON_THROW_ON_ERROR)
                : null,
            'preferences' => $preferences !== null
                ? json_encode($preferences === [] ? new stdClass() : $preferences, JSON_THROW_ON_ERROR)
                : null,
            'version' => $this->personaVersion($normalized),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * Current persona version, defaulting to 1 for a pre-existing file persona
     * that has never been written through the versioned API.
     */
    private function personaVersion(string $name): int
    {
        $current = $this->objectVersions?->current(self::PERSONA_OBJECT_TYPE, strtolower(trim($name))) ?? 0;

        return max(1, $current);
    }

    private function validation(string $message): Response
    {
        return Router::errorResponse(ApiErrorCode::VALIDATION_ERROR, $message, null, 422);
    }

    /**
     * A JSON object (possibly empty) or null when the value is not an object.
     *
     * @return array<string, mixed>|null
     */
    private function validateAvatar(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        // A non-empty JSON array is a list, not an object.
        if ($value !== [] && array_is_list($value)) {
            return null;
        }

        return $value;
    }

    private function validateModel(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A non-empty, unique list of role strings including "orchestrator", or null.
     *
     * @return list<string>|null
     */
    private function validateAllowedRoles(mixed $value): ?array
    {
        if (!is_array($value) || $value === [] || !array_is_list($value)) {
            return null;
        }

        $roles = [];
        foreach ($value as $role) {
            if (!is_string($role) || trim($role) === '') {
                return null;
            }
            $roles[] = trim($role);
        }

        $roles = array_values(array_unique($roles));
        if (!in_array('orchestrator', $roles, true)) {
            return null;
        }

        return $roles;
    }

    /**
     * A backstory string, an explicit null, or false when the type is invalid.
     */
    private function validateBackstory(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }

        return is_string($value) ? $value : false;
    }

    /**
     * A list of context strings, an explicit null, or false when invalid.
     *
     * @return list<string>|false|null
     */
    private function validateContext(mixed $value): array|false|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            return false;
        }
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                return false;
            }
        }

        return $value;
    }

    /**
     * A preferences object (possibly empty), an explicit null, or false when invalid.
     *
     * @return array<string, mixed>|false|null
     */
    private function validatePreferences(mixed $value): array|false|null
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return false;
        }
        // A non-empty JSON array is a list, not an object.
        if ($value !== [] && array_is_list($value)) {
            return false;
        }

        return $value;
    }

    /**
     * Compose soul.md content, carrying the model in YAML frontmatter so the
     * discovery layer (readPersonaModel) and the served wire agree on the model.
     */
    private function composeSoulMarkdown(string $soul, ?string $model): string
    {
        $body = rtrim($soul) . "\n";

        if ($model === null || trim($model) === '') {
            return $body;
        }

        return "---\nmodel: " . trim($model) . "\n---\n\n" . ltrim($body);
    }

    /**
     * Encode the structured CAP authoring fields into the identity sidecar.
     *
     * An empty avatar/preferences object is written as `{}` (never `[]`) so a
     * round-trip through the served wire keeps the schema's object type.
     *
     * @param array<string, mixed> $avatar
     * @param list<string> $allowedRoles
     * @param list<string>|null $context
     * @param array<string, mixed>|null $preferences
     */
    private function encodeIdentity(array $avatar, array $allowedRoles, ?array $context, ?array $preferences): string
    {
        $record = [
            'avatar' => $avatar === [] ? new stdClass() : $avatar,
            'allowed_roles' => $allowedRoles,
            'context' => $context,
            'preferences' => $preferences === [] ? new stdClass() : $preferences,
        ];

        return json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /**
     * Read the identity sidecar, falling back to CAP defaults when it is absent
     * or unreadable (a pre-existing file persona: empty avatar, orchestrator-only).
     *
     * @return array{avatar: array<string, mixed>, allowed_roles: list<string>, context: list<string>|null, preferences: array<string, mixed>|null}
     */
    private function readIdentity(string $personaPath): array
    {
        $defaults = [
            'avatar' => [],
            'allowed_roles' => ['orchestrator'],
            'context' => null,
            'preferences' => null,
        ];

        $path = rtrim($personaPath, '/') . '/' . self::IDENTITY_SIDECAR;
        if (!is_file($path)) {
            return $defaults;
        }
        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return $defaults;
        }

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $defaults;
        }

        if (!is_array($decoded)) {
            return $defaults;
        }

        $avatar = $this->validateAvatar($decoded['avatar'] ?? null);
        $allowedRoles = $this->validateAllowedRoles($decoded['allowed_roles'] ?? null);
        $context = $this->validateContext($decoded['context'] ?? null);
        $preferences = $this->validatePreferences($decoded['preferences'] ?? null);

        return [
            'avatar' => $avatar ?? [],
            'allowed_roles' => $allowedRoles ?? ['orchestrator'],
            'context' => $context === false ? null : $context,
            'preferences' => $preferences === false ? null : $preferences,
        ];
    }

    private function readBackstory(string $personaPath): ?string
    {
        $path = rtrim($personaPath, '/') . '/backstory.md';
        if (!is_file($path)) {
            return null;
        }
        $content = file_get_contents($path);
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        return rtrim($content);
    }

    private function personaTimestamp(string $soulPath): string
    {
        $mtime = @filemtime($soulPath);

        return gmdate('Y-m-d\TH:i:s\Z', $mtime !== false ? $mtime : time());
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

    private function normalizePersonaName(mixed $value): ?string
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

    private function workspacePath(): string
    {
        return dirname($this->personaDiscovery->personasDir());
    }

    private function workspaceOperations(): FileSystemOperations
    {
        return new FileSystemOperations($this->workspacePath());
    }

    /**
     * @return array<string, mixed>
     */
    private function readPreferenceDocument(string $personaPath): array
    {
        $path = rtrim($personaPath, '/') . '/preferences.json';
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
            throw new \RuntimeException(sprintf('Failed to read persona directory "%s".', $path));
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
                throw new \RuntimeException(sprintf('Failed to delete persona file "%s".', $entryPath));
            }
        }

        if (!@rmdir($path)) {
            throw new \RuntimeException(sprintf('Failed to delete persona directory "%s".', $path));
        }
    }
}
