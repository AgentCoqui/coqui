<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\TextExtractor;
use CoquiBot\Coqui\Backstory\Extractor\MarkdownExtractor;
use CoquiBot\Coqui\Backstory\Extractor\JsonExtractor;
use CoquiBot\Coqui\Backstory\Extractor\YamlExtractor;
use CoquiBot\Coqui\Backstory\Extractor\CsvExtractor;
use CoquiBot\Coqui\Backstory\Extractor\CodeBlockExtractor;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory;
use CoquiBot\Coqui\Backstory\Extractor\HtmlExtractor;
use CoquiBot\Coqui\Backstory\Extractor\RtfExtractor;
use CoquiBot\Coqui\Backstory\Extractor\XlsxExtractor;
use CoquiBot\Coqui\Backstory\Extractor\XmlExtractor;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-backstory-extractor-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

// --- TextExtractor ---

test('TextExtractor extracts plain text', function () {
    $path = $this->tempDir . '/file.txt';
    file_put_contents($path, 'Hello world');

    $extractor = new TextExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toBe('Hello world');
    expect($result->tokenEstimate)->toBeGreaterThan(0);
});

test('TextExtractor fails on empty file', function () {
    $path = $this->tempDir . '/empty.txt';
    file_put_contents($path, '');

    $extractor = new TextExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('empty');
});

test('TextExtractor reports supported extensions', function () {
    $extractor = new TextExtractor();
    expect($extractor->supportedExtensions())->toBe(['txt']);
});

test('TextExtractor normalizes UTF-16 input', function () {
    $path = $this->tempDir . '/utf16.txt';
    $content = "\xFF\xFE" . mb_convert_encoding('Hello from UTF-16', 'UTF-16LE', 'UTF-8');
    file_put_contents($path, $content);

    $extractor = new TextExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toBe('Hello from UTF-16');
});

// --- MarkdownExtractor ---

test('MarkdownExtractor passes through markdown', function () {
    $path = $this->tempDir . '/file.md';
    file_put_contents($path, "# Title\n\nSome content.");

    $extractor = new MarkdownExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toBe("# Title\n\nSome content.");
});

test('MarkdownExtractor supports mdx files', function () {
    $path = $this->tempDir . '/component.mdx';
    file_put_contents($path, "# Profile\n\n<Component prop=\"value\" />");

    $extractor = new MarkdownExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('<Component prop="value" />');
    expect($extractor->supportedExtensions())->toContain('mdx');
});

// --- JsonExtractor ---

test('JsonExtractor wraps valid JSON in code fence', function () {
    $path = $this->tempDir . '/data.json';
    file_put_contents($path, '{"key": "value"}');

    $extractor = new JsonExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('```json');
    expect($result->content)->toContain('{"key": "value"}');
    expect($result->content)->toMatch('/```$/');
});

test('JsonExtractor fails on invalid JSON', function () {
    $path = $this->tempDir . '/bad.json';
    file_put_contents($path, '{not valid}');

    $extractor = new JsonExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('Invalid JSON');
});

// --- YamlExtractor ---

test('YamlExtractor wraps YAML in code fence', function () {
    $path = $this->tempDir . '/config.yaml';
    file_put_contents($path, "key: value\nlist:\n  - item");

    $extractor = new YamlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('```yaml');
    expect($result->content)->toContain('key: value');
});

test('YamlExtractor supports yml extension', function () {
    $extractor = new YamlExtractor();
    expect($extractor->supportedExtensions())->toContain('yml');
});

// --- CsvExtractor ---

test('CsvExtractor converts CSV to markdown table', function () {
    $path = $this->tempDir . '/data.csv';
    file_put_contents($path, "Name,Age\nAlice,30\nBob,25");

    $extractor = new CsvExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('| Name | Age |');
    expect($result->content)->toContain('| --- | --- |');
    expect($result->content)->toContain('| Alice | 30 |');
    expect($result->content)->toContain('| Bob | 25 |');
});

test('CsvExtractor handles TSV files', function () {
    $path = $this->tempDir . '/data.tsv';
    file_put_contents($path, "Name\tAge\nAlice\t30");

    $extractor = new CsvExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('| Name | Age |');
    expect($result->content)->toContain('| Alice | 30 |');
});

