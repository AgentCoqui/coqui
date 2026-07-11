<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Rich, poll-friendly snapshot of an in-flight loop.
 */
final readonly class LoopLiveSnapshot
{
    /**
     * @param array<string, mixed>      $loop
     * @param array<string, mixed>      $position
     * @param array<string, mixed>|null $currentStage
     * @param array<string, mixed>      $budget
     * @param list<LoopLiveStage>       $stages
     * @param list<LoopLiveEvent>       $recentEvents
     */
    public function __construct(
        public array $loop,
        public array $position,
        public ?array $currentStage,
        public array $budget,
        public array $stages,
        public array $recentEvents,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'loop' => $this->loop,
            'position' => $this->position,
            'current_stage' => $this->currentStage,
            'budget' => $this->budget,
            'stages' => array_map(static fn(LoopLiveStage $s): array => $s->toArray(), $this->stages),
            'recent_events' => array_map(static fn(LoopLiveEvent $e): array => $e->toArray(), $this->recentEvents),
        ];
    }
}
