<?php

declare(strict_types=1);

use CoquiBot\Coqui\Storage\AuditRedactor;

test('every production SessionStorage construction attaches an audit redactor', function (): void {
    $srcDir = dirname(__DIR__, 3) . '/src';

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir));
    $offenders = [];
    $siteCount = 0;

    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname()) ?: '';
        $offset = 0;

        while (($pos = strpos($contents, 'new SessionStorage(', $offset)) !== false) {
            $offset = $pos + 1;
            $siteCount++;

            // Look at the call through to its closing paren, allowing multi-line calls.
            $window = substr($contents, $pos, 400);

            if (!str_contains($window, 'auditRedactor:')) {
                $line = substr_count(substr($contents, 0, $pos), "\n") + 1;
                $offenders[] = str_replace($srcDir . '/', '', $file->getPathname()) . ':' . $line;
            }
        }
    }

    expect($siteCount)->toBeGreaterThanOrEqual(7);
    expect($offenders)->toBe([]);
});

test('BootManager exposes an AuditRedactor', function (): void {
    $method = new ReflectionMethod(CoquiBot\Coqui\Config\BootManager::class, 'auditRedactor');

    expect((string) $method->getReturnType())->toBe(AuditRedactor::class);
});
