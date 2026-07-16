<?php

declare(strict_types=1);

/**
 * Generates config/documentation.json from the docs on disk.
 *
 * The file list is globbed and per-doc metadata comes from frontmatter — this
 * script holds no list of what exists. Run: composer regen-docs
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use CoquiBot\Coqui\Config\DocumentationIndex;

$projectRoot = dirname(__DIR__);
$index = (new DocumentationIndex($projectRoot))->build();

$json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    fwrite(STDERR, "Failed to encode documentation index\n");
    exit(1);
}

$outPath = $projectRoot . '/config/documentation.json';

if (file_put_contents($outPath, $json . "\n") === false) {
    fwrite(STDERR, "Failed to write {$outPath}\n");
    exit(1);
}

$totalSections = array_sum(array_map(
    static fn (array $file): int => count($file['sections']),
    $index['files'],
));

echo "Written to {$outPath}\n";
echo count($index['files']) . " files indexed\n";
echo "{$totalSections} total sections\n";
