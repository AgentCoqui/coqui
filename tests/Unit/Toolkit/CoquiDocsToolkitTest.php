<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Config\DocumentationIndex;
use CoquiBot\Coqui\Toolkit\CoquiDocsToolkit;

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

function coquiDocsFindTool(CoquiDocsToolkit $toolkit, string $name): ToolInterface
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->toFunctionSchema()['function']['name'] === $name) {
            return $tool;
        }
    }

    throw new RuntimeException("Tool '{$name}' not found in CoquiDocsToolkit");
}

// ---------------------------------------------------------------
// Fixture setup
// ---------------------------------------------------------------

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/coqui-docs-tk-' . bin2hex(random_bytes(8));
    mkdir($this->root, 0755, true);

    mkdir($this->root . '/config', 0755, true);

    // Create a doc file with several headings (including H4).
    //
    // The "Shell Configuration" cross-reference under Model Configuration is
    // load-bearing for the search ranking test: it puts a body occurrence of that
    // term at a LOWER line number than the heading carrying it, which is the only
    // shape where rank has to beat line-order. It sits outside the first paragraph
    // on purpose — DocumentationIndex derives the doc description from that
    // paragraph, and a description hit ranks every line in the file as a meta hit,
    // erasing the distinction the test exists to prove.
    mkdir($this->root . '/docs', 0755, true);
    $docContent = <<<'MD'
# Configuration Guide

Overview of configuration options.

## Model Configuration

Set the model in openclaw.json.

Shell Configuration is covered later in this guide.

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

    // A doc larger than MAX_READ_BYTES, mirroring docs/API.md at ~144 KB.
    $huge = "# Huge Doc\n\nIntro.\n\n## First Big Section\n\n"
        . str_repeat("Filler line of prose to exceed the read cap.\n", 2000)
        . "\n## Second Big Section\n\nTail content.\n";
    file_put_contents($this->root . '/docs/HUGE.md', $huge);

    // Coqui ships 20 docs whose combined index runs to ~27K tokens. A fixture of
    // one doc indexes to well under any byte ceiling, which would let a regression
    // that dumps the whole index pass the ceiling test. Mirror the real scale so
    // the ceiling actually gates.
    for ($i = 1; $i <= 8; $i++) {
        $filler = "# Filler Doc {$i}\n\nDescription of filler doc {$i}.\n";

        for ($s = 1; $s <= 30; $s++) {
            $filler .= "\n## Filler Doc {$i} Section {$s}\n\nContent for section {$s}.\n";
        }

        file_put_contents($this->root . "/docs/FILLER{$i}.md", $filler);
    }

    // Generated index for the fixture docs — mirrors what composer regen-docs produces.
    file_put_contents(
        $this->root . '/config/documentation.json',
        json_encode((new \CoquiBot\Coqui\Config\DocumentationIndex($this->root))->build(), JSON_PRETTY_PRINT),
    );

    $this->toolkit = new CoquiDocsToolkit($this->root);
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

test('provides 3 tools', function () {
    expect($this->toolkit->tools())->toHaveCount(3);
});

test('tool names are correct', function () {
    $names = array_map(
        fn($t) => $t->toFunctionSchema()['function']['name'],
        $this->toolkit->tools(),
    );

    expect($names)->toContain('coqui_docs_map');
    expect($names)->toContain('coqui_docs_read');
    expect($names)->toContain('coqui_docs_search');
});

test('guidelines contain COQUI-DOCS-GUIDELINES tags', function () {
    $guidelines = $this->toolkit->guidelines();

    expect($guidelines)->toContain('<COQUI-DOCS-GUIDELINES>');
    expect($guidelines)->toContain('coqui_docs_search');
    expect($guidelines)->toContain('coqui_docs_read');
});

// ---------------------------------------------------------------
// coqui_docs_map
// ---------------------------------------------------------------

it('coqui_docs_map returns a compact summary with no arguments', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute([]);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['files'][0])->toHaveKeys(['path', 'title', 'description', 'section_count'])
        // Compact means compact: no heading list in the no-arg response.
        ->and($data['files'][0])->not->toHaveKey('sections');
});

it('coqui_docs_map stays under a hard byte ceiling with no arguments', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $compact = strlen($tool->execute([])->content);
    $full = strlen((string) json_encode((new DocumentationIndex($this->root))->load()));

    // Measured against the same doc set the tool just read, so the gate holds
    // whatever the fixture grows into: dumping the index verbatim fails here.
    expect($full)->toBeGreaterThan(8192)
        ->and($compact)->toBeLessThan(intdiv($full, 4))
        // The full index is ~27K tokens. Discovery must cost ~600, not 27,000.
        ->and($compact)->toBeLessThan(8192);
});

it('coqui_docs_map returns full sections for a named file', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md']);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['path'])->toBe('docs/CONFIGURATION.md')
        ->and($data['sections'])->not->toBeEmpty()
        ->and(array_column($data['sections'], 'heading'))->toContain('Model Configuration');
});

it('coqui_docs_map errors with the available list for an unknown file', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute(['file' => 'docs/NOPE.md']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('docs/CONFIGURATION.md');
});

it('coqui_docs_map works when config/documentation.json is absent', function () {
    unlink($this->root . '/config/documentation.json');
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute([]);
    $data = json_decode($result->content, true);

    // A fresh checkout has no generated index. Discovery must still work.
    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and(array_column($data['files'], 'path'))->toContain('docs/CONFIGURATION.md');
});

// ---------------------------------------------------------------
// coqui_docs_read
// ---------------------------------------------------------------

it('coqui_docs_read returns a section by exact heading', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Model Configuration']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('Set the model in openclaw.json')
        ->and($result->content)->not->toContain('Configure shell access');
});

