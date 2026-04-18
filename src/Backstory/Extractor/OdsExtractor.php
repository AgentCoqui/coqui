<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

use SimpleXMLElement;
use ZipArchive;

final class OdsExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        if (!self::isRuntimeSupported()) {
            return ExtractorResult::fail('ODS extraction requires the PHP zip extension');
        }

        $zip = new ZipArchive();
        $opened = $zip->open($absolutePath);
        if ($opened !== true) {
            return ExtractorResult::fail('Failed to open ODS archive');
        }

        try {
            $xml = OpenDocumentArchiveReader::loadContentXml($zip);
            if ($xml === null) {
                return ExtractorResult::fail('ODS workbook is missing content.xml');
            }

            $tableNodes = $xml->xpath('/*[local-name()="document-content"]/*[local-name()="body"]/*[local-name()="spreadsheet"]/*[local-name()="table"]');
            if (!is_array($tableNodes) || $tableNodes === []) {
                return ExtractorResult::fail('ODS workbook contains no readable sheets');
            }

            $sections = [];
            foreach ($tableNodes as $index => $tableNode) {
                $rows = $this->readTableRows($tableNode);
                if ($rows === []) {
                    continue;
                }

                $table = $this->renderMarkdownTable($rows);
                if ($table === null) {
                    continue;
                }

                $name = OpenDocumentArchiveReader::attributeValue($tableNode, OpenDocumentArchiveReader::TABLE_NS, 'name');
                if ($name === '') {
                    $name = 'Sheet ' . ($index + 1);
                }

                $sections[] = '#### Sheet: ' . $name . "\n\n" . $table;
            }

            if ($sections === []) {
                return ExtractorResult::fail('ODS workbook contains no extractable rows');
            }

            $content = implode("\n\n", $sections);

            return ExtractorResult::ok($content, BackstoryTextReader::estimateTokens($content));
        } finally {
            $zip->close();
        }
    }

    public function supportedExtensions(): array
    {
        return ['ods'];
    }

    public static function isRuntimeSupported(): bool
    {
        return OpenDocumentArchiveReader::isRuntimeSupported();
    }

    /**
     * @return list<list<string>>
     */
    private function readTableRows(SimpleXMLElement $tableNode): array
    {
        $rowNodes = $tableNode->xpath('./*[local-name()="table-row"]');
        if (!is_array($rowNodes)) {
            return [];
        }

        $rows = [];
        foreach ($rowNodes as $rowNode) {
            $repeatRows = OpenDocumentArchiveReader::repeatCount(
                $rowNode,
                OpenDocumentArchiveReader::TABLE_NS,
                'number-rows-repeated',
            );

            $row = $this->readRowCells($rowNode);
            if ($row === [] || $this->rowIsEmpty($row)) {
                continue;
            }

            for ($i = 0; $i < $repeatRows; $i++) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function readRowCells(SimpleXMLElement $rowNode): array
    {
        $cellNodes = $rowNode->xpath('./*[local-name()="table-cell" or local-name()="covered-table-cell"]');
        if (!is_array($cellNodes)) {
            return [];
        }

        $cells = [];
        foreach ($cellNodes as $cellNode) {
            $repeatColumns = OpenDocumentArchiveReader::repeatCount(
                $cellNode,
                OpenDocumentArchiveReader::TABLE_NS,
                'number-columns-repeated',
            );

            $value = OpenDocumentArchiveReader::localName($cellNode) === 'covered-table-cell'
                ? ''
                : $this->extractCellValue($cellNode);

            for ($i = 0; $i < $repeatColumns; $i++) {
                $cells[] = $value;
            }
        }

        while ($cells !== [] && end($cells) === '') {
            array_pop($cells);
        }

        return $cells;
    }

    private function extractCellValue(SimpleXMLElement $cellNode): string
    {
        $paragraphNodes = $cellNode->xpath('.//*[local-name()="p"]');
        if (is_array($paragraphNodes) && $paragraphNodes !== []) {
            $paragraphs = [];
            foreach ($paragraphNodes as $paragraphNode) {
                $text = OpenDocumentArchiveReader::extractNodeText($paragraphNode);
                if ($text !== '') {
                    $paragraphs[] = $text;
                }
            }

            if ($paragraphs !== []) {
                return implode('<br>', $paragraphs);
            }
        }

        foreach (['string-value', 'value', 'date-value', 'time-value', 'boolean-value'] as $attributeName) {
            $value = OpenDocumentArchiveReader::attributeValue(
                $cellNode,
                OpenDocumentArchiveReader::OFFICE_NS,
                $attributeName,
            );
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param list<string> $row
     */
    private function rowIsEmpty(array $row): bool
    {
        return implode('', $row) === '';
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

        $headers = array_map(
            static fn(string $value): string => self::escapeCell($value === '' ? ' ' : $value),
            $headers,
        );

        $hasDataRows = false;
        $lines = [];
        $lines[] = '| ' . implode(' | ', $headers) . ' |';
        $lines[] = '| ' . implode(' | ', array_fill(0, count($headers), '---')) . ' |';

        foreach ($rows as $row) {
            if ($this->rowIsEmpty($row)) {
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

    private static function escapeCell(string $value): string
    {
        return str_replace('|', '\\|', trim($value));
    }
}