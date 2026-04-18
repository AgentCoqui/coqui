<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory;

/**
 * Represents a single discovered file in the backstory source directory.
 */
final readonly class BackstoryFileEntry
{
    public function __construct(
        /** Relative path from the backstory/ directory root (e.g. "01-folder/file3.docx"). */
        public string $relativePath,
        /** Absolute path on disk. */
        public string $absolutePath,
        /** Lowercase file extension without dot (e.g. "txt", "pdf"). */
        public string $extension,
    ) {}
}