test('CsvExtractor escapes pipe characters in values', function () {
    $path = $this->tempDir . '/data.csv';
    file_put_contents($path, "Name,Note\nAlice,\"a|b\"");

    $extractor = new CsvExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('a\\|b');
});

test('CsvExtractor fails on headers-only file', function () {
    $path = $this->tempDir . '/data.csv';
    file_put_contents($path, "Name,Age");

    $extractor = new CsvExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('no data rows');
});

// --- HtmlExtractor ---

test('HtmlExtractor sanitizes and converts html to markdown', function () {
    $path = $this->tempDir . '/profile.html';
    file_put_contents($path, '<h1>Title</h1><script>alert(1)</script><p>Hello <strong>world</strong>.</p>');

    $extractor = new HtmlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('# Title');
    expect($result->content)->toContain('Hello **world**.');
    expect($result->content)->not->toContain('alert(1)');
});

test('HtmlExtractor strips dangerous href values', function () {
    $path = $this->tempDir . '/links.html';
    file_put_contents($path, '<p><a href="javascript:alert(1)">Click</a></p>');

    $extractor = new HtmlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('Click');
    expect($result->content)->not->toContain('javascript:');
});

// --- XmlExtractor ---

test('XmlExtractor renders simple xml as markdown outline', function () {
    $path = $this->tempDir . '/simple.xml';
    file_put_contents($path, '<profile><name>Alice</name><traits><curiosity>high</curiosity></traits></profile>');

    $extractor = new XmlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('- profile');
    expect($result->content)->toContain('- name: Alice');
    expect($result->content)->toContain('- curiosity: high');
});

test('XmlExtractor falls back to fenced xml for mixed content', function () {
    $path = $this->tempDir . '/complex.xml';
    file_put_contents($path, '<profile>Lead text<detail>value</detail></profile>');

    $extractor = new XmlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('```xml');
    expect($result->content)->toContain('<profile>');
});

// --- RtfExtractor ---

test('RtfExtractor extracts plain text from simple rtf', function () {
    $path = $this->tempDir . '/story.rtf';
    file_put_contents($path, '{\rtf1\ansi Hello \b world\par Second line}');

    $extractor = new RtfExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('Hello world');
    expect($result->content)->toContain('Second line');
});

test('RtfExtractor rejects invalid rtf files', function () {
    $path = $this->tempDir . '/story.rtf';
    file_put_contents($path, 'not actually rtf');

    $extractor = new RtfExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('Invalid RTF');
});

// --- CodeBlockExtractor ---

test('CodeBlockExtractor wraps source files in fenced blocks', function () {
    $path = $this->tempDir . '/script.py';
    file_put_contents($path, "def greet():\n    return 'hi'\n");

    $extractor = new CodeBlockExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('```python');
    expect($result->content)->toContain("def greet():\n    return 'hi'");
});

// --- XlsxExtractor ---

