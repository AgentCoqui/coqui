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
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        $content = trim($result->content);
        if ($content === '') {
            return ExtractorResult::fail('File is empty');
        }

        return ExtractorResult::ok($content, BackstoryTextReader::estimateTokens($content));
    }

    public function supportedExtensions(): array
    {
        return ['txt'];
    }

}
