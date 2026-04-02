<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Declares a template parameter for a loop definition.
 *
 * Parameters enable {{variable}} substitution in role prompts at loop start time,
 * making loop definitions reusable across different contexts without editing the JSON.
 */
final readonly class LoopParameterDefinition
{
    public function __construct(
        public string $name,
        public string $description,
        public bool $required = true,
        public ?string $default = null,
    ) {
        if ($name === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                sprintf('Parameter name must be lowercase alphanumeric with underscores, got: "%s"', $name),
            );
        }

        if ($description === '') {
            throw new \InvalidArgumentException(
                sprintf('Parameter "%s" must have a non-empty description', $name),
            );
        }

        if (!$required && $default === null) {
            throw new \InvalidArgumentException(
                sprintf('Optional parameter "%s" must have a default value', $name),
            );
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'],
            required: $data['required'] ?? true,
            default: $data['default'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'name' => $this->name,
            'description' => $this->description,
            'required' => $this->required,
        ];

        if ($this->default !== null) {
            $result['default'] = $this->default;
        }

        return $result;
    }
}
