<?php

declare(strict_types=1);

use CoquiBot\Coqui\CoquiSpace\Installer\ComposerRunner;

beforeEach(function () {
    $this->workingDirectory = sys_get_temp_dir() . '/coqui-composer-runner-' . bin2hex(random_bytes(4));
    mkdir($this->workingDirectory, 0755, true);
});

afterEach(function () {
    if (file_exists($this->workingDirectory . '/composer.json')) {
        unlink($this->workingDirectory . '/composer.json');
    }

    if (is_dir($this->workingDirectory)) {
        rmdir($this->workingDirectory);
    }
});

test('composer runner requires composer json in working directory', function () {
    $runner = new ComposerRunner($this->workingDirectory);

    expect(fn() => $runner->run(['require', 'vendor/package']))
        ->toThrow(RuntimeException::class, 'composer.json');
});

test('composer runner rejects unsupported commands before execution', function () {
    file_put_contents($this->workingDirectory . '/composer.json', json_encode(['name' => 'test/workspace'], JSON_THROW_ON_ERROR));
    $runner = new ComposerRunner($this->workingDirectory);

    expect(fn() => $runner->run(['exec', 'rm', '-rf', '/']))
        ->toThrow(InvalidArgumentException::class, 'Unsupported Composer command');
});