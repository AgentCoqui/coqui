<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\OpenClawConfig;
use CarmeloSantana\PHPAgents\Context\HeuristicCounter;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Provider\Response;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Agent\OrchestratorAgent;
use CoquiBot\Coqui\Agent\OrchestratorDependencies;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\CompositeToolkitProvider;
use CoquiBot\Coqui\Config\ProfilePreferences;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Contract\MountDefinition;
use CoquiBot\Coqui\Contract\ToolkitLoadingKeyProvider;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;
use CoquiBot\Coqui\Memory\MemoryEntry;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Tests\Support\Agent\CompositeBudgetTestToolkit;
use CoquiBot\Coqui\Tool\StubTool;

beforeEach(function () {
    $this->workspace = sys_get_temp_dir() . '/coqui-agent-test-' . bin2hex(random_bytes(4));
    mkdir($this->workspace, 0755, true);

    // CredentialResolver reads this file
    file_put_contents($this->workspace . '/.env', '');

    $this->projectRoot = dirname(__DIR__, 3);

    $this->config = OpenClawConfig::fromArray([
        'agents' => [
            'defaults' => [
                'model' => ['primary' => 'ollama/qwen3:latest'],
                'roles' => ['coder' => 'ollama/qwen3:latest'],
            ],
        ],
    ]);

    $this->roleResolver = new RoleResolver($this->config);

    // Minimal provider stub — never called during construction
    $this->provider = new class implements ProviderInterface {
        public function chat(array $messages, array $tools = [], array $options = []): Response
        {
            throw new \RuntimeException('Not implemented');
        }

        public function stream(array $messages, array $tools = [], array $options = []): iterable
        {
            throw new \RuntimeException('Not implemented');
        }

        public function structured(array $messages, string $schema, array $options = []): mixed
        {
            throw new \RuntimeException('Not implemented');
        }

        public function models(): array
        {
            return [];
        }

        public function isAvailable(): bool
        {
            return false;
        }

        public function getModel(): string
        {
            return 'test/mock';
        }

        public function withModel(string $model): static
        {
            return $this;
        }
    };
});

afterEach(function () {
    cleanupTestTree($this->workspace);
});

test('constructs successfully without MountManager', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);
});

test('constructs successfully with empty MountManager', function () {
    $mountManager = new MountManager($this->workspace);

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            mountManager: $mountManager,
        ),
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);
});

test('constructs successfully with MountManager and real mounts', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-agent-mount-' . bin2hex(random_bytes(4));
    mkdir($mountDir, 0755, true);

    $mountManager = new MountManager($this->workspace, [
        new MountDefinition($mountDir, 'external', 'rw', 'Test mount'),
    ]);

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            mountManager: $mountManager,
        ),
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);

    rmdir($mountDir);
});

test('constructs with null mountManager using null-safe allowedPaths', function () {
    // This specifically tests the line: allowedPaths: $this->mountManager?->allowedPaths() ?? []
    // where mountManager is null — the ?-> should return null, ?? coalesces to []
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            mountManager: null,
        ),
    );

    expect($agent)->toBeInstanceOf(OrchestratorAgent::class);
});

test('tools returns expected standalone tools', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $toolNames = array_map(fn($t) => $t->name(), $agent->tools());

    expect($toolNames)->toContain('spawn_agent');
    expect($toolNames)->toContain('credentials');
    expect($toolNames)->toContain('package_info');
    expect($toolNames)->toContain('php_execute');
});

test('tools excludes restart_coqui when no onRestart callback', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $toolNames = array_map(fn($t) => $t->name(), $agent->tools());

    expect($toolNames)->not->toContain('restart_coqui');
});

test('tools includes restart_coqui when onRestart callback provided', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            onRestart: fn() => null,
        ),
    );

    $toolNames = array_map(fn($t) => $t->name(), $agent->tools());

    expect($toolNames)->toContain('restart_coqui');
});

