<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Toolkit\CoquiSourceToolkit;

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

function coquiSourceFindTool(CoquiSourceToolkit $toolkit, string $name): ToolInterface
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->toFunctionSchema()['function']['name'] === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool '{$name}' not found in CoquiSourceToolkit");
}

// ---------------------------------------------------------------
// Fixture setup
// ---------------------------------------------------------------

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/coqui-src-tk-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0755, true);

    // config/ directory with source.json and documentation.json
    mkdir($this->root . '/config', 0755, true);
    file_put_contents($this->root . '/config/source.json', json_encode([
        'version' => '1.0.0',
        'layers' => ['agent' => 'Agent classes'],
        'files' => [
            [
                'path' => 'src/Agent/Orchestrator.php',
                'fqcn' => 'Acme\\Agent\\Orchestrator',
                'layer' => 'agent',
                'description' => 'The main orchestrator',
                'methods' => ['run(): Output'],
            ],
        ],
        'externalDependencies' => [
            ['name' => 'php-agents', 'version' => '^1.0'],
        ],
    ], JSON_PRETTY_PRINT));

    // Create a doc file with several headings (including H4)
    mkdir($this->root . '/docs', 0755, true);
    $docContent = <<<'MD'
# Configuration Guide

Overview of configuration options.

## Model Configuration

Set the model in openclaw.json.

### Provider Setup

Choose your provider.

#### Ollama Example

```json
{
    "model": "ollama/llama3"
}
```

## Shell Configuration

Configure shell access.

### `shellAllowedCommands`

An array of allowed commands.

## Code Block Test

Here is an example:

```markdown
# This Is Not A Real Heading

Some markdown inside a code block.

## Also Not Real
```

After the code block.

## Last Section

The final section.
MD;
    file_put_contents($this->root . '/docs/CONFIGURATION.md', $docContent);

    // Generated index for the fixture docs — mirrors what composer regen-docs produces.
    file_put_contents(
        $this->root . '/config/documentation.json',
        json_encode((new \CoquiBot\Coqui\Config\DocumentationIndex($this->root))->build(), JSON_PRETTY_PRINT),
    );

    // A source file for coqui_read tests
    mkdir($this->root . '/src/Agent', 0755, true);
    file_put_contents($this->root . '/src/Agent/Orchestrator.php', '<?php class Orchestrator {}');

    // An empty directory for listing tests
    mkdir($this->root . '/src/Empty', 0755, true);

    // Files for glob tests
    file_put_contents($this->root . '/src/Agent/Child.php', '<?php class Child {}');
    mkdir($this->root . '/src/Tool', 0755, true);
    file_put_contents($this->root . '/src/Tool/VisionTool.php', '<?php class VisionTool {}');

    $this->toolkit = new CoquiSourceToolkit($this->root);
});

afterEach(function () {
    if (!is_dir($this->root)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($this->root);
});

// ---------------------------------------------------------------
// Tool registration
// ---------------------------------------------------------------

// coqui_docs_map coexists with the older doc tools until Task 6 retires them.
test('provides 7 tools', function () {
    expect($this->toolkit->tools())->toHaveCount(7);
});

test('tool names are correct', function () {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toContain('coqui_source_map');
    expect($names)->toContain('coqui_read');
    expect($names)->toContain('coqui_list');
    expect($names)->toContain('coqui_search');
    expect($names)->toContain('coqui_doc_map');
    expect($names)->toContain('coqui_doc_read');
    expect($names)->toContain('coqui_docs_map');
});

test('guidelines contain COQUI-SOURCE-GUIDELINES tags', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toContain('<COQUI-SOURCE-GUIDELINES>');
    expect($guidelines)->toContain('coqui_source_map');
    expect($guidelines)->toContain('coqui_doc_map');
});

// ---------------------------------------------------------------
// coqui_source_map
// ---------------------------------------------------------------

test('coqui_source_map returns full map', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_source_map');
    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data)->toHaveKey('version');
    expect($data)->toHaveKey('files');
    expect($data)->toHaveKey('layers');
});

test('coqui_source_map filters by section', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_source_map');
    $result = $tool->execute(['section' => 'layers']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data)->toHaveKey('agent');
    expect($data)->not->toHaveKey('files');
});

test('coqui_source_map returns error for invalid section', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_source_map');
    $result = $tool->execute(['section' => 'nonexistent']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain("Unknown section 'nonexistent'");
    expect($result->content)->toContain('Available:');
});

// ---------------------------------------------------------------
// coqui_read
// ---------------------------------------------------------------

test('coqui_read returns file content', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_read');
    $result = $tool->execute(['path' => 'src/Agent/Orchestrator.php']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('class Orchestrator');
});

test('coqui_read returns error for missing file', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_read');
    $result = $tool->execute(['path' => 'src/Agent/Missing.php']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('File not found');
});

test('coqui_read blocks directory traversal', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_read');
    $result = $tool->execute(['path' => '../../etc/passwd']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    // Path either triggers "escapes project root" (if realpath resolves) or "File not found"
    expect($result->content)->toMatch('/escapes project root|File not found/');
});

