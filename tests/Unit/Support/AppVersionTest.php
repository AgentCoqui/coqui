<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\AppVersion;

function createAppVersionTestRoot(): string
{
    $root = sys_get_temp_dir() . '/coqui-app-version-' . bin2hex(random_bytes(8));
    mkdir($root . '/config', 0777, true);

    return $root;
}

function deleteAppVersionTestRoot(string $root): void
{
    $items = scandir($root);
    if (!is_array($items)) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $root . '/' . $item;
        if (is_dir($path)) {
            deleteAppVersionTestRoot($path);
            continue;
        }

        unlink($path);
    }

    rmdir($root);
}

test('app version prefers environment override', function () {
    $root = createAppVersionTestRoot();
    $original = getenv('COQUI_VERSION');
    file_put_contents($root . '/config/version.txt', "1.2.3\n");
    putenv('COQUI_VERSION=9.9.9');

    try {
        expect(AppVersion::current($root))->toBe('9.9.9');
    } finally {
        if ($original === false) {
            putenv('COQUI_VERSION');
        } else {
            putenv("COQUI_VERSION={$original}");
        }

        deleteAppVersionTestRoot($root);
    }
});

test('app version uses injected version file when present', function () {
    $root = createAppVersionTestRoot();
    $original = getenv('COQUI_VERSION');
    putenv('COQUI_VERSION');
    file_put_contents($root . '/config/version.txt', "2.3.4\n");

    try {
        expect(AppVersion::current($root))->toBe('2.3.4');
    } finally {
        if ($original === false) {
            putenv('COQUI_VERSION');
        } else {
            putenv("COQUI_VERSION={$original}");
        }

        deleteAppVersionTestRoot($root);
    }
});

test('app version falls back to dev without env file or tag', function () {
    $root = createAppVersionTestRoot();
    $original = getenv('COQUI_VERSION');
    putenv('COQUI_VERSION');

    try {
        expect(AppVersion::current($root))->toBe('dev');
    } finally {
        if ($original === false) {
            putenv('COQUI_VERSION');
        } else {
            putenv("COQUI_VERSION={$original}");
        }

        deleteAppVersionTestRoot($root);
    }
});