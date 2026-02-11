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
use Coqui\Observer\TerminalObserver;
use Coqui\Storage\SessionStorage;
use Coqui\Tool\SpawnAgentTool;

/**
 * The top-level orchestrator agent that receives user input.
 *
 * Runs on a cheap local model (Ollama) and delegates specialized tasks
 * to child agents via the spawn_agent tool.
 */
final class OrchestratorAgent extends AbstractAgent
{
    private SpawnAgentTool $spawnTool;

    public function __construct(
        ProviderInterface $provider,
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $workDir,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?TerminalObserver $observer = null,
        int $maxIterations = 25,
    ) {
        parent::__construct($provider, $maxIterations);

        // Add filesystem toolkit
        $this->addToolkit(new FilesystemToolkit(rootPath: $this->workDir));

        // Add shell toolkit with allowed commands
        $this->addToolkit(new ShellToolkit(
            workDir: $this->workDir,
            allowedCommands: ['composer', 'php', 'git', 'grep', 'find', 'cat', 'head', 'tail', 'wc', 'ls'],
            timeout: 60,
        ));

        // Add memory toolkit if MEMORY.md exists or can be created
        $memoryPath = $this->workDir . '/MEMORY.md';
        $memory = new FileMemory($memoryPath);
        $this->addToolkit(new MemoryToolkit($memory));

        // Create spawn tool
        $this->spawnTool = new SpawnAgentTool(
            roleResolver: $this->roleResolver,
            config: $this->config,
            workDir: $this->workDir,
            storage: $this->storage,
            sessionId: $this->sessionId,
            observer: $this->observer,
        );
    }

    public function instructions(): string
    {
        $roles = implode(', ', $this->roleResolver->availableRoles());

        return <<<INSTRUCTIONS
            You are an AI orchestrator assistant running in a terminal environment.
            
            ## Your Role
            
            You coordinate tasks and delegate specialized work to child agents when appropriate.
            You have direct access to the filesystem and shell commands for simple operations.
            
            ## Available Specialist Agents
            
            You can spawn child agents for specialized tasks using the `spawn_agent` tool.
            Available roles: {$roles}
            
            - **coder**: Expert PHP developer. Use for writing code, implementing features, refactoring.
            - **reviewer**: Code analyst. Use for reviewing code quality, finding bugs, security audit.
            
            ## When to Delegate
            
            Delegate to a specialist when:
            - The task requires generating significant amounts of code
            - The task requires deep expertise (security review, optimization)
            - The task would benefit from a more capable model
            
            Handle yourself when:
            - Simple file operations (read, list, search)
            - Running quick commands
            - Gathering information
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
            5. When done, call the `done` tool with your final response
            
            You MUST call the done tool when the task is complete.
            INSTRUCTIONS;
    }

    /**
     * @return ToolInterface[]
     */
    public function tools(): array
    {
        return [$this->spawnTool];
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