test('coqui_read returns error for empty path', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_read');
    $result = $tool->execute(['path' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('required');
});

test('coqui_read returns error for directory path', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_read');
    $result = $tool->execute(['path' => 'src/Agent']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Not a file');
});

test('coqui_read truncates large files', function () {
    // Create a file larger than MAX_READ_BYTES (65536)
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_read');
    $largePath = $this->root . '/src/large.txt';
    file_put_contents($largePath, str_repeat('x', 70000));

    $result = $tool->execute(['path' => 'src/large.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('truncated at 65536 bytes');
});

// ---------------------------------------------------------------
// coqui_list
// ---------------------------------------------------------------

test('coqui_list returns directory entries', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_list');
    $result = $tool->execute(['path' => 'src/Agent']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Orchestrator.php');
    expect($result->content)->toContain('Child.php');
});

test('coqui_list shows empty directory message', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_list');
    $result = $tool->execute(['path' => 'src/Empty']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('empty');
});

test('coqui_list returns error for nonexistent directory', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_list');
    $result = $tool->execute(['path' => 'src/Missing']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Directory not found');
});

test('coqui_list blocks directory traversal', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_list');
    $result = $tool->execute(['path' => '../../tmp']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('escapes project root');
});

test('coqui_list recursive shows nested files', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_list');
    $result = $tool->execute(['path' => 'src', 'recursive' => true]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Agent/Orchestrator.php');
    expect($result->content)->toContain('Tool/VisionTool.php');
})->skip(PHP_OS_FAMILY === 'Windows', 'Recursive listing returns backslash paths on Windows');

// ---------------------------------------------------------------
// coqui_search
// ---------------------------------------------------------------

test('coqui_search finds files with standard glob', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_search');
    $result = $tool->execute(['pattern' => 'src/Agent/*.php']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('src/Agent/Orchestrator.php');
    expect($result->content)->toContain('src/Agent/Child.php');
})->skip(PHP_OS_FAMILY === 'Windows', 'Glob search returns backslash paths on Windows');

test('coqui_search finds files with recursive glob', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_search');
    $result = $tool->execute(['pattern' => 'src/**/*.php']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('src/Agent/Orchestrator.php');
    expect($result->content)->toContain('src/Tool/VisionTool.php');
})->skip(PHP_OS_FAMILY === 'Windows', 'Glob search returns backslash paths on Windows');

test('coqui_search returns message for no matches', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_search');
    $result = $tool->execute(['pattern' => 'src/**/*.rb']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('No files found');
});

test('coqui_search returns error for empty pattern', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_search');
    $result = $tool->execute(['pattern' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('required');
});

// ---------------------------------------------------------------
// coqui_doc_map
// ---------------------------------------------------------------

test('coqui_doc_map returns full index', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_map');
    $result = $tool->execute([]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data)->toHaveKey('version');
    expect($data)->toHaveKey('files');
    expect($data['files'])->toHaveCount(1);
    expect($data['files'][0]['path'])->toBe('docs/CONFIGURATION.md');
});

test('coqui_doc_map filters by file', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_map');
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->mimeType)->toBe('application/json');
    expect($result->displayHint)->toBe('structured-json');

    $data = json_decode($result->content, true);
    expect($data['path'])->toBe('docs/CONFIGURATION.md');
    expect($data['sections'])->toBeArray();
    expect(count($data['sections']))->toBeGreaterThan(0);
});

test('coqui_doc_map returns error for nonexistent file', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_map');
    $result = $tool->execute(['file' => 'docs/MISSING.md']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('File not found in documentation index');
    expect($result->content)->toContain('docs/CONFIGURATION.md');
});

// ---------------------------------------------------------------
// coqui_doc_read — full file
// ---------------------------------------------------------------

test('coqui_doc_read returns full file when no section specified', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('# Configuration Guide');
    expect($result->content)->toContain('## Model Configuration');
});

test('coqui_doc_read returns error for missing file', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/MISSING.md']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('File not found');
});

test('coqui_doc_read returns error for empty file path', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => '']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('required');
});

// ---------------------------------------------------------------
// coqui_doc_read — index extraction (exact match)
// ---------------------------------------------------------------

test('coqui_doc_read extracts section by exact heading from index', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Model Configuration']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('## Model Configuration');
});

test('coqui_doc_read exact match is case-insensitive', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'model configuration']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Model Configuration');
});

test('coqui_doc_read strips backticks for matching', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'shellAllowedCommands']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('shellAllowedCommands');
});

// ---------------------------------------------------------------
// coqui_doc_read — index extraction (substring match)
// ---------------------------------------------------------------

test('coqui_doc_read matches section by substring in index', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    // "model" is a substring of "Model Configuration"
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'model']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Model Configuration');
});

test('coqui_doc_read substring match is case-insensitive', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'SHELL']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Shell Configuration');
});

// ---------------------------------------------------------------
// coqui_doc_read — fallback to file parsing
// ---------------------------------------------------------------

