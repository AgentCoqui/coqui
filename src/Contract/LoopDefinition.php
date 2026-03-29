<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares a loop workflow — an ordered sequence of role stages with a termination condition.
 *
 * Loop definitions are stored as JSON files in workspace/loops/ (user-editable)
 * and config/loops/ (built-in). They define how the bot iterates through multiple
 * agent roles to accomplish complex, long-running tasks autonomously.
 *
 * Inspired by Anthropic's Harness (generator-evaluator) and gstack (multi-specialist sprint).
 */
final readonly class LoopDefinition
{
    /**
     * @param string                  $name                 Slug-safe identifier (filename without .json)
     * @param string                  $description          Human-readable description of the loop's purpose
     * @param list<LoopRoleDefinition> $roles               Ordered role stages executed per iteration cycle
     * @param TerminationCondition    $terminationCondition How the loop determines when to stop
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $roles,
        public TerminationCondition $terminationCondition,
    ) {
        if ($name === '' || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name)) {
            throw new \InvalidArgumentException(
                sprintf('Loop name must be slug-safe (lowercase alphanumeric, hyphens, underscores), got: "%s"', $name),
            );
        }

        if ($description === '') {
            throw new \InvalidArgumentException('Loop "description" must not be empty');
        }

        if ($roles === []) {
            throw new \InvalidArgumentException('Loop must define at least one role stage');
        }
    }

    /**
     * Parse a loop definition from a decoded JSON array.
     *
     * @param array<string, mixed> $data Decoded JSON from a loop definition file
     */
    public static function fromArray(array $data): self
    {
        $roles = [];
        foreach ($data['roles'] ?? [] as $roleData) {
            if (!is_array($roleData)) {
                throw new \InvalidArgumentException('Each entry in "roles" must be an object');
            }
            $roles[] = LoopRoleDefinition::fromArray($roleData);
        }

        $terminationData = $data['termination_condition'] ?? null;
        if (!is_array($terminationData)) {
            throw new \InvalidArgumentException('Loop must define a "termination_condition" object');
        }

        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            roles: $roles,
            terminationCondition: TerminationCondition::fromArray($terminationData),
        );
    }

    /**
     * Parse a loop definition from a JSON string.
     *
     * @throws \InvalidArgumentException On invalid JSON or missing fields
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true);

        if (!is_array($data)) {
            throw new \InvalidArgumentException('Loop definition must be valid JSON object');
        }

        return self::fromArray($data);
    }

    /**
     * Serialize to JSON-safe array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'roles' => array_map(static fn(LoopRoleDefinition $r) => $r->toArray(), $this->roles),
            'termination_condition' => $this->terminationCondition->toArray(),
        ];
    }

    /**
     * Serialize to formatted JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Get the role names referenced by this definition.
     *
     * @return list<string>
     */
    public function roleNames(): array
    {
        return array_map(static fn(LoopRoleDefinition $r) => $r->role, $this->roles);
    }

    /**
     * Number of stages per iteration cycle.
     */
    public function stageCount(): int
    {
        return count($this->roles);
    }
}
