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
     * @param string      $role           Coqui role name (must exist in RoleDiscovery)
     * @param string      $prompt         Role-specific task instructions for this loop stage
     * @param list<string> $skills        Optional skill names to inject
     * @param int|null    $maxIterations  Per-stage iteration override (null = role default)
     */
    public function __construct(
        public string $role,
        public string $prompt,
        public array $skills = [],
        public ?int $maxIterations = null,
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
        ];
    }
}
