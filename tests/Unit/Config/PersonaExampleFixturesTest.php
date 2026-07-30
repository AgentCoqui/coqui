<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\PersonaDiscovery;
use CoquiBot\Coqui\Config\PersonaPreferences;
use PHPUnit\Framework\Assert;

test('preferences example fixtures remain valid', function () {
    $projectRoot = dirname(__DIR__, 3);
    $examplePaths = glob($projectRoot . '/examples/preferences/*.json') ?: [];

    Assert::assertNotEmpty($examplePaths);

    foreach ($examplePaths as $path) {
        $preferences = PersonaPreferences::fromFile($path);

        Assert::assertTrue($preferences->isValid(), basename($path) . ' should remain a valid example fixture');
    }
});

test('worked persona example is discoverable and valid', function () {
    $projectRoot = dirname(__DIR__, 3);
    $sourceDir = $projectRoot . '/examples/personas/deliberate-operator';
    $workspacePath = sys_get_temp_dir() . '/coqui-persona-examples-' . bin2hex(random_bytes(4));
    $personaDir = $workspacePath . '/personas/deliberate-operator';
    $samplesDir = $personaDir . '/samples/responses';
    $cleanupTree = static function (string $dir): void {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
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
    };

    mkdir($workspacePath, 0755, true);

    try {
        mkdir($samplesDir, 0755, true);

        copy($sourceDir . '/soul.md', $personaDir . '/soul.md');
        copy($sourceDir . '/backstory.md', $personaDir . '/backstory.md');
        copy($sourceDir . '/preferences.json', $personaDir . '/preferences.json');
        copy($sourceDir . '/security.md', $personaDir . '/security.md');
        copy($sourceDir . '/samples/responses/status-update.md', $samplesDir . '/status-update.md');

        $discovery = new PersonaDiscovery($workspacePath);
        $preferences = PersonaPreferences::fromFile($personaDir . '/preferences.json');

        Assert::assertTrue($discovery->personaExists('deliberate-operator'));
        Assert::assertSame('openai/gpt-5.4-mini-2026-03-17', $discovery->readPersonaModel('deliberate-operator'));
        Assert::assertCount(1, $discovery->listResponseSamples('deliberate-operator'));
        Assert::assertTrue($preferences->isValid());
        Assert::assertSame('Operating Context', $preferences->getBackstoryLabel());
        Assert::assertNotSame('', trim((string) file_get_contents($personaDir . '/security.md')));
    } finally {
        $cleanupTree($workspacePath);
    }
});