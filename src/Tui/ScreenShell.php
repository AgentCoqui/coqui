<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

/**
 * Composes optional header, footer, and sidebar regions into a viewport.
 */
final readonly class ScreenShell
{
    public const string SIDEBAR_LEFT = 'left';
    public const string SIDEBAR_RIGHT = 'right';

    /**
     * @param list<string> $contentLines
     */
    public function __construct(
        private array $contentLines,
        private ?ShellRegion $header = null,
        private ?ShellRegion $footer = null,
        private ?ShellRegion $sidebar = null,
        private string $sidebarPosition = self::SIDEBAR_RIGHT,
        private int $contentMinWidth = 24,
        private int $contentMinHeight = 4,
    ) {}

    /**
     * @return list<string>
     */
    public function render(int $width, int $height): array
    {
        $viewportWidth = max(1, $width);
        $viewportHeight = max(1, $height);

        $header = $this->header;
        $footer = $this->footer;
        $sidebar = $this->sidebar;

        while (true) {
            $headerHeight = $header !== null ? count($header->lines) : 0;
            $footerHeight = $footer !== null ? count($footer->lines) : 0;
            $sidebarWidth = $this->resolveSidebarWidth($sidebar);
            $contentWidth = $viewportWidth - ($sidebar !== null ? $sidebarWidth + 1 : 0);
            $contentHeight = $viewportHeight - $headerHeight - $footerHeight;

            $drop = null;
            $dropPriority = -1;

            if ($sidebar !== null && $contentWidth < $this->contentMinWidth) {
                $drop = 'sidebar';
                $dropPriority = $sidebar->collapsePriority;
            }

            if ($header !== null && $contentHeight < $this->contentMinHeight && $header->collapsePriority > $dropPriority) {
                $drop = 'header';
                $dropPriority = $header->collapsePriority;
            }

            if ($footer !== null && $contentHeight < $this->contentMinHeight && $footer->collapsePriority > $dropPriority) {
                $drop = 'footer';
            }

            if ($drop === null) {
                break;
            }

            if ($drop === 'header') {
                $header = null;
            } elseif ($drop === 'footer') {
                $footer = null;
            } elseif ($drop === 'sidebar') {
                $sidebar = null;
            }
        }

        $headerHeight = $header !== null ? count($header->lines) : 0;
        $footerHeight = $footer !== null ? count($footer->lines) : 0;
        $sidebarWidth = $this->resolveSidebarWidth($sidebar);
        $contentWidth = max(1, $viewportWidth - ($sidebar !== null ? $sidebarWidth + 1 : 0));
        $contentHeight = max(1, $viewportHeight - $headerHeight - $footerHeight);

        $lines = [];

        foreach (($header !== null ? $header->lines : []) as $line) {
            $lines[] = $this->fitLine($line, $viewportWidth);
        }

        $contentLines = $this->normalizeLines($this->contentLines, $contentHeight);
        if ($sidebar !== null) {
            $sidebarLines = $this->normalizeLines($sidebar->lines, $contentHeight);

            foreach ($contentLines as $index => $line) {
                $contentPart = $this->fitLine($line, $contentWidth);
                $sidebarPart = $this->fitLine($sidebarLines[$index] ?? '', $sidebarWidth);
                $lines[] = $this->sidebarPosition === self::SIDEBAR_LEFT
                    ? $sidebarPart . ' ' . $contentPart
                    : $contentPart . ' ' . $sidebarPart;
            }
        } else {
            foreach ($contentLines as $line) {
                $lines[] = $this->fitLine($line, $viewportWidth);
            }
        }

        foreach (($footer !== null ? $footer->lines : []) as $line) {
            $lines[] = $this->fitLine($line, $viewportWidth);
        }

        $lines = array_slice($lines, 0, $viewportHeight);
        while (count($lines) < $viewportHeight) {
            $lines[] = str_repeat(' ', $viewportWidth);
        }

        return $lines;
    }

    private function resolveSidebarWidth(?ShellRegion $sidebar): int
    {
        if ($sidebar === null) {
            return 0;
        }

        if (is_int($sidebar->preferredWidth) && $sidebar->preferredWidth > 0) {
            return $sidebar->preferredWidth;
        }

        $width = 0;
        foreach ($sidebar->lines as $line) {
            $width = max($width, $this->visibleWidth($line));
        }

        return max(1, $width);
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private function normalizeLines(array $lines, int $targetHeight): array
    {
        $normalized = array_slice($lines, 0, $targetHeight);

        while (count($normalized) < $targetHeight) {
            $normalized[] = '';
        }

        return $normalized;
    }

    private function fitLine(string $line, int $width): string
    {
        if ($width <= 0) {
            return '';
        }

        $visible = $this->stripDecorations($line);
        $visibleWidth = $this->visibleWidth($line);

        if ($visibleWidth > $width && $line === $visible) {
            $line = mb_strimwidth($line, 0, $width, '');
            $visibleWidth = mb_strwidth($line);
        }

        if ($visibleWidth >= $width) {
            return $line;
        }

        return $line . str_repeat(' ', $width - $visibleWidth);
    }

    private function visibleWidth(string $line): int
    {
        return mb_strwidth($this->stripDecorations($line));
    }

    private function stripDecorations(string $line): string
    {
        $line = preg_replace('/\e\[[\d;?]*[A-Za-z]/', '', $line) ?? $line;

        return preg_replace('/<\/?[^>]+>/', '', $line) ?? $line;
    }
}