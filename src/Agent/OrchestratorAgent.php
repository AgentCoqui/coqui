<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\CancellationTokenInterface;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\PendingInputProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Memory\MemoryStore;
use CoquiBot\Coqui\Memory\MemorySummarizer;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\BackgroundTaskToolkit;
use CoquiBot\Coqui\Toolkit\MemoryToolkit;
use CoquiBot\Coqui\Toolkit\ProjectSourceToolkit;
use CoquiBot\Coqui\Toolkit\SkillToolkit;
use CoquiBot\Coqui\Toolkit\ToolkitGeneratorToolkit;
use CoquiBot\Coqui\Tool\CredentialTool;
use CoquiBot\Coqui\Tool\PackageInfoTool;
use CoquiBot\Coqui\Tool\PhpExecuteTool;
use CoquiBot\Coqui\Tool\RestartTool;
use CoquiBot\Coqui\Tool\SpawnAgentTool;
use CoquiBot\Coqui\Tool\ToolRegistry;
use CoquiBot\Coqui\Tool\ToolSearchTool;
use CoquiBot\Coqui\Tool\VisionTool;

use SplObserver;

/**
 * The top-level orchestrator agent that receives user input.
 *
 * Runs on a cheap local model (Ollama) and delegates specialized tasks
 * to child agents via the spawn_agent tool.
 *
 * File I/O is sandboxed to the workspace directory. Read access to the
 * project root is available through shell commands (cat, grep, find).
 */
final class OrchestratorAgent extends AbstractAgent
{
    private SpawnAgentTool $spawnTool;
    private CredentialTool $credentialTool;
    private PackageInfoTool $packageInfoTool;
    private PhpExecuteTool $phpExecuteTool;
    private ?RestartTool $restartTool = null;
    private ?VisionTool $visionTool = null;
    private ToolRegistry $toolRegistry;
    private ToolSearchTool $toolSearchTool;

    public function __construct(
        ProviderInterface $provider,
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?SplObserver $observer = null,
        ?ToolkitDiscovery $discovery = null,
        int $maxIterations = AbstractAgent::DEFAULT_MAX_ITERATIONS,
        ?ToolExecutionPolicyInterface $executionPolicy = null,
        private readonly ?ScriptSanitizer $sanitizer = null,
        ?\Closure $onRestart = null,
        ?CredentialResolverInterface $credentialResolver = null,
        private readonly ?SkillDiscovery $skillDiscovery = null,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        ?CancellationTokenInterface $cancellationToken = null,
        ?PendingInputProviderInterface $pendingInputProvider = null,
        ?BackgroundTaskToolkit $backgroundTaskToolkit = null,
        private readonly ?MemoryStore $memoryStore = null,
        private readonly ?MemorySummarizer $memorySummarizer = null,
        private readonly ?MountManager $mountManager = null,
    ) {
        // Initialise the registry before parent::__construct() so that our
        // addToolkit() override can populate it immediately for every toolkit added.
        $this->toolRegistry = new ToolRegistry();

        parent::__construct($provider, $maxIterations, $executionPolicy, $cancellationToken, $pendingInputProvider);

        // Use injected resolver or create one (backward compat for standalone use)
        $credentialResolver ??= new \CoquiBot\Coqui\Config\CredentialResolver(workspacePath: $this->workspacePath);
        $credentialResolver->loadIntoProcessEnv();

        // Filesystem toolkit — sandboxed to workspace (read/write) with optional mount allowlist
        $this->addToolkit(new FilesystemToolkit(
            rootPath: $this->workspacePath,
            allowedPaths: $this->mountManager?->allowedPaths() ?? [],
        ));

        // Shell toolkit — runs in project root for read access
        $shellAllowed = $this->resolveShellAllowedCommands();
        $this->addToolkit(new ShellToolkit(
            workDir: $this->projectRoot,
            allowedCommands: $shellAllowed,
            timeout: 60,
        ));

        // Memory toolkit — SQLite-backed with optional vector search
        if ($this->memoryStore !== null) {
            $this->addToolkit(new MemoryToolkit($this->memoryStore));
        }

        // Project source toolkit — read-only access to the Coqui project codebase
        $this->addToolkit(new ProjectSourceToolkit(projectRoot: $this->projectRoot));

        // Toolkit generator — scaffold new toolkit packages
        $this->addToolkit(new ToolkitGeneratorToolkit(workspacePath: $this->workspacePath));

        // Skill toolkit — discover and use Agent Skills
        if ($this->skillDiscovery !== null) {
            $this->addToolkit(new SkillToolkit($this->skillDiscovery));
        }

        // Register any auto-discovered toolkits from installed packages
        if ($discovery !== null) {
            foreach ($discovery->instantiateRegistered() as $toolkit) {
                $this->addToolkit($toolkit);
            }
        }

        // Create spawn tool with workspace isolation
        $this->spawnTool = new SpawnAgentTool(
            roleResolver: $this->roleResolver,
            config: $this->config,
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            roleDiscovery: $this->roleDiscovery,
            storage: $this->storage,
            sessionId: $this->sessionId,
            observer: $this->observer,
            mountManager: $this->mountManager,
            shellAllowedCommands: $shellAllowed,
        );

        // Create credential tool for API key management
        $this->credentialTool = new CredentialTool(
            resolver: $credentialResolver,
        );

        // Create package info tool for SDK introspection
        $this->packageInfoTool = new PackageInfoTool(
            projectRoot: $this->projectRoot,
        );

        // Create PHP execution tool for running SDK code
        $this->phpExecuteTool = new PhpExecuteTool(
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            sanitizer: $this->sanitizer,
            mountManager: $this->mountManager,
        );

        // Create restart tool if callback provided
        if ($onRestart !== null) {
            $this->restartTool = new RestartTool(onRestart: $onRestart);
        }

        // Create vision tool for image analysis
        $this->visionTool = new VisionTool(
            analyzer: new VisionAnalyzer(
                roleResolver: $this->roleResolver,
                config: $this->config,
                roleDiscovery: $this->roleDiscovery,
                providerFactory: new ProviderFactory($this->config),
            ),
        );

        // Background task toolkit — only in API mode
        if ($backgroundTaskToolkit !== null) {
            $this->addToolkit($backgroundTaskToolkit);
        }

        // Register standalone tools in the registry now that they're all created.
        // Toolkit tools are already registered via addToolkit() override above.
        foreach ([$this->spawnTool, $this->credentialTool, $this->packageInfoTool, $this->phpExecuteTool] as $tool) {
            $this->toolRegistry->register($tool);
        }

        if ($this->visionTool !== null) {
            $this->toolRegistry->register($this->visionTool);
        }

        if ($this->restartTool !== null) {
            $this->toolRegistry->register($this->restartTool);
        }

        // Create the tool search tool — always-loaded, not subject to maxTools cap.
        // Gives the agent on-demand access to the full tool library via BM25 search.
        $this->toolSearchTool = new ToolSearchTool($this->toolRegistry);

        // Apply maxTools cap from config (0 = unlimited).
        // tool_search is placed first in tools() so it is always within the cap.
        $maxToolsCfg = $this->config->get('agents.defaults.maxTools');
        if (is_int($maxToolsCfg) || is_string($maxToolsCfg)) {
            $cap = (int) $maxToolsCfg;
            if ($cap > 0) {
                $this->setMaxTools($cap);
            }
        }
    }

