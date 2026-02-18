<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutionPolicyInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Memory\FileMemory;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Toolkit\MemoryToolkit;
use CarmeloSantana\PHPAgents\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Contract\CredentialResolverInterface;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Toolkit\ProjectSourceToolkit;
use CoquiBot\Coqui\Toolkit\SkillToolkit;
use CoquiBot\Coqui\Toolkit\ToolkitGeneratorToolkit;
use CoquiBot\Coqui\Tool\CredentialTool;
use CoquiBot\Coqui\Tool\PackageInfoTool;
use CoquiBot\Coqui\Tool\PhpExecuteTool;
use CoquiBot\Coqui\Tool\RestartTool;
use CoquiBot\Coqui\Tool\SpawnAgentTool;

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
        int $maxIterations = 25,
        ?ToolExecutionPolicyInterface $executionPolicy = null,
        private readonly ?ScriptSanitizer $sanitizer = null,
        ?\Closure $onRestart = null,
        ?CredentialResolverInterface $credentialResolver = null,
        private readonly ?SkillDiscovery $skillDiscovery = null,
        private readonly ?RoleDiscovery $roleDiscovery = null,
    ) {
        parent::__construct($provider, $maxIterations, $executionPolicy);

        // Use injected resolver or create one (backward compat for standalone use)
        $credentialResolver ??= new \CoquiBot\Coqui\Config\CredentialResolver(workspacePath: $this->workspacePath);
        $credentialResolver->loadIntoProcessEnv();

        // Filesystem toolkit — sandboxed to workspace (read/write)
        $this->addToolkit(new FilesystemToolkit(rootPath: $this->workspacePath));

        // Shell toolkit — runs in project root for read access
        $this->addToolkit(new ShellToolkit(
            workDir: $this->projectRoot,
            allowedCommands: ['php', 'git', 'grep', 'find', 'cat', 'head', 'tail', 'wc', 'ls'],
            timeout: 60,
        ));

        // Memory toolkit — persisted inside workspace
        $memoryPath = $this->workspacePath . '/MEMORY.md';
        $memory = new FileMemory($memoryPath);
        $this->addToolkit(new MemoryToolkit($memory));

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
        );

        // Create restart tool if callback provided
        if ($onRestart !== null) {
            $this->restartTool = new RestartTool(onRestart: $onRestart);
        }
    }

    public function instructions(): string
    {
        $roles = implode(', ', $this->roleResolver->availableRoles());
        $skillsSummary = $this->skillDiscovery?->buildPromptSummary() ?? 'No skills installed.';

        $prompt = new OrchestratorPrompt(
            workspacePath: $this->workspacePath,
            projectRoot: $this->projectRoot,
            availableRoles: $roles,
            availableSkills: $skillsSummary,
        );

        return $prompt->render();
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
        ];

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
}
