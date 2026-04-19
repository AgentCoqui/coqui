<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Converts CSV/TSV files into markdown tables.
 */
final class CsvExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $delimiter = $extension === 'tsv' ? "\t" : ',';

        $handle = fopen($absolutePath, 'r');
        if ($handle === false) {
            return ExtractorResult::fail('Failed to open file');
        }

        try {
            return $this->parseToMarkdownTable($handle, $delimiter);
        } finally {
            fclose($handle);
        }
    }

    public function supportedExtensions(): array
    {
        return ['csv', 'tsv'];
    }

    /**
     * @param resource $handle
     */
    private function parseToMarkdownTable($handle, string $delimiter): ExtractorResult
    {
        $headers = fgetcsv($handle, 0, $delimiter, '"', '');
        if ($headers === false || $headers === [null]) {
            return ExtractorResult::fail('File is empty or has no valid header row');
        }

        // Sanitize headers
        $headers = array_map(static fn(mixed $h): string => trim((string) $h), $headers);
        $colCount = count($headers);

        $lines = [];
        $lines[] = '| ' . implode(' | ', $headers) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, $colCount, '---')) . ' |';

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            // Pad or trim row to match header column count
            $cells = array_pad(
                array_map(static fn(mixed $c): string => trim((string) $c), $row),
                $colCount,
                '',
            );
            $cells = array_slice($cells, 0, $colCount);

            // Escape pipe characters in cell values
            $cells = array_map(static fn(string $c): string => str_replace('|', '\\|', $c), $cells);

            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        if (count($lines) <= 2) {
            return ExtractorResult::fail('File has headers but no data rows');
        }

        $output = implode("\n", $lines);

        return ExtractorResult::ok($output, self::estimateTokens($output));
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
