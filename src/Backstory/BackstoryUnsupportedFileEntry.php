<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

/**
 * Represents a discovered backstory source file that is skipped because its extension is unsupported.
 */
final readonly class BackstoryUnsupportedFileEntry
{
    public function __construct(
        /** Relative path from the backstory/ directory root. */
        public string $relativePath,
        /** Absolute path on disk. */
        public string $absolutePath,
        /** Lowercase file extension without dot, or an empty string when none exists. */
        public string $extension,
        /** Human-readable explanation for why the file was skipped. */
        public string $reason,
    ) {}
}