<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Memory;

use DateTimeImmutable;

final readonly class MemoryEntry
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $content,
        public string $area = 'main',
        public array $metadata = [],
        public ?string $id = null,
        public ?float $score = null,
        public ?DateTimeImmutable $createdAt = null,
    ) {}

    public function withId(string $id): self
    {
        return new self(
            $this->content,
            $this->area,
            $this->metadata,
            $id,
            $this->score,
            $this->createdAt,
        );
    }

    public function withScore(float $score): self
    {
        return new self(
            $this->content,
            $this->area,
            $this->metadata,
            $this->id,
            $score,
            $this->createdAt,
        );
    }
}
