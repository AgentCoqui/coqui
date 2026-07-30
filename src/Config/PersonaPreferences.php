<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\SystemRole;

/**
 * Behavioral settings and communication patterns loaded from a persona's preferences.json.
 *
 * Preferences are split into two sections:
 * - promptDirectives: key-value pairs rendered into the system prompt to guide communication style
 * - behavior: code-level settings that configure agent parameters (e.g. temperature hints, tool preferences)
 */
final readonly class PersonaPreferences
{
    private const array ALLOWED_TOP_LEVEL_FIELDS = ['prompt_directives', 'behavior', 'prompts'];
    private const array ALLOWED_PROMPTS_FIELDS = ['features', 'prompt_sections', 'roles', 'labels'];
    private const array ALLOWED_FEATURES = ['artifacts', 'projects', 'loops', 'background_tasks'];
    private const array SECTION_ALIASES = [
        'deferred' => 'deferred_toolkits',
        'project' => 'project_context',
    ];
    private const array ALLOWED_PROMPT_SECTIONS = [
        'soul',
        'backstory',
        'context',
        'base',
        'memory',
        'preferences',
        'tools',
        'security',
        'done',
        'deferred_toolkits',
        'project_context',
    ];
    private const array ALLOWED_PROMPT_SECTION_MODES = [true, false, 'stub'];
    private const array ALLOWED_ROLE_FIELDS = ['allow', 'deny'];
    private const array ALLOWED_LABELS = ['backstory', 'context'];

    /**
     * @param array<string, string> $promptDirectives Communication directives rendered into the system prompt.
     * @param array<string, mixed> $behavior Code-level settings for agent configuration.
     * @param array<string, mixed> $prompts Validated prompt policy configuration.
     * @param list<string> $validationErrors Validation errors collected while parsing preferences.
     */
    public function __construct(
        public array $promptDirectives = [],
        public array $behavior = [],
        public array $prompts = [],
        public array $validationErrors = [],
    ) {}

    /**
     * Load preferences from a JSON file.
     *
     * Expected format:
     * {
     *   "prompt_directives": {
     *     "response_style": "concise and measured",
     *     "formatting": "prefer markdown tables over lists"
     *   },
     *   "behavior": {
     *     "temperature_hint": 0.7
     *   }
     * }
     */
    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            return new self();
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return new self();
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new self(validationErrors: ['Invalid JSON in preferences.json.']);
        }

        if (!is_array($data)) {
            return new self(validationErrors: ['preferences.json must decode to a JSON object.']);
        }

        return self::fromArray($data, dirname($path));
    }

    /**
     * Load preferences from a persona directory.
     */
    public static function fromPersonaPath(string $personaPath): self
    {
        return self::fromFile(rtrim($personaPath, '/') . '/preferences.json');
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data, ?string $personaPath = null): self
    {
        $errors = [];

        $directives = [];
        self::validateTopLevelKeys($data, $errors);

        if (isset($data['prompt_directives']) && is_array($data['prompt_directives'])) {
            foreach ($data['prompt_directives'] as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $directives[$key] = $value;
                } elseif (is_string($key)) {
                    $errors[] = sprintf('prompt_directives.%s must be a string.', $key);
                }
            }
        } elseif (isset($data['prompt_directives'])) {
            $errors[] = 'prompt_directives must be an object of string values.';
        }

        $behavior = [];
        if (isset($data['behavior']) && is_array($data['behavior'])) {
            $behavior = $data['behavior'];
        } elseif (isset($data['behavior'])) {
            $errors[] = 'behavior must be an object.';
        }

        $prompts = self::buildDefaultPromptPolicy();
        if (isset($data['prompts']) && is_array($data['prompts'])) {
            self::parsePromptPolicy($data['prompts'], $prompts, $errors);
        } elseif (isset($data['prompts'])) {
            $errors[] = 'prompts must be an object.';
        }

        if ($personaPath !== null) {
            self::validateSecurityOverride($personaPath, $errors);
        }

        return new self(
            promptDirectives: $directives,
            behavior: $behavior,
            prompts: $prompts,
            validationErrors: $errors,
        );
    }

    public static function empty(): self
    {
        return new self();
    }

    /**
     * Curated app-facing schema for the persona preferences workspace.
     *
     * @param list<string> $availableRoles
     * @return array<string, mixed>
     */
    public static function appSchema(array $availableRoles = []): array
    {
        $roleOptions = array_map(
            static fn(string $role): array => [
                'value' => $role,
                'label' => ucwords(str_replace(['-', '_'], ' ', $role)),
            ],
            $availableRoles,
        );

        return [
            'version' => 1,
            'sections' => [
                [
                    'id' => 'communication_style',
                    'label' => 'Communication Style',
                    'description' => 'How the persona speaks, collaborates, and frames feedback.',
                    'fields' => [
                        self::suggestedTextField(
                            'response_style',
                            'Response Style',
                            'prompt_directives.response_style',
                            'Choose how the persona should sound in normal replies.',
                            ['structured and measured', 'brief and exact', 'commercial and outcome-first'],
                        ),
                        self::suggestedTextField(
                            'collaboration',
                            'Collaboration Style',
                            'prompt_directives.collaboration',
                            'Guide how the persona should work with the user while solving problems.',
                            ['call out risks and assumptions early'],
                        ),
                        self::suggestedTextField(
                            'feedback',
                            'Feedback Style',
                            'prompt_directives.feedback',
                            'Shape how direct or soft the persona should be when critiquing work.',
                            ['favor direct critique over soft framing'],
                        ),
                    ],
                ],
                [
                    'id' => 'planning_reasoning',
                    'label' => 'Planning and Reasoning',
                    'description' => 'How the persona evaluates tradeoffs, plans work, and applies critique.',
                    'fields' => [
                        self::suggestedTextField(
                            'decision_making',
                            'Decision Making',
                            'prompt_directives.decision_making',
                            'Guide how the persona should choose between competing options.',
                            [
                                'state tradeoffs before recommending a path',
                                'prefer the option with the clearest measurable upside',
                            ],
                        ),
                        self::selectField(
                            'planning_mode',
                            'Planning Mode',
                            'behavior.planning_mode',
                            'Choose how structured the persona should be before acting.',
                            ['deliberate', 'structured'],
                        ),
                        self::toggleField(
                            'critique_mode',
                            'Critique Mode',
                            'behavior.critique_mode',
                            'When enabled, the persona leans harder into critical review and challenge.',
                        ),
                    ],
                ],
                [
                    'id' => 'capabilities_tools',
                    'label' => 'Capabilities and Tools',
                    'description' => 'Control which major workflow features this persona can actively use.',
                    'fields' => [
                        self::toggleField('artifacts', 'Artifacts', 'prompts.features.artifacts', 'Allow artifact creation and artifact-aware workflows.'),
                        self::toggleField('projects', 'Projects', 'prompts.features.projects', 'Allow project context and project-aware workflows.'),
                        self::toggleField('loops', 'Loops', 'prompts.features.loops', 'Allow loop orchestration and loop-aware execution.'),
                        self::toggleField('background_tasks', 'Background Tasks', 'prompts.features.background_tasks', 'Allow background tasks and deferred execution.'),
                    ],
                ],
                [
                    'id' => 'roles_autonomy',
                    'label' => 'Roles and Autonomy',
                    'description' => 'Constrain which roles the persona can use or explicitly block.',
                    'fields' => [
                        self::multiSelectField(
                            'allow_roles',
                            'Allowed Roles',
                            'prompts.roles.allow',
                            'If set, the persona is restricted to this role allow-list. Orchestrator must remain available.',
                            $roleOptions,
                        ),
                        self::multiSelectField(
                            'deny_roles',
                            'Denied Roles',
                            'prompts.roles.deny',
                            'Use deny-list rules to block roles that should never be used by this persona.',
                            $roleOptions,
                        ),
                    ],
                ],
            ],
            'deferred' => [
                'advanced_editor' => true,
                'unsupported_fields_hidden' => true,
            ],
        ];
    }

    public function isEmpty(): bool
    {
        return $this->promptDirectives === [] && $this->behavior === [] && $this->hasPromptPolicy() === false;
    }

    public function hasPromptDirectives(): bool
    {
        return $this->promptDirectives !== [];
    }

    public function hasPromptPolicy(): bool
    {
        return $this->effectivePrompts() !== self::buildDefaultPromptPolicy();
    }

    public function isValid(): bool
    {
        return $this->validationErrors === [];
    }

    /**
     * @return list<string>
     */
    public function getValidationErrors(): array
    {
        return $this->validationErrors;
    }

    /**
     * Render prompt directives as a Markdown section.
     *
     * Returns null when no directives are configured.
     */
    public function renderPromptSection(): ?string
    {
        if ($this->promptDirectives === []) {
            return null;
        }

        $lines = ['## Preferences', ''];

        foreach ($this->promptDirectives as $key => $value) {
            $label = ucfirst(str_replace('_', ' ', $key));
            $lines[] = "- **{$label}:** {$value}";
        }

        return implode("\n", $lines);
    }

    /**
     * Get a behavior setting by key.
     */
    public function getBehavior(string $key, mixed $default = null): mixed
    {
        return $this->behavior[$key] ?? $default;
    }

    public function isFeatureEnabled(string $feature, bool $default = true): bool
    {
        return $this->effectivePrompts()['features'][$feature] ?? $default;
    }

    /**
     * @return array<string, bool>
     */
    public function featureFlags(): array
    {
        return $this->effectivePrompts()['features'];
    }

    public function getPromptSectionMode(string $section, bool|string $default = true): bool|string
    {
        $normalized = self::normalizePromptSectionName($section);

        return $this->effectivePrompts()['prompt_sections'][$normalized] ?? $default;
    }

    public function isPromptSectionEnabled(string $section, bool $default = true): bool
    {
        return $this->getPromptSectionMode($section, $default) !== false;
    }

    public function isPromptSectionStubbed(string $section): bool
    {
        return $this->getPromptSectionMode($section) === 'stub';
    }

    /**
     * @return list<string>
     */
    public function allowedRoles(): array
    {
        return $this->effectivePrompts()['roles']['allow'] ?? [];
    }

    /**
     * @return list<string>
     */
    public function deniedRoles(): array
    {
        return $this->effectivePrompts()['roles']['deny'] ?? [];
    }

    public function isRoleAllowed(string $role): bool
    {
        $normalizedRole = strtolower(trim($role));
        if ($normalizedRole === SystemRole::Orchestrator->value) {
            return true;
        }

        $allow = $this->allowedRoles();
        $deny = $this->deniedRoles();

        if ($allow !== [] && !in_array($normalizedRole, $allow, true)) {
            return false;
        }

        return !in_array($normalizedRole, $deny, true);
    }

    public function hasRoleRestrictions(): bool
    {
        return $this->allowedRoles() !== [] || $this->deniedRoles() !== [];
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function filterAllowedRoles(array $roles): array
    {
        $filtered = [];
        foreach ($roles as $role) {
            if ($this->isRoleAllowed($role)) {
                $filtered[] = $role;
            }
        }

        return $filtered;
    }

    public function getBackstoryLabel(): string
    {
        return $this->effectivePrompts()['labels']['backstory'] ?? 'Backstory';
    }

    public function getContextLabel(): string
    {
        return $this->effectivePrompts()['labels']['context'] ?? 'Context';
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectionSummary(): array
    {
        return [
            'is_valid' => $this->isValid(),
            'validation_errors' => $this->validationErrors,
            'features' => $this->featureFlags(),
            'prompt_sections' => $this->effectivePrompts()['prompt_sections'] ?? [],
            'roles' => [
                'allow' => $this->allowedRoles(),
                'deny' => $this->deniedRoles(),
            ],
            'labels' => $this->effectivePrompts()['labels'] ?? [],
        ];
    }

    /**
     * Curated values for app-facing preference editors.
     *
     * @return array<string, mixed>
     */
    public function editorValues(): array
    {
        return [
            'prompt_directives' => $this->promptDirectives,
            'behavior' => $this->behavior,
            'prompts' => [
                'features' => $this->effectivePrompts()['features'] ?? [],
                'roles' => [
                    'allow' => $this->allowedRoles(),
                    'deny' => $this->deniedRoles(),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function effectivePrompts(): array
    {
        if ($this->prompts === []) {
            return self::buildDefaultPromptPolicy();
        }

        return $this->prompts;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $errors
     */
    private static function validateTopLevelKeys(array $data, array &$errors): void
    {
        foreach (array_keys($data) as $key) {
            if (!in_array($key, self::ALLOWED_TOP_LEVEL_FIELDS, true)) {
                $errors[] = sprintf('Unknown preferences field "%s".', $key);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function buildDefaultPromptPolicy(): array
    {
        return [
            'features' => [],
            'prompt_sections' => [],
            'roles' => [
                'allow' => [],
                'deny' => [],
            ],
            'labels' => [
                'backstory' => 'Backstory',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $policyData
     * @param array<string, mixed> &$prompts
     * @param list<string> $errors
     */
    private static function parsePromptPolicy(array $policyData, array &$prompts, array &$errors): void
    {
        foreach (array_keys($policyData) as $key) {
            if (!in_array($key, self::ALLOWED_PROMPTS_FIELDS, true)) {
                $errors[] = sprintf('Unknown prompts field "%s".', $key);
            }
        }

        self::parseFeatures($policyData['features'] ?? null, $prompts, $errors);
        self::parsePromptSections($policyData['prompt_sections'] ?? null, $prompts, $errors);
        self::parseRoles($policyData['roles'] ?? null, $prompts, $errors);
        self::parseLabels($policyData['labels'] ?? null, $prompts, $errors);
    }

    /**
        * @param array<string, mixed> &$prompts
     * @param list<string> $errors
     */
    private static function parseFeatures(mixed $featuresData, array &$prompts, array &$errors): void
    {
        if ($featuresData === null) {
            return;
        }

        if (!is_array($featuresData)) {
            $errors[] = 'prompts.features must be an object of boolean values.';
            return;
        }

        foreach ($featuresData as $key => $value) {
            if (!is_string($key) || !in_array($key, self::ALLOWED_FEATURES, true)) {
                $errors[] = sprintf('Unknown prompts.features entry "%s".', (string) $key);
                continue;
            }

            if (!is_bool($value)) {
                $errors[] = sprintf('prompts.features.%s must be a boolean.', $key);
                continue;
            }

            $prompts['features'][$key] = $value;
        }
    }

    /**
        * @param array<string, mixed> &$prompts
     * @param list<string> $errors
     */
    private static function parsePromptSections(mixed $sectionsData, array &$prompts, array &$errors): void
    {
        if ($sectionsData === null) {
            return;
        }

        if (!is_array($sectionsData)) {
            $errors[] = 'prompts.prompt_sections must be an object of booleans or "stub" values.';
            return;
        }

        foreach ($sectionsData as $key => $value) {
            $section = self::normalizePromptSectionName((string) $key);

            if (!in_array($section, self::ALLOWED_PROMPT_SECTIONS, true)) {
                $errors[] = sprintf('Unknown prompts.prompt_sections entry "%s".', (string) $key);
                continue;
            }

            if (!in_array($value, self::ALLOWED_PROMPT_SECTION_MODES, true)) {
                $errors[] = sprintf('prompts.prompt_sections.%s must be true, false, or "stub".', $section);
                continue;
            }

            if ($section === 'security' && $value !== true) {
                $errors[] = 'prompts.prompt_sections.security cannot be changed. Use a persona-specific security.md override instead.';
                $prompts['prompt_sections'][$section] = true;
                continue;
            }

            $prompts['prompt_sections'][$section] = $value;
        }
    }

    /**
        * @param array<string, mixed> &$prompts
     * @param list<string> $errors
     */
    private static function parseRoles(mixed $rolesData, array &$prompts, array &$errors): void
    {
        if ($rolesData === null) {
            return;
        }

        if (!is_array($rolesData)) {
            $errors[] = 'prompts.roles must be an object with optional allow and deny arrays.';
            return;
        }

        foreach (array_keys($rolesData) as $key) {
            if (!in_array($key, self::ALLOWED_ROLE_FIELDS, true)) {
                $errors[] = sprintf('Unknown prompts.roles field "%s".', $key);
            }
        }

        $allow = self::normalizeRoleList($rolesData['allow'] ?? null, 'prompts.roles.allow', $errors);
        $deny = self::normalizeRoleList($rolesData['deny'] ?? null, 'prompts.roles.deny', $errors);

        if ($allow !== [] && !in_array(SystemRole::Orchestrator->value, $allow, true)) {
            $errors[] = 'prompts.roles.allow must include orchestrator.';
            array_unshift($allow, SystemRole::Orchestrator->value);
            $allow = array_values(array_unique($allow));
        }

        if (in_array(SystemRole::Orchestrator->value, $deny, true)) {
            $errors[] = 'prompts.roles.deny cannot include orchestrator.';
            $deny = array_values(array_diff($deny, [SystemRole::Orchestrator->value]));
        }

        $overlap = array_values(array_intersect($allow, $deny));

        if ($overlap !== []) {
            $errors[] = sprintf(
                'prompts.roles.allow and prompts.roles.deny overlap for: %s.',
                implode(', ', $overlap),
            );
            $deny = array_values(array_diff($deny, $overlap));
        }

        $prompts['roles']['allow'] = $allow;
        $prompts['roles']['deny'] = $deny;
    }

    /**
        * @param array<string, mixed> &$prompts
     * @param list<string> $errors
     */
    private static function parseLabels(mixed $labelsData, array &$prompts, array &$errors): void
    {
        if ($labelsData === null) {
            return;
        }

        if (!is_array($labelsData)) {
            $errors[] = 'prompts.labels must be an object.';
            return;
        }

        foreach (array_keys($labelsData) as $key) {
            if (!in_array($key, self::ALLOWED_LABELS, true)) {
                $errors[] = sprintf('Unknown prompts.labels field "%s".', $key);
            }
        }

        if (array_key_exists('backstory', $labelsData)) {
            $normalized = self::normalizeHeadingLabel($labelsData['backstory']);
            if ($normalized === null) {
                $errors[] = 'prompts.labels.backstory must be a non-empty string.';
            } else {
                $prompts['labels']['backstory'] = $normalized;
            }
        }

        if (array_key_exists('context', $labelsData)) {
            $normalized = self::normalizeHeadingLabel($labelsData['context']);
            if ($normalized === null) {
                $errors[] = 'prompts.labels.context must be a non-empty string.';
            } else {
                $prompts['labels']['context'] = $normalized;
            }
        }
    }

    private static function normalizePromptSectionName(string $section): string
    {
        $normalized = strtolower(trim($section));

        return self::SECTION_ALIASES[$normalized] ?? $normalized;
    }

    /**
     * @param list<string> $errors
     * @return list<string>
     */
    private static function normalizeRoleList(mixed $value, string $fieldName, array &$errors): array
    {
        if ($value === null) {
            return [];
        }

        if (!is_array($value)) {
            $errors[] = sprintf('%s must be an array of role names.', $fieldName);
            return [];
        }

        $normalized = [];
        foreach ($value as $role) {
            if (!is_string($role) || trim($role) === '') {
                $errors[] = sprintf('%s must only contain non-empty strings.', $fieldName);
                continue;
            }

            $normalized[] = strtolower(trim($role));
        }

        return array_values(array_unique($normalized));
    }

    private static function normalizeHeadingLabel(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', str_replace(["\r", "\n"], ' ', $value)) ?? '');
        $normalized = ltrim($normalized, "# \t");

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param list<string> $suggestions
     * @return array<string, mixed>
     */
    private static function suggestedTextField(
        string $id,
        string $label,
        string $path,
        string $description,
        array $suggestions,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'storage_path' => $path,
            'input' => 'suggested_text',
            'description' => $description,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @param list<string> $options
     * @return array<string, mixed>
     */
    private static function selectField(
        string $id,
        string $label,
        string $path,
        string $description,
        array $options,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'storage_path' => $path,
            'input' => 'select',
            'description' => $description,
            'options' => $options,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function toggleField(
        string $id,
        string $label,
        string $path,
        string $description,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'storage_path' => $path,
            'input' => 'toggle',
            'description' => $description,
        ];
    }

    /**
     * @param list<array{value: string, label: string}> $options
     * @return array<string, mixed>
     */
    private static function multiSelectField(
        string $id,
        string $label,
        string $path,
        string $description,
        array $options,
    ): array {
        return [
            'id' => $id,
            'label' => $label,
            'storage_path' => $path,
            'input' => 'multi_select',
            'description' => $description,
            'options' => $options,
        ];
    }

    /**
     * @param list<string> $errors
     */
    private static function validateSecurityOverride(string $personaPath, array &$errors): void
    {
        $securityPath = rtrim($personaPath, '/') . '/security.md';
        if (!is_file($securityPath)) {
            return;
        }

        $content = file_get_contents($securityPath);
        if ($content === false || trim($content) === '') {
            $errors[] = 'Persona security.md override must not be empty. Remove the file to fall back to workspace or default security.';
        }
    }
}