test('composite toolkits expand child toolkits with independent loading keys', function () {
    file_put_contents(
        $this->workspace . '/toolkits.json',
        json_encode([
            'vendor/composite-budget-toolkit' => [CompositeBudgetTestToolkit::class],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
    );

    $loadingRegistry = new ToolkitLoadingRegistry($this->workspace);
    $loadingRegistry->setMode('CompositeChild:alpha', ToolkitLoadingMode::Deferred);
    $loadingRegistry->setMode('CompositeChild:beta', ToolkitLoadingMode::Eager);

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->workspace,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            discovery: new ToolkitDiscovery(projectRoot: $this->workspace, workspacePath: $this->workspace),
            loadingRegistry: $loadingRegistry,
        ),
    );

    $appliedModes = $agent->getAppliedLoadingModes();
    $deferredNames = array_column($agent->getDeferredToolkitInfo(), 'name');
    $decisionsByName = [];
    foreach ($agent->getToolkitLoadingDecisions() as $decision) {
        $decisionsByName[$decision['name']] = $decision;
    }

    expect($appliedModes['CompositeChild:alpha'])->toBe(ToolkitLoadingMode::Deferred);
    expect($appliedModes['CompositeChild:beta'])->toBe(ToolkitLoadingMode::Eager);
    expect($deferredNames)->toContain('CompositeChild:alpha');
    expect($decisionsByName['CompositeChild:alpha']['reason'])->toBe('explicit_deferred');
    expect($decisionsByName['CompositeChild:beta']['reason'])->toBe('explicit_eager');
});

test('instructions returns non-empty string', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $instructions = $agent->instructions();

    expect($instructions)->toBeString();
    expect($instructions)->not->toBeEmpty();
});

test('orchestrator instructions include soul from workspace prompts override', function () {
    mkdir($this->workspace . '/prompts', 0755, true);
    file_put_contents($this->workspace . '/prompts/soul.md', '# Workspace Soul' . "\n\nStay grounded.");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('# Workspace Soul');
    expect($instructions)->toContain('Stay grounded.');

    unlink($this->workspace . '/prompts/soul.md');
    rmdir($this->workspace . '/prompts');
});

test('instructions include mount storage map when mounts exist', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-agent-mount-' . bin2hex(random_bytes(4));
    mkdir($mountDir, 0755, true);

    $mountManager = new MountManager($this->workspace, [
        new MountDefinition($mountDir, 'datasets', 'ro', 'Training data'),
    ]);

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            mountManager: $mountManager,
        ),
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('datasets');

    rmdir($mountDir);
});

test('instructions include pending notifications section when set', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $agent->setNotificationPromptSection("[PENDING NOTIFICATIONS]\n\n1. [task.completed] Build finished");

    $instructions = $agent->instructions();

    expect($instructions)->toContain('[PENDING NOTIFICATIONS]');
    expect($instructions)->toContain('Build finished');
});

test('prompt section breakdown includes pending notifications when set', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $agent->setNotificationPromptSection("[PENDING NOTIFICATIONS]\n\n1. [task.completed] Build finished");

    $breakdown = $agent->getPromptSectionBreakdown(new HeuristicCounter());
    $ids = array_column($breakdown, 'id');

    expect($ids)->toContain('context.pending-notifications');
});

test('system prompt appends conversation history section when set', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $agent->setConversationHistoryPromptSection("## Conversation History\n\n- [5m] user [full] Earlier question");

    $prompt = $agent->getSystemPromptText();

    expect($prompt)->toContain('## Conversation History');
    expect($prompt)->toContain('Earlier question');
});

test('prompt section breakdown includes conversation history when set', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    $agent->setConversationHistoryPromptSection("## Conversation History\n\n- [5m] user [full] Earlier question");

    $breakdown = $agent->getPromptSectionBreakdown(new HeuristicCounter());
    $ids = array_column($breakdown, 'id');

    expect($ids)->toContain('context.conversation-history');
});

