<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * A single thin nudge emitted on the loop events stream.
 */
final readonly class LoopStreamEvent
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $type,
        public array $data,
    ) {}
}