test('XlsxExtractor converts workbook sheets into markdown tables', function () {
    if (!XlsxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/book.xlsx';
    createTestXlsx($path, [
        'Timeline' => [
            ['Year', 'Event'],
            ['2024', 'Launch'],
            ['2025', 'Expansion'],
        ],
        'Values' => [
            ['Trait', 'Level'],
            ['Curiosity', 'High'],
        ],
    ]);

    $extractor = new XlsxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Sheet: Timeline');
    expect($result->content)->toContain('| Year | Event |');
    expect($result->content)->toContain('| 2024 | Launch |');
    expect($result->content)->toContain('#### Sheet: Values');
    expect($result->content)->toContain('| Trait | Level |');
});

test('XlsxExtractor fails when workbook has no data rows', function () {
    if (!XlsxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/empty.xlsx';
    createTestXlsx($path, [
        'Sheet1' => [
            ['Header'],
        ],
    ]);

    $extractor = new XlsxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('no extractable rows');
});

// --- ExtractorFactory ---

test('ExtractorFactory maps extensions to extractors', function () {
    $factory = new ExtractorFactory();

    expect($factory->get('txt'))->toBeInstanceOf(TextExtractor::class);
    expect($factory->get('md'))->toBeInstanceOf(MarkdownExtractor::class);
    expect($factory->get('json'))->toBeInstanceOf(JsonExtractor::class);
    expect($factory->get('yaml'))->toBeInstanceOf(YamlExtractor::class);
    expect($factory->get('yml'))->toBeInstanceOf(YamlExtractor::class);
    expect($factory->get('csv'))->toBeInstanceOf(CsvExtractor::class);
    expect($factory->get('tsv'))->toBeInstanceOf(CsvExtractor::class);
    expect($factory->get('mdx'))->toBeInstanceOf(MarkdownExtractor::class);
    expect($factory->get('html'))->toBeInstanceOf(HtmlExtractor::class);
    expect($factory->get('xml'))->toBeInstanceOf(XmlExtractor::class);
    expect($factory->get('rtf'))->toBeInstanceOf(RtfExtractor::class);
    expect($factory->get('py'))->toBeInstanceOf(CodeBlockExtractor::class);
    if (XlsxExtractor::isRuntimeSupported()) {
        expect($factory->get('xlsx'))->toBeInstanceOf(XlsxExtractor::class);
    }
});

test('ExtractorFactory returns null for unsupported extension', function () {
    $factory = new ExtractorFactory();
    expect($factory->get('exe'))->toBeNull();
});

test('ExtractorFactory isSupported', function () {
    $factory = new ExtractorFactory();
    expect($factory->isSupported('txt'))->toBeTrue();
    expect($factory->isSupported('TXT'))->toBeTrue();
    expect($factory->isSupported('php'))->toBeTrue();
    expect($factory->isSupported('xlsx'))->toBe(XlsxExtractor::isRuntimeSupported());
    expect($factory->isSupported('exe'))->toBeFalse();
});

/**
 * @param array<string, list<list<string>>> $sheets
 */
function createTestXlsx(string $path, array $sheets): void
{
    $zip = new ZipArchive();
    $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    expect($opened)->toBeTrue();

    $sharedStringMap = [];
    $sharedStrings = [];
    $sheetXml = [];
    $sheetIndex = 1;

    foreach ($sheets as $sheetName => $rows) {
        $sheetRows = [];
        foreach ($rows as $rowIndex => $row) {
            $cells = [];
            foreach ($row as $columnIndex => $value) {
                $key = (string) $value;
                if (!array_key_exists($key, $sharedStringMap)) {
                    $sharedStringMap[$key] = count($sharedStrings);
                    $sharedStrings[] = $key;
                }

                $cellRef = columnLetter($columnIndex + 1) . ($rowIndex + 1);
                $cells[] = '<c r="' . $cellRef . '" t="s"><v>' . $sharedStringMap[$key] . '</v></c>';
            }

            $sheetRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
        }

        $sheetXml[] = [
            'id' => $sheetIndex,
            'name' => $sheetName,
            'xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<sheetData>' . implode('', $sheetRows) . '</sheetData>'
                . '</worksheet>',
        ];
        $sheetIndex++;
    }

    $workbookSheets = [];
    $workbookRelationships = [];

    foreach ($sheetXml as $sheet) {
        $workbookSheets[] = '<sheet name="' . htmlspecialchars($sheet['name'], ENT_XML1) . '" sheetId="' . $sheet['id'] . '" r:id="rId' . $sheet['id'] . '"/>';
        $workbookRelationships[] = '<Relationship Id="rId' . $sheet['id'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheet['id'] . '.xml"/>';
        $zip->addFromString('xl/worksheets/sheet' . $sheet['id'] . '.xml', $sheet['xml']);
    }

    $sharedStringItems = array_map(
        static fn(string $value): string => '<si><t>' . htmlspecialchars($value, ENT_XML1) . '</t></si>',
        $sharedStrings,
    );

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . implode('', array_map(
            static fn(array $sheet): string => '<Override PartName="/xl/worksheets/sheet' . $sheet['id'] . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
            $sheetXml,
        ))
        . '</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . implode('', $workbookSheets) . '</sheets>'
        . '</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', $workbookRelationships)
        . '<Relationship Id="rIdSharedStrings" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . count($sharedStrings) . '" uniqueCount="' . count($sharedStrings) . '">'
        . implode('', $sharedStringItems)
        . '</sst>');

    $zip->close();
}

function columnLetter(int $index): string
{
    $letters = '';

    while ($index > 0) {
        $index--;
        $letters = chr(($index % 26) + 65) . $letters;
        $index = intdiv($index, 26);
    }

    return $letters;
}
