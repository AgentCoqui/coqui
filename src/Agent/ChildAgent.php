<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Agent;

use CarmeloSantana\PHPAgents\Agent\AbstractAgent;
use CarmeloSantana\PHPAgents\Contract\ProviderInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ModelCapability;

/**
 * A flexible child agent that receives its instructions and toolkits at construction time.
 *
 * Used by SpawnAgentTool to delegate tasks to specialized models.
 */
final class ChildAgent extends AbstractAgent
{

    /**
     * @param ToolkitInterface[] $toolkits
     */
    public function __construct(
        ProviderInterface $provider,
        private readonly string $role,
        private readonly string $taskInstructions,
        array $toolkits = [],
        int $maxIterations = 25,
    ) {
        parent::__construct($provider, $maxIterations);

        foreach ($toolkits as $toolkit) {
            $this->addToolkit($toolkit);
        }
    }

    public function instructions(): string
    {
        $roleInstructions = match ($this->role) {
            'coder' => <<<INSTRUCTIONS
                You are an expert PHP developer. Your task is to write clean, well-documented code.
                
                Guidelines:
                - Use PHP 8.4+ features: readonly classes, enums, typed properties, constructor promotion
                - Follow PER-CS 2.0 coding style
                - All files must start with declare(strict_types=1)
                - Use final classes by default
                - Write comprehensive error handling
                - Include type declarations for all parameters and return types
                INSTRUCTIONS,

            'reviewer' => <<<INSTRUCTIONS
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

        return <<<PROMPT
            {$roleInstructions}
            
            ## Your Task
            
            {$this->taskInstructions}
            
            ## Completion
            
            When you have fully completed the task, call the `done` tool with your final response.
            Do NOT end without calling the done tool.
            PROMPT;
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
