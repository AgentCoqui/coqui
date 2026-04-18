<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use SimpleXMLElement;
use ZipArchive;

/**
 * Extracts worksheet data from XLSX files into markdown tables.
 *
 * This extractor is optional at runtime and depends on ZipArchive support.
 */
final class XlsxExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        if (!self::isRuntimeSupported()) {
            return ExtractorResult::fail('XLSX extraction requires the PHP zip extension');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath);
        if ($opened !== true) {
            return ExtractorResult::fail('Failed to open XLSX archive');
        }

        try {
            $workbookXml = $zip->getFromName('xl/workbook.xml');
            if (!is_string($workbookXml)) {
                return ExtractorResult::fail('XLSX workbook is missing xl/workbook.xml');
            }

            $relationships = $this->readWorkbookRelationships($zip);
            $sharedStrings = $this->readSharedStrings($zip);
            $sheets = $this->readSheetDefinitions($workbookXml, $relationships);

            if ($sheets === []) {
                return ExtractorResult::fail('XLSX workbook contains no readable worksheets');
            }

            $sections = [];
            foreach ($sheets as $sheet) {
                $sheetXml = $zip->getFromName($sheet['path']);
                if (!is_string($sheetXml)) {
                    continue;
                }

                $rows = $this->readSheetRows($sheetXml, $sharedStrings);
                if ($rows === []) {
                    continue;
                }

                $table = $this->renderMarkdownTable($rows);
                if ($table === null) {
                    continue;
                }

                $sections[] = '#### Sheet: ' . $sheet['name'] . "\n\n" . $table;
            }

            if ($sections === []) {
                return ExtractorResult::fail('XLSX workbook contains no extractable rows');
            }

            $content = implode("\n\n", $sections);

            return ExtractorResult::ok($content, BackstoryTextReader::estimateTokens($content));
        } finally {
            $zip->close();
        }
    }

    public function supportedExtensions(): array
    {
        return ['xlsx'];
    }

    public static function isRuntimeSupported(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /**
     * @return array<string, string>
     */
    private function readWorkbookRelationships(ZipArchive $zip): array
    {
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($relationshipsXml)) {
            return [];
        }

        $xml = $this->loadXml($relationshipsXml);
        if ($xml === null) {
            return [];
        }

        $relationshipNodes = $xml->xpath('/*[local-name()="Relationships"]/*[local-name()="Relationship"]');
        if (!is_array($relationshipNodes)) {
            return [];
        }

        $relationships = [];
        foreach ($relationshipNodes as $relationship) {
            $id = trim((string) $relationship['Id']);
            $target = trim((string) $relationship['Target']);
            if ($id === '' || $target === '') {
                continue;
            }

            $normalized = str_replace('\\', '/', $target);
            $normalized = preg_replace('#^/+#', '', $normalized) ?? $normalized;
            if (!str_starts_with($normalized, 'xl/')) {
                $normalized = 'xl/' . ltrim($normalized, '/');
            }

            $relationships[$id] = $normalized;
        }

        return $relationships;
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $sharedStringsXml = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($sharedStringsXml)) {
            return [];
        }

        $xml = $this->loadXml($sharedStringsXml);
        if ($xml === null) {
            return [];
        }

        $stringNodes = $xml->xpath('/*[local-name()="sst"]/*[local-name()="si"]');
        if (!is_array($stringNodes)) {
            return [];
        }

        $strings = [];
        foreach ($stringNodes as $stringNode) {
            $strings[] = $this->extractTextRuns($stringNode);
        }

        return $strings;
    }

    /**
     * @param array<string, string> $relationships
     * @return list<array{name: string, path: string}>
     */
    private function readSheetDefinitions(string $workbookXml, array $relationships): array
    {
        $xml = $this->loadXml($workbookXml);
        if ($xml === null) {
            return [];
        }

        $sheetNodes = $xml->xpath('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"]');
        if (!is_array($sheetNodes)) {
            return [];
        }

        $sheetEntries = [];
        foreach ($sheetNodes as $sheet) {
            $attributes = $sheet->attributes();
            $relationshipAttributes = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            $name = trim((string) ($attributes['name'] ?? 'Sheet'));
            $relationshipId = trim((string) ($relationshipAttributes['id'] ?? ''));
            if ($relationshipId === '') {
                continue;
            }

            $path = $relationships[$relationshipId] ?? null;
            if ($path === null) {
                continue;
            }

            $sheetEntries[] = ['name' => $name, 'path' => $path];
        }

        return $sheetEntries;
    }

    /**
     * @param list<string> $sharedStrings
     * @return list<list<string>>
     */
    private function readSheetRows(string $sheetXml, array $sharedStrings): array
    {
        $xml = $this->loadXml($sheetXml);
        if ($xml === null) {
            return [];
        }

        $rowNodes = $xml->xpath('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]');
        if (!is_array($rowNodes)) {
            return [];
        }

        $rows = [];
        $maxColumns = 0;

        foreach ($rowNodes as $row) {
            $cells = [];

            $cellNodes = $row->xpath('./*[local-name()="c"]');
            if (!is_array($cellNodes)) {
                continue;
            }

            foreach ($cellNodes as $cell) {
                $reference = trim((string) $cell['r']);
                $columnIndex = $this->columnIndexFromReference($reference);
                $cells[$columnIndex] = $this->extractCellValue($cell, $sharedStrings);
            }

            if ($cells === []) {
                continue;
            }

            ksort($cells);
            $maxColumns = max($maxColumns, (int) max(array_keys($cells)) + 1);
            $rows[] = $cells;
        }

        if ($rows === [] || $maxColumns === 0) {
            return [];
        }

        /** @var list<list<string>> $normalizedRows */
        $normalizedRows = [];
        foreach ($rows as $row) {
            $line = array_fill(0, $maxColumns, '');
            foreach ($row as $index => $value) {
                $line[$index] = $value;
            }

            $normalizedRows[] = array_values(array_map(static fn(string $value): string => trim($value), $line));
        }

        return $normalizedRows;
    }

    /**
     * @param list<list<string>> $rows
     */
    private function renderMarkdownTable(array $rows): ?string
    {
        if ($rows === []) {
            return null;
        }

        $headers = $rows[0];
        $rows = array_slice($rows, 1);

        $headers = array_map(static fn(string $value): string => self::escapeCell($value === '' ? ' ' : $value), $headers);

        $hasDataRows = false;
        $lines = [];
        $lines[] = '| ' . implode(' | ', $headers) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';

        foreach ($rows as $row) {
            if (implode('', $row) === '') {
                continue;
            }

            $hasDataRows = true;
            $cells = array_map(
                static fn(string $value): string => self::escapeCell($value),
                array_pad($row, count($headers), ''),
            );
            $cells = array_slice($cells, 0, count($headers));
            $lines[] = '| ' . implode(' | ', $cells) . ' |';
        }

        if (!$hasDataRows) {
            return null;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $sharedStrings
     */
    private function extractCellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = trim((string) $cell['t']);
        $valueNodes = $cell->xpath('./*[local-name()="v"]');
        $value = is_array($valueNodes) && isset($valueNodes[0])
            ? trim((string) $valueNodes[0])
            : '';

        if ($type === 's') {
            $index = (int) $value;
            return $sharedStrings[$index] ?? '';
        }

        if ($type === 'inlineStr') {
            return $this->extractTextRuns($cell);
        }

        if ($type === 'b') {
            return $value === '1' ? 'true' : 'false';
        }

        return $value;
    }

    private function columnIndexFromReference(string $reference): int
    {
        if ($reference === '') {
            return 0;
        }

        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');

        $index = 0;
        $length = strlen($letters);
        for ($position = 0; $position < $length; $position++) {
            $index = ($index * 26) + (ord($letters[$position]) - 64);
        }

        return max(0, $index - 1);
    }

    private function loadXml(string $xml): ?SimpleXMLElement
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $element = simplexml_load_string($xml);

            return $element instanceof SimpleXMLElement ? $element : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function normalizeXmlText(string $text): string
    {
        return trim((string) (preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    private function extractTextRuns(SimpleXMLElement $element): string
    {
        $textNodes = $element->xpath('.//*[local-name()="t"]');
        if (!is_array($textNodes) || $textNodes === []) {
            return $this->normalizeXmlText((string) $element);
        }

        $parts = [];
        foreach ($textNodes as $textNode) {
            $parts[] = (string) $textNode;
        }

        return $this->normalizeXmlText(implode(' ', $parts));
    }

    private static function escapeCell(string $value): string
    {
        return str_replace('|', '\\|', trim($value));
    }
}