<?php

declare(strict_types=1);

use CoquiBot\Coqui\Tool\PhpExecuteTool;

beforeEach(function () {
    $this->projectRoot = dirname(__DIR__, 3);
    $this->tmpDir = sys_get_temp_dir() . '/coqui-exec-test-' . bin2hex(random_bytes(4));
    mkdir($this->tmpDir, 0755, true);
    $this->tool = new PhpExecuteTool(
        projectRoot: $this->projectRoot,
        workspacePath: $this->tmpDir,
    );
});

afterEach(function () {
    // Clean up tmp dir
    $files = glob($this->tmpDir . '/tmp/*') ?: [];
    foreach ($files as $f) {
        unlink($f);
    }
    $tmpSubDir = $this->tmpDir . '/tmp';
    if (is_dir($tmpSubDir)) {
        rmdir($tmpSubDir);
    }
    if (is_dir($this->tmpDir)) {
        rmdir($this->tmpDir);
    }
});

test('has correct name', function () {
    expect($this->tool->name())->toBe('php_execute');
});

test('executes simple PHP code', function () {
    $result = $this->tool->execute([
        'code' => 'echo "Hello from Coqui!";',
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('Hello from Coqui!');
})->skip(PHP_OS_FAMILY === 'Windows', 'ReactPHP process pipes are not supported on Windows');

test('captures stderr on error', function () {
    $result = $this->tool->execute([
        'code' => '$x = 1 / 0;',
    ]);

    // Division by zero produces a warning/error
    expect($result->content)->toContain('stderr');
})->skip(PHP_OS_FAMILY === 'Windows', 'ReactPHP process pipes are not supported on Windows');

test('denies eval in code', function () {
    $result = $this->tool->execute([
        'code' => 'eval("echo 1;");',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('eval');
});

test('denies exec in code', function () {
    $result = $this->tool->execute([
        'code' => 'exec("whoami");',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('exec');
});

test('denies system in code', function () {
    $result = $this->tool->execute([
        'code' => 'system("ls");',
    ]);

    expect($result->status->value)->toBe('error');
});

test('denies backtick execution', function () {
    $result = $this->tool->execute([
        'code' => '$output = `whoami`;',
    ]);

    expect($result->status->value)->toBe('error');
});

test('requires code parameter', function () {
    $result = $this->tool->execute(['code' => '']);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('required');
});

test('cleans up temp files after execution', function () {
    $this->tool->execute([
        'code' => 'echo "test";',
    ]);

    $tmpDir = $this->tmpDir . '/tmp';
    $files = is_dir($tmpDir) ? (glob($tmpDir . '/exec_*.php') ?: []) : [];

    expect($files)->toBeEmpty();
})->skip(PHP_OS_FAMILY === 'Windows', 'ReactPHP process pipes are not supported on Windows');

test('reports exit code', function () {
    $result = $this->tool->execute([
        'code' => 'echo "success"; exit(0);',
    ]);

    expect($result->content)->toContain('Exit code');
    expect($result->content)->toContain('0');
})->skip(PHP_OS_FAMILY === 'Windows', 'ReactPHP process pipes are not supported on Windows');

test('generates valid function schema', function () {
    $schema = $this->tool->toFunctionSchema();

    expect($schema['type'])->toBe('function');
    expect($schema['function']['name'])->toBe('php_execute');
    expect($schema['function']['parameters']['required'])->toBe(['code']);
});

test('autoloader is available in executed code', function () {
    $result = $this->tool->execute([
        'code' => <<<'PHP'
            // Test that the autoloader loaded successfully
            echo class_exists(\Symfony\Component\Console\Command\Command::class) ? "autoloader works" : "no autoloader";
            PHP,
    ]);

    expect($result->status->value)->toBe('success');
    expect($result->content)->toContain('autoloader works');
})->skip(PHP_OS_FAMILY === 'Windows', 'ReactPHP process pipes are not supported on Windows');

// ---------------------------------------------------------------
// ScriptSanitizer denies file-write functions
// ---------------------------------------------------------------

test('denies file_put_contents in code', function () {
    $result = $this->tool->execute([
        'code' => 'file_put_contents("/tmp/hack.txt", "data");',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('file_put_contents');
});

test('denies fwrite in code', function () {
    $result = $this->tool->execute([
        'code' => '$f = fopen("/tmp/x", "w"); fwrite($f, "data");',
    ]);

    expect($result->status->value)->toBe('error');
});

test('denies fopen in code', function () {
    $result = $this->tool->execute([
        'code' => '$f = fopen("/tmp/x", "w");',
    ]);

    expect($result->status->value)->toBe('error');
    expect($result->content)->toContain('fopen');
});

test('denies mkdir in code', function () {
    $result = $this->tool->execute([
        'code' => 'mkdir("/tmp/evil-dir");',
    ]);

    expect($result->status->value)->toBe('error');
});

test('denies unlink in code', function () {
    $result = $this->tool->execute([
        'code' => 'unlink("/tmp/some-file.txt");',
    ]);

    expect($result->status->value)->toBe('error');
});

test('denies fputcsv in code', function () {
    $result = $this->tool->execute([
        'code' => '$f = fopen("/tmp/x.csv", "w"); fputcsv($f, ["a","b"]);',
    ]);

    expect($result->status->value)->toBe('error');
});

// ---------------------------------------------------------------
// Runtime disable_functions blocks write functions even if
// sanitizer is bypassed (e.g. via variable function calls)
// ---------------------------------------------------------------

test('runtime disable_functions blocks file_put_contents', function () {
    // Use variable indirection to bypass static analysis
    $result = $this->tool->execute([
        'code' => <<<'PHP'
            $fn = 'file_put_' . 'contents';
            $fn('/tmp/coqui-test-blocked.txt', 'should not write');
            echo 'written';
            PHP,
    ]);

    // Either the sanitizer catches it or RuntimeException from disable_functions
    if ($result->status->value === 'error') {
        expect(true)->toBeTrue(); // Blocked by sanitizer
    } else {
        // If it somehow passed sanitizer, runtime should block it
        expect($result->content)->not->toContain('written');
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'ReactPHP process pipes are not supported on Windows');
