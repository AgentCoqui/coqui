<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\TickCallbackInterface;
use CarmeloSantana\PHPAgents\Contract\ToolExecutorInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;
use CoquiBot\Coqui\Config\RoleDiscovery;
use CoquiBot\Coqui\Contract\ChildAgentHandoff;
use CoquiBot\Coqui\Contract\SystemRole;

/**
 * A flexible child agent that receives its instructions and toolkits at construction time.
 *
 * Used by SpawnAgentTool to delegate tasks to specialized models.
 */
final class ChildAgent extends AbstractAgent
{
    private readonly ChildAgentHandoff $handoff;

    /**
     * @param ToolkitInterface[] $toolkits
     */
    public function __construct(
        ProviderInterface $provider,
        private readonly string $role,
        string|ChildAgentHandoff $taskInstructions,
        array $toolkits = [],
        int $maxIterations = AbstractAgent::DEFAULT_MAX_ITERATIONS,
        private readonly ?RoleDiscovery $roleDiscovery = null,
        ?ToolExecutorInterface $toolExecutor = null,
        ?TickCallbackInterface $tickCallback = null,
        private readonly ?string $profileIdentityPreamble = null,
    ) {
        parent::__construct(
            $provider,
            $maxIterations,
            toolExecutor: $toolExecutor,
            tickCallback: $tickCallback,
        );

        $this->handoff = is_string($taskInstructions)
            ? ChildAgentHandoff::fromTask($taskInstructions)
            : $taskInstructions;

        foreach ($toolkits as $toolkit) {
            $this->addToolkit($toolkit);
        }
    }

    public function instructions(): string
    {
        $roleInstructions = $this->resolveRoleInstructions();

        // Prepend profile identity when a personality profile is active
        if ($this->profileIdentityPreamble !== null && $this->profileIdentityPreamble !== '') {
            $roleInstructions = $this->profileIdentityPreamble . "\n\n" . $roleInstructions;
        }

        return <<<PROMPT
            {$roleInstructions}
            
            ## Your Task
            
            {$this->handoff->taskInstructions()}
            
            ## Completion
            
            When you have fully completed the task, call the `done` tool with your final response.
            Do NOT end without calling the done tool.
            PROMPT;
    }

    /**
     * Resolve role instructions from file-based roles, falling back to hardcoded defaults.
     */
    private function resolveRoleInstructions(): string
    {
        // Try file-based role discovery first
        if ($this->roleDiscovery !== null && $this->roleDiscovery->roleExists($this->role)) {
            return $this->roleDiscovery->readInstructions($this->role);
        }

        // Fall back to hardcoded defaults for backward compatibility
        return match ($this->role) {
            SystemRole::Coder->value => <<<INSTRUCTIONS
                You are an expert PHP developer. Your task is to write clean, well-documented code.
                
                Guidelines:
                - Use PHP 8.4+ features: readonly classes, enums, typed properties, constructor promotion
                - Follow PER-CS 2.0 coding style
                - All files must start with declare(strict_types=1)
                - Use final classes by default
                - Write comprehensive error handling
                - Include type declarations for all parameters and return types
                INSTRUCTIONS,

            SystemRole::Reviewer->value => <<<INSTRUCTIONS
                You are a code reviewer. Analyze the provided code for:
                
                - Bugs and logic errors
                - Security vulnerabilities
                - Performance issues
                - Code style violations
                - Missing error handling
                - Incomplete implementations
                
                Provide specific, actionable feedback with line references.
                INSTRUCTIONS,

            default => <<<INSTRUCTIONS
                You are a helpful AI assistant working on a specific task.
                Be thorough and complete the task fully before signaling done.
                INSTRUCTIONS,
        };
    }

    /**
     * @return ModelCapability[]
     */
    public function requiredCapabilities(): array
    {
        return [ModelCapability::Text, ModelCapability::Tools];
    }

    public function getRole(): string
    {
        return $this->role;
    }
}
