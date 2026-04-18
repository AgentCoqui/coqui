<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Extracts plain text files (.txt) as-is.
 */
final class TextExtractor implements ExtractorInterface
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
        return ['txt'];
    }

    private static function estimateTokens(string $text): int
    {
        // Rough heuristic: ~4 characters per token
        return (int) ceil(mb_strlen($text) / 4);
    }
}
