<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A selectable choice. `label` is the answer identifier.
 */
final readonly class QuestionOption
{
    public function __construct(
        public string $label,
        public ?string $description = null,
    ) {
        if ($label === '') {
            throw new \InvalidArgumentException('QuestionOption label must not be empty');
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $description = $data['description'] ?? null;

        return new self(
            label: (string) ($data['label'] ?? ''),
            description: is_string($description) && $description !== '' ? $description : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['label' => $this->label];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        return $out;
    }
}
