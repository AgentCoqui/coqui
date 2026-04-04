<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Enum\ToolResultStatus;
use CoquiBot\Coqui\Api\ProcessCancellationToken;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use React\ChildProcess\Process as ReactProcess;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use function React\Async\await;

final class ShellToolkit implements ToolkitInterface
{
    /**
     * Regex patterns that indicate dangerous shell constructs.
     * These are checked in addition to the configurable denylist.
     *
     * @var string[]
     */
    private const DENIED_PATTERNS = [
        '/\brm\s+(-[a-z]*r[a-z]*\s+-[a-z]*f|-[a-z]*f[a-z]*\s+-[a-z]*r)\b/i',  // rm -rf / rm -fr variants
        '/\brm\s+-[a-z]*rf\b/i',                                                  // rm -rf combined
        '/\|\s*(bash|sh|zsh|dash)\b/i',                                            // Pipe to shell
        '/\bcurl\b.*\|\s*(bash|sh|zsh)\b/i',                                      // curl | bash
        '/\bwget\b.*\|\s*(bash|sh|zsh)\b/i',                                      // wget | bash
        '/\bphp\s+-r\b/i',                                                         // php -r inline execution
        '/\bmkfifo\b/i',                                                            // Named pipe creation
        '/\b(nc|ncat|netcat)\s/i',                                                  // Network tools
    ];

    /**
     * @param string[] $allowedCommands
     * @param string[] $deniedCommands
     */
    public function __construct(
        private readonly string $workDir = '.',
        private readonly array $allowedCommands = [],
        private readonly array $deniedCommands = ['sudo', 'chmod 777'],
        private readonly int $timeout = 30,
        private readonly bool $unsafe = false,
        private readonly ?ProcessCancellationToken $cancellationToken = null,
    ) {}

    public function tools(): array
    {
        return [$this->execTool()];
    }

    public function guidelines(): string
    {
        $allowed = $this->unsafe
            ? 'all (unsafe mode — only catastrophic patterns blocked at policy layer)'
            : (empty($this->allowedCommands) ? 'all (except denied)' : implode(', ', $this->allowedCommands));

        return <<<GUIDELINES
        <SHELL-GUIDELINES>
        Working directory: {$this->workDir}
        Allowed commands: {$allowed}
        Timeout: {$this->timeout}s
        - Use shell commands for build, test, and system operations.
        - Prefer specific commands over broad ones.
        - Always check exit codes and stderr.
        </SHELL-GUIDELINES>
        GUIDELINES;
    }

