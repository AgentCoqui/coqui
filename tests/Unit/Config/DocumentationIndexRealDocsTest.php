<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\DocumentationIndex;

$projectRoot = dirname(__DIR__, 3);

it('indexes every docs/*.md that exists on disk', function () use ($projectRoot) {
    $onDisk = array_map(
        fn (string $path): string => 'docs/' . basename($path),
        glob($projectRoot . '/docs/*.md') ?: [],
    );
    sort($onDisk);

    $indexed = array_values(array_filter(
        array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path'),
        fn (string $path): bool => str_starts_with($path, 'docs/'),
    ));
    sort($indexed);

    // A doc that exists but is not indexed is invisible to the agent — the exact
    // regression that hid LOOPS.md and PERSONAS.md behind a hardcoded allowlist.
    expect($indexed)->toBe($onDisk)
        ->and($indexed)->toHaveCount(18);
});

it('indexes the docs that the old hardcoded allowlist omitted', function () use ($projectRoot) {
    $indexed = array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path');

    expect($indexed)
        ->toContain('docs/LOOPS.md')
        ->toContain('docs/PERSONAS.md')
        ->toContain('docs/QUESTIONS.md')
        ->toContain('docs/ARTIFACTS.md')
        ->toContain('docs/PROJECTS.md')
        ->toContain('docs/CHAT.md')
        ->toContain('docs/DATA_FLOW.md')
        ->toContain('docs/TOOLKIT-EXTENSIBILITY.md');
});

it('includes README.md and AGENTS.md', function () use ($projectRoot) {
    $indexed = array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path');

    expect($indexed)->toContain('README.md')->toContain('AGENTS.md');
});

it('gives every indexed doc a non-empty title and description', function () use ($projectRoot) {
    foreach ((new DocumentationIndex($projectRoot))->build()['files'] as $file) {
        expect($file['title'])->not->toBe('', "{$file['path']} has no title")
            ->and($file['description'])->not->toBe('', "{$file['path']} has no description");
    }
});

it('gives every docs/*.md frontmatter-sourced metadata', function () use ($projectRoot) {
    foreach (glob($projectRoot . '/docs/*.md') ?: [] as $path) {
        $content = file_get_contents($path);

        // Compare the first line, not a raw "---\n" prefix: the repo sets no
        // .gitattributes eol, so a Windows checkout yields CRLF and a literal
        // prefix match fails there while passing everywhere else.
        $firstLine = rtrim(strtok($content, "\n"), "\r");

        expect($firstLine)->toBe('---', basename($path) . ' is missing frontmatter');
    }
});

it('finds a loops-only term in the real docs/LOOPS.md', function () use ($projectRoot) {
    $toolkit = new \CoquiBot\Coqui\Toolkit\CoquiDocsToolkit(projectRoot: $projectRoot);
    $tool = null;

    foreach ($toolkit->tools() as $candidate) {
        if ($candidate->toFunctionSchema()['function']['name'] === 'coqui_docs_search') {
            $tool = $candidate;
        }
    }

    $data = json_decode($tool->execute(['query' => 'on_question'])->content, true);
    $paths = array_column($data['results'], 'path');

    // docs/LOOPS.md was invisible to the agent under the hardcoded allowlist.
    // This is the direct regression test for that eight-doc blind spot.
    expect($paths)->toContain('docs/LOOPS.md');
});

it('surfaces the real docs/LOOPS.md for the bare term "loops"', function () use ($projectRoot) {
    $toolkit = new \CoquiBot\Coqui\Toolkit\CoquiDocsToolkit(projectRoot: $projectRoot);
    $tool = null;

    foreach ($toolkit->tools() as $candidate) {
        if ($candidate->toFunctionSchema()['function']['name'] === 'coqui_docs_search') {
            $tool = $candidate;
        }
    }

    $data = json_decode($tool->execute(['query' => 'loops'])->content, true);
    $paths = array_column($data['results'], 'path');

    // The on_question test above only gates because API.md cannot match a
    // loops-only term. "loops" is the question an agent actually asks, and it
    // returned 20/20 results from docs/API.md — the eight-doc blind spot
    // re-created at the ranking layer. docs/LOOPS.md is titled "Loops"; nothing
    // in the corpus is a stronger answer.
    expect($paths)->toContain('docs/LOOPS.md')
        ->and($data['results'][0]['path'])->toBe('docs/LOOPS.md');
});

it('never indexes working artefacts under docs/superpowers', function () use ($projectRoot) {
    $indexed = array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path');

    foreach ($indexed as $path) {
        expect($path)->not->toContain('superpowers/');
    }
});
