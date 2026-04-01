<?php

declare(strict_types=1);

test('non-repl agent entrypoints inject ReactHttpClientAdapter', function () {
    $files = [
        __DIR__ . '/../../../src/Command/ApiCommand.php',
        __DIR__ . '/../../../src/Command/TurnRunCommand.php',
        __DIR__ . '/../../../src/Command/TaskRunCommand.php',
    ];

    foreach ($files as $file) {
        $source = file_get_contents($file);

        expect($source)->toContain('use CoquiBot\\Coqui\\Provider\\ReactHttpClientAdapter;')
            ->and($source)->toContain('httpClient: new ReactHttpClientAdapter()');
    }
});