<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;

// Sweep temp workspaces left behind by tests/Unit/Agent/LeanHarness.php's
// makeOrchestrator(). Each call creates a fresh sys_get_temp_dir() workspace
// that the harness itself never cleans up (Tasks 4-8 all call it repeatedly),
// so without this they accrete across the whole suite run. Bound via uses()
// so it applies suite-wide (a bare afterEach() in Pest.php only scopes to
// hooks/tests declared in this exact file, per Pest's Backtrace::testFile()).
uses()->afterEach(function (): void {
	foreach (glob(sys_get_temp_dir() . '/coqui-lean-harness-*') ?: [] as $dir) {
		if (is_dir($dir)) {
			cleanupTestTree($dir);
		}
	}

	foreach ($GLOBALS['__coqui_artifact_test_workspaces'] ?? [] as $dir) {
		if (is_string($dir) && is_dir($dir)) {
			cleanupTestTree($dir);
		}
	}
	$GLOBALS['__coqui_artifact_test_workspaces'] = [];
})->in('Unit');

function toolFromToolkit(ToolkitInterface $toolkit, string $name): ToolInterface
{
	foreach ($toolkit->tools() as $tool) {
		if ($tool->name() === $name) {
			return $tool;
		}
	}

	throw new InvalidArgumentException(sprintf('Tool "%s" not found.', $name));
}

/**
	* @return array<string, mixed>
	*/
function assertStructuredToolResult(CarmeloSantana\PHPAgents\Tool\ToolResult $result): array
{
	expect($result->status)->toBe(CarmeloSantana\PHPAgents\Enum\ToolResultStatus::Success);
	expect($result->mimeType)->toBe('application/json');
	expect($result->displayHint)->toBe('structured-json');

	$data = json_decode($result->content, true);
	expect($data)->toBeArray();

	return $data;
}

function releaseTestObjectProperties(object $context): void
{
	foreach (array_keys(get_object_vars($context)) as $property) {
		if (is_object($context->{$property})) {
			$context->{$property} = null;
		}
	}

	gc_collect_cycles();
}

/**
 * Build a file-backed ArtifactStore for tests, rooted at a unique temp workspace.
 * The workspace path is registered on $GLOBALS so afterEach can clean it up.
 */
function artifactStoreForTest(PDO $pdo): CoquiBot\Coqui\Storage\ArtifactStore
{
	$workspace = sys_get_temp_dir() . '/coqui-artifact-ws-' . bin2hex(random_bytes(6));
	@mkdir($workspace, 0775, true);
	$GLOBALS['__coqui_artifact_test_workspaces'][] = $workspace;

	return new CoquiBot\Coqui\Storage\ArtifactStore(
		$pdo,
		new CoquiBot\Coqui\Storage\ArtifactFileService($workspace),
	);
}

function cleanupSqliteTestDb(string $dbPath): void
{
	foreach (['', '-wal', '-shm'] as $suffix) {
		$path = $dbPath . $suffix;

		for ($attempt = 0; $attempt < 5; $attempt++) {
			clearstatcache(true, $path);
			if (!file_exists($path)) {
				break;
			}

			if (@unlink($path)) {
				break;
			}

			usleep(50_000);
		}
	}
}

function cleanupTestTree(string $dir): void
{
	if (!is_dir($dir)) {
		return;
	}

	$files = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
		RecursiveIteratorIterator::CHILD_FIRST,
	);

	foreach ($files as $file) {
		$path = $file->getPathname();
		if ($file->isDir()) {
			@rmdir($path);
		} else {
			@unlink($path);
		}
	}

	@rmdir($dir);
}

function createFakeComposerBinary(): string
{
	$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fake-composer-' . bin2hex(random_bytes(8));

	if (PHP_OS_FAMILY === 'Windows') {
		$path = $base . '.bat';
		file_put_contents($path, "@echo off\r\nexit /b 0\r\n");
		return $path;
	}

	$path = $base;
	file_put_contents($path, "#!/bin/sh\nexit 0\n");
	chmod($path, 0755);

	return $path;
}

/**
	* @param list<string> $paragraphs
	*/
function createTestOdt(string $path, array $paragraphs): void
{
	$zip = new ZipArchive();
	$opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	expect($opened)->toBeTrue();

	$body = implode('', array_map(
		static fn(string $paragraph): string => '<text:p>' . htmlspecialchars($paragraph, ENT_XML1) . '</text:p>',
		$paragraphs,
	));

	$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
	$zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
		. '<office:body><office:text>' . $body . '</office:text></office:body>'
		. '</office:document-content>');

	$zip->close();
}

