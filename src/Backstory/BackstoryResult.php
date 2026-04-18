<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

/**
 * Result of a backstory generation run.
 */
final readonly class BackstoryResult
{
    /**
     * @param array<int, array{relative_path: string, error: string}> $errors
     */
    public function __construct(
        public int $totalFiles,
        public int $failedFiles,
        public int $totalTokens,
        public float $generationTimeMs,
        public array $errors = [],
    ) {}
}