    private function execTool(): ToolInterface
    {
        return new Tool(
            name: 'exec',
            description: 'Execute a shell command.',
            parameters: [
                new StringParameter('command', 'The shell command to execute'),
                new StringParameter('cwd', 'Working directory to run the command in. Relative paths are resolved from the default working directory. Defaults to project root.', required: false),
                new NumberParameter('timeout', 'Timeout in seconds', required: false, integer: true),
            ],
            callback: function (array $input): ToolResult {
                $command = $input['command'] ?? '';
                $timeout = (int) ($input['timeout'] ?? $this->timeout);
                $cwd = $input['cwd'] ?? null;

                if ($command === '') {
                    return ToolResult::error('Command is required');
                }

                // In unsafe mode, skip all command validation — only basic sanity
                // and working directory resolution apply. Catastrophic commands are
                // blocked at the execution policy layer (CatastrophicBlacklist).
                if (!$this->unsafe) {
                    if (!$this->isCommandAllowed($command)) {
                        return ToolResult::error("Command not allowed: {$command}");
                    }

                    // When an allowlist is active, also check for shell injection
                    // patterns that could bypass the allowlist.
                    if (!empty($this->allowedCommands) && $this->hasShellInjection($command)) {
                        return ToolResult::error('Denied: command contains shell metacharacters that could bypass the allowlist.');
                    }

                    // Check configurable denylist (substring match)
                    foreach ($this->deniedCommands as $denied) {
                        if (str_contains($command, $denied)) {
                            return ToolResult::error("Denied command pattern detected: {$denied}");
                        }
                    }

                    // Check built-in regex deny patterns
                    foreach (self::DENIED_PATTERNS as $pattern) {
                        if (preg_match($pattern, $command)) {
                            return ToolResult::error("Denied: command matches a blocked security pattern.");
                        }
                    }
                }

                // Resolve working directory
                $effectiveCwd = $this->resolveCwd($cwd);
                if ($effectiveCwd === null) {
                    return ToolResult::error("Invalid working directory: {$cwd}");
                }

                // Use ReactPHP child-process for non-blocking execution.
                // During await(), the event loop runs — spinner timer fires.
                $reactProcess = new ReactProcess($command, $effectiveCwd);
                $deferred = new Deferred();
                $stdout = '';
                $stderr = '';
                $timedOut = false;
                $cancelled = false;

                try {
                    $reactProcess->start();
                } catch (\Throwable $e) {
                    return ToolResult::error("Failed to execute command: {$e->getMessage()}");
                }

                $reactProcess->stdout?->on('data', static function (string $chunk) use (&$stdout): void {
                    $stdout .= $chunk;
                });

                $reactProcess->stderr?->on('data', static function (string $chunk) use (&$stderr): void {
                    $stderr .= $chunk;
                });

                $this->cancellationToken?->onCancel(static function () use ($reactProcess, &$cancelled): void {
                    $cancelled = true;
                    $reactProcess->terminate();
                });

                $timeoutTimer = Loop::addTimer($timeout, static function () use ($reactProcess, &$timedOut): void {
                    $timedOut = true;
                    $reactProcess->terminate();
                });

                $reactProcess->on('exit', static function (?int $code) use ($deferred, $timeoutTimer): void {
                    Loop::cancelTimer($timeoutTimer);
                    $deferred->resolve($code ?? 1);
                });

                $exitCode = (int) await($deferred->promise());

                if ($timedOut) {
                    return ToolResult::error("Command timed out after {$timeout}s");
                }

                if ($cancelled) {
                    return ToolResult::error('Command cancelled.');
                }

                $result = [
                    'exit_code' => $exitCode,
                    'stdout' => $stdout,
                    'stderr' => $stderr,
                ];

                return new ToolResult(
                    status: $exitCode === 0
                        ? ToolResultStatus::Success
                        : ToolResultStatus::Error,
                    content: json_encode($result, JSON_PRETTY_PRINT) ?: '',
                );
            },
        );
    }

    private function isCommandAllowed(string $command): bool
    {
        if (empty($this->allowedCommands)) {
            return true;
        }

        // Parse the actual executable from the command, stripping any
        // environment variable assignments (KEY=value) and handling
        // common shell constructs.
        $trimmed = trim($command);
        $words = preg_split('/\s+/', $trimmed) ?: [$trimmed];

        // Skip leading env var assignments (e.g., FOO=bar command)
        $firstWord = '';
        foreach ($words as $word) {
            if (str_contains($word, '=') && !str_starts_with($word, '-')) {
                continue;
            }
            $firstWord = $word;
            break;
        }

        if ($firstWord === '') {
            return false;
        }

        // Check against allowlist
        foreach ($this->allowedCommands as $allowed) {
            if ($firstWord === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check for shell metacharacters that could be used to chain
     * or redirect command execution beyond the allowlist.
     */
    private function hasShellInjection(string $command): bool
    {
        // Detect command chaining that could bypass the allowlist
        $patterns = [
            '/[;&|]/',                    // Command separators and pipes
            '/\$\(/',                     // Command substitution
            '/`/',                        // Backtick substitution
            '/\b(eval|source|\.)\s/',    // eval/source execution
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    private function resolveCwd(?string $cwd): ?string
    {
        if ($cwd === null || $cwd === '') {
            return $this->workDir;
        }

        // Resolve relative paths against the default working directory
        if (!str_starts_with($cwd, '/')) {
            $cwd = $this->workDir . '/' . $cwd;
        }

        $resolved = realpath($cwd);

        if ($resolved === false || !is_dir($resolved)) {
            return null;
        }

        return $resolved;
    }
}
