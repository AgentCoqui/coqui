<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Renderer\Ansi;

use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

final class TableRenderer implements NodeRendererInterface
{
    private const string DIM = "\033[2m";
    private const string BOLD = "\033[1m";
    private const string RESET = "\033[0m";

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        Table::assertInstanceOf($node);

        // Collect all rows with their cell data
        $headerRows = [];
        $bodyRows = [];
        $alignments = [];

        foreach ($node->children() as $section) {
            if (!$section instanceof TableSection) {
                continue;
            }
            $isHead = $section->getType() === TableSection::TYPE_HEAD;

            foreach ($section->children() as $row) {
                if (!$row instanceof TableRow) {
                    continue;
                }
                $cells = [];
                $colIndex = 0;
                foreach ($row->children() as $cell) {
                    if (!$cell instanceof TableCell) {
                        continue;
                    }
                    $text = trim($childRenderer->renderNodes($cell->children()));
                    $cells[] = $text;

                    if ($isHead && $cell->getAlign() !== null) {
                        $alignments[$colIndex] = $cell->getAlign();
                    }
                    $colIndex++;
                }

                if ($isHead) {
                    $headerRows[] = $cells;
                } else {
                    $bodyRows[] = $cells;
                }
            }
        }

        $allRows = array_merge($headerRows, $bodyRows);
        if ($allRows === []) {
            return '';
        }

        // Calculate column widths
        $colCount = max(array_map(count(...), $allRows));
        $widths = array_fill(0, $colCount, 0);
        foreach ($allRows as $row) {
            foreach ($row as $i => $cell) {
                $widths[$i] = max($widths[$i], $this->visibleLength($cell));
            }
        }

        // Render
        $output = '';
        $separator = $this->renderSeparator($widths);

        foreach ($headerRows as $row) {
            $output .= self::BOLD . $this->renderRow($row, $widths, $alignments) . self::RESET . "\n";
        }

        if ($headerRows !== []) {
            $output .= self::DIM . $separator . self::RESET . "\n";
        }

        foreach ($bodyRows as $row) {
            $output .= $this->renderRow($row, $widths, $alignments) . "\n";
        }

        return $output . "\n";
    }

    /**
     * @param string[] $cells
     * @param int[] $widths
     * @param array<int, string|null> $alignments
     */
    private function renderRow(array $cells, array $widths, array $alignments): string
    {
        $parts = [];
        foreach ($widths as $i => $width) {
            $cell = $cells[$i] ?? '';
            $align = $alignments[$i] ?? null;
            $parts[] = $this->padCell($cell, $width, $align);
        }

        return self::DIM . '│ ' . self::RESET
            . implode(self::DIM . ' │ ' . self::RESET, $parts)
            . self::DIM . ' │' . self::RESET;
    }

    /**
     * @param int[] $widths
     */
    private function renderSeparator(array $widths): string
    {
        $parts = [];
        foreach ($widths as $width) {
            $parts[] = str_repeat('─', $width);
        }

        return '├─' . implode('─┼─', $parts) . '─┤';
    }

    private function padCell(string $text, int $width, ?string $align): string
    {
        $visible = $this->visibleLength($text);
        $padding = max(0, $width - $visible);

        return match ($align) {
            TableCell::ALIGN_RIGHT => str_repeat(' ', $padding) . $text,
            TableCell::ALIGN_CENTER => str_repeat(' ', (int) floor($padding / 2))
                . $text
                . str_repeat(' ', (int) ceil($padding / 2)),
            default => $text . str_repeat(' ', $padding),
        };
    }

    /**
     * Calculate visible string length, ignoring ANSI escape sequences.
     */
    private function visibleLength(string $text): int
    {
        $stripped = (string) preg_replace('/\033\[[0-9;]*m/', '', $text);

        return mb_strlen($stripped);
    }
}
