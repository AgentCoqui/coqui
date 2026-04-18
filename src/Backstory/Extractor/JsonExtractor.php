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
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        $content = trim($result->content);
        if ($content === '') {
            return ExtractorResult::fail('File is empty');
        }

        // Validate JSON
        json_decode($content);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ExtractorResult::fail('Invalid JSON: ' . json_last_error_msg());
        }

        $output = BackstoryTextReader::toCodeFence($content, 'json');

        return ExtractorResult::ok($output, BackstoryTextReader::estimateTokens($output));
    }

    public function supportedExtensions(): array
    {
        return ['json'];
    }

}
