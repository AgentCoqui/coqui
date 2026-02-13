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
use CoquiBot\Coqui\Config\RoleResolver;
use CoquiBot\Coqui\Observer\TerminalObserver;
use CoquiBot\Coqui\Storage\SessionStorage;
use SplObserver;

/**
 * Tool that spawns a child agent with a different model for specialized tasks.
 *
 * The orchestrator uses this to delegate work to Claude (coder), GPT-4 (reviewer), etc.
 */
final class SpawnAgentTool implements ToolInterface
{
    private int $currentIteration = 0;

    public function __construct(
        private readonly RoleResolver $roleResolver,
        private readonly ConfigInterface $config,
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?SessionStorage $storage = null,
        private readonly ?string $sessionId = null,
        private readonly ?SplObserver $observer = null,
    ) {}

    public function name(): string
    {
        return 'spawn_agent';
    }

    public function description(): string
    {
        $roles = implode(', ', $this->roleResolver->availableRoles());

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
        $roles = $this->roleResolver->availableRoles();

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
        if ($this->observer instanceof TerminalObserver) {
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
                maxIterations: 15,
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
            if ($this->observer instanceof TerminalObserver) {
                $this->observer->handleEvent('child.end', null);
            }

            return ToolResult::success($output->content);
        } catch (\Throwable $e) {
            if ($this->observer instanceof TerminalObserver) {
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
        return match ($role) {
            'coder' => [
                new FilesystemToolkit(rootPath: $this->workspacePath),
                new ShellToolkit(
                    workDir: $this->projectRoot,
                    allowedCommands: ['php', 'git', 'grep', 'find', 'cat', 'head', 'tail', 'wc'],
                    timeout: 60,
                ),
            ],

            'reviewer' => [
                new FilesystemToolkit(rootPath: $this->workspacePath, readOnly: true),
            ],

            default => [
                new FilesystemToolkit(rootPath: $this->workspacePath, readOnly: true),
            ],
        };
    }

    public function setCurrentIteration(int $iteration): void
    {
        $this->currentIteration = $iteration;
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
