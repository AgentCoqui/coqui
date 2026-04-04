<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Toolkit\ShellToolkit;
use React\EventLoop\Loop;

function shellExecTool(ShellToolkit $toolkit): ToolInterface
{
    foreach ($toolkit->tools() as $tool) {
        if ($tool->toFunctionSchema()['function']['name'] === 'exec') {
            return $tool;
        }
    }

    throw new RuntimeException("exec tool not found");
}

beforeEach(function () {
    $this->workDir = sys_get_temp_dir() . '/coqui-shell-' . bin2hex(random_bytes(8));
    mkdir($this->workDir, 0755, true);
});

afterEach(function () {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->workDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->workDir);
});

// ---------------------------------------------------------------
// cwd parameter resolution
// ---------------------------------------------------------------

test('exec uses default workDir when no cwd given', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->toBe(realpath($this->workDir));
})->skip(PHP_OS_FAMILY === 'Windows', 'pwd output format differs on Windows (MSYS vs realpath)');

test('exec resolves relative cwd from workDir', function () {
    mkdir($this->workDir . '/sub');
    $toolkit = new ShellToolkit(workDir: $this->workDir);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => 'sub']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->toBe(realpath($this->workDir . '/sub'));
})->skip(PHP_OS_FAMILY === 'Windows', 'pwd output format differs on Windows (MSYS vs realpath)');

test('exec returns error for non-existent cwd', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => '/does/not/exist/coqui-test']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('exec returns error when cwd is a file not a directory', function () {
    file_put_contents($this->workDir . '/notadir.txt', 'content');
    $toolkit = new ShellToolkit(workDir: $this->workDir);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => $this->workDir . '/notadir.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ---------------------------------------------------------------
// allowedCommands behavior (open-by-default vs restrictive)
// ---------------------------------------------------------------

test('exec allows all commands when allowedCommands is empty', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo hello']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->toBe('hello');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('exec blocks command not in allowlist when allowlist is set', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, allowedCommands: ['echo']);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'ls']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

// ---------------------------------------------------------------
// unsafe mode
// ---------------------------------------------------------------

test('unsafe mode allows commands blocked by allowlist', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, allowedCommands: ['echo'], unsafe: true);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'ls']);

    expect($result->status)->toBe(ToolResultStatus::Success);
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec uses Unix commands not available on Windows');

test('unsafe mode allows commands blocked by denylist', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, deniedCommands: ['echo'], unsafe: true);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo hello']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->toBe('hello');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec uses Unix commands not available on Windows');

test('unsafe mode bypasses DENIED_PATTERNS', function () {
    // 'mkdir -p' would normally work, but 'rm -rf /' is a DENIED_PATTERN.
    // Test using a pattern that is normally blocked: recursive rm
    $toolkit = new ShellToolkit(workDir: $this->workDir, unsafe: true);
    $tool = shellExecTool($toolkit);

    // Create a temp dir, then rm -rf it — the command gets past ShellToolkit
    $subDir = $this->workDir . '/to-remove';
    mkdir($subDir, 0755, true);
    file_put_contents($subDir . '/file.txt', 'content');

    $result = $tool->execute(['command' => 'rm -rf ' . escapeshellarg($subDir)]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(is_dir($subDir))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'rm -rf is not available on Windows');

test('unsafe mode guidelines show unrestricted', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, allowedCommands: ['echo'], unsafe: true);

    expect($toolkit->guidelines())->toContain('unsafe mode');
});

test('non-unsafe mode still blocks denied commands', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, deniedCommands: ['ls']);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'ls']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});

test('exec terminates running command when cancellation token is triggered', function () {
    $token = new ProcessCancellationToken();
    $toolkit = new ShellToolkit(workDir: $this->workDir, cancellationToken: $token, timeout: 10);
    $tool = shellExecTool($toolkit);

    Loop::addTimer(0.05, static function () use ($token): void {
        $token->cancel();
    });

    $result = $tool->execute(['command' => 'sleep 5']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Command cancelled.');
})->skip(PHP_OS_FAMILY === 'Windows', 'sleep command is intended for Unix environments');

// ---------------------------------------------------------------
// cwd sandbox enforcement (rootPath + allowedPaths)
// ---------------------------------------------------------------

test('cwd sandbox blocks escape above rootPath', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => '/tmp']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('Invalid working directory');
})->skip(PHP_OS_FAMILY === 'Windows', 'Path resolution differs on Windows');

test('cwd sandbox allows subdirectory of rootPath', function () {
    $sub = $this->workDir . '/nested';
    mkdir($sub, 0755, true);

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => $sub]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->toBe(realpath($sub));
})->skip(PHP_OS_FAMILY === 'Windows', 'pwd output format differs on Windows');

test('cwd sandbox allows mount path', function () {
    // Use /tmp as a simulated mount path
    $mountDir = sys_get_temp_dir() . '/coqui-mount-' . bin2hex(random_bytes(4));
    mkdir($mountDir, 0755, true);

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        allowedPaths: [['realPath' => realpath($mountDir), 'readOnly' => true]],
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => $mountDir]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->toBe(realpath($mountDir));

    rmdir($mountDir);
})->skip(PHP_OS_FAMILY === 'Windows', 'Path resolution differs on Windows');

test('cwd sandbox blocks path not in rootPath or mounts', function () {
    $outsideDir = sys_get_temp_dir() . '/coqui-outside-' . bin2hex(random_bytes(4));
    mkdir($outsideDir, 0755, true);

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        allowedPaths: [], // no mounts
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => $outsideDir]);

    expect($result->status)->toBe(ToolResultStatus::Error);

    rmdir($outsideDir);
})->skip(PHP_OS_FAMILY === 'Windows', 'Path resolution differs on Windows');

test('cwd sandbox is not enforced when rootPath is null', function () {
    // Default behavior — no sandbox enforcement
    $toolkit = new ShellToolkit(workDir: $this->workDir);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => sys_get_temp_dir()]);

    expect($result->status)->toBe(ToolResultStatus::Success);
})->skip(PHP_OS_FAMILY === 'Windows', 'pwd output format differs on Windows');

test('cwd sandbox blocks relative path that escapes via ..', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => '../../../tmp']);

    expect($result->status)->toBe(ToolResultStatus::Error);
})->skip(PHP_OS_FAMILY === 'Windows', 'Path resolution differs on Windows');
