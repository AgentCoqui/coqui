<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

/**
 * Renders mini sparkline charts using Unicode block characters.
 *
 * Maps an array of numeric values to 8-level block characters (▁▂▃▄▅▆▇█)
 * proportional to the min/max range. Returns a Symfony Console style-tagged
 * string for inline rendering in tables, progress displays, and dashboards.
 *
 * Pure static utility with no state or dependencies.
 */
final class Sparkline
{
    /** @var list<string> Block characters from shortest to tallest. */
    private const array BLOCKS = ['▁', '▂', '▃', '▄', '▅', '▆', '▇', '█'];

    /**
     * Render a sparkline from numeric values.
     *
     * @param list<int|float> $values    Data points to visualize.
     * @param string          $style     Symfony Console color tag (e.g. "fg=cyan").
     * @param int             $maxWidth  Maximum number of characters to render.
     *                                   If values exceed this, the most recent are used.
     * @return string Styled sparkline string, or empty string if no values.
     */
    public static function render(array $values, string $style = 'fg=cyan', int $maxWidth = 12): string
    {
        if ($values === []) {
            return '';
        }

        // Take the most recent values if we exceed max width
        if (count($values) > $maxWidth) {
            $values = array_slice($values, -$maxWidth);
        }

        // Values is guaranteed non-empty (checked above + after slice)
        /** @var non-empty-array<int|float> $values */
        $min = min($values);
        $max = max($values);
        $range = (float) ($max - $min);

        $chars = [];
        foreach ($values as $value) {
            if ($range <= 0.0) {
                // All values are equal — use mid-level block
                $chars[] = self::BLOCKS[4];
            } else {
                $normalized = ($value - $min) / $range;
                $index = (int) round($normalized * (count(self::BLOCKS) - 1));
                $index = max(0, min(count(self::BLOCKS) - 1, $index));
                $chars[] = self::BLOCKS[$index];
            }
        }

        $spark = implode('', $chars);

        return '<' . $style . '>' . $spark . '</>';
    }
}
