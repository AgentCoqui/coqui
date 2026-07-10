<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\BackstoryAssembler;
use CoquiBot\Coqui\Backstory\BackstoryManifest;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-backstory-assembler-' . bin2hex(random_bytes(4));
    $this->profilePath = $this->tempDir . '/profiles/test';
    $this->backstoryDir = $this->profilePath . '/backstory';
    mkdir($this->backstoryDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('generate creates backstory.md from text files', function () {
    file_put_contents($this->backstoryDir . '/file1.txt', 'First content.');
    file_put_contents($this->backstoryDir . '/file2.txt', 'Second content.');

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->totalFiles)->toBe(2);
    expect($result->failedFiles)->toBe(0);
    expect($result->totalTokens)->toBeGreaterThan(0);
    expect($result->generationTimeMs)->toBeGreaterThanOrEqual(0.0);
    expect($result->errors)->toBe([]);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('## Backstory');
    expect($output)->toContain('### File: /file1.txt');
    expect($output)->toContain('First content.');
    expect($output)->toContain('### File: /file2.txt');
    expect($output)->toContain('Second content.');
});

test('generate uses custom heading label when provided', function () {
    file_put_contents($this->backstoryDir . '/file1.txt', 'First content.');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath, 'Lore');

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('## Lore');
    expect($output)->not->toContain('## Backstory');
});

test('generate creates manifest file', function () {
    file_put_contents($this->backstoryDir . '/file1.txt', 'Content.');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $manifestPath = BackstoryManifest::manifestPath($this->profilePath);
    expect(is_file($manifestPath))->toBeTrue();

    $manifest = BackstoryManifest::load($manifestPath);
    expect($manifest->totalFiles)->toBe(1);
    expect($manifest->failedFiles)->toBe(0);
    expect($manifest->generatedAt)->not->toBe('');
    expect($manifest->contentHash)->toStartWith('sha256:');
    expect($manifest->files)->toHaveCount(1);
    expect($manifest->files[0]['status'])->toBe('ok');
});

test('generate handles json files with code fences', function () {
    file_put_contents($this->backstoryDir . '/data.json', '{"name": "Test"}');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('```json');
    expect($output)->toContain('{"name": "Test"}');
    expect($output)->toContain('```');
});

test('generate handles yaml files with code fences', function () {
    file_put_contents($this->backstoryDir . '/config.yaml', "key: value\nlist:\n  - item1");

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('```yaml');
    expect($output)->toContain('key: value');
});

test('generate handles csv files as markdown tables', function () {
    file_put_contents($this->backstoryDir . '/data.csv', "Year,Event\n1978,Born\n1996,Business");

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('| Year | Event |');
    expect($output)->toContain('| --- | --- |');
    expect($output)->toContain('| 1978 | Born |');
    expect($output)->toContain('| 1996 | Business |');
});

test('generate handles markdown files as passthrough', function () {
    file_put_contents($this->backstoryDir . '/notes.md', "# My Notes\n\nSome important notes.");

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('# My Notes');
    expect($output)->toContain('Some important notes.');
});

test('generate handles extended text-like formats and code blocks', function () {
    file_put_contents($this->backstoryDir . '/history.xml', '<timeline><year>2024</year><event>Launch</event></timeline>');
    file_put_contents($this->backstoryDir . '/voice.mdx', '# Voice\n\n<Quote>Measured.</Quote>');
    file_put_contents($this->backstoryDir . '/notes.rtf', '{\rtf1\ansi Profile note\par Second line}');
    file_put_contents($this->backstoryDir . '/example.py', "def greet():\n    return 'hello'\n");

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->totalFiles)->toBe(4);
    expect($result->failedFiles)->toBe(0);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('- timeline');
    expect($output)->toContain('- year: 2024');
    expect($output)->toContain('<Quote>Measured.</Quote>');
    expect($output)->toContain('Profile note');
    expect($output)->toContain('```python');
});

