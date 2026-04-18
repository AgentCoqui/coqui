<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use PhpOffice\PhpWord\IOFactory;

/**
 * Extracts text content from DOCX files.
 */
final class DocxExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        try {
            $phpWord = IOFactory::load($absolutePath, 'Word2007');
        } catch (\Throwable $e) {
            return ExtractorResult::fail('DOCX extraction failed: ' . $e->getMessage());
        }

        $textParts = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text = $this->extractElementText($element);
                if ($text !== '') {
                    $textParts[] = $text;
                }
            }
        }

        $content = trim(implode("\n\n", $textParts));
        if ($content === '') {
            return ExtractorResult::fail('DOCX contains no extractable text');
        }

        return ExtractorResult::ok($content, self::estimateTokens($content));
    }

    public function supportedExtensions(): array
    {
        return ['docx'];
    }

    private function extractElementText(object $element): string
    {
        if (method_exists($element, 'getText')) {
            return trim((string) $element->getText());
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                if (!is_object($child)) {
                    continue;
                }
                $text = $this->extractElementText($child);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            return implode("\n", $parts);
        }

        return '';
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
