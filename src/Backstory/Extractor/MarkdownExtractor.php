<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Passes through markdown files (.md) without transformation.
 */
final class MarkdownExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        $content = file_get_contents($absolutePath);

        if ($content === false) {
            return ExtractorResult::fail('Failed to read file');
        }

        $content = trim($content);
        if ($content === '') {
            return ExtractorResult::fail('File is empty');
        }

        return ExtractorResult::ok($content, self::estimateTokens($content));
    }

    public function supportedExtensions(): array
    {
        return ['md'];
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