test('generate handles sql files with mixed parsed and preserved statements', function () {
    file_put_contents($this->backstoryDir . '/financials.sql', <<<'SQL'
CREATE TABLE financials (
    year INT,
    revenue DECIMAL(10, 2),
    profit DECIMAL(10, 2)
);
INSERT INTO financials VALUES (2024, 100.00, 20.00);
SELECT * FROM financials;
SQL);

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->failedFiles)->toBe(0);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /financials.sql');
    expect($output)->toContain('#### Table: financials');
    expect($output)->toContain('| year | revenue | profit |');
    expect($output)->toContain('#### Unparsed SQL');
    expect($output)->toContain('SELECT * FROM financials;');
});

test('generate handles utf16 text input', function () {
    $content = "\xFF\xFE" . mb_convert_encoding('Encoded backstory', 'UTF-16LE', 'UTF-8');
    file_put_contents($this->backstoryDir . '/utf16.txt', $content);

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->failedFiles)->toBe(0);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('Encoded backstory');
});

test('generate handles xlsx files when zip support is available', function () {
    if (!class_exists(\CoquiBot\Coqui\Backstory\Extractor\XlsxExtractor::class)
        || !\CoquiBot\Coqui\Backstory\Extractor\XlsxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    createTestXlsx($this->backstoryDir . '/timeline.xlsx', [
        'Timeline' => [
            ['Year', 'Event'],
            ['2024', 'Launch'],
            ['2025', 'Expansion'],
        ],
    ]);

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->failedFiles)->toBe(0);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /timeline.xlsx');
    expect($output)->toContain('#### Sheet: Timeline');
    expect($output)->toContain('| Year | Event |');
    expect($output)->toContain('| 2024 | Launch |');
});

test('generate handles pptx files when zip support is available', function () {
    if (!class_exists(\CoquiBot\Coqui\Backstory\Extractor\PptxExtractor::class)
        || !\CoquiBot\Coqui\Backstory\Extractor\PptxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    createTestPptx($this->backstoryDir . '/briefing.pptx', [
        [
            'title' => 'Identity',
            'bullets' => ['Quietly formidable', 'Pattern beneath the surface'],
        ],
        [
            'title' => 'Continuity',
            'bullets' => ['Shared memories', 'Separate histories'],
        ],
    ]);

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->failedFiles)->toBe(0);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /briefing.pptx');
    expect($output)->toContain('#### Slide 1: Identity');
    expect($output)->not->toContain('- Identity');
    expect($output)->toContain('- Quietly formidable');
    expect($output)->toContain('#### Slide 2: Continuity');
});

test('generate includes speaker notes from pptx files when present', function () {
    if (!class_exists(\CoquiBot\Coqui\Backstory\Extractor\PptxExtractor::class)
        || !\CoquiBot\Coqui\Backstory\Extractor\PptxExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    createTestPptx($this->backstoryDir . '/briefing-notes.pptx', [
        [
            'title' => 'Identity',
            'bullets' => ['Quietly formidable'],
            'notes' => ['Mention continuity anchor', 'Reference fallback plan'],
        ],
    ]);

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->failedFiles)->toBe(0);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /briefing-notes.pptx');
    expect($output)->toContain('#### Slide 1: Identity');
    expect($output)->toContain('##### Speaker Notes');
    expect($output)->toContain('- Mention continuity anchor');
    expect($output)->toContain('- Reference fallback plan');
});

test('generate handles open document formats when zip support is available', function () {
    if (!\CoquiBot\Coqui\Backstory\Extractor\OdtExtractor::isRuntimeSupported()) {
        test()->markTestSkipped('ZipArchive is not available');
    }

    createTestOdt($this->backstoryDir . '/origin.odt', ['Quiet beginning', 'Signal noted']);
    createTestOds($this->backstoryDir . '/routes.ods', [
        'Routes' => [
            ['Alias', 'Window'],
            ['Kade', '0400-0600Z'],
        ],
    ]);
    createTestOdp($this->backstoryDir . '/briefing.odp', [
        [
            'title' => 'Continuity',
            'bullets' => ['No active content executed'],
        ],
    ]);

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->failedFiles)->toBe(0);
    expect($result->unsupportedFiles)->toBe(0);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /origin.odt');
    expect($output)->toContain('Quiet beginning');
    expect($output)->toContain('### File: /routes.ods');
    expect($output)->toContain('| Alias | Window |');
    expect($output)->toContain('### File: /briefing.odp');
    expect($output)->toContain('#### Slide 1: Continuity');
});