function createRawOdt(string $path, string $textBodyXml): void
{
	$zip = new ZipArchive();
	$opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	expect($opened)->toBeTrue();

	$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.text');
	$zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
		. '<office:body><office:text>' . $textBodyXml . '</office:text></office:body>'
		. '</office:document-content>');

	$zip->close();
}

/**
	* @param array<string, list<list<string>>> $sheets
	*/
function createTestOds(string $path, array $sheets): void
{
	$zip = new ZipArchive();
	$opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	expect($opened)->toBeTrue();

	$tables = [];
	foreach ($sheets as $sheetName => $rows) {
		$rowXml = [];
		foreach ($rows as $row) {
			$cells = [];
			foreach ($row as $value) {
				$cells[] = '<table:table-cell office:value-type="string"><text:p>'
					. htmlspecialchars((string) $value, ENT_XML1)
					. '</text:p></table:table-cell>';
			}

			$rowXml[] = '<table:table-row>' . implode('', $cells) . '</table:table-row>';
		}

		$tables[] = '<table:table table:name="' . htmlspecialchars($sheetName, ENT_XML1) . '">' . implode('', $rowXml) . '</table:table>';
	}

	$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
	$zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
		. '<office:body><office:spreadsheet>' . implode('', $tables) . '</office:spreadsheet></office:body>'
		. '</office:document-content>');

	$zip->close();
}

function createRawOds(string $path, string $spreadsheetXml): void
{
	$zip = new ZipArchive();
	$opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	expect($opened)->toBeTrue();

	$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
	$zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
		. '<office:body><office:spreadsheet>' . $spreadsheetXml . '</office:spreadsheet></office:body>'
		. '</office:document-content>');

	$zip->close();
}

/**
	* @param list<array{title?: string, bullets?: list<string>}> $slides
	*/
function createTestOdp(string $path, array $slides): void
{
	$zip = new ZipArchive();
	$opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	expect($opened)->toBeTrue();

	$pages = [];
	foreach ($slides as $index => $slide) {
		$paragraphs = [];
		$title = trim((string) ($slide['title'] ?? ''));
		if ($title !== '') {
			$paragraphs[] = '<text:p>' . htmlspecialchars($title, ENT_XML1) . '</text:p>';
		}

		foreach ($slide['bullets'] ?? [] as $bullet) {
			$bullet = trim($bullet);
			if ($bullet !== '') {
				$paragraphs[] = '<text:p>' . htmlspecialchars($bullet, ENT_XML1) . '</text:p>';
			}
		}

		$pages[] = '<draw:page draw:name="Slide ' . ($index + 1) . '"><draw:frame><draw:text-box>'
			. implode('', $paragraphs)
			. '</draw:text-box></draw:frame></draw:page>';
	}

	$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');
	$zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
		. '<office:body><office:presentation>' . implode('', $pages) . '</office:presentation></office:body>'
		. '</office:document-content>');

	$zip->close();
}

function createRawOdp(string $path, string $presentationXml): void
{
	$zip = new ZipArchive();
	$opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	expect($opened)->toBeTrue();

	$zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.presentation');
	$zip->addFromString('content.xml', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0">'
		. '<office:body><office:presentation>' . $presentationXml . '</office:presentation></office:body>'
		. '</office:document-content>');

	$zip->close();
}

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

/**
	* @param list<array{title?: string, bullets?: list<string>, notes?: list<string>}> $slides
	*/
