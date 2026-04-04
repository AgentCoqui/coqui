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
     * Regex patterns matching common write-oriented redirections/commands
     * with absolute paths. Used by validateWriteTargets() to detect
     * writes outside the workspace sandbox.
     *
     * Each pattern should capture the target path in group 1.
     *
     * @var string[]
     */
    private const WRITE_REDIRECT_PATTERNS = [
        // Shell output redirections: >, >>, 2>, 2>>, &>, &>>
        '/(?:^|[^\\\\])(?:>>?|[12]>>?|&>>?)\s*([\'"]?)(\/?[^\s;|&<>]+)\1/m',
    ];

    /**
     * Commands whose last positional argument is a write target.
     *
     * @var string[]
     */
    private const WRITE_COMMANDS = [
        'tee', 'cp', 'mv', 'install', 'rsync', 'scp',
    ];

    /**
     * Environment variable name patterns that are safe to pass to subprocesses.
     * Checked case-insensitively. Prefix-matched (e.g. 'LC_' matches LC_ALL, LC_CTYPE).
     *
     * @var string[]
     */
    private const SAFE_ENV_PREFIXES = [
        'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL', 'PWD', 'OLDPWD',
        'LANG', 'LANGUAGE', 'LC_', 'TERM', 'COLORTERM',
        'TMPDIR', 'TMP', 'TEMP',
        'DISPLAY', 'WAYLAND_DISPLAY', 'XDG_',
        'EDITOR', 'VISUAL', 'PAGER',
        'SSH_AUTH_SOCK', 'SSH_AGENT_PID', 'GPG_AGENT_INFO',
        'FORCE_COLOR', 'NO_COLOR', 'CLICOLOR', 'CLICOLOR_FORCE',
        // Build tools
        'GIT_', 'COMPOSER_', 'NODE_', 'NPM_', 'NVM_', 'YARN_',
        'GOPATH', 'GOROOT', 'CARGO_', 'RUSTUP_', 'JAVA_HOME', 'MAVEN_',
        'PIP_', 'VIRTUAL_ENV', 'CONDA_', 'PYENV_',
        'DOCKER_', 'KUBECONFIG',
        // Coqui workspace
        'COQUI_WORKSPACE',
    ];

    /**
     * Environment variable name patterns that are ALWAYS blocked,
     * regardless of SAFE_ENV_PREFIXES. Substring-matched case-insensitively.
     *
     * @var string[]
     */
    private const SENSITIVE_ENV_PATTERNS = [
        'KEY', 'TOKEN', 'SECRET', 'PASSWORD', 'CREDENTIAL', 'AUTH',
    ];

    /**
     * @param string[] $allowedCommands
     * @param string[] $deniedCommands
     * @param array<int, array{realPath: string, readOnly: bool}> $allowedPaths Mount paths for cwd sandbox
     */
    public function __construct(
        private readonly string $workDir = '.',
        private readonly array $allowedCommands = [],
        private readonly array $deniedCommands = ['sudo', 'chmod 777'],
        private readonly int $timeout = 30,
        private readonly bool $unsafe = false,
        private readonly ?ProcessCancellationToken $cancellationToken = null,
        private readonly ?string $rootPath = null,
        private readonly array $allowedPaths = [],
        private readonly bool $sandboxWrites = true,
        private readonly bool $scrubEnvironment = true,
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

        $lines = [
            '<SHELL-GUIDELINES>',
            "Working directory: {$this->workDir}",
            "Allowed commands: {$allowed}",
            "Timeout: {$this->timeout}s",
            '- Use shell commands for build, test, and system operations.',
            '- Prefer specific commands over broad ones.',
            '- Always check exit codes and stderr.',
        ];

        if ($this->sandboxWrites) {
            $lines[] = '- Shell output (redirections, cp, mv) is sandboxed to the workspace and mounted directories. Absolute paths outside the sandbox will be rejected.';
        }

        if (!empty($this->allowedCommands)) {
            $lines[] = '- Allowlist mode rejects shell operators, redirection, line breaks, and leading environment assignments.';
        }

        $lines[] = '</SHELL-GUIDELINES>';

        return implode("\n", $lines);
    }

    private function execTool(): ToolInterface
    {
        return new Tool(
            name: 'exec',
            description: 'Execute a shell command.',
            parameters: [
                new StringParameter('command', 'The shell command to execute'),
                new StringParameter('cwd', 'Working directory to run the command in. Relative paths are resolved from the default working directory. Defaults to the workspace root.', required: false),
                new NumberParameter('timeout', 'Timeout in seconds', required: false, integer: true),
            ],
            callback: function (array $input): ToolResult {
                $command = $input['command'] ?? '';
                $timeout = (int) ($input['timeout'] ?? $this->timeout);
                $cwd = $input['cwd'] ?? null;

                if ($command === '') {
                    return ToolResult::error('Command is required');
                }

                $allowlistViolation = $this->validateAllowlistedCommand($command);
                if ($allowlistViolation !== null) {
                    return ToolResult::error($allowlistViolation);
                }

                // In unsafe mode, skip all command validation — only basic sanity
                // and working directory resolution apply. Catastrophic commands are
                // blocked at the execution policy layer (CatastrophicBlacklist).
                if (!$this->unsafe) {
                    if (!$this->isCommandAllowed($command)) {
                        return ToolResult::error("Command not allowed: {$command}");
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

                // Sandbox writes: validate all write targets are within the sandbox.
                // This check is always-on when enabled — not gated by allowlist or --unsafe.
                if ($this->sandboxWrites) {
                    $writeViolation = $this->validateWriteTargets($command, $effectiveCwd);
                    if ($writeViolation !== null) {
                        return ToolResult::error($writeViolation);
                    }
                }

                // Build subprocess environment
                $processEnv = $this->scrubEnvironment ? $this->buildSanitizedEnvironment() : null;

                // Use ReactPHP child-process for non-blocking execution.
                // During await(), the event loop runs — spinner timer fires.
                $reactProcess = new ReactProcess($command, $effectiveCwd, $processEnv);
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

        $trimmed = trim($command);
        $words = preg_split('/\s+/', $trimmed, 2) ?: [$trimmed];
        $firstWord = $words[0];

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
     * Reject shell syntax that makes allowlist mode unsafe.
     */
    private function validateAllowlistedCommand(string $command): ?string
    {
        if (empty($this->allowedCommands)) {
            return null;
        }

        if (preg_match('/[\r\n]/', $command) === 1) {
            return 'Denied: allowlisted commands cannot contain line breaks.';
        }

        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        if ($tokens !== [] && $this->hasLeadingEnvironmentAssignment($tokens)) {
            return 'Denied: allowlisted commands cannot start with environment variable assignments.';
        }

        if ($this->hasShellOperators($command)) {
            return 'Denied: allowlisted commands cannot use shell operators, redirection, or command substitution.';
        }

        return null;
    }

    /**
     * @param string[] $tokens
     */
    private function hasLeadingEnvironmentAssignment(array $tokens): bool
    {
        $firstToken = $tokens[0] ?? '';

        if ($firstToken === '') {
            return false;
        }

        return preg_match('/^[A-Za-z_][A-Za-z0-9_]*=.*/', $firstToken) === 1;
    }

    /**
     * Detect shell operators outside of quoted strings.
     */
    private function hasShellOperators(string $command): bool
    {
        $quote = null;
        $escapeNext = false;
        $length = strlen($command);

        for ($index = 0; $index < $length; $index++) {
            $char = $command[$index];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                    continue;
                }

                if ($char === '`') {
                    return true;
                }

                if ($char === '$' && ($command[$index + 1] ?? '') === '(') {
                    return true;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if (in_array($char, [';', '&', '|', '<', '>', '`'], true)) {
                return true;
            }

            if ($char === '$' && ($command[$index + 1] ?? '') === '(') {
                return true;
            }
        }

        return $quote !== null;
    }

    /**
     * Validate that all write targets in a shell command are within the sandbox.
     *
     * Parses redirect operators and write-oriented commands, extracts target
     * paths, and validates each against the workspace root and allowed mounts.
     *
     * This is a best-effort heuristic parser — it handles the common patterns
     * generated by LLMs but cannot parse all shell edge cases (eval, variable
     * indirection, process substitution). Defense-in-depth via CatastrophicBlacklist
     * and environment scrubbing covers what the parser misses.
     *
     * @return string|null Error message if a write target escapes, null if clean
     */
    private function validateWriteTargets(string $command, string $effectiveCwd): ?string
    {
        $targets = [];

        // 1. Extract redirect targets (>, >>, 2>, 2>>, &>, &>>)
        // Match unquoted redirections — skip inside single quotes
        $targets = [...$targets, ...$this->extractRedirectTargets($command)];

        // 2. Extract write-command destination arguments
        $targets = [...$targets, ...$this->extractWriteCommandTargets($command)];

        // 3. Check for dd of= pattern
        if (preg_match('/\bdd\b.*\bof=([^\s]+)/i', $command, $m)) {
            $targets[] = $m[1];
        }

        // 4. Validate each target path
        foreach ($targets as $target) {
            $target = trim($target, "'\"");

            if ($target === '' || $target === '/dev/null' || $target === '/dev/stderr' || $target === '/dev/stdout') {
                continue;
            }

            // Block paths containing variable expansion or command substitution — we
            // can't resolve them statically and they could point anywhere
            if (preg_match('/\$[\({a-zA-Z_]|`/', $target)) {
                return "Denied: shell write target contains variable expansion or command substitution: {$target}";
            }

            $violation = $this->isPathOutsideSandbox($target, $effectiveCwd);
            if ($violation !== null) {
                return $violation;
            }
        }

        return null;
    }

    /**
     * Extract output redirect targets from a command string.
     *
     * Parses the command character-by-character to respect quoting contexts,
     * then finds redirect operators and their target paths.
     *
     * @return string[]
     */
    private function extractRedirectTargets(string $command): array
    {
        $targets = [];
        $length = strlen($command);
        $quote = null;
        $escapeNext = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($escapeNext) {
                $escapeNext = false;
                continue;
            }

            // Track quote context
            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                }
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            // Outside quotes: look for output redirect operators
            // Match: >, >>, 2>, 2>>, &>, &>>, 1>, 1>>
            if ($char === '>' || (($char === '1' || $char === '2' || $char === '&') && ($command[$i + 1] ?? '') === '>')) {
                $pos = $i;

                // Skip the operator chars
                if ($char === '1' || $char === '2' || $char === '&') {
                    $pos++; // skip digit/&
                }
                $pos++; // skip first >
                if (($command[$pos] ?? '') === '>') {
                    $pos++; // skip second > (append mode)
                }

                // Skip whitespace after operator
                while ($pos < $length && $command[$pos] === ' ') {
                    $pos++;
                }

                // Extract the target path — up to next whitespace or shell metachar
                $target = '';
                $tQuote = null;
                while ($pos < $length) {
                    $tc = $command[$pos];
                    if ($tQuote === null && ($tc === "'" || $tc === '"')) {
                        $tQuote = $tc;
                        $pos++;
                        continue;
                    }
                    if ($tQuote !== null && $tc === $tQuote) {
                        $tQuote = null;
                        $pos++;
                        continue;
                    }
                    if ($tQuote === null && in_array($tc, [' ', "\t", ';', '|', '&', '<', '>', "\n"], true)) {
                        break;
                    }
                    $target .= $tc;
                    $pos++;
                }

                if ($target !== '') {
                    $targets[] = $target;
                }

                // Advance outer loop past what we consumed
                $i = $pos - 1;
            }
        }

        return $targets;
    }

    /**
     * Extract destination paths from write-oriented commands (cp, mv, tee, etc.).
     *
     * Uses a simple heuristic: the last non-option argument is the destination.
     * For tee, the path arguments after the command name are targets.
     *
     * @return string[]
     */
    private function extractWriteCommandTargets(string $command): array
    {
        $targets = [];

        // Split on pipe sequences to handle each pipeline segment
        $segments = preg_split('/\s*\|\s*/', $command) ?: [$command];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            $tokens = $this->tokenizeCommand($segment);
            if ($tokens === []) {
                continue;
            }

            $cmd = basename($tokens[0]);

            if (!in_array($cmd, self::WRITE_COMMANDS, true)) {
                continue;
            }

            if ($cmd === 'tee') {
                // tee writes to all file arguments (skip options starting with -)
                for ($j = 1, $count = count($tokens); $j < $count; $j++) {
                    if (!str_starts_with($tokens[$j], '-')) {
                        $targets[] = $tokens[$j];
                    }
                }
            } else {
                // cp, mv, install, rsync, scp — last non-option arg is destination
                $nonOptions = array_values(array_filter(
                    array_slice($tokens, 1),
                    static fn(string $t): bool => !str_starts_with($t, '-'),
                ));
                if (count($nonOptions) >= 2) {
                    $targets[] = $nonOptions[count($nonOptions) - 1];
                }
            }
        }

        return $targets;
    }

    /**
     * Simple shell tokenizer that respects quoting.
     *
     * @return string[]
     */
    private function tokenizeCommand(string $command): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $escapeNext = false;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($escapeNext) {
                $current .= $char;
                $escapeNext = false;
                continue;
            }

            if ($char === '\\' && $quote !== "'") {
                $escapeNext = true;
                continue;
            }

            if ($quote !== null) {
                if ($char === $quote) {
                    $quote = null;
                    continue;
                }
                $current .= $char;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                continue;
            }

            $current .= $char;
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    /**
     * Check if a target path is outside the sandbox boundary.
     *
     * @return string|null Error message if outside sandbox, null if within
     */
    private function isPathOutsideSandbox(string $target, string $effectiveCwd): ?string
    {
        if ($this->rootPath === null) {
            return null; // No sandbox configured
        }

        $realRoot = realpath($this->rootPath);
        if ($realRoot === false) {
            return null;
        }

        // Resolve the target to an absolute path
        if (str_starts_with($target, '/')) {
            $absoluteTarget = $target;
        } elseif (str_starts_with($target, '~/') || $target === '~') {
            // Home directory expansion
            $home = getenv('HOME') ?: '/';
            $absoluteTarget = $home . '/' . ltrim(substr($target, 1), '/');
        } else {
            $absoluteTarget = $effectiveCwd . '/' . $target;
        }

        // Canonicalize path segments (resolve . and ..)
        $segments = explode('/', $absoluteTarget);
        $resolved = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $segment;
            }
        }
        $canonicalized = '/' . implode('/', $resolved);

        // Resolve symlinks when the parent directory exists (handles macOS /var → /private/var)
        $parentDir = dirname($canonicalized);
        $realParent = realpath($parentDir);
        if ($realParent !== false) {
            $canonicalized = $realParent . '/' . basename($canonicalized);
        }

        // Check if under workspace root
        if (str_starts_with($canonicalized, $realRoot)) {
            return null;
        }

        // Check against allowed mount paths (rw only — block writes to ro mounts)
        foreach ($this->allowedPaths as $allowed) {
            $realMountPath = realpath($allowed['realPath']);
            if ($realMountPath === false) {
                $realMountPath = $allowed['realPath'];
            }
            if (str_starts_with($canonicalized, $realMountPath)) {
                if ($allowed['readOnly']) {
                    return "Denied: write target is in a read-only mount: {$target}";
                }
                return null;
            }
        }

        return "Denied: shell write target escapes the workspace sandbox: {$target}";
    }

    /**
     * Build a sanitized environment for subprocess execution.
     *
     * Keeps known-safe variables (PATH, HOME, GIT_*, etc.) and strips
     * anything containing sensitive patterns (KEY, TOKEN, SECRET, etc.).
     *
     * @return array<string, string>
     */
    private function buildSanitizedEnvironment(): array
    {
        $env = getenv();
        if (!is_array($env)) {
            return [];
        }

        $sanitized = [];

        foreach ($env as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                continue;
            }

            $upperName = strtoupper($name);

            // Check sensitive patterns first (deny takes priority)
            $isSensitive = false;
            foreach (self::SENSITIVE_ENV_PATTERNS as $pattern) {
                if (str_contains($upperName, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                continue;
            }

            // Check against safe prefixes
            foreach (self::SAFE_ENV_PREFIXES as $prefix) {
                $upperPrefix = strtoupper($prefix);
                if ($upperName === $upperPrefix || str_starts_with($upperName, $upperPrefix)) {
                    $sanitized[$name] = $value;
                    break;
                }
            }
        }

        return $sanitized;
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

        // Enforce sandbox: resolved cwd must be under the root path or an allowed mount
        if ($this->rootPath !== null) {
            $realRoot = realpath($this->rootPath);
            if ($realRoot !== false) {
                if (str_starts_with($resolved, $realRoot)) {
                    return $resolved;
                }

                // Check allowed mount paths
                foreach ($this->allowedPaths as $allowed) {
                    if (str_starts_with($resolved, $allowed['realPath'])) {
                        return $resolved;
                    }
                }

                // Path escapes sandbox
                return null;
            }
        }

        return $resolved;
    }
}
