<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Wraps YAML files in a fenced code block.
 */
final class YamlExtractor implements ExtractorInterface
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

        $output = BackstoryTextReader::toCodeFence($content, 'yaml');

        return ExtractorResult::ok($output, BackstoryTextReader::estimateTokens($output));
    }

    public function supportedExtensions(): array
    {
        return ['yaml', 'yml'];
    }

}
