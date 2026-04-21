<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

/**
 * Optional shell region that can collapse when the viewport gets tight.
 */
final readonly class ShellRegion
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public array $lines,
        public int $collapsePriority = 100,
        public ?int $preferredWidth = null,
    ) {}
}