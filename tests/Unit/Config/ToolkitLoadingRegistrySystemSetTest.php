<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;

it('treats only the supplied core toolkits as immutable system toolkits', function () {
    $dir = sys_get_temp_dir() . '/lean-reg-' . uniqid();
    mkdir($dir);

    $registry = new ToolkitLoadingRegistry($dir, ['FileSystemToolkit', 'ShellToolkit']);

    expect($registry->isSystem('FileSystemToolkit'))->toBeTrue();
    // Under lean, Memory is no longer system — it can be overridden.
    expect($registry->isSystem('MemoryToolkit'))->toBeFalse();

    $registry->setMode('MemoryToolkit', ToolkitLoadingMode::Eager);
    expect($registry->getMode('MemoryToolkit'))->toBe(ToolkitLoadingMode::Eager);
});

it('falls back to the legacy SYSTEM_TOOLKITS when no core set is supplied', function () {
    $dir = sys_get_temp_dir() . '/lean-reg-' . uniqid();
    mkdir($dir);

    $registry = new ToolkitLoadingRegistry($dir);

    expect($registry->isSystem('MemoryToolkit'))->toBeTrue();
});
