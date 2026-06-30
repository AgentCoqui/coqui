<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Typed envelope for parent-to-child agent task handoff.
 */
final readonly class ChildAgentHandoff
{
    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $artifactIds
     */
    public function __construct(
        public string $task,
        public string $context = '',
        public array $metadata = [],
        public ?string $intent = null,
        public ?string $workflowPhase = null,
        public array $artifactIds = [],
        public ?string $parentSessionId = null,
        public ?string $workScopeSessionId = null,
        public ?string $projectId = null,
    ) {}

    public static function fromTask(string $task): self
    {
        return new self(task: $task);
    }

    /**
     * @param array<string, mixed> $metadata
     * @param list<string> $artifactIds
     */
    public static function fromInput(
        string $task,
        string $context = '',
        array $metadata = [],
        ?string $intent = null,
        ?string $workflowPhase = null,
        array $artifactIds = [],
        ?string $parentSessionId = null,
        ?string $workScopeSessionId = null,
        ?string $projectId = null,
    ): self
    {
        return new self(
            task: trim($task),
            context: trim($context),
            metadata: $metadata,
            intent: $intent,
            workflowPhase: $workflowPhase,
            artifactIds: $artifactIds,
            parentSessionId: $parentSessionId,
            workScopeSessionId: $workScopeSessionId,
            projectId: $projectId,
        );
    }

    public function hasContext(): bool
    {
        return $this->context !== '';
    }

    public function taskInstructions(): string
    {
        return $this->task;
    }

    public function userPrompt(): string
    {
        if (!$this->hasContext()) {
            return $this->task;
        }

        return "## Context\n\n{$this->context}\n\n## Task\n\n{$this->task}";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'task' => $this->task,
            'context' => $this->context,
            'metadata' => $this->metadata,
            'intent' => $this->intent,
            'workflow_phase' => $this->workflowPhase,
            'artifact_ids' => $this->artifactIds,
            'parent_session_id' => $this->parentSessionId,
            'work_scope_session_id' => $this->workScopeSessionId,
            'project_id' => $this->projectId,
        ];
    }
}