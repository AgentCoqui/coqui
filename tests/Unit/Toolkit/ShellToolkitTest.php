<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CarmeloSantana\PHPAgents\Toolkit\ShellToolkit;

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
})->skip(PHP_OS_FAMILY === 'Windows', 'pwd is not cross-platform (MSYS path format differs from realpath())');

test('exec resolves relative cwd from workDir', function () {
    mkdir($this->workDir . '/sub');
    $toolkit = new ShellToolkit(workDir: $this->workDir);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'pwd', 'cwd' => 'sub']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->toBe(realpath($this->workDir . '/sub'));
})->skip(PHP_OS_FAMILY === 'Windows', 'pwd is not cross-platform (MSYS path format differs from realpath())');

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
});

test('exec blocks command not in allowlist when allowlist is set', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, allowedCommands: ['echo']);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'ls']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