function createTestPptx(string $path, array $slides): void
{
	$zip = new ZipArchive();
	$opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
	expect($opened)->toBeTrue();

	$slideXml = [];
	$notesXml = [];
	$slideIndex = 1;

	foreach ($slides as $slide) {
		$paragraphs = [];
		$title = trim((string) ($slide['title'] ?? ''));
		if ($title !== '') {
			$paragraphs[] = createPptxParagraph($title);
		}

		foreach ($slide['bullets'] ?? [] as $bullet) {
			$bullet = trim($bullet);
			if ($bullet !== '') {
				$paragraphs[] = createPptxParagraph($bullet);
			}
		}

		$slideXml[] = [
			'id' => $slideIndex,
			'has_notes' => ($slide['notes'] ?? []) !== [],
			'xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
				. '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
				. '<p:sp><p:nvSpPr><p:cNvPr id="2" name="TextBox ' . $slideIndex . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr/>'
				. '<p:txBody><a:bodyPr/><a:lstStyle/>' . implode('', $paragraphs) . '</p:txBody></p:sp>'
				. '</p:spTree></p:cSld></p:sld>',
		];

		$notesParagraphs = [];
		foreach ($slide['notes'] ?? [] as $note) {
			$note = trim($note);
			if ($note !== '') {
				$notesParagraphs[] = createPptxParagraph($note);
			}
		}

		if ($notesParagraphs !== []) {
			$notesXml[$slideIndex] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
				. '<p:notes xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
				. '<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
				. '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes TextBox ' . $slideIndex . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr><p:spPr/>'
				. '<p:txBody><a:bodyPr/><a:lstStyle/>' . implode('', $notesParagraphs) . '</p:txBody></p:sp>'
				. '</p:spTree></p:cSld></p:notes>';
		}

		$slideIndex++;
	}

	$slideIds = [];
	$slideRelationships = [];
	foreach ($slideXml as $slide) {
		$slideIds[] = '<p:sldId id="' . (255 + $slide['id']) . '" r:id="rId' . $slide['id'] . '"/>';
		$slideRelationships[] = '<Relationship Id="rId' . $slide['id'] . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide' . $slide['id'] . '.xml"/>';
		$zip->addFromString('ppt/slides/slide' . $slide['id'] . '.xml', $slide['xml']);

		$slideLevelRelationships = [];
		if ($slide['has_notes']) {
			$slideLevelRelationships[] = '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/notesSlide" Target="../notesSlides/notesSlide' . $slide['id'] . '.xml"/>';
			$zip->addFromString('ppt/notesSlides/notesSlide' . $slide['id'] . '.xml', $notesXml[$slide['id']]);
		}

		if ($slideLevelRelationships !== []) {
			$zip->addFromString('ppt/slides/_rels/slide' . $slide['id'] . '.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
				. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
				. implode('', $slideLevelRelationships)
				. '</Relationships>');
		}
	}

	$zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
		. '<Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
		. implode('', array_map(
			static fn(array $slide): string => '<Override PartName="/ppt/slides/slide' . $slide['id'] . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>',
			$slideXml,
		))
		. implode('', array_map(
			static fn(int $slideId): string => '<Override PartName="/ppt/notesSlides/notesSlide' . $slideId . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.notesSlide+xml"/>',
			array_keys($notesXml),
		))
		. '</Types>');

	$zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
		. '</Relationships>');

	$zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
		. '<p:sldIdLst>' . implode('', $slideIds) . '</p:sldIdLst>'
		. '</p:presentation>');

	$zip->addFromString('ppt/_rels/presentation.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. implode('', $slideRelationships)
		. '</Relationships>');

	$zip->close();
}

function createPptxParagraph(string $text): string
{
	return '<a:p><a:r><a:t>' . htmlspecialchars($text, ENT_XML1) . '</a:t></a:r></a:p>';
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

/**
	* Create a non-blocking duplex stream pair for interactive input tests.
	*
	* Prefers Unix domain socket pairs when available, but falls back to a local
	* TCP listener on platforms where STREAM_PF_UNIX pairs are unavailable.
	*
	* @return array{0: resource, 1: resource}
	*/
function createNonBlockingStreamPair(): array
{
	$pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
	if (
		is_array($pair)
		&& isset($pair[0], $pair[1])
		&& is_resource($pair[0])
		&& is_resource($pair[1])
	) {
		stream_set_blocking($pair[0], false);
		stream_set_blocking($pair[1], false);

		return [$pair[0], $pair[1]];
	}

	$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
	assert(is_resource($server), sprintf('Failed to create TCP test socket server: %s (%d)', $error, $errno));

	$address = stream_socket_get_name($server, false);
	assert(is_string($address) && $address !== '');

	$writer = @stream_socket_client('tcp://' . $address, $errno, $error);
	assert(is_resource($writer), sprintf('Failed to connect TCP test socket client: %s (%d)', $error, $errno));

	$reader = @stream_socket_accept($server, 1.0);
	@fclose($server);
	assert(is_resource($reader), 'Failed to accept TCP test socket connection.');

	stream_set_blocking($reader, false);
	stream_set_blocking($writer, false);

	return [$reader, $writer];
}

/**
 * Shared factory: a simple single-select QuestionRequest used across the
 * structured-questions test suite (storage, persistence, responders,
 * handler, and loop-flow tests). Lives here so focused runs of any single
 * test file resolve it without loading a sibling test file.
 */
function sampleRequest(string $id = 'q1'): \CoquiBot\Coqui\Contract\QuestionRequest
{
    return new \CoquiBot\Coqui\Contract\QuestionRequest(
        id: $id,
        prompt: 'Which fruit?',
        format: \CoquiBot\Coqui\Contract\QuestionFormat::SingleSelect,
        options: [
            new \CoquiBot\Coqui\Contract\QuestionOption('apple'),
            new \CoquiBot\Coqui\Contract\QuestionOption('pear'),
        ],
        allowOther: false,
        suggested: new \CoquiBot\Coqui\Contract\QuestionResponse(['apple']),
    );
}
