<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\TextExtractor;
use CoquiBot\Coqui\Backstory\Extractor\MarkdownExtractor;
use CoquiBot\Coqui\Backstory\Extractor\JsonExtractor;
use CoquiBot\Coqui\Backstory\Extractor\YamlExtractor;
use CoquiBot\Coqui\Backstory\Extractor\CsvExtractor;
use CoquiBot\Coqui\Backstory\Extractor\CodeBlockExtractor;
use CoquiBot\Coqui\Backstory\Extractor\DocxExtractor;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory;
use CoquiBot\Coqui\Backstory\Extractor\HtmlExtractor;
use CoquiBot\Coqui\Backstory\Extractor\OdpExtractor;
use CoquiBot\Coqui\Backstory\Extractor\OdsExtractor;
use CoquiBot\Coqui\Backstory\Extractor\OdtExtractor;
use CoquiBot\Coqui\Backstory\Extractor\PptxExtractor;
use CoquiBot\Coqui\Backstory\Extractor\RtfExtractor;
use CoquiBot\Coqui\Backstory\Extractor\SqlExtractor;
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

// --- DocxExtractor ---

test('DocxExtractor reads docm files as Word OOXML documents', function () {
    $path = $this->tempDir . '/story.docm';
    createTestDocx($path, ['First paragraph', 'Second paragraph']);

    $extractor = new DocxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('First paragraph');
    expect($result->content)->toContain('Second paragraph');
    expect($extractor->supportedExtensions())->toContain('docm');
});

