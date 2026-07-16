<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\DocumentationIndex;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/coqui-docidx-' . bin2hex(random_bytes(8));
    mkdir($this->root . '/docs', 0755, true);
    mkdir($this->root . '/config', 0755, true);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->root));
});

function writeDoc(string $root, string $relative, string $content): void
{
    file_put_contents($root . '/' . $relative, $content);
}

it('globs every docs/*.md rather than an allowlist', function () {
    writeDoc($this->root, 'docs/ALPHA.md', "# Alpha\n\nFirst doc.\n");
    writeDoc($this->root, 'docs/BETA.md', "# Beta\n\nSecond doc.\n");
    writeDoc($this->root, 'docs/GAMMA.md', "# Gamma\n\nThird doc.\n");

    $index = (new DocumentationIndex($this->root))->build();

    expect(array_column($index['files'], 'path'))
        ->toBe(['docs/ALPHA.md', 'docs/BETA.md', 'docs/GAMMA.md']);
});

it('includes README.md and AGENTS.md from the project root', function () {
    writeDoc($this->root, 'README.md', "# Coqui Bot\n\nOverview.\n");
    writeDoc($this->root, 'AGENTS.md', "# Contributor Guide\n\nRules.\n");

    $index = (new DocumentationIndex($this->root))->build();
    $paths = array_column($index['files'], 'path');

    expect($paths)->toContain('README.md')->toContain('AGENTS.md');
});

it('takes title and description from frontmatter when present', function () {
    writeDoc($this->root, 'docs/LOOPS.md', <<<'MD'
        ---
        title: Loops
        description: Loop system reference — stages, policies, and scheduling
        ---

        # Loops Heading That Is Not The Title

        Body text.
        MD);

    $index = (new DocumentationIndex($this->root))->build();

    expect($index['files'][0]['title'])->toBe('Loops')
        ->and($index['files'][0]['description'])
        ->toBe('Loop system reference — stages, policies, and scheduling');
});

it('falls back to the H1 and first paragraph when frontmatter is absent', function () {
    writeDoc($this->root, 'docs/PLAIN.md', <<<'MD'
        # Plain Doc

        The first paragraph describes the doc.

        ## A Section

        More text.
        MD);

    $index = (new DocumentationIndex($this->root))->build();

    expect($index['files'][0]['title'])->toBe('Plain Doc')
        ->and($index['files'][0]['description'])->toBe('The first paragraph describes the doc.');
});

it('strips frontmatter from the H1 fallback search but keeps line numbers absolute', function () {
    writeDoc($this->root, 'docs/FM.md', <<<'MD'
        ---
        title: Front
        ---

        # Front Matter Doc

        ## Section One

        Text.
        MD);

    $index = (new DocumentationIndex($this->root))->build();
    $sections = $index['files'][0]['sections'];

    // line_start is 1-based against the file on disk, frontmatter included.
    expect($sections[0]['heading'])->toBe('Front Matter Doc')
        ->and($sections[0]['line_start'])->toBe(5)
        ->and($sections[1]['heading'])->toBe('Section One')
        ->and($sections[1]['line_start'])->toBe(7);
});

it('extracts H1 through H4 with line ranges and skips fenced code blocks', function () {
    writeDoc($this->root, 'docs/CODE.md', <<<'MD'
        # Top

        ## Real Section

        ```bash
        # Not A Heading
        ```

        #### Deep Section

        Tail.
        MD);

    $index = (new DocumentationIndex($this->root))->build();
    $headings = array_column($index['files'][0]['sections'], 'heading');

    expect($headings)->toBe(['Top', 'Real Section', 'Deep Section']);
});

it('closes each section at the next heading and the last at EOF', function () {
    writeDoc($this->root, 'docs/RANGE.md', "# One\n\nA\n\n## Two\n\nB\n");

    $sections = (new DocumentationIndex($this->root))->build()['files'][0]['sections'];

    expect($sections[0]['line_start'])->toBe(1)
        ->and($sections[0]['line_end'])->toBe(4)
        ->and($sections[1]['line_start'])->toBe(5)
        ->and($sections[1]['line_end'])->toBe(7);
});

it('ignores nested directories such as docs/superpowers', function () {
    mkdir($this->root . '/docs/superpowers/plans', 0755, true);
    writeDoc($this->root, 'docs/superpowers/plans/old-plan.md', "# A Plan\n\nText.\n");
    writeDoc($this->root, 'docs/REAL.md', "# Real\n\nText.\n");

    $index = (new DocumentationIndex($this->root))->build();

    expect(array_column($index['files'], 'path'))->toBe(['docs/REAL.md']);
});

it('load() returns the generated index when it is present and valid', function () {
    writeDoc($this->root, 'docs/ONDISK.md', "# On Disk\n\nText.\n");
    file_put_contents($this->root . '/config/documentation.json', json_encode([
        'version' => '1.0.0',
        'files' => [['path' => 'docs/CACHED.md', 'title' => 'Cached', 'description' => 'From cache', 'sections' => []]],
    ]));

    $index = (new DocumentationIndex($this->root))->load();

    expect(array_column($index['files'], 'path'))->toBe(['docs/CACHED.md']);
});

it('load() falls back to build() when the generated index is absent', function () {
    writeDoc($this->root, 'docs/ONDISK.md', "# On Disk\n\nText.\n");

    $index = (new DocumentationIndex($this->root))->load();

    expect(array_column($index['files'], 'path'))->toBe(['docs/ONDISK.md']);
});

it('load() falls back to build() when the generated index is corrupt', function () {
    writeDoc($this->root, 'docs/ONDISK.md', "# On Disk\n\nText.\n");
    file_put_contents($this->root . '/config/documentation.json', '{not valid json');

    $index = (new DocumentationIndex($this->root))->load();

    expect(array_column($index['files'], 'path'))->toBe(['docs/ONDISK.md']);
});

it('uses the filename as the title when a doc has neither frontmatter nor an H1', function () {
    writeDoc($this->root, 'docs/NOHEAD.md', "Just prose, no heading at all.\n");

    $index = (new DocumentationIndex($this->root))->build();

    expect($index['files'][0]['title'])->toBe('NOHEAD.md')
        ->and($index['files'][0]['description'])->toBe('Just prose, no heading at all.');
});
