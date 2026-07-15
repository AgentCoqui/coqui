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
     * @param list<LoopParameterDefinition> $parameters     Declared template parameters for {{variable}} substitution
     * @param OnQuestionPolicy        $onQuestion           How ask_user behaves for this loop's non-interactive stages
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $roles,
        public TerminationCondition $terminationCondition,
        public array $parameters = [],
        public OnQuestionPolicy $onQuestion = OnQuestionPolicy::Block,
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

        $parameters = [];
        foreach ($data['parameters'] ?? [] as $paramData) {
            if (!is_array($paramData)) {
                throw new \InvalidArgumentException('Each entry in "parameters" must be an object');
            }
            $parameters[] = LoopParameterDefinition::fromArray($paramData);
        }

        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            roles: $roles,
            terminationCondition: TerminationCondition::fromArray($terminationData),
            parameters: $parameters,
            onQuestion: OnQuestionPolicy::fromString($data['on_question'] ?? null),
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
        $result = [
            'name' => $this->name,
            'description' => $this->description,
            'roles' => array_map(static fn(LoopRoleDefinition $r) => $r->toArray(), $this->roles),
            'termination_condition' => $this->terminationCondition->toArray(),
            'on_question' => $this->onQuestion->value,
        ];

        if ($this->parameters !== []) {
            $result['parameters'] = array_map(
                static fn(LoopParameterDefinition $p) => $p->toArray(),
                $this->parameters,
            );
        }

        return $result;
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

    /**
     * Get parameter names that are required (no default value).
     *
     * @return list<string>
     */
    public function requiredParameterNames(): array
    {
        return array_values(array_map(
            static fn(LoopParameterDefinition $p) => $p->name,
            array_filter($this->parameters, static fn(LoopParameterDefinition $p) => $p->required),
        ));
    }

    /**
     * Build the full parameter map with defaults applied.
     *
     * @param array<string, string> $provided User-supplied parameter values
     * @return array<string, string> Merged map (provided values override defaults)
     */
    public function resolveParameters(array $provided): array
    {
        $resolved = [];
        foreach ($this->parameters as $param) {
            if (isset($provided[$param->name])) {
                $resolved[$param->name] = $provided[$param->name];
            } elseif ($param->default !== null) {
                $resolved[$param->name] = $param->default;
            }
        }

        return $resolved;
    }
}
