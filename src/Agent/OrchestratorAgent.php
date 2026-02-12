<?php

declare(strict_types=1);

namespace Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CarmeloSantana\PHPAgents\Memory\FileMemory;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Toolkit\MemoryToolkit;
use CarmeloSantana\PHPAgents\Toolkit\ShellToolkit;
use Coqui\Config\RoleResolver;
use Coqui\Config\ToolkitDiscovery;
use Coqui\Observer\TerminalObserver;
use Coqui\Storage\SessionStorage;
use Coqui\Tool\ComposerTool;
use Coqui\Tool\SpawnAgentTool;

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
    private ComposerTool $composerTool;

    public function __construct(
        ProviderInterface $provider,
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?TerminalObserver $observer = null,
        ?ToolkitDiscovery $discovery = null,
        int $maxIterations = 25,
    ) {
        parent::__construct($provider, $maxIterations);

        // Filesystem toolkit — sandboxed to workspace (read/write)
        $this->addToolkit(new FilesystemToolkit(rootPath: $this->workspacePath));

        // Shell toolkit — runs in project root for read access (no composer — handled by ComposerTool)
        $this->addToolkit(new ShellToolkit(
            workDir: $this->projectRoot,
            allowedCommands: ['php', 'git', 'grep', 'find', 'cat', 'head', 'tail', 'wc', 'ls'],
            timeout: 60,
        ));

        // Memory toolkit — persisted inside workspace
        $memoryPath = $this->workspacePath . '/MEMORY.md';
        $memory = new FileMemory($memoryPath);
        $this->addToolkit(new MemoryToolkit($memory));

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
            storage: $this->storage,
            sessionId: $this->sessionId,
            observer: $this->observer,
        );

        // Create composer tool for dependency management
        $this->composerTool = new ComposerTool(
            projectRoot: $this->projectRoot,
            workspacePath: $this->workspacePath,
            discovery: $discovery,
        );
    }

    public function instructions(): string
    {
        $roles = implode(', ', $this->roleResolver->availableRoles());

        return <<<INSTRUCTIONS
            You are an AI orchestrator assistant running in a terminal environment.
            
            ## Your Role
            
            You coordinate tasks and delegate specialized work to child agents when appropriate.
            You have direct access to the filesystem, shell commands, and composer for managing dependencies.
            
            ## Workspace Isolation
            
            Your file read/write operations (read_file, write_file, etc.) are sandboxed to:
            **Workspace:** {$this->workspacePath}
            
            To read project source files outside the workspace, use shell commands like
            `cat`, `grep`, `find`, `head`, `tail` which run from the project root:
            **Project root:** {$this->projectRoot}
            
            ## Available Specialist Agents
            
            You can spawn child agents for specialized tasks using the `spawn_agent` tool.
            Available roles: {$roles}
            
            - **coder**: Expert PHP developer. Use for writing code, implementing features, refactoring.
            - **reviewer**: Code analyst. Use for reviewing code quality, finding bugs, security audit.
            
            ## Composer / Package Management
            
            Use the `composer` tool to manage dependencies. It supports:
            - `require`: Install new packages (with automatic backup)
            - `remove`: Uninstall packages (with automatic backup)
            - `show`: Inspect a specific package
            - `installed`: List all installed packages
            - `update`: Update packages (with automatic backup)
            - `validate`: Validate composer.json
            - `outdated`: Check for outdated packages
            
            All mutating operations automatically backup composer.json and composer.lock.
            Some packages (full frameworks) are blocked by a denylist.
            
            ## When to Delegate
            
            Delegate to a specialist when:
            - The task requires generating significant amounts of code
            - The task requires deep expertise (security review, optimization)
            - The task would benefit from a more capable model
            
            Handle yourself when:
            - Simple file operations (read, list, search)
            - Running quick commands
            - Gathering information
            - Managing dependencies
            - Coordinating multiple sub-tasks
            
            ## Memory
            
            Use the memory tools to save important information across sessions:
            - `memory_save`: Save facts, preferences, or context for later
            - `memory_load`: Recall previously saved information
            - `memory_forget`: Remove outdated information
            
            ## Guidelines
            
            1. Think step-by-step before acting
            2. Read files before modifying them
            3. Use spawn_agent for complex coding tasks
            4. Save important discoveries to memory
            5. Files you create go in the workspace directory
            6. When done, call the `done` tool with your final response
            
            You MUST call the done tool when the task is complete.
            INSTRUCTIONS;
    }

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [$this->spawnTool, $this->composerTool];
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
