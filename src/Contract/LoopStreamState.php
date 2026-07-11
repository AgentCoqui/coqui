<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * The minimal loop snapshot a single events-stream tick observes.
 */
final readonly class LoopStreamState
{
    public function __construct(
        public string $status,
        public int $currentIteration,
        public int $currentStage,
        public ?int $latestActivityId,
    ) {}
}
