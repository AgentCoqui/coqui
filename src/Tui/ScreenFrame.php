<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tui;

/**
 * Normalized rendered screen content for a single terminal viewport.
 */
final readonly class ScreenFrame
{
    /**
     * @param list<string> $lines
     */
    public function __construct(
        public array $lines,
        public int $width,
        public int $height,
    ) {}

    public static function fromRenderedOutput(string $rendered, int $width, int $height): self
    {
        $normalizedHeight = max(1, $height);
        $normalizedWidth = max(1, $width);
        $lines = self::splitLines($rendered);
        $lines = array_slice($lines, 0, $normalizedHeight);

        while (count($lines) < $normalizedHeight) {
            $lines[] = '';
        }

        return new self($lines, $normalizedWidth, $normalizedHeight);
    }

    public function sharesViewport(self $other): bool
    {
        return $this->width === $other->width && $this->height === $other->height;
    }

    /**
     * @return list<ScreenFramePatch>
     */
    public function diffAgainst(self $previous): array
    {
        $patches = [];
        $limit = max(count($this->lines), count($previous->lines));

        for ($row = 0; $row < $limit; $row++) {
            $nextLine = $this->lines[$row] ?? '';
            $previousLine = $previous->lines[$row] ?? '';

            if ($nextLine !== $previousLine) {
                $patches[] = new ScreenFramePatch($row, $nextLine);
            }
        }

        return $patches;
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $rendered): array
    {
        if ($rendered === '') {
            return [];
        }

        $rendered = str_replace(["\r\n", "\r"], "\n", $rendered);
        $lines = explode("\n", $rendered);

        if (end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }
}