    /**
     * Override addToolkit() to also register every tool in the BM25 registry.
     * This keeps the registry in sync with all toolkits regardless of when they
     * are added (during construction or later for background tasks, etc.).
     */
    #[\Override]
    public function addToolkit(ToolkitInterface $toolkit): static
    {
        foreach ($toolkit->tools() as $tool) {
            $this->toolRegistry->register($tool);
        }

        return parent::addToolkit($toolkit);
    }

    public function instructions(): string
    {
        $roles = implode(', ', $this->roleResolver->availableRoles());
        $skillsSummary = $this->skillDiscovery?->buildPromptSummary() ?? 'No skills installed.';
        $storageMap = $this->mountManager?->storageMap() ?? '';

        $prompt = new OrchestratorPrompt(
            workspacePath: $this->workspacePath,
            projectRoot: $this->projectRoot,
            availableRoles: $roles,
            availableSkills: $skillsSummary,
            storageMap: $storageMap,
        );

        $rendered = $prompt->render();

        // Inject core memory summary if available
        $memorySummary = $this->memorySummarizer?->getSummary();
        if ($memorySummary !== null && $memorySummary !== '') {
            $rendered .= "\n\n# CORE MEMORIES\n\n" . $memorySummary;
        }

        return $rendered;
    }

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        $tools = [
            $this->spawnTool,
            $this->credentialTool,
            $this->packageInfoTool,
            $this->phpExecuteTool,
            // Tool search is always-loaded so the agent can discover the full
            // tool library regardless of the maxTools cap setting.
            $this->toolSearchTool,
        ];

        if ($this->visionTool !== null) {
            $tools[] = $this->visionTool;
        }

        if ($this->restartTool !== null) {
            $tools[] = $this->restartTool;
        }

        return $tools;
    }

    /**
     * @return ModelCapability[]
     */
    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools];
    }

    public function getSpawnTool(): SpawnAgentTool
    {
        return $this->spawnTool;
    }

    /** Default shell commands available to the orchestrator. */
    private const array DEFAULT_SHELL_COMMANDS = [
        'php', 'git', 'grep', 'find', 'cat', 'head', 'tail', 'wc', 'ls',
        'curl', 'wget', 'make', 'sort', 'uniq', 'sed', 'awk', 'diff',
    ];

    /**
     * Resolve shell allowed commands from config or defaults.
     *
     * Reads `agents.defaults.shellAllowedCommands` from openclaw.json.
     * If not set, uses DEFAULT_SHELL_COMMANDS.
     *
     * @return string[]
     */
    private function resolveShellAllowedCommands(): array
    {
        $configured = $this->config->get('agents.defaults.shellAllowedCommands');

        if (is_array($configured) && !empty($configured)) {
            return array_values(array_filter($configured, 'is_string'));
        }

        return self::DEFAULT_SHELL_COMMANDS;
    }
}