it('coqui_docs_read matches headings case- and backtick-insensitively', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'shellallowedcommands']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('An array of allowed commands');
});

it('coqui_docs_read suggests the closest heading when a section is not found', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Model Configurashun']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Did you mean')
        ->and($result->content)->toContain('Model Configuration');
});

it('coqui_docs_read returns the section list instead of truncating an oversized file', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/HUGE.md']);

    // The old behaviour returned ~46% of docs/API.md with no signal at all.
    expect($result->content)->not->toContain('truncated at')
        ->and($result->content)->toContain('First Big Section')
        ->and($result->content)->toContain('Second Big Section')
        ->and($result->content)->toContain('section')
        ->and(strlen($result->content))->toBeLessThan(65536);
});

it('coqui_docs_read returns a whole small file unchanged', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('# Configuration Guide')
        ->and($result->content)->toContain('An array of allowed commands');
});

it('coqui_docs_read falls back to direct parsing when the index is absent', function () {
    unlink($this->root . '/config/documentation.json');
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Model Configuration']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('Set the model in openclaw.json');
});

it('coqui_docs_read rejects paths escaping the project root', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => '../../../etc/passwd']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ---------------------------------------------------------------
// coqui_docs_search
// ---------------------------------------------------------------

it('coqui_docs_search finds a term in a doc body and reports its heading', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $result = $tool->execute(['query' => 'openclaw.json']);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['results'][0]['path'])->toBe('docs/CONFIGURATION.md')
        ->and($data['results'][0]['heading'])->toBe('Model Configuration')
        ->and($data['results'][0]['snippet'])->toContain('openclaw.json')
        ->and($data['results'][0]['line'])->toBeGreaterThan(0);
});

it('coqui_docs_search ranks heading matches above body matches', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'Shell Configuration'])->content, true);

    // The fixture cross-references "Shell Configuration" from the body of Model
    // Configuration, above the heading of the same name. Both hits are in one file,
    // so path cannot break the tie and the body hit has the lower line: only rank
    // can put the heading first. Assert the shape too — if the fixture ever loses
    // the earlier body hit, this test silently stops gating anything.
    $headingHit = $data['results'][0];
    $bodyHit = $data['results'][1];

    expect($headingHit['heading'])->toBe('Shell Configuration')
        ->and($headingHit['snippet'])->toBe('## Shell Configuration')
        ->and($bodyHit['heading'])->toBe('Model Configuration')
        ->and($bodyHit['line'])->toBeLessThan($headingHit['line'])
        ->and($bodyHit['path'])->toBe($headingHit['path']);
});

it('coqui_docs_search is case-insensitive', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'OPENCLAW.JSON'])->content, true);

    expect($data['results'])->not->toBeEmpty();
});

it('coqui_docs_search returns empty results rather than an error for no match', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $result = $tool->execute(['query' => 'zzzznotpresentanywhere']);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['results'])->toBe([])
        ->and($data['total_matches'])->toBe(0);
});

it('coqui_docs_search requires a query', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    expect($tool->execute(['query' => ''])->status)->toBe(ToolResultStatus::Error);
});

it('coqui_docs_search bounds results and reports the truncation', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    // 'Filler' appears 2000 times in the HUGE.md fixture.
    $data = json_decode($tool->execute(['query' => 'Filler', 'limit' => 5])->content, true);

    expect($data['results'])->toHaveCount(5)
        ->and($data['total_matches'])->toBeGreaterThan(5)
        // A silent cap is the failure mode this whole change exists to kill.
        ->and($data['truncated'])->toBeTrue();
});

it('coqui_docs_search caps limit at 50', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'Filler', 'limit' => 9999])->content, true);

    expect($data['results'])->toHaveCount(50);
});

it('coqui_docs_search clamps a limit below 1 up to a single result', function () {
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $result = $tool->execute(['query' => 'Filler', 'limit' => 0]);
    $data = json_decode($result->content, true);

    // The schema carries no minimum, so an out-of-range limit reaches the callback.
    // It must clamp — neither erroring nor falling through to every match.
    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['results'])->toHaveCount(1)
        ->and($data['truncated'])->toBeTrue();
});

it('coqui_docs_search keeps long multibyte lines encodable', function () {
    // An em-dash straddling the snippet cut-off. Slicing by byte splits the
    // sequence, and ToolResult::json turns unencodable payloads into a bare
    // '{}' — the whole response vanishes with no error. Docs are full of em-dashes.
    file_put_contents(
        $this->root . '/docs/WIDE.md',
        "# Wide\n\n## Wide Section\n\n" . str_repeat('a', 198) . "—needle" . str_repeat('b', 50) . "\n",
    );
    // beforeEach generates the index; a doc added after it needs a fresh one.
    file_put_contents(
        $this->root . '/config/documentation.json',
        json_encode((new DocumentationIndex($this->root))->build(), JSON_PRETTY_PRINT),
    );
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $result = $tool->execute(['query' => 'needle']);
    $data = json_decode($result->content, true);

    expect($result->content)->not->toBe('{}')
        ->and($data['results'])->toHaveCount(1)
        ->and($data['results'][0]['path'])->toBe('docs/WIDE.md')
        ->and(mb_check_encoding($data['results'][0]['snippet'], 'UTF-8'))->toBeTrue();
});

it('coqui_docs_search works when config/documentation.json is absent', function () {
    unlink($this->root . '/config/documentation.json');
    $tool = coquiDocsFindTool(new CoquiDocsToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'openclaw.json'])->content, true);

    expect($data['results'])->not->toBeEmpty();
});