test('generate records failed files in manifest', function () {
    file_put_contents($this->backstoryDir . '/bad.json', 'not valid json');

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->failedFiles)->toBe(1);
    expect($result->errors)->toHaveCount(1);
    expect($result->errors[0]['relative_path'])->toBe('bad.json');
    expect($result->errors[0]['error'])->toContain('Invalid JSON');

    $manifest = BackstoryManifest::load(BackstoryManifest::manifestPath($this->profilePath));
    expect($manifest->failedFiles)->toBe(1);
    expect($manifest->errors)->toHaveCount(1);
});

test('generate respects sort order with numbered files', function () {
    file_put_contents($this->backstoryDir . '/zebra.txt', 'Z');
    file_put_contents($this->backstoryDir . '/01-first.txt', 'A');
    file_put_contents($this->backstoryDir . '/02-second.txt', 'B');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $output = file_get_contents($this->profilePath . '/backstory.md');

    $pos01 = strpos($output, '### File: /01-first.txt');
    $pos02 = strpos($output, '### File: /02-second.txt');
    $posZ = strpos($output, '### File: /zebra.txt');

    expect($pos01)->toBeLessThan($pos02);
    expect($pos02)->toBeLessThan($posZ);
});

test('needsRegeneration returns false without backstory dir', function () {
    $assembler = new BackstoryAssembler();
    expect($assembler->needsRegeneration($this->tempDir . '/no-profile'))->toBeFalse();
});

test('needsRegeneration returns true without manifest', function () {
    file_put_contents($this->backstoryDir . '/file.txt', 'content');

    $assembler = new BackstoryAssembler();
    expect($assembler->needsRegeneration($this->profilePath))->toBeTrue();
});

test('needsRegeneration returns false after fresh generation', function () {
    file_put_contents($this->backstoryDir . '/file.txt', 'content');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    expect($assembler->needsRegeneration($this->profilePath))->toBeFalse();
});

test('needsRegeneration returns true after file modification', function () {
    file_put_contents($this->backstoryDir . '/file.txt', 'content');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    // Modify a source file
    file_put_contents($this->backstoryDir . '/file.txt', 'modified content');

    expect($assembler->needsRegeneration($this->profilePath))->toBeTrue();
});

test('needsRegeneration returns true after file addition', function () {
    file_put_contents($this->backstoryDir . '/file1.txt', 'content');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    // Add a new file
    file_put_contents($this->backstoryDir . '/file2.txt', 'new content');

    expect($assembler->needsRegeneration($this->profilePath))->toBeTrue();
});

test('generate with no source files cleans up', function () {
    // Generate once with files
    file_put_contents($this->backstoryDir . '/file.txt', 'content');
    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    expect(is_file($this->profilePath . '/backstory.md'))->toBeTrue();

    // Remove source files and regenerate
    unlink($this->backstoryDir . '/file.txt');
    $result = $assembler->generate($this->profilePath);

    expect($result->totalFiles)->toBe(0);
    expect(is_file($this->profilePath . '/backstory.md'))->toBeFalse();
});

test('generate handles nested directories', function () {
    mkdir($this->backstoryDir . '/01-chapter', 0755, true);
    file_put_contents($this->backstoryDir . '/01-chapter/001-intro.txt', 'Introduction');
    file_put_contents($this->backstoryDir . '/01-chapter/002-body.txt', 'Body text');
    file_put_contents($this->backstoryDir . '/summary.txt', 'Top level summary');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /summary.txt');
    expect($output)->toContain('### File: /01-chapter/001-intro.txt');
    expect($output)->toContain('### File: /01-chapter/002-body.txt');
    expect($output)->toContain('Introduction');
    expect($output)->toContain('Body text');
    expect($output)->toContain('Top level summary');
});

test('getManifest returns null without manifest file', function () {
    $assembler = new BackstoryAssembler();
    expect($assembler->getManifest($this->profilePath))->toBeNull();
});

test('getManifest returns manifest after generation', function () {
    file_put_contents($this->backstoryDir . '/file.txt', 'content');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    $manifest = $assembler->getManifest($this->profilePath);
    expect($manifest)->not->toBeNull();
    expect($manifest->totalFiles)->toBe(1);
});

