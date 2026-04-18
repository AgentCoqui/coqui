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
        $content = file_get_contents($absolutePath);

        if ($content === false) {
            return ExtractorResult::fail('Failed to read file');
        }

        $content = trim($content);
        if ($content === '') {
            return ExtractorResult::fail('File is empty');
        }

        $output = "```yaml\n" . $content . "\n```";

        return ExtractorResult::ok($output, self::estimateTokens($output));
    }

    public function supportedExtensions(): array
    {
        return ['yaml', 'yml'];
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