test('instructions include profile preferences and scoped core memories', function () {
    $profilePath = $this->workspace . '/profiles/caelum';
    mkdir($profilePath, 0755, true);
    file_put_contents($profilePath . '/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($profilePath . '/backstory.md', '# Origin' . "\n\nBorn from continuity.");

    $preferencesPath = $profilePath . '/preferences.json';
    file_put_contents($preferencesPath, json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
        ],
    ], JSON_THROW_ON_ERROR));

    $memoryDbPath = sys_get_temp_dir() . '/coqui-agent-memory-' . bin2hex(random_bytes(4)) . '.db';
    $memoryStore = new MemoryStore($memoryDbPath);
    $memoryStore->save(new MemoryEntry(content: 'Caelum memory', area: 'identity', metadata: ['importance' => 0.95], profileId: 'caelum'));
    $memoryStore->save(new MemoryEntry(content: 'Other memory', area: 'identity', metadata: ['importance' => 0.95], profileId: 'other'));

    try {
        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            deps: new OrchestratorDependencies(
                memoryStore: $memoryStore,
                memorySummarizer: new MemorySummarizer($memoryStore),
                activeProfile: 'caelum',
                activeProfilePath: $profilePath,
                profilePreferences: ProfilePreferences::fromFile($preferencesPath),
            ),
        );

        $instructions = $agent->instructions();

        expect($instructions)->toContain('## Preferences');
        expect($instructions)->toContain('Warm and curious');
        expect($instructions)->toContain('Caelum memory');
        expect($instructions)->not->toContain('Other memory');
    } finally {
        cleanupSqliteTestDb($memoryDbPath);
    }
});

test('role prompt section breakdown includes profile identity backstory and preferences', function () {
    $profilePath = $this->workspace . '/profiles/caelum';
    mkdir($profilePath, 0755, true);
    file_put_contents($profilePath . '/soul.md', '# Caelum' . "\n\nA calm companion.");
    file_put_contents($profilePath . '/backstory.md', '# Origin' . "\n\nBorn from continuity.");

    $preferencesPath = $profilePath . '/preferences.json';
    file_put_contents($preferencesPath, json_encode([
        'prompt_directives' => [
            'Tone' => 'Warm and curious',
        ],
    ], JSON_THROW_ON_ERROR));

    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/coder.md', "---\nname: coder\ndisplay_name: Coder\ndescription: Writes code\naccess_level: full\n---\nYou write excellent code.");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: new RoleDiscovery($this->workspace, $this->projectRoot),
            activeRole: 'coder',
            activeProfile: 'caelum',
            activeProfilePath: $profilePath,
            profilePreferences: ProfilePreferences::fromFile($preferencesPath),
        ),
    );

    $breakdown = $agent->getPromptSectionBreakdown(new HeuristicCounter());
    $ids = array_column($breakdown, 'id');

    expect($ids)->toContain('prompt.soul');
    expect($ids)->toContain('prompt.backstory');
    expect($ids)->toContain('prompt.preferences');
    expect($ids)->toContain('role.coder');
});

test('getSpawnTool returns SpawnAgentTool', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    expect($agent->getSpawnTool()->name())->toBe('spawn_agent');
});

test('getActiveRole returns null by default', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
    );

    expect($agent->getActiveRole())->toBeNull();
});

test('getActiveRole returns the configured role', function () {
    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            activeRole: 'coder',
        ),
    );

    expect($agent->getActiveRole())->toBe('coder');
});

test('activeRole with readonly access has fewer tools than full access', function () {
    // Create a role discovery with a readonly role
    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/reviewer.md', "---\nname: reviewer\ndisplay_name: Reviewer\ndescription: Code reviewer\naccess_level: readonly\n---\nYou review code.");

    $roleDiscovery = new CoquiBot\Coqui\Config\RoleDiscovery(
        workspacePath: $this->workspace,
    );

    $fullAgent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: $roleDiscovery,
        ),
    );

    $readonlyAgent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: $roleDiscovery,
            activeRole: 'reviewer',
        ),
    );

    // Readonly should have fewer tools (no ShellToolkit = no exec)
    expect($readonlyAgent->getToolCount())->toBeLessThan($fullAgent->getToolCount());

    unlink($rolesDir . '/reviewer.md');
    rmdir($rolesDir);
});

