<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

/**
 * Single-line update produced when diffing two rendered screen frames.
 */
final readonly class ScreenFramePatch
{
    public function __construct(
        public int $row,
        public string $line,
    ) {}
}