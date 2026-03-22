<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Message\UserMessage;
use CarmeloSantana\PHPAgents\Provider\ProviderFactory;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Toolkit\FilesystemToolkit;
use CarmeloSantana\PHPAgents\Toolkit\ShellToolkit;
use CoquiBot\Coqui\Agent\ChildAgent;
use CoquiBot\Coqui\Agent\PlanTodoGenerator;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Toolkit\ArtifactToolkit;
use CoquiBot\Coqui\Toolkit\ProjectSourceToolkit;
use CoquiBot\Coqui\Toolkit\TodoToolkit;
use CoquiBot\Coqui\Storage\TodoStore;
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
    /** Default shell commands for child agents (subset of orchestrator's). */
    private const array DEFAULT_CHILD_SHELL_COMMANDS = [
        'php', 'git', 'grep', 'find', 'cat', 'head', 'tail', 'wc',
        'curl', 'wget', 'sort', 'uniq', 'sed', 'awk', 'diff',
    ];

    /** Read-only shell commands for readonly-shell access level. */
    private const array READ_ONLY_SHELL_COMMANDS = [
        'grep', 'find', 'cat', 'head', 'tail', 'wc', 'ls',
        'sort', 'uniq', 'sed', 'awk', 'diff',
    ];

    private int $currentIteration = 0;
    private int $childRunCount = 0;

    /**
     * @param array<string> $shellAllowedCommands
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
        private readonly array $shellAllowedCommands = self::DEFAULT_CHILD_SHELL_COMMANDS,
    ) {}

    public function name(): string
    {
        return 'spawn_agent';
    }

    public function description(): string
    {
        $roles = implode(', ', $this->roleResolver->selectableRoles());

        return <<<DESC
            Spawn a specialized child agent to handle a specific task.
            
            Use this when a task requires expertise or capabilities better suited to a different model.
            For example, spawn a 'coder' agent to write complex code, or a 'reviewer' agent to analyze code quality.
            
            Available roles: {$roles}
            
            The child agent will run independently and return its result.
            DESC;
    }

    public function parameters(): array
    {
        $roles = $this->roleResolver->selectableRoles();

        return [
            new EnumParameter(
                name: 'role',
                description: 'The role/specialty of the child agent to spawn',
                values: !empty($roles) ? $roles : ['coder', 'reviewer'],
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

        // Resolve role to model
        $modelString = $this->roleResolver->resolve($role);

        // Notify observer about child spawn
        if ($this->observer !== null && method_exists($this->observer, 'handleEvent')) {
            $this->observer->handleEvent('child.start', ['role' => $role, 'model' => $modelString]);
        }

        try {
            // Create provider for child agent
            $provider = ProviderFactory::fromModelString($modelString, $this->config);

            // Build toolkits based on role
            $toolkits = $this->buildToolkits($role);

            // Create and run child agent
            $child = new ChildAgent(
                provider: $provider,
                role: $role,
                taskInstructions: $task,
                toolkits: $toolkits,
                maxIterations: $this->roleResolver->resolveMaxIterations($role),
                roleDiscovery: $this->roleDiscovery,
            );

            // Attach observer if available
            if ($this->observer !== null) {
                $child->attach($this->observer);
            }

            // Build prompt with optional context
            $prompt = $task;
            if ($context !== '') {
                $prompt = "## Context\n\n{$context}\n\n## Task\n\n{$task}";
            }

            $output = $child->run(new UserMessage($prompt));

            // Log child run to storage
            if ($this->storage !== null && $this->sessionId !== null) {
                $this->storage->logChildRun(
                    sessionId: $this->sessionId,
                    parentIteration: $this->currentIteration,
                    agentRole: $role,
                    model: $modelString,
                    prompt: $prompt,
                    result: $output->content,
                    tokenCount: $output->usage !== null ? $output->usage->totalTokens : 0,
                );
            }

            // Notify observer about child completion
            if ($this->observer !== null && method_exists($this->observer, 'handleEvent')) {
                $this->observer->handleEvent('child.end', null);
            }

            $this->childRunCount++;

            return ToolResult::success($output->content);
        } catch (\Throwable $e) {
            if ($this->observer !== null && method_exists($this->observer, 'handleEvent')) {
                $this->observer->handleEvent('child.end', null);
            }

            return ToolResult::error("Child agent failed: {$e->getMessage()}");
        }
    }

    /**
     * @return \CarmeloSantana\PHPAgents\Contract\ToolkitInterface[]
     */
    private function buildToolkits(string $role): array
    {
        $accessLevel = $this->resolveAccessLevel($role);

        // Child agents get read-only mount access regardless of role access level
        $mountPaths = $this->mountManager?->allowedPathsReadOnly() ?? [];

        $toolkits = match ($accessLevel) {
            'full' => [
                new FilesystemToolkit(rootPath: $this->workspacePath, allowedPaths: $mountPaths),
                new ShellToolkit(
                    workDir: $this->projectRoot,
                    allowedCommands: $this->shellAllowedCommands,
                    timeout: 60,
                ),
                new ProjectSourceToolkit(projectRoot: $this->projectRoot),
            ],

            'readonly-shell' => [
                new FilesystemToolkit(rootPath: $this->workspacePath, readOnly: true, allowedPaths: $mountPaths),
                new ShellToolkit(
                    workDir: $this->projectRoot,
                    allowedCommands: self::READ_ONLY_SHELL_COMMANDS,
                    timeout: 60,
                ),
                new ProjectSourceToolkit(projectRoot: $this->projectRoot),
            ],

            'readonly' => [
                new FilesystemToolkit(rootPath: $this->workspacePath, readOnly: true, allowedPaths: $mountPaths),
                new ProjectSourceToolkit(projectRoot: $this->projectRoot),
            ],

            // 'minimal' — no toolkits
            default => [],
        };

        // Artifact toolkit — share parent session's artifacts with child agents.
        // Non-full access levels get read-only artifact access (no delete).
        if ($this->storage !== null && $this->sessionId !== null) {
            $artifactStore = new ArtifactStore($this->storage->getPdo());
            $todoStore = new TodoStore($this->storage->getPdo());

            $planTodoGenerator = new PlanTodoGenerator(
                roleResolver: $this->roleResolver,
                config: $this->config,
                todoStore: $todoStore,
                roleDiscovery: $this->roleDiscovery,
            );

            $toolkits[] = new ArtifactToolkit(
                $artifactStore,
                $this->sessionId,
                readOnly: $accessLevel !== 'full',
                planTodoGenerator: $planTodoGenerator,
            );
        }

        // Todo toolkit — session-scoped task tracking shared with child agents.
        if ($this->storage !== null && $this->sessionId !== null) {
            $todoStore ??= new TodoStore($this->storage->getPdo());
            $toolkits[] = new TodoToolkit(
                $todoStore,
                $this->sessionId,
                $role,
                $accessLevel,
            );
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
                $properties = $this->roleDiscovery->getRole($role);
                return $properties->accessLevel;
            } catch (\Throwable) {
                // Fall through to hardcoded defaults
            }
        }

        // Backward-compatible fallback
        return match ($role) {
            'coder' => 'full',
            default => 'readonly',
        };
    }

    public function setCurrentIteration(int $iteration): void
    {
        $this->currentIteration = $iteration;
    }

    public function getChildRunCount(): int
    {
        return $this->childRunCount;
    }

    public function toFunctionSchema(): array
    {
        $roles = $this->roleResolver->availableRoles();

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
                            'enum' => !empty($roles) ? $roles : ['coder', 'reviewer'],
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