test('activeRole with minimal access has fewest tools', function () {
    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/minimal-bot.md', "---\nname: minimal-bot\ndisplay_name: Minimal Bot\ndescription: Minimal access\naccess_level: minimal\n---\nYou are minimal.");
    file_put_contents($rolesDir . '/reviewer.md', "---\nname: reviewer\ndisplay_name: Reviewer\ndescription: Code reviewer\naccess_level: readonly\n---\nYou review code.");

    $roleDiscovery = new CoquiBot\Coqui\Config\RoleDiscovery(
        workspacePath: $this->workspace,
    );

    $readonlyAgent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: $roleDiscovery,
            activeRole: 'reviewer',
        ),
    );

    $minimalAgent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: $roleDiscovery,
            activeRole: 'minimal-bot',
        ),
    );

    // Minimal should have fewer tools than readonly (no filesystem either)
    expect($minimalAgent->getToolCount())->toBeLessThan($readonlyAgent->getToolCount());

    unlink($rolesDir . '/minimal-bot.md');
    unlink($rolesDir . '/reviewer.md');
    rmdir($rolesDir);
});

test('activeRole instructions uses role markdown when role exists', function () {
    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/test-role.md', "---\nname: test-role\ndisplay_name: Test Role\ndescription: A test role\naccess_level: full\n---\nYou are a specialized test assistant.");
    mkdir($this->workspace . '/prompts', 0755, true);
    file_put_contents($this->workspace . '/prompts/soul.md', '# Workspace Soul');

    $roleDiscovery = new CoquiBot\Coqui\Config\RoleDiscovery(
        workspacePath: $this->workspace,
    );

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: $roleDiscovery,
            activeRole: 'test-role',
        ),
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('You are a specialized test assistant.');
    expect($instructions)->not->toContain('# Workspace Soul');
    expect($instructions)->not->toContain('You are Coqui');

    unlink($rolesDir . '/test-role.md');
    rmdir($rolesDir);
    unlink($this->workspace . '/prompts/soul.md');
    rmdir($this->workspace . '/prompts');
});

test('profile policy can disable project toolkits and stub non-core standalone tools', function () {
    $dbPath = sys_get_temp_dir() . '/coqui-agent-policy-' . bin2hex(random_bytes(4)) . '.db';
    $storage = new SessionStorage($dbPath);
    $projectStore = new ProjectStore($storage->getPdo());
    $sessionId = $storage->createSession('orchestrator', 'ollama/qwen3:latest');

    try {
        $agent = new OrchestratorAgent(
            provider: $this->provider,
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspace,
            deps: new OrchestratorDependencies(
                storage: $storage,
                sessionId: $sessionId,
                projectStore: $projectStore,
                profilePreferences: ProfilePreferences::fromArray([
                    'prompts' => [
                        'features' => [
                            'artifacts' => false,
                            'projects' => false,
                            'loops' => false,
                        ],
                        'prompt_sections' => [
                            'tools' => 'stub',
                        ],
                    ],
                ]),
            ),
        );

        $toolNames = array_map(static fn($tool) => $tool->name(), array_slice($agent->tools(), 2));
        $stubbedTools = array_filter(array_slice($agent->tools(), 2), static fn($tool) => $tool instanceof StubTool);
        $breakdownClasses = array_map(
            static fn(array $entry): string => $entry['class'],
            $agent->getToolkitTokenBreakdown(new HeuristicCounter()),
        );
        $policy = $agent->getProfilePolicySummary();

        expect($toolNames)->toContain('spawn_agent');
        expect($stubbedTools)->not->toBeEmpty();
        expect($breakdownClasses)->not->toContain('CoquiBot\\Coqui\\Toolkit\\ArtifactToolkit');
        expect($breakdownClasses)->not->toContain('CoquiBot\\Coqui\\Toolkit\\ProjectToolkit');
        expect($policy)->not->toBeNull();
        expect($policy['tools_stubbed'])->toBeTrue();
        expect($policy['excluded_tool_prompt_slugs'])->toContain('artifacts');
        expect($policy['excluded_tool_prompt_slugs'])->toContain('projects');
        expect($policy['excluded_tool_prompt_slugs'])->toContain('loops');
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});

// --- Profile soul loading ---

test('profile soul.md replaces default soul in orchestrator instructions', function () {
    $profileDir = $this->workspace . '/profiles/test-persona';
    mkdir($profileDir, 0755, true);
    file_put_contents($profileDir . '/soul.md', "# Test Persona\n\nBond glyph: ∞\n\nYou are Test Persona: calm and precise.");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            activeProfile: 'test-persona',
            activeProfilePath: $profileDir,
        ),
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('# Test Persona');
    expect($instructions)->toContain('Bond glyph: ∞');
    expect($instructions)->toContain('You are Test Persona: calm and precise.');
});

