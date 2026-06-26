<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Structured metadata for reviewer and rework child-agent runs.
 */
final readonly class ReviewHandoffMetadata
{
    public function __construct(
        public string $phase,
        public int $round,
        public string $sourceRole,
        public bool $autoIterate = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phase' => $this->phase,
            'round' => $this->round,
            'source_role' => $this->sourceRole,
            'auto_iterate' => $this->autoIterate,
        ];
    }
}