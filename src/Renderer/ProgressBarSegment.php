<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

/**
 * A single segment of a progress bar with a label, value, and color style.
 *
 * Segments are proportionally sized within the bar based on their value
 * relative to the total. The style uses Symfony Console color tags
 * (e.g. "fg=blue", "fg=green;bg=black").
 */
final readonly class ProgressBarSegment
{
    public function __construct(
        public string $label,
        public int|float $value,
        public string $style,
    ) {}
}
