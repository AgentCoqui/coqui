<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * One entry in a loop's recent-activity feed.
 */
final readonly class LoopLiveEvent
{
    public function __construct(
        public string $timestamp,
        public ?string $stageId,
        public ?string $role,
        public string $type,
        public string $summary,
    ) {}

    /**
     * @return array{timestamp: string, stage_id: ?string, role: ?string, type: string, summary: string}
     */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'stage_id' => $this->stageId,
            'role' => $this->role,
            'type' => $this->type,
            'summary' => $this->summary,
        ];
    }
}
