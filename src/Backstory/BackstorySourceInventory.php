<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

/**
 * Full discovery result for a profile backstory tree.
 */
final readonly class BackstorySourceInventory
{
    /**
     * @param list<BackstoryFileEntry> $supportedEntries
     * @param list<BackstoryUnsupportedFileEntry> $unsupportedEntries
     */
    public function __construct(
        public array $supportedEntries,
        public array $unsupportedEntries,
    ) {}

    public function isEmpty(): bool
    {
        return $this->supportedEntries === [] && $this->unsupportedEntries === [];
    }

    public function totalFiles(): int
    {
        return count($this->supportedEntries) + count($this->unsupportedEntries);
    }

    public function supportedFiles(): int
    {
        return count($this->supportedEntries);
    }

    public function unsupportedFiles(): int
    {
        return count($this->unsupportedEntries);
    }
}