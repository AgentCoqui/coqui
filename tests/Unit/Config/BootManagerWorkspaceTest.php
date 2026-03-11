<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\BootManager;

test('constructor accepts workspace override parameter', function () {
    $boot = new BootManager(
        workDir: __DIR__,
        workspaceOverride: '/tmp/custom-workspace',
    );

    expect($boot)->toBeInstanceOf(BootManager::class);
});

test('constructor defaults workspace override to null', function () {
    $boot = new BootManager(workDir: __DIR__);

    expect($boot)->toBeInstanceOf(BootManager::class);
});
