<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

/**
 * Result of a single WatchJob scan cycle.
 */
final readonly class WatchJobResult
{
    /**
     * @param int    $added    Number of new items detected and processed
     * @param int    $modified Number of changed items detected and processed
     * @param int    $removed  Number of removed items detected and processed
     * @param list<string> $errors  Non-fatal error messages (e.g. malformed files)
     */
    public function __construct(
        public int $added = 0,
        public int $modified = 0,
        public int $removed = 0,
        public array $errors = [],
    ) {}

    public function hasChanges(): bool
    {
        return $this->added > 0 || $this->modified > 0 || $this->removed > 0;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function total(): int
    {
        return $this->added + $this->modified + $this->removed;
    }
}
