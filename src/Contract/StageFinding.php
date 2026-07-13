<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A single reviewer finding within a gate stage verdict.
 */
final readonly class StageFinding
{
    public function __construct(
        public StageSeverity $severity,
        public string $summary,
        public ?string $location = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            severity: StageSeverity::tryFrom((string) ($data['severity'] ?? 'minor')) ?? StageSeverity::Minor,
            summary: (string) ($data['summary'] ?? ''),
            location: isset($data['location']) && $data['location'] !== '' ? (string) $data['location'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity->value,
            'summary' => $this->summary,
            'location' => $this->location,
        ];
    }
}
