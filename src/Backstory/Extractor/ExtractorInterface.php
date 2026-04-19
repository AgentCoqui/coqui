<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Contract for backstory file content extractors.
 *
 * Each implementation handles one or more file extensions, extracting
 * text content and converting it to a markdown-compatible format.
 */
interface ExtractorInterface
{
    /**
     * Extract content from a file and return it as markdown-ready text.
     */
    public function extract(string $absolutePath): ExtractorResult;

    /**
     * File extensions this extractor handles (lowercase, no dot).
     *
     * @return list<string>
     */
    public function supportedExtensions(): array;
}