test('profile soul.md overrides workspace soul.md', function () {
    // Set up workspace soul
    mkdir($this->workspace . '/prompts', 0755, true);
    file_put_contents($this->workspace . '/prompts/soul.md', '# Workspace Soul' . "\n\nDefault workspace identity.");

    // Set up profile soul
    $profileDir = $this->workspace . '/profiles/custom';
    mkdir($profileDir, 0755, true);
    file_put_contents($profileDir . '/soul.md', "# Custom Profile\n\nBond glyph: \$\n\nYou are Custom.");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            activeProfile: 'custom',
            activeProfilePath: $profileDir,
        ),
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('# Custom Profile');
    expect($instructions)->toContain('You are Custom.');
    expect($instructions)->not->toContain('# Workspace Soul');
    expect($instructions)->not->toContain('Default workspace identity.');
});

test('profile identity preamble prepended to role instructions', function () {
    // Set up profile
    $profileDir = $this->workspace . '/profiles/persona';
    mkdir($profileDir, 0755, true);
    file_put_contents($profileDir . '/soul.md', "# Persona\n\nBond glyph: \$\n\nYou are Persona.");

    // Set up role
    $rolesDir = $this->workspace . '/roles';
    mkdir($rolesDir, 0755, true);
    file_put_contents($rolesDir . '/coder.md', "---\nname: coder\ndisplay_name: Coder\ndescription: Writes code\naccess_level: full\n---\nYou write excellent code.");

    $roleDiscovery = new CoquiBot\Coqui\Config\RoleDiscovery(
        workspacePath: $this->workspace,
    );

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            roleDiscovery: $roleDiscovery,
            activeRole: 'coder',
            activeProfile: 'persona',
            activeProfilePath: $profileDir,
        ),
    );

    $instructions = $agent->instructions();

    // Both profile identity preamble AND role instructions should be present
    expect($instructions)->toContain('You are Persona.');
    expect($instructions)->toContain('You write excellent code.');

    // Profile preamble should appear before role instructions
    $preamblePos = strpos($instructions, 'You are Persona.');
    $rolePos = strpos($instructions, 'You write excellent code.');
    expect($preamblePos)->toBeLessThan($rolePos);
});

test('profile soul frontmatter is stripped from instructions', function () {
    $profileDir = $this->workspace . '/profiles/frontmatter-test';
    mkdir($profileDir, 0755, true);
    file_put_contents($profileDir . '/soul.md', "---\nmodel: anthropic/claude-sonnet-4-20250514\n---\n# Frontmatter Profile\n\nYou have personality.");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            activeProfile: 'frontmatter-test',
            activeProfilePath: $profileDir,
        ),
    );

    $instructions = $agent->instructions();

    expect($instructions)->toContain('# Frontmatter Profile');
    expect($instructions)->toContain('You have personality.');
    expect($instructions)->not->toContain('model: anthropic/claude-sonnet-4-20250514');
});

test('getSystemPromptText includes profile soul content', function () {
    $profileDir = $this->workspace . '/profiles/system-test';
    mkdir($profileDir, 0755, true);
    file_put_contents($profileDir . '/soul.md', "# System Test Profile\n\nBond glyph: 𑁍\n\nYou are the system test profile.");

    $agent = new OrchestratorAgent(
        provider: $this->provider,
        roleResolver: $this->roleResolver,
        config: $this->config,
        projectRoot: $this->projectRoot,
        workspacePath: $this->workspace,
        deps: new OrchestratorDependencies(
            activeProfile: 'system-test',
            activeProfilePath: $profileDir,
        ),
    );

    $systemPromptText = $agent->getSystemPromptText();

    expect($systemPromptText)->toContain('# System Test Profile');
    expect($systemPromptText)->toContain('Bond glyph: 𑁍');
    expect($systemPromptText)->toContain('You are the system test profile.');
});
