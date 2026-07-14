<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares a single role stage within a loop definition.
 *
 * Each role maps to an existing Coqui role (e.g. "coder", "reviewer")
 * and optionally overrides the prompt for loop-specific context.
 */
final readonly class LoopRoleDefinition
{
    /**
     * @param string      $role                      Coqui role name (must exist in RoleDiscovery)
     * @param string      $prompt                    Role-specific task instructions for this loop stage
     * @param list<string> $skills                   Optional skill names to inject
     * @param int|null    $maxIterations             Per-stage iteration override (null = role default)
     * @param bool        $gate                      Stage acts as a hard gate that halts the loop on failure
     * @param bool        $artifactRequired          Stage must produce an artifact to advance
     * @param bool        $memoryRequired            Stage must record memory to advance
     */
    public function __construct(
        public string $role,
        public string $prompt,
        public array $skills = [],
        public ?int $maxIterations = null,
        public bool $gate = false,
        public bool $artifactRequired = false,
        public bool $memoryRequired = false,
    ) {
        if ($role === '') {
            throw new \InvalidArgumentException('Loop role "role" (name) must not be empty');
        }

        if ($prompt === '') {
            throw new \InvalidArgumentException(
                sprintf('Loop role "%s" must have a non-empty "prompt"', $role),
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'] ?? $data['name'] ?? '',
            prompt: $data['prompt'] ?? '',
            skills: $data['skills'] ?? [],
            maxIterations: isset($data['max_iterations']) ? (int) $data['max_iterations'] : null,
            gate: (bool) ($data['gate'] ?? false),
            artifactRequired: (bool) ($data['artifact_required'] ?? false),
            memoryRequired: (bool) ($data['memory_required'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'prompt' => $this->prompt,
            'skills' => $this->skills,
            'max_iterations' => $this->maxIterations,
            'gate' => $this->gate,
            'artifact_required' => $this->artifactRequired,
            'memory_required' => $this->memoryRequired,
        ];
    }
}
