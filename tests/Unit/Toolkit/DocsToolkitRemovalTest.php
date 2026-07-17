<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);

it('has deleted the source map and its integrity test', function () use ($projectRoot) {
    expect(file_exists($projectRoot . '/config/source.json'))->toBeFalse()
        ->and(file_exists($projectRoot . '/tests/Unit/Config/SourceMapIntegrityTest.php'))->toBeFalse()
        ->and(file_exists($projectRoot . '/prompts/tools/coqui-source.md'))->toBeFalse();
});

it('leaves no reference to the removed toolkit, tools, or slug', function () use ($projectRoot) {
    $stale = ['CoquiSourceToolkit', 'coqui_source_map', 'coqui_read', 'coqui_list', 'coqui_search', 'coqui-source', 'coqui_doc_map', 'coqui_doc_read'];
    $found = [];

    foreach (['src', 'prompts', 'config', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot . '/' . $dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() === basename(__FILE__)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            foreach ($stale as $needle) {
                if (str_contains($content, $needle)) {
                    $found[] = $file->getPathname() . ' → ' . $needle;
                }
            }
        }
    }

    expect($found)->toBe([]);
});

it('exposes exactly the three coqui_docs_* tools', function () use ($projectRoot) {
    $toolkit = new \CoquiBot\Coqui\Toolkit\CoquiDocsToolkit(projectRoot: $projectRoot);

    $names = array_map(
        static fn ($tool): string => $tool->toFunctionSchema()['function']['name'],
        $toolkit->tools(),
    );
    sort($names);

    expect($names)->toBe(['coqui_docs_map', 'coqui_docs_read', 'coqui_docs_search']);
});
