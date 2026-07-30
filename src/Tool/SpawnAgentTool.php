<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Toolkit\FileSystemToolkit;
use CoquiBot\Coqui\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Toolkit\WebToolkit;
use CoquiBot\Coqui\Agent\ChildAgent;
use CoquiBot\Coqui\Agent\CodeReviewCycle;
use CoquiBot\Coqui\Agent\VisionAnalyzer;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\PersonaPreferences;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleToolkitResolver;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\ShellConfigResolver;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Contract\ChildAgentHandoff;
use CoquiBot\Coqui\Contract\SystemRole;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Contract\ToolkitVisibility;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Storage\ArtifactFileService;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SkillLifecycleStore;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;
use CoquiBot\Coqui\Toolkit\CoquiDocsToolkit;
use CoquiBot\Coqui\Toolkit\SkillToolkit;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
use SplObserver;

/**
 * Tool that spawns a child agent with a different model for specialized tasks.
 *
 * The orchestrator uses this to delegate work to Claude (coder), GPT-4 (reviewer), etc.
 */
final class SpawnAgentTool implements ToolInterface
{
    private int $childRunCount = 0;
    private ?VisionAnalyzer $visionAnalyzer = null;

    /**
     * @param array<string> $shellAllowedCommands
     * @param array<string> $shellDeniedCommands
     */
    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?SplObserver $observer = null,
        private readonly ?MountManager $mountManager = null,
        private readonly array $shellAllowedCommands = [],
        private readonly ?ProjectStore $projectStore = null,
        private readonly ?ToolkitDiscovery $discovery = null,
        private readonly ?MemoryStore $memoryStore = null,
        private readonly ?SkillDiscovery $skillDiscovery = null,
        private readonly ?ScriptSanitizer $sanitizer = null,
        private readonly ?ToolkitVisibilityRegistry $visibilityRegistry = null,
        private readonly array $shellDeniedCommands = ['sudo'],
        private readonly bool $unsafeMode = false,
        private readonly ?ToolExecutorInterface $toolExecutor = null,
        private readonly ?ProviderFactory $providerFactory = null,
        private readonly ?string $profileIdentityPreamble = null,
        private readonly ?string $activeProfile = null,
        private readonly ?string $activeProfilePath = null,
    ) {}

    public function name(): string
    {
        return 'spawn_agent';
    }

    public function description(): string
    {
        $roles = $this->describeSelectableRoles();

        return <<<DESC
            Spawn a specialized child agent to handle a specific task.

            Use this when a task requires expertise or capabilities better suited to a different model.
            For example, spawn a 'coder' agent to write complex code, or a 'reviewer' agent to analyze code quality.

            Available roles (grouped by category):
            {$roles}

            The child agent will run independently and return its result.
            DESC;
    }

    /**
     * Build a compact, category-grouped listing of selectable roles with their
     * descriptions so the model can pick the right one without a separate lookup.
     *
     * Falls back to a bare comma list of names when role metadata is unavailable.
     */
    private function describeSelectableRoles(): string
    {
        $names = $this->selectableRolesForProfile();

        if ($this->roleDiscovery === null) {
            return implode(', ', $names);
        }

        /** @var array<string, list<string>> $byCategory */
        $byCategory = [];
        foreach ($names as $name) {
            try {
                $props = $this->roleDiscovery->getRole($name, $this->activeProfilePath);
            } catch (\Throwable) {
                $byCategory['general'][] = "- {$name}";
                continue;
            }

            $description = trim($props->description);
            $byCategory[$props->category][] = $description !== ''
                ? "- {$name}: {$description}"
                : "- {$name}";
        }

        if ($byCategory === []) {
            return implode(', ', $names);
        }

        ksort($byCategory);

        $lines = [];
        foreach ($byCategory as $category => $entries) {
            $lines[] = "{$category}:";
            foreach ($entries as $entry) {
                $lines[] = "  {$entry}";
            }
        }

        return implode("\n", $lines);
    }

    public function parameters(): array
    {
        $roles = $this->selectableRolesForProfile();

        return [
            new EnumParameter(
                name: 'role',
                description: 'The role/specialty of the child agent to spawn',
                values: !empty($roles) ? $roles : [SystemRole::Coder->value, SystemRole::Reviewer->value],
                required: true,
            ),
            new StringParameter(
                name: 'task',
                description: 'A detailed description of the task for the child agent to complete',
                required: true,
            ),
            new StringParameter(
                name: 'context',
                description: 'Optional context: file contents, prior results, or other relevant information',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $role = $input['role'] ?? '';
        $task = $input['task'] ?? '';
        $context = $input['context'] ?? '';

        if ($role === '' || $task === '') {
            return ToolResult::error('Both role and task are required');
        }

        $preferences = $this->profilePreferences();
        if ($preferences !== null && !$preferences->isRoleAllowed($role)) {
            return ToolResult::error(sprintf('Profile "%s" does not allow role "%s".', $this->activeProfile, $role));
        }

        $handoff = ChildAgentHandoff::fromInput(
            task: $task,
            context: $context,
            metadata: [
                'source' => 'spawn_agent',
                'role' => $role,
            ],
            intent: 'delegated_task',
            workflowPhase: 'delegation',
            parentSessionId: $this->sessionId,
        );

        // Resolve role to model
        $modelString = $this->roleResolver->resolve($role, $this->activeProfile);

        // Notify observer about child spawn
        $this->notifyObserver('child.start', ['role' => $role, 'model' => $modelString]);

        try {
            // Create provider for child agent
            $factory = $this->providerFactory ?? new ProviderFactory($this->config);
            $provider = $factory->create($modelString);

            // Build toolkits based on role
            $toolkits = $this->buildToolkits($role);

            // Create and run child agent
            $child = new ChildAgent(
                provider: $provider,
                role: $role,
                taskInstructions: $handoff,
                toolkits: $toolkits,
                maxIterations: $this->roleResolver->resolveMaxIterations($role, $this->activeProfile),
                roleDiscovery: $this->roleDiscovery,
                toolExecutor: $this->toolExecutor,
                profileIdentityPreamble: $this->profileIdentityPreamble,
                activeProfilePath: $this->activeProfilePath,
            );

            // Attach observer if available
            if ($this->observer !== null) {
                $child->attach($this->observer);
            }

            // Build prompt with optional context
            $prompt = $handoff->userPrompt();

            $output = $child->run(new UserMessage($prompt));

            // Log child run to storage
            if ($this->storage !== null && $this->sessionId !== null) {
                $this->storage->logChildRun(
                    sessionId: $this->sessionId,
                    parentIteration: 0,
                    agentRole: $role,
                    model: $modelString,
                    prompt: $prompt,
                    result: $output->content,
                    tokenCount: $output->usage !== null ? $output->usage->totalTokens : 0,
                    metadata: $handoff->toArray(),
                );
            }

            // Notify observer about child completion
            $this->notifyObserver('child.end', null);

            $this->childRunCount++;

            // Run automated code review cycle for coder agents
            if ($this->shouldAutoReview($role)) {
                $reviewResult = $this->runCodeReviewCycle(
                    coderOutput: $output->content,
                    originalTask: $prompt,
                    coderRole: $role,
                );

                if ($reviewResult !== null) {
                    $summary = $reviewResult->buildSummary();

                    return ToolResult::success($reviewResult->finalContent . "\n\n" . $summary);
                }
            }

            return ToolResult::success($output->content);
        } catch (\Throwable $e) {
            $this->notifyObserver('child.end', null);

            return ToolResult::error("Child agent failed: {$e->getMessage()}");
        }
    }

    /**
     * @return \CarmeloSantana\PHPAgents\Contract\ToolkitInterface[]
     */
    private function buildToolkits(string $role): array
    {
        $accessLevel = $this->resolveAccessLevel($role);

        // Full-access children get the same mount permissions as the orchestrator.
        // Non-full access levels are forced to read-only mount access.
        $mountPaths = match ($accessLevel) {
            'full' => $this->mountManager?->allowedPaths() ?? [],
            default => $this->mountManager?->allowedPathsReadOnly() ?? [],
        };

        $toolkits = match ($accessLevel) {
            'full' => [
                new FileSystemToolkit(workspacePath: $this->workspacePath, allowedPaths: $mountPaths),
                new ShellToolkit(
                    workDir: $this->workspacePath,
                    allowedCommands: $this->shellAllowedCommands,
                    deniedCommands: $this->shellDeniedCommands,
                    timeout: CoquiDefaults::SHELL_TIMEOUT_SECONDS,
                    unsafe: $this->unsafeMode,
                    rootPath: $this->workspacePath,
                    allowedPaths: $mountPaths,
                    sandboxWrites: ShellConfigResolver::resolveSandboxWrites($this->config),
                    scrubEnvironment: ShellConfigResolver::resolveScrubEnvironment($this->config),
                ),
                new WebToolkit(
                    storage: $this->storage,
                    parentSessionId: $this->sessionId,
                    workspacePath: $this->workspacePath,
                ),
                new CoquiDocsToolkit(projectRoot: $this->projectRoot),
            ],

            'readonly-shell' => [
                new FileSystemToolkit(workspacePath: $this->workspacePath, readOnly: true, allowedPaths: $mountPaths),
                new ShellToolkit(
                    workDir: $this->workspacePath,
                    allowedCommands: ShellConfigResolver::READ_ONLY_SHELL_COMMANDS,
                    timeout: CoquiDefaults::SHELL_TIMEOUT_SECONDS,
                    rootPath: $this->workspacePath,
                    allowedPaths: $mountPaths,
                    sandboxWrites: ShellConfigResolver::resolveSandboxWrites($this->config),
                    scrubEnvironment: ShellConfigResolver::resolveScrubEnvironment($this->config),
                ),
                new CoquiDocsToolkit(projectRoot: $this->projectRoot),
            ],

            'readonly' => [
                new FileSystemToolkit(workspacePath: $this->workspacePath, readOnly: true, allowedPaths: $mountPaths),
                new CoquiDocsToolkit(projectRoot: $this->projectRoot),
            ],

            // 'minimal' — no toolkits
            default => [],
        };

        // Memory toolkit — gives child agents access to persistent cross-session memory.
        if ($this->memoryStore !== null && $accessLevel === 'full') {
            $toolkits[] = new MemoryToolkit($this->memoryStore, $this->workspacePath, $this->activeProfile);
        }

        // Skill toolkit — gives child agents access to discovered Agent Skills.
        if ($this->skillDiscovery !== null && $accessLevel !== 'minimal') {
            $toolkits[] = new SkillToolkit(
                $this->skillDiscovery,
                $this->storage !== null ? new SkillLifecycleStore($this->storage->getPdo()) : null,
                $this->sessionId,
                null,
                $role,
            );
        }

        // Artifact toolkit — share parent session's artifacts with child agents.
        // Non-full access levels get read-only artifact access (no delete).
        if ($this->storage !== null && $this->sessionId !== null && $this->isFeatureEnabled('artifacts')) {
            $artifactStore = new ArtifactStore(
                $this->storage->getPdo(),
                new ArtifactFileService($this->workspacePath),
            );

            $toolkits[] = new ArtifactToolkit(
                $artifactStore,
                $this->sessionId,
                readOnly: $accessLevel !== 'full',
                createdBy: $role,
            );

            // Memory-on-promotion: a child that can create/update artifacts must
            // also be able to record — and supersede — a durable memory pointer to
            // a canonical one. Non-full children still get a create-capable artifact
            // toolkit (readOnly only withholds delete), but did not receive the
            // MemoryToolkit above (that is full-access only), so wire it in here.
            if ($this->memoryStore !== null && $accessLevel !== 'full') {
                $toolkits[] = new MemoryToolkit($this->memoryStore, $this->workspacePath, $this->activeProfile);
            }
        }

        // Project toolkit — lightweight project (working-directory) management shared with child agents.
        if ($this->projectStore !== null && $this->storage !== null && $this->isFeatureEnabled('projects')) {
            $toolkits[] = new \CoquiBot\Coqui\Toolkit\ProjectToolkit(
                $this->projectStore,
                $this->sessionId,
                $this->workspacePath,
                null,
                $this->storage,
            );
        }

        // PHP execution tool — available to full-access child agents.
        if ($accessLevel === 'full' && $this->sanitizer !== null) {
            $toolkits[] = new \CoquiBot\Coqui\Toolkit\SingleToolToolkit(
                new PhpExecuteTool(
                    projectRoot: $this->projectRoot,
                    workspacePath: $this->workspacePath,
                    sanitizer: $this->sanitizer,
                    mountManager: $this->mountManager,
                ),
            );
        }

        // Vision tool — available to full-access child agents.
        if ($accessLevel === 'full' && $this->visionAnalyzer !== null) {
            $toolkits[] = new \CoquiBot\Coqui\Toolkit\SingleToolToolkit(
                new VisionTool(analyzer: $this->visionAnalyzer),
            );
        }

        // Auto-discovered toolkits from installed packages.
        // Uses instantiateRegisteredGrouped() to get package names for visibility filtering.
        // Child agents get child-mode credential guards (error messages say "report to parent"
        // instead of suggesting the non-existent credentials tool).
        if ($this->discovery !== null && $accessLevel !== 'minimal') {
            foreach ($this->discovery->instantiateRegisteredGrouped(
                childMode: true,
                context: [
                    'config' => $this->config,
                    'activeProfile' => $this->activeProfile,
                    'sessionId' => $this->sessionId,
                ],
            ) as $entry) {
                $toolkit = $entry['toolkit'];

                // Apply per-package visibility from toolkit-visibility.json.
                // Disabled packages are already skipped by instantiateRegisteredGrouped().
                // Stub packages are treated as Enabled for children because children lack
                // tool_search — stub schemas would instruct the LLM to call a non-existent tool.
                if ($this->visibilityRegistry !== null) {
                    $vis = $this->visibilityRegistry->getPackageVisibility($entry['package']);
                    if ($vis === ToolkitVisibility::Disabled) {
                        continue;
                    }
                    // Stub → Enabled: children don't have tool_search, so stub is useless
                }

                $toolkits[] = $toolkit;
            }
        }

        // Apply role-based toolkit filtering from the child role's frontmatter
        $resolver = $this->buildChildRoleResolver($role);
        if ($resolver->hasRules()) {
            $toolkits = array_values(array_filter(
                $toolkits,
                static fn($toolkit) => $resolver->isToolkitAllowed($toolkit::class),
            ));
        }

        return $toolkits;
    }

    /**
     * Resolve access_level from RoleProperties, falling back to hardcoded defaults.
     */
    private function resolveAccessLevel(string $role): string
    {
        if ($this->roleDiscovery !== null) {
            try {
                $properties = $this->roleDiscovery->getRole($role, $this->activeProfilePath);
                return $properties->accessLevel;
            } catch (\Throwable) {
                // Fall through to hardcoded defaults
            }
        }

        // Backward-compatible fallback
        return match ($role) {
            SystemRole::Coder->value => 'full',
            default => 'readonly',
        };
    }

    /**
     * Build a RoleToolkitResolver for a child role's frontmatter.
     */
    private function buildChildRoleResolver(string $role): RoleToolkitResolver
    {
        if ($this->roleDiscovery === null) {
            return new RoleToolkitResolver(null);
        }

        try {
            $properties = $this->roleDiscovery->getRole($role, $this->activeProfilePath);

            return new RoleToolkitResolver($properties->toolkits);
        } catch (\Throwable) {
            return new RoleToolkitResolver(null);
        }
    }

    public function setVisionAnalyzer(VisionAnalyzer $analyzer): void
    {
        $this->visionAnalyzer = $analyzer;
    }

    public function getChildRunCount(): int
    {
        return $this->childRunCount;
    }

    private function notifyObserver(string $event, mixed $payload): void
    {
        if (!is_object($this->observer) || !method_exists($this->observer, 'handleEvent')) {
            return;
        }

        call_user_func([$this->observer, 'handleEvent'], $event, $payload);
    }

    /**
     * @return list<string>
     */
    private function selectableRolesForProfile(): array
    {
        $roles = array_values($this->roleResolver->selectableRoles());
        $preferences = $this->profilePreferences();

        return $preferences?->filterAllowedRoles($roles) ?? $roles;
    }

    private function isFeatureEnabled(string $feature, bool $default = true): bool
    {
        return $this->profilePreferences()?->isFeatureEnabled($feature, $default) ?? $default;
    }

    private function profilePreferences(): ?PersonaPreferences
    {
        if ($this->activeProfilePath === null) {
            return null;
        }

        return PersonaPreferences::fromProfilePath($this->activeProfilePath);
    }

    /**
     * Determine if the role should receive automated code review.
     */
    private function shouldAutoReview(string $role): bool
    {
        // Check global config
        if ($this->config instanceof \CoquiBot\Coqui\Config\OpenClawConfig) {
            $reviewConfig = $this->config->getCodeReviewConfig();
            if (!$reviewConfig['enabled']) {
                return false;
            }
        }

        // Check role-level auto_review flag
        if ($this->roleDiscovery !== null) {
            try {
                $properties = $this->roleDiscovery->getRole($role, $this->activeProfilePath);
                return $properties->autoReview;
            } catch (\Throwable) {
                // Fall through
            }
        }

        // Default: only coder role
        return $role === SystemRole::Coder->value;
    }

    /**
     * Run the code review cycle against a coder's output.
     */
    private function runCodeReviewCycle(
        string $coderOutput,
        string $originalTask,
        string $coderRole,
    ): ?\CoquiBot\Coqui\Contract\CodeReviewResult {
        try {
            $reviewConfig = $this->config instanceof \CoquiBot\Coqui\Config\OpenClawConfig
                ? $this->config->getCodeReviewConfig()
                : ['maxRounds' => CoquiDefaults::CODE_REVIEW_MAX_ROUNDS, 'autoIterate' => CoquiDefaults::CODE_REVIEW_AUTO_ITERATE];

            $cycle = new CodeReviewCycle(
                roleResolver: $this->roleResolver,
                config: $this->config,
                roleDiscovery: $this->roleDiscovery,
                observer: $this->observer,
                toolExecutor: $this->toolExecutor,
                providerFactory: $this->providerFactory,
                activeProfile: $this->activeProfile,
                activeProfilePath: $this->activeProfilePath,
                profileIdentityPreamble: $this->profileIdentityPreamble,
            );

            $reviewerToolkits = $this->buildToolkits(SystemRole::Reviewer->value);
            $coderToolkits = $reviewConfig['autoIterate'] ? $this->buildToolkits($coderRole) : [];

            return $cycle->run(
                coderOutput: $coderOutput,
                originalTask: $originalTask,
                reviewerToolkits: $reviewerToolkits,
                maxRounds: $reviewConfig['maxRounds'],
                coderToolkits: $coderToolkits,
                autoIterate: $reviewConfig['autoIterate'],
                coderMaxIterations: $this->roleResolver->resolveMaxIterations($coderRole, $this->activeProfile),
            );
        } catch (\Throwable) {
            // Review cycle failure should not prevent returning the coder's output
            return null;
        }
    }

    public function toFunctionSchema(): array
    {
        $roles = $this->selectableRolesForProfile();

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'role' => [
                            'type' => 'string',
                            'description' => 'The role/specialty of the child agent',
                            'enum' => !empty($roles) ? $roles : [SystemRole::Coder->value, SystemRole::Reviewer->value],
                        ],
                        'task' => [
                            'type' => 'string',
                            'description' => 'Detailed task description for the child agent',
                        ],
                        'context' => [
                            'type' => 'string',
                            'description' => 'Optional context information',
                        ],
                    ],
                    'required' => ['role', 'task'],
                ],
            ],
        ];
    }
}