test('OdtExtractor reads text paragraphs from odt files', function () {
    if (!OdtExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/story.odt';
    createTestOdt($path, ['Quiet beginning', 'Second marker']);

    $extractor = new OdtExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('Quiet beginning');
    expect($result->content)->toContain('Second marker');
});

test('OdtExtractor preserves heading and list structure from LibreOffice documents', function () {
        if (!OdtExtractor::isRuntimeSupported()) {
                test()->markTestSkipped('ZipArchive is not available');
        }

        $path = $this->tempDir . '/structured.odt';
        createRawOdt($path, <<<'XML'
<text:h text:outline-level="1">Origin</text:h>
<text:p>Quiet beginning</text:p>
<text:list>
    <text:list-item><text:p>First marker</text:p></text:list-item>
    <text:list-item>
        <text:p>Second marker</text:p>
        <text:list>
            <text:list-item><text:p>Nested marker</text:p></text:list-item>
        </text:list>
    </text:list-item>
</text:list>
XML);

        $extractor = new OdtExtractor();
        $result = $extractor->extract($path);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('#### Origin');
        expect($result->content)->toContain('Quiet beginning');
        expect($result->content)->toContain('- First marker');
        expect($result->content)->toContain('- Second marker');
        expect($result->content)->toContain('  - Nested marker');
});

test('OdsExtractor converts sheets into markdown tables', function () {
    if (!OdsExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/book.ods';
    createTestOds($path, [
        'Timeline' => [
            ['Year', 'Event'],
            ['2026', 'OpenDocument support'],
        ],
    ]);

    $extractor = new OdsExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Sheet: Timeline');
    expect($result->content)->toContain('| Year | Event |');
    expect($result->content)->toContain('| 2026 | OpenDocument support |');
});

test('OdsExtractor preserves repeated rows and multiline cells from LibreOffice sheets', function () {
        if (!OdsExtractor::isRuntimeSupported()) {
                test()->markTestSkipped('ZipArchive is not available');
        }

        $path = $this->tempDir . '/repeated.ods';
        createRawOds($path, <<<'XML'
<table:table table:name="Timeline">
    <table:table-row>
        <table:table-cell office:value-type="string"><text:p>Alias</text:p></table:table-cell>
        <table:table-cell office:value-type="string"><text:p>Window</text:p></table:table-cell>
        <table:table-cell table:number-columns-repeated="1024"/>
    </table:table-row>
    <table:table-row table:number-rows-repeated="2">
        <table:table-cell office:value-type="string"><text:p>Kade</text:p></table:table-cell>
        <table:table-cell office:value-type="string"><text:p>0400Z</text:p><text:p>0600Z</text:p></table:table-cell>
        <table:table-cell table:number-columns-repeated="1024"/>
    </table:table-row>
</table:table>
XML);

        $extractor = new OdsExtractor();
        $result = $extractor->extract($path);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('| Alias | Window |');
        expect(substr_count($result->content, '| Kade | 0400Z<br>0600Z |'))->toBe(2);
});

test('OdsExtractor expands merged LibreOffice cells for markdown readability', function () {
        if (!OdsExtractor::isRuntimeSupported()) {
                test()->markTestSkipped('ZipArchive is not available');
        }

        $path = $this->tempDir . '/merged.ods';
        createRawOds($path, <<<'XML'
<table:table table:name="Merge Map">
    <table:table-row>
        <table:table-cell office:value-type="string" table:number-columns-spanned="2"><text:p>Region</text:p></table:table-cell>
        <table:covered-table-cell/>
        <table:table-cell office:value-type="string"><text:p>Status</text:p></table:table-cell>
    </table:table-row>
    <table:table-row>
        <table:table-cell office:value-type="string" table:number-columns-spanned="2"><text:p>North Wing</text:p></table:table-cell>
        <table:covered-table-cell/>
        <table:table-cell office:value-type="string"><text:p>Stable</text:p></table:table-cell>
    </table:table-row>
</table:table>
XML);

        $extractor = new OdsExtractor();
        $result = $extractor->extract($path);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('| Region | Region | Status |');
        expect($result->content)->toContain('| North Wing | North Wing | Stable |');
});

test('OdsExtractor fails when workbook has no data rows', function () {
    if (!OdsExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/empty.ods';
    createTestOds($path, [
        'Sheet1' => [
            ['Header'],
        ],
    ]);

    $extractor = new OdsExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('no extractable rows');
});

test('OdpExtractor converts slides into markdown sections', function () {
    if (!OdpExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/deck.odp';
    createTestOdp($path, [
        [
            'title' => 'Signals',
            'bullets' => ['Archive stays read-only'],
        ],
    ]);

    $extractor = new OdpExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Slide 1: Signals');
    expect($result->content)->toContain('- Archive stays read-only');
});

test('OdpExtractor falls back to non-generic slide names when no title frame is present', function () {
        if (!OdpExtractor::isRuntimeSupported()) {
                test()->markTestSkipped('ZipArchive is not available');
        }

        $path = $this->tempDir . '/named-slide.odp';
        createRawOdp($path, <<<'XML'
<draw:page draw:name="System Map">
    <draw:frame>
        <draw:text-box>
            <text:p>Quietly formidable</text:p>
            <text:p>Pattern beneath the surface</text:p>
        </draw:text-box>
    </draw:frame>
</draw:page>
XML);

        $extractor = new OdpExtractor();
        $result = $extractor->extract($path);

        expect($result->success)->toBeTrue();
        expect($result->content)->toContain('#### Slide 1: System Map');
        expect($result->content)->toContain('- Quietly formidable');
        expect($result->content)->toContain('- Pattern beneath the surface');
});

test('OdpExtractor fails when slides contain no text', function () {
    if (!OdpExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/blank.odp';
    createTestOdp($path, [
        [],
    ]);

    $extractor = new OdpExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('no extractable slide text');
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

test('CodeBlockExtractor no longer claims sql files', function () {
    $extractor = new CodeBlockExtractor();

    expect($extractor->supportedExtensions())->not->toContain('sql');
});

// --- SqlExtractor ---

test('SqlExtractor converts simple table inserts into markdown tables', function () {
    $path = $this->tempDir . '/financials.sql';
    file_put_contents($path, <<<'SQL'
CREATE TABLE financials (
    year INT,
    revenue DECIMAL(10, 2),
    profit DECIMAL(10, 2)
);
INSERT INTO financials (year, revenue, profit) VALUES
    (2018, 1000000.00, 200000.00),
    (2019, 1500000.00, 300000.00);
SQL);

    $extractor = new SqlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Table: financials');
    expect($result->content)->toContain('| year | revenue | profit |');
    expect($result->content)->toContain('| 2018 | 1000000.00 | 200000.00 |');
    expect($result->content)->not->toContain('#### Unparsed SQL');
});

test('SqlExtractor uses create table schema for sqlite style inserts', function () {
    $path = $this->tempDir . '/notes.sql';
    file_put_contents($path, <<<'SQL'
CREATE TABLE notes (
    id INTEGER,
    note TEXT
);
INSERT INTO notes VALUES (1, 'hello; world');
SQL);

    $extractor = new SqlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('| id | note |');
    expect($result->content)->toContain('| 1 | hello; world |');
});

test('SqlExtractor supports quoted postgres identifiers', function () {
    $path = $this->tempDir . '/quoted.sql';
    file_put_contents($path, <<<'SQL'
CREATE TABLE "Revenue Rollup" (
    "year" INT,
    "profit" NUMERIC(10, 2)
);
INSERT INTO "Revenue Rollup" ("year", "profit") VALUES (2024, 400.25);
SQL);

    $extractor = new SqlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Table: Revenue Rollup');
    expect($result->content)->toContain('| year | profit |');
    expect($result->content)->toContain('| 2024 | 400.25 |');
});

test('SqlExtractor preserves unsupported statements per statement in source order', function () {
    $path = $this->tempDir . '/mixed.sql';
    file_put_contents($path, <<<'SQL'
CREATE TABLE financials (
    year INT,
    revenue DECIMAL(10, 2)
);
INSERT INTO financials VALUES (2024, 100.00);
SELECT * FROM financials;
SQL);

    $extractor = new SqlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Table: financials');
    expect($result->content)->toContain('#### Unparsed SQL');
    expect($result->content)->toContain('```sql');
    expect($result->content)->toContain('SELECT * FROM financials;');
    expect(strpos($result->content, '#### Table: financials'))->toBeLessThan(strpos($result->content, 'SELECT * FROM financials;'));
});

test('SqlExtractor ignores semicolons in comments and strings when splitting statements', function () {
    $path = $this->tempDir . '/comments.sql';
    file_put_contents($path, <<<'SQL'
-- comment with a semicolon;
CREATE TABLE messages (
    id INT,
    body TEXT
);
INSERT INTO messages VALUES (1, 'semi;colon');
/* another; comment */
SELECT 1;
SQL);

    $extractor = new SqlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('| 1 | semi;colon |');
    expect($result->content)->toContain('SELECT 1;');
});

test('SqlExtractor fails on empty file', function () {
    $path = $this->tempDir . '/empty.sql';
    file_put_contents($path, '');

    $extractor = new SqlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('empty');
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

test('XlsxExtractor reads xlsm files using the same safe OOXML path', function () {
    if (!XlsxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/book.xlsm';
    createTestXlsx($path, [
        'Timeline' => [
            ['Year', 'Event'],
            ['2026', 'Audit'],
        ],
    ]);

    $extractor = new XlsxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('| 2026 | Audit |');
    expect($extractor->supportedExtensions())->toContain('xlsm');
});

// --- PptxExtractor ---

test('PptxExtractor converts slide text into markdown sections', function () {
    if (!PptxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/deck.pptx';
    createTestPptx($path, [
        [
            'title' => 'Identity',
            'bullets' => ['Calm under pressure', 'Pattern-first thinking'],
        ],
        [
            'title' => 'Focus',
            'bullets' => ['Research continuity', 'Shared memory scaffolds'],
        ],
    ]);

    $extractor = new PptxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Slide 1: Identity');
    expect($result->content)->not->toContain('- Identity');
    expect($result->content)->toContain('- Calm under pressure');
    expect($result->content)->toContain('#### Slide 2: Focus');
    expect($result->content)->toContain('- Research continuity');
});

test('PptxExtractor fails when slides contain no text', function () {
    if (!PptxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/blank.pptx';
    createTestPptx($path, [
        [],
    ]);

    $extractor = new PptxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('no extractable slide text');
});

test('PptxExtractor reads pptm files using the same safe OOXML path', function () {
    if (!PptxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/deck.pptm';
    createTestPptx($path, [
        [
            'title' => 'Signals',
            'bullets' => ['No macros executed'],
        ],
    ]);

    $extractor = new PptxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Slide 1: Signals');
    expect($result->content)->toContain('- No macros executed');
    expect($extractor->supportedExtensions())->toContain('pptm');
});

test('PptxExtractor includes speaker notes when present', function () {
    if (!PptxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    $path = $this->tempDir . '/notes-deck.pptx';
    createTestPptx($path, [
        [
            'title' => 'Signals',
            'bullets' => ['Archive stays read-only'],
            'notes' => ['Emphasize continuity', 'Mention fallback paths'],
        ],
    ]);

    $extractor = new PptxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('#### Slide 1: Signals');
    expect($result->content)->toContain('- Archive stays read-only');
    expect($result->content)->toContain('##### Speaker Notes');
    expect($result->content)->toContain('- Emphasize continuity');
    expect($result->content)->toContain('- Mention fallback paths');
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
    expect($factory->get('sql'))->toBeInstanceOf(SqlExtractor::class);
    expect($factory->get('py'))->toBeInstanceOf(CodeBlockExtractor::class);
    expect($factory->get('docm'))->toBeInstanceOf(DocxExtractor::class);
    if (OdtExtractor::isRuntimeSupported()) {
        expect($factory->get('odt'))->toBeInstanceOf(OdtExtractor::class);
    }
    if (OdsExtractor::isRuntimeSupported()) {
        expect($factory->get('ods'))->toBeInstanceOf(OdsExtractor::class);
    }
    if (OdpExtractor::isRuntimeSupported()) {
        expect($factory->get('odp'))->toBeInstanceOf(OdpExtractor::class);
    }
    if (XlsxExtractor::isRuntimeSupported()) {
        expect($factory->get('xlsx'))->toBeInstanceOf(XlsxExtractor::class);
        expect($factory->get('xlsm'))->toBeInstanceOf(XlsxExtractor::class);
    }
    if (PptxExtractor::isRuntimeSupported()) {
        expect($factory->get('pptx'))->toBeInstanceOf(PptxExtractor::class);
        expect($factory->get('pptm'))->toBeInstanceOf(PptxExtractor::class);
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
    expect($factory->isSupported('docm'))->toBeTrue();
    expect($factory->isSupported('odt'))->toBe(OdtExtractor::isRuntimeSupported());
    expect($factory->isSupported('ods'))->toBe(OdsExtractor::isRuntimeSupported());
    expect($factory->isSupported('odp'))->toBe(OdpExtractor::isRuntimeSupported());
    expect($factory->isSupported('xlsx'))->toBe(XlsxExtractor::isRuntimeSupported());
    expect($factory->isSupported('xlsm'))->toBe(XlsxExtractor::isRuntimeSupported());
    expect($factory->isSupported('pptx'))->toBe(PptxExtractor::isRuntimeSupported());
    expect($factory->isSupported('pptm'))->toBe(PptxExtractor::isRuntimeSupported());
    expect($factory->isSupported('exe'))->toBeFalse();
});
