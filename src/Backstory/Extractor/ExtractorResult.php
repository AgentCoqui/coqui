<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Result of extracting content from a single backstory source file.
 */
final readonly class ExtractorResult
{
    public function __construct(
        public ?string $content,
        public bool $success,
        public ?string $error = null,
        public int $tokenEstimate = 0,
    ) {}

    public static function ok(string $content, int $tokenEstimate): self
    {
        return new self(content: $content, success: true, tokenEstimate: $tokenEstimate);
    }

    public static function fail(string $error): self
    {
        return new self(content: null, success: false, error: $error);
    }
}
