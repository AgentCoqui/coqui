<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use Smalot\PdfParser\Parser;

/**
 * Extracts text content from PDF files.
 */
final class PdfExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);
            $text = trim($pdf->getText());
        } catch (\Throwable $e) {
            return ExtractorResult::fail('PDF extraction failed: ' . $e->getMessage());
        }

        if ($text === '') {
            return ExtractorResult::fail('PDF contains no extractable text');
        }

        return ExtractorResult::ok($text, self::estimateTokens($text));
    }

    public function supportedExtensions(): array
    {
        return ['pdf'];
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