test('coqui_doc_read falls back to file parsing for unindexed file', function () {
    // Create a doc file not in the index
    file_put_contents($this->root . '/docs/EXTRA.md', <<<'MD'
# Extra Docs

Overview.

## Special Section

Content of special section.

## Another Section

More content.
MD);

    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/EXTRA.md', 'section' => 'Special Section']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('## Special Section');
    expect($result->content)->toContain('Content of special section');
    // Should not include the next section
    expect($result->content)->not->toContain('## Another Section');
});

test('coqui_doc_read fallback uses substring matching', function () {
    file_put_contents($this->root . '/docs/EXTRA.md', <<<'MD'
# Extra

## Advanced Provider Setup

Details here.

## Other
MD);

    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/EXTRA.md', 'section' => 'provider']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Advanced Provider Setup');
});

test('coqui_doc_read fallback returns section extending to end of file', function () {
    file_put_contents($this->root . '/docs/EXTRA.md', <<<'MD'
# Top

## Last Section

This is the very last section with no following heading.
Content continues to EOF.
MD);

    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/EXTRA.md', 'section' => 'Last Section']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('very last section');
    expect($result->content)->toContain('Content continues to EOF');
});

// ---------------------------------------------------------------
// coqui_doc_read — code-fenced heading protection
// ---------------------------------------------------------------

test('coqui_doc_read fallback skips headings inside code blocks', function () {
    file_put_contents($this->root . '/docs/FENCED.md', <<<'MD'
# Top

## Real Section

Content before code block.

```markdown
# Fake Heading Inside Code Block

Not a real section.
```

More content after code block.

## Next Section

Next content.
MD);

    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/FENCED.md', 'section' => 'Real Section']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect($result->content)->toContain('Content before code block');
    expect($result->content)->toContain('More content after code block');
    // Should NOT contain content from Next Section
    expect($result->content)->not->toContain('Next content');
});

test('coqui_doc_read fallback does not match fake heading inside code block', function () {
    file_put_contents($this->root . '/docs/FENCED.md', <<<'MD'
# Top

Overview.

```
## Fake Section
```

## Real After Code

Content.
MD);

    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    // "Fake Section" should not be found since it's inside a code block
    $result = $tool->execute(['file' => 'docs/FENCED.md', 'section' => 'Fake Section']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
});

// ---------------------------------------------------------------
// coqui_doc_read — section not found with closest match
// ---------------------------------------------------------------

test('coqui_doc_read suggests closest match on miss', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    // "Modle Configuration" is close to "Model Configuration"
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Modle Configuration']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('not found');
    expect($result->content)->toContain('Did you mean');
    expect($result->content)->toContain('Model Configuration');
});

test('coqui_doc_read lists available sections on miss', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'zzzzz_nonexistent_zzzzz']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Available sections:');
    expect($result->content)->toContain('Model Configuration');
    expect($result->content)->toContain('Shell Configuration');
});

// ---------------------------------------------------------------
// coqui_doc_read — H4 heading extraction
// ---------------------------------------------------------------

test('coqui_doc_read available sections include H4 headings', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    // Search for something that won't match to trigger the "Available sections" response
    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'zzzzz_nonexistent_zzzzz']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    // H4 heading "Ollama Example" should be in the available sections list
    expect($result->content)->toContain('Ollama Example');
});

// ---------------------------------------------------------------
// coqui_doc_read — path sandboxing
// ---------------------------------------------------------------

test('coqui_doc_read blocks directory traversal', function () {
    $tool = coquiSourceFindTool($this->toolkit, 'coqui_doc_read');
    $result = $tool->execute(['file' => '../../etc/passwd']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    // Path either triggers "escapes project root" (if realpath resolves) or "File not found"
    expect($result->content)->toMatch('/escapes project root|File not found/');
});

// ---------------------------------------------------------------
// coqui_docs_map
// ---------------------------------------------------------------

it('coqui_docs_map returns a compact summary with no arguments', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute([]);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['files'][0])->toHaveKeys(['path', 'title', 'description', 'section_count'])
        // Compact means compact: no heading list in the no-arg response.
        ->and($data['files'][0])->not->toHaveKey('sections');
});

it('coqui_docs_map stays under a hard byte ceiling with no arguments', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    // The full index is ~27K tokens. Discovery must cost ~600, not 27,000.
    expect(strlen($tool->execute([])->content))->toBeLessThan(8192);
});

it('coqui_docs_map returns full sections for a named file', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md']);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['path'])->toBe('docs/CONFIGURATION.md')
        ->and($data['sections'])->not->toBeEmpty()
        ->and(array_column($data['sections'], 'heading'))->toContain('Model Configuration');
});

it('coqui_docs_map errors with the available list for an unknown file', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute(['file' => 'docs/NOPE.md']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('docs/CONFIGURATION.md');
});

it('coqui_docs_map works when config/documentation.json is absent', function () {
    unlink($this->root . '/config/documentation.json');
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute([]);
    $data = json_decode($result->content, true);

    // A fresh checkout has no generated index. Discovery must still work.
    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and(array_column($data['files'], 'path'))->toContain('docs/CONFIGURATION.md');
});
