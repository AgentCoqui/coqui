<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer;

use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Generic terminal progress bar with colored proportional segments.
 *
 * Renders a visual bar using Unicode block characters (█ for filled, ░ for
 * empty) with each segment colored via Symfony Console style tags. Designed
 * to be reusable across different progress visualization contexts:
 *
 * - Context window usage (system/user/assistant/tool/summary breakdown)
 * - Loop progress (completed/remaining iterations, review rounds)
 * - Summarization analytics (before/after compression ratios)
 *
 * The bar itself is a pure renderer — callers build the data model and
 * pass segments + total. No coupling to agents, conversations, or tokens.
 */
final class ProgressBar
{
    private const string FILLED_CHAR = '█';
    private const string EMPTY_CHAR = '░';
    private const int DEFAULT_WIDTH = 50;
    private const int MIN_WIDTH = 10;
    private const int MAX_WIDTH = 120;

    public function __construct(
        private readonly int $width = self::DEFAULT_WIDTH,
    ) {}

    /**
     * Render the progress bar directly to a SymfonyStyle output.
     *
     * @param ProgressBarSegment[] $segments  Ordered segments to display.
     * @param int|float            $total     Maximum value (e.g. total tokens, total iterations).
     * @param string               $emptyStyle Style for the unfilled portion (default: fg=gray).
     * @param bool                 $showPercent Whether to append an overall percentage label.
     * @param bool                 $showLegend  Whether to render a legend row below the bar.
     * @param string               $label      Optional prefix label (e.g. "Context").
     */
    public function render(
        SymfonyStyle $io,
        int|float $total,
        array $segments,
        string $emptyStyle = 'fg=gray',
        bool $showPercent = true,
        bool $showLegend = false,
        string $label = '',
    ): void {
        $io->writeln($this->build($total, $segments, $emptyStyle, $showPercent, $label));

        if ($showLegend) {
            $io->writeln($this->buildLegend($segments, $emptyStyle));
        }
    }

    /**
     * Build the progress bar as a styled string (for testing or embedding).
     *
     * @param ProgressBarSegment[] $segments
     */
    public function build(
        int|float $total,
        array $segments,
        string $emptyStyle = 'fg=gray',
        bool $showPercent = true,
        string $label = '',
    ): string {
        $width = max(self::MIN_WIDTH, min(self::MAX_WIDTH, $this->width));

        if ($total <= 0) {
            $bar = '<' . $emptyStyle . '>' . str_repeat(self::EMPTY_CHAR, $width) . '</>';
            return $this->formatLine($bar, 0.0, $showPercent, $label);
        }

        $usedChars = 0;
        $usedValue = 0;
        $parts = [];

        foreach ($segments as $segment) {
            if ($segment->value <= 0) {
                continue;
            }

            $usedValue += $segment->value;
            $chars = (int) round(($segment->value / $total) * $width);

            // Ensure at least 1 char for any non-zero segment
            if ($chars === 0 && $segment->value > 0) {
                $chars = 1;
            }

            // Don't exceed total width
            if ($usedChars + $chars > $width) {
                $chars = $width - $usedChars;
            }

            if ($chars > 0) {
                $parts[] = '<' . $segment->style . '>' . str_repeat(self::FILLED_CHAR, $chars) . '</>';
                $usedChars += $chars;
            }
        }

        // Fill remaining with empty chars
        $remaining = $width - $usedChars;
        if ($remaining > 0) {
            $parts[] = '<' . $emptyStyle . '>' . str_repeat(self::EMPTY_CHAR, $remaining) . '</>';
        }

        $bar = implode('', $parts);
        $percent = min(100.0, ($usedValue / $total) * 100);

        return $this->formatLine($bar, $percent, $showPercent, $label);
    }

    /**
     * Build a legend row showing segment labels with their colors.
     *
     * @param ProgressBarSegment[] $segments
     */
    public function buildLegend(array $segments, string $emptyStyle = 'fg=gray'): string
    {
        $items = [];

        foreach ($segments as $segment) {
            if ($segment->value <= 0) {
                continue;
            }

            $items[] = '<' . $segment->style . '>' . self::FILLED_CHAR . '</>'
                . ' <fg=gray>' . $segment->label . '</>';
        }

        $items[] = '<' . $emptyStyle . '>' . self::FILLED_CHAR . '</>'
            . ' <fg=gray>Available</>';

        return '    ' . implode('  ', $items);
    }

    private function formatLine(string $bar, float $percent, bool $showPercent, string $label): string
    {
        $prefix = $label !== '' ? "  {$label} " : '  ';
        $suffix = $showPercent ? sprintf(' <fg=gray>%.1f%%</>', $percent) : '';

        return $prefix . $bar . $suffix;
    }
}
