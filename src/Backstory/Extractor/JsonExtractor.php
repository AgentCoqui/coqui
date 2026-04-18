<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Wraps JSON files in a fenced code block.
 */
final class JsonExtractor implements ExtractorInterface
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

        // Validate JSON
        json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ExtractorResult::fail('Invalid JSON: ' . json_last_error_msg());
        }

        $output = "```json\n" . $content . "\n```";

        return ExtractorResult::ok($output, self::estimateTokens($output));
    }

    public function supportedExtensions(): array
    {
        return ['json'];
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