test('hasBackstoryDir returns false for missing dir', function () {
    expect(BackstoryAssembler::hasBackstoryDir($this->tempDir . '/no-profile'))->toBeFalse();
});

test('hasBackstoryDir returns true when dir exists', function () {
    expect(BackstoryAssembler::hasBackstoryDir($this->profilePath))->toBeTrue();
});

test('generate handles empty files gracefully', function () {
    file_put_contents($this->backstoryDir . '/empty.txt', '');
    file_put_contents($this->backstoryDir . '/notempty.txt', 'content');

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->totalFiles)->toBe(2);
    expect($result->failedFiles)->toBe(1); // empty file fails

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /notempty.txt');
    expect($output)->toContain('content');
});

test('generate records unsupported files in manifest without including them in output', function () {
    file_put_contents($this->backstoryDir . '/story.txt', 'content');
    file_put_contents($this->backstoryDir . '/payload.exe', 'skip me');

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->totalFiles)->toBe(2);
    expect($result->failedFiles)->toBe(0);
    expect($result->unsupportedFiles)->toBe(1);

    $output = file_get_contents($this->profilePath . '/backstory.md');
    expect($output)->toContain('### File: /story.txt');
    expect($output)->not->toContain('payload.exe');

    $manifest = BackstoryManifest::load(BackstoryManifest::manifestPath($this->profilePath));
    expect($manifest->unsupportedFiles)->toHaveCount(1);
    expect($manifest->unsupportedFiles[0]['relative_path'])->toBe('payload.exe');
    expect($manifest->unsupportedFiles[0]['reason'])->toBe('Unsupported extension: .exe');
});

test('generate persists manifest when only unsupported files exist', function () {
    file_put_contents($this->backstoryDir . '/payload.exe', 'skip me');

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->totalFiles)->toBe(1);
    expect($result->failedFiles)->toBe(0);
    expect($result->unsupportedFiles)->toBe(1);
    expect(is_file($this->profilePath . '/backstory.md'))->toBeFalse();

    $manifest = BackstoryManifest::load(BackstoryManifest::manifestPath($this->profilePath));
    expect($manifest->totalFiles)->toBe(1);
    expect($manifest->unsupportedFileCount())->toBe(1);
});

test('needsRegeneration returns true after unsupported file addition', function () {
    file_put_contents($this->backstoryDir . '/file.txt', 'content');

    $assembler = new BackstoryAssembler();
    $assembler->generate($this->profilePath);

    file_put_contents($this->backstoryDir . '/ignored.exe', 'skip me');

    expect($assembler->needsRegeneration($this->profilePath))->toBeTrue();
});

test('generate handles copied real-profile fixture corpus', function () {
    $fixtureDir = dirname(__DIR__, 2) . '/Fixtures/Backstory/real-profile-sample';
    $fixtureFiles = array_values(array_filter(
        scandir($fixtureDir) ?: [],
        static fn(string $file): bool => $file !== '.' && $file !== '..',
    ));

    foreach ($fixtureFiles as $fixtureFile) {
        copy($fixtureDir . '/' . $fixtureFile, $this->backstoryDir . '/' . $fixtureFile);
    }

    // Support for .html/.pdf/.docx depends on whether the optional
    // coqui-toolkit-backstory-formats mod is installed (it self-registers those
    // extractors). Derive the expected supported set from the real factory so this
    // test holds whether or not the mod is present rather than hardcoding it.
    $factory = new \CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory();
    $supportedFixtureFiles = array_values(array_filter(
        $fixtureFiles,
        static fn(string $file): bool => $factory->isSupported((string) pathinfo($file, PATHINFO_EXTENSION)),
    ));

    $assembler = new BackstoryAssembler();
    $result = $assembler->generate($this->profilePath);

    expect($result->totalFiles)->toBe(count($fixtureFiles));
    expect($result->failedFiles)->toBe(0);
    expect($result->unsupportedFiles)->toBe(count($fixtureFiles) - count($supportedFixtureFiles));

    $output = file_get_contents($this->profilePath . '/backstory.md');
    foreach ($supportedFixtureFiles as $fixtureFile) {
        expect($output)->toContain('### File: /' . $fixtureFile);
    }
});
