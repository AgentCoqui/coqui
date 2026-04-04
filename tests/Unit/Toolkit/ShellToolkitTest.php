<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CoquiBot\Coqui\Config\ShellConfigResolver;
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

test('allowlist blocks shell redirection', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, allowedCommands: ['cat']);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'cat /etc/hosts > blocked.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('shell operators');
    expect(file_exists($this->workDir . '/blocked.txt'))->toBeFalse();
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('allowlist blocks leading environment assignments', function () {
    $toolkit = new ShellToolkit(workDir: $this->workDir, allowedCommands: ['grep']);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'PATH=. grep root /etc/passwd']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('environment variable assignments');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('allowlist permits quoted regex alternation', function () {
    file_put_contents($this->workDir . '/patterns.txt', "alpha\nbeta\n");

    $toolkit = new ShellToolkit(workDir: $this->workDir, allowedCommands: ['grep']);
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => "grep -E 'alpha|beta' patterns.txt"]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect($output['stdout'])->toContain('alpha');
    expect($output['stdout'])->toContain('beta');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

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

test('readonly shell command list excludes write-capable tools', function () {
    expect(ShellConfigResolver::READ_ONLY_SHELL_COMMANDS)->not->toContain('find');
    expect(ShellConfigResolver::READ_ONLY_SHELL_COMMANDS)->not->toContain('sed');
    expect(ShellConfigResolver::READ_ONLY_SHELL_COMMANDS)->not->toContain('awk');
    expect(ShellConfigResolver::READ_ONLY_SHELL_COMMANDS)->not->toContain('sort');
});

// ---------------------------------------------------------------
// Write sandbox (sandboxWrites)
// ---------------------------------------------------------------

test('write sandbox blocks redirect to absolute path outside workspace', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo pwned > /tmp/escape.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks append redirect outside workspace', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo pwned >> /tmp/escape.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks stderr redirect outside workspace', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'ls 2> /tmp/err.log']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox allows redirect to file within workspace', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo allowed > output.txt']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_exists($this->workDir . '/output.txt'))->toBeTrue();
    expect(trim(file_get_contents($this->workDir . '/output.txt')))->toBe('allowed');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox allows redirect using absolute workspace path', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $target = $this->workDir . '/abs-output.txt';
    $result = $tool->execute(['command' => "echo ok > {$target}"]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_exists($target))->toBeTrue();
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox allows redirect to rw mount path', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-mount-rw-' . bin2hex(random_bytes(4));
    mkdir($mountDir, 0755, true);

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
        allowedPaths: [['realPath' => realpath($mountDir), 'readOnly' => false]],
    );
    $tool = shellExecTool($toolkit);

    $target = $mountDir . '/mounted-output.txt';
    $result = $tool->execute(['command' => "echo ok > {$target}"]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_exists($target))->toBeTrue();

    unlink($target);
    rmdir($mountDir);
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks redirect to readonly mount', function () {
    $mountDir = sys_get_temp_dir() . '/coqui-mount-ro-' . bin2hex(random_bytes(4));
    mkdir($mountDir, 0755, true);

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
        allowedPaths: [['realPath' => realpath($mountDir), 'readOnly' => true]],
    );
    $tool = shellExecTool($toolkit);

    $target = $mountDir . '/readonly-output.txt';
    $result = $tool->execute(['command' => "echo blocked > {$target}"]);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('read-only mount');

    rmdir($mountDir);
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks home directory tilde path', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo evil >> ~/.bashrc']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks cp to path outside workspace', function () {
    file_put_contents($this->workDir . '/source.txt', 'data');

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'cp source.txt /tmp/escape-cp.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks mv to path outside workspace', function () {
    file_put_contents($this->workDir . '/moveme.txt', 'data');

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'mv moveme.txt /tmp/escape-mv.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks tee to path outside workspace', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo data | tee /tmp/escape-tee.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks variable expansion in target paths', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo evil > $HOME/.evil']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('variable expansion');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox allows /dev/null redirect', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo discarded > /dev/null']);

    expect($result->status)->toBe(ToolResultStatus::Success);
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox is disabled when sandboxWrites is false', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: false,
    );
    $tool = shellExecTool($toolkit);

    $target = sys_get_temp_dir() . '/coqui-sandbox-off-' . bin2hex(random_bytes(4)) . '.txt';
    $result = $tool->execute(['command' => "echo test > {$target}"]);

    expect($result->status)->toBe(ToolResultStatus::Success);
    expect(file_exists($target))->toBeTrue();

    unlink($target);
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox still applies in unsafe mode', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        unsafe: true,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo pwned > /tmp/unsafe-escape.txt']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox blocks dd of= outside workspace', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'dd if=/dev/zero of=/tmp/escape.img bs=1M count=1']);

    expect($result->status)->toBe(ToolResultStatus::Error);
    expect($result->content)->toContain('sandbox');
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

// ---------------------------------------------------------------
// Environment scrubbing (scrubEnvironment)
// ---------------------------------------------------------------

test('env scrubbing removes API keys from subprocess', function () {
    // Set a fake API key in the environment
    $originalKey = getenv('COQUI_TEST_API_KEY');
    putenv('COQUI_TEST_API_KEY=sk-test-secret-12345');

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        scrubEnvironment: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'env']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect($output['stdout'])->not->toContain('sk-test-secret-12345');

    // Restore
    if ($originalKey === false) {
        putenv('COQUI_TEST_API_KEY');
    } else {
        putenv("COQUI_TEST_API_KEY={$originalKey}");
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('env scrubbing preserves PATH', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        scrubEnvironment: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo $PATH']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->not->toBeEmpty();
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('env scrubbing preserves HOME', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        scrubEnvironment: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'echo $HOME']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect(trim($output['stdout']))->not->toBeEmpty();
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('env scrubbing preserves GIT_ variables', function () {
    $original = getenv('GIT_AUTHOR_NAME');
    putenv('GIT_AUTHOR_NAME=TestUser');

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        scrubEnvironment: true,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'env | grep GIT_AUTHOR_NAME']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect($output['stdout'])->toContain('GIT_AUTHOR_NAME=TestUser');

    if ($original === false) {
        putenv('GIT_AUTHOR_NAME');
    } else {
        putenv("GIT_AUTHOR_NAME={$original}");
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('env scrubbing disabled passes full environment', function () {
    $originalKey = getenv('COQUI_TEST_SECRET_TOKEN');
    putenv('COQUI_TEST_SECRET_TOKEN=tok-visible');

    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        scrubEnvironment: false,
    );
    $tool = shellExecTool($toolkit);

    $result = $tool->execute(['command' => 'env | grep COQUI_TEST_SECRET_TOKEN']);

    expect($result->status)->toBe(ToolResultStatus::Success);
    $output = json_decode($result->content, true);
    expect($output['stdout'])->toContain('tok-visible');

    if ($originalKey === false) {
        putenv('COQUI_TEST_SECRET_TOKEN');
    } else {
        putenv("COQUI_TEST_SECRET_TOKEN={$originalKey}");
    }
})->skip(PHP_OS_FAMILY === 'Windows', 'ShellToolkit exec is designed for Unix environments');

test('write sandbox guidelines mention sandbox', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: true,
    );

    expect($toolkit->guidelines())->toContain('sandbox');
});

test('write sandbox guidelines omit sandbox when disabled', function () {
    $toolkit = new ShellToolkit(
        workDir: $this->workDir,
        rootPath: $this->workDir,
        sandboxWrites: false,
    );

    expect($toolkit->guidelines())->not->toContain('sandboxed to the workspace');
});
