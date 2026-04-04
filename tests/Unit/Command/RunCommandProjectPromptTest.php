<?php

declare(strict_types=1);

test('run command keeps the readline prompt neutral while syncing active project after agent turns', function () {
    $source = file_get_contents(__DIR__ . '/../../../src/Command/RunCommand.php');

    expect($source)
        ->toContain("\$readlinePrompt = ' › ';")
        ->not->toContain("sprintf(' [%s] › ', \$this->activeProjectSlug)");

    preg_match_all(
        '/\\$shutdownGuard\\(\\$shutdownStty\\);\n\s+\\$this->restoreActiveProject\\(\\);/',
        $source,
        $matches,
    );

    expect($matches[0])->toHaveCount(2);
});