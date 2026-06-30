<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\MountManager;
use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Config\ScriptSanitizer;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Support\IdGenerator;
use React\ChildProcess\Process as ReactProcess;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use function React\Async\await;

/**
 * Executes generated PHP code in a subprocess.
 *
 * Writes code to a temp file in the workspace, auto-prepends a bootstrap
 * preamble (strict_types, autoloader, dotenv), runs it via `php`, captures
 * stdout/stderr, and cleans up. Output is truncated to prevent context overflow.
 *
 * Security layers:
 * 1. ScriptSanitizer — static check for denied functions/patterns
 * 2. InteractiveApprovalPolicy — user sees the code before execution
 * 3. Timeout — process is killed if it exceeds the time limit
 */
final class PhpExecuteTool implements ToolInterface
{
    private const MAX_OUTPUT_BYTES = 32768;
    private const LINT_FILE_PREFIX = 'lint_';
    private const EXECUTION_FILE_PREFIX = 'exec_';

    /**
     * Filesystem write functions blocked at the PHP engine level via disable_functions.
     * This is defense-in-depth — ScriptSanitizer catches these in static analysis,
     * but disable_functions enforces the restriction at runtime even if static
     * analysis is bypassed (e.g. via variable indirection or eval).
     */
    private const DISABLED_WRITE_FUNCTIONS = [
        'file_put_contents',
        'fwrite',
        'fputs',
        'fopen',
        'mkdir',
        'rmdir',
        'unlink',
        'rename',
        'copy',
        'touch',
        'chmod',
        'chown',
        'chgrp',
        'symlink',
        'link',
        'tempnam',
        'fputcsv',
    ];

    private const RUNTIME_GUARD_PREFIX = 'php_execute bootstrap failed:';

    private readonly ScriptSanitizer $sanitizer;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly int $defaultTimeout = 30,
        ?ScriptSanitizer $sanitizer = null,
        private readonly ?MountManager $mountManager = null,
    ) {
        $this->sanitizer = $sanitizer ?? new ScriptSanitizer();
    }

    public function name(): string
    {
        return 'php_execute';
    }

    public function description(): string
    {
        return <<<'DESC'
            Execute inline PHP code for quick calculations, debugging, data inspection, snippet validation, and SDK interactions.
            
            The code runs in a subprocess with:
            - strict_types=1 enabled
            - Composer autoloader loaded automatically
            - Workspace .env file loaded (credentials available via getenv())
            - An automatic syntax check before execution
            
            Prefer this over shell exec when the task is "run some PHP".
            Use shell or composer tools for repository-wide commands such as composer test, composer analyse, pest, or phpstan.
            For complex multi-file scripts, prefer writing files and then using the right workspace or shell tools.
            
            IMPORTANT:
            - Access credentials via getenv('KEY_NAME') — never hardcode secrets
            - The code is validated for safety before execution
            - Syntax failures are reported before runtime execution starts
            - Output is truncated to ~32KB
            - Functions like eval(), exec(), system() are not allowed
            - Filesystem write functions (file_put_contents, fwrite, mkdir, etc.) are blocked
            - To write files, use the workspace file tools (write_file, create_dir) instead
            DESC;
    }

    public function parameters(): array
    {
        return [
            new StringParameter(
                name: 'code',
                description: 'The PHP code to execute. Do NOT include <?php tag or declare(strict_types=1) — they are added automatically.',
                required: true,
            ),
            new StringParameter(
                name: 'description',
                description: 'Brief description of what this code does (shown in approval prompt).',
                required: false,
            ),
            new NumberParameter(
                name: 'timeout',
                description: 'Timeout in seconds (default: 30).',
                required: false,
                integer: true,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $code = $input['code'] ?? '';
        $timeout = (int) ($input['timeout'] ?? $this->defaultTimeout);

        if (trim($code) === '') {
            return ToolResult::error('Code is required.');
        }

        // Static safety check
        $issues = $this->sanitizer->validate($code);
        if (!empty($issues)) {
            $issueList = implode("\n- ", $issues);

            return ToolResult::error(
                "**Phase:** safety-check\n"
                . "**Summary:** PHP snippet rejected before execution.\n\n"
                . "**Issues:**\n- {$issueList}\n\n"
                . 'Rewrite the code without using denied functions or patterns.',
            );
        }

        $lintFile = null;
        $executionFile = null;

        try {
            $tmpDir = $this->ensureTempDirectory();
            $identifier = IdGenerator::hex(8);
            $lintFile = $this->writeTempScript($tmpDir, self::LINT_FILE_PREFIX, $identifier, $this->buildLintScript($code));
            $executionFile = $this->writeTempScript($tmpDir, self::EXECUTION_FILE_PREFIX, $identifier, $this->buildScript($code));
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }

        try {
            $lintResult = $this->lintScript($lintFile, $timeout);
            if ($lintResult !== null) {
                return $lintResult;
            }

            return $this->runScript($executionFile, $timeout);
        } finally {
            $this->deleteIfExists($lintFile);
            $this->deleteIfExists($executionFile);
        }
    }

    private function ensureTempDirectory(): string
    {
        $tmpDir = PathHelper::trimTrailingSlash($this->workspacePath) . '/tmp';

        if (is_dir($tmpDir)) {
            return $tmpDir;
        }

        if (!mkdir($tmpDir, CoquiDefaults::DIRECTORY_MODE, true) && !is_dir($tmpDir)) {
            throw new \RuntimeException('Failed to create php_execute temp directory.');
        }

        return $tmpDir;
    }

    private function writeTempScript(string $tmpDir, string $prefix, string $identifier, string $content): string
    {
        $path = sprintf('%s/%s%s.php', $tmpDir, $prefix, $identifier);

        if (file_put_contents($path, $content) === false) {
            throw new \RuntimeException(sprintf('Failed to write temporary PHP script: %s', basename($path)));
        }

        return $path;
    }

    private function deleteIfExists(?string $path): void
    {
        if ($path !== null && file_exists($path)) {
            unlink($path);
        }
    }

    private function buildLintScript(string $code): string
    {
        return "<?php\n" . $code . "\n";
    }

    private function buildScript(string $code): string
    {
        $projectAutoloader = $this->projectRoot . '/vendor/autoload.php';
        $workspaceAutoloader = PathHelper::trimTrailingSlash($this->workspacePath) . '/vendor/autoload.php';
        $envPath = PathHelper::trimTrailingSlash($this->workspacePath) . '/.env';

        $preamble = "<?php\n\ndeclare(strict_types=1);\n\n";

        // Load project autoloader (read-only access via open_basedir)
        $preamble .= "require '{$projectAutoloader}';\n";

        // Load workspace autoloader if it exists (for bot-installed packages)
        $preamble .= "if (file_exists('{$workspaceAutoloader}')) {\n";
        $preamble .= "    require '{$workspaceAutoloader}';\n";
        $preamble .= "}\n\n";

        // Load .env if it exists
        $preamble .= <<<'DOTENV'
            // Load workspace credentials
            $__envFile = '__ENV_PATH__';
            if (file_exists($__envFile)) {
                foreach (file($__envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $__line) {
                    $__line = trim($__line);
                    if ($__line === '' || str_starts_with($__line, '#')) continue;
                    $__eq = strpos($__line, '=');
                    if ($__eq === false) continue;
                    $__key = trim(substr($__line, 0, $__eq));
                    $__val = trim(substr($__line, $__eq + 1));
                    if ((str_starts_with($__val, '"') && str_ends_with($__val, '"'))
                        || (str_starts_with($__val, "'") && str_ends_with($__val, "'"))) {
                        $__val = substr($__val, 1, -1);
                    }
                    $_ENV[$__key] = $__val;
                    putenv("{$__key}={$__val}");
                }
                unset($__envFile, $__line, $__eq, $__key, $__val);
            }

            DOTENV;

        // Replace the placeholder with actual path
        $preamble = str_replace('__ENV_PATH__', addslashes($envPath), $preamble);

        $preamble .= $this->buildRuntimeGuardPreamble();

        $preamble .= "\n// --- User code begins ---\n\n";

        return $preamble . $code . "\n";
    }

    private function lintScript(string $scriptPath, int $timeout): ?ToolResult
    {
        $result = $this->runPhpProcess($this->buildPhpCommandLine($scriptPath, lintOnly: true), $timeout);

        if ($result['timedOut']) {
            return ToolResult::error(
                $this->formatProcessOutput(
                    phase: 'syntax-check',
                    summary: "PHP syntax check timed out after {$timeout}s.",
                    stdout: $result['stdout'],
                    stderr: $result['stderr'],
                    exitCode: $result['exitCode'],
                    note: 'Shorten the snippet or increase the timeout, then rerun php_execute.',
                ),
            );
        }

        if ($result['startError'] !== null) {
            return ToolResult::error(
                $this->formatProcessOutput(
                    phase: 'syntax-check',
                    summary: 'Failed to start the PHP syntax check subprocess.',
                    stdout: '',
                    stderr: $result['startError'],
                    exitCode: $result['exitCode'],
                ),
            );
        }

        if ($result['exitCode'] === 0) {
            return null;
        }

        return ToolResult::error(
            $this->formatProcessOutput(
                phase: 'syntax-check',
                summary: 'PHP syntax check failed before execution.',
                stdout: $this->normalizeLintStream($result['stdout'], $scriptPath),
                stderr: $this->normalizeLintStream($result['stderr'], $scriptPath),
                exitCode: $result['exitCode'],
                note: 'Fix the syntax error and rerun php_execute.',
            ),
        );
    }

    private function buildRuntimeGuardPreamble(): string
    {
        $guard = <<<'PHP'
            // Verify runtime safety directives before user code executes.
            $__requiredOpenBasedirPaths = __REQUIRED_OPEN_BASEDIR__;
            $__effectiveOpenBasedir = (string) ini_get('open_basedir');
            $__effectiveOpenBasedirPaths = array_values(array_filter(array_map(
                static fn(string $path): string => rtrim(trim($path), DIRECTORY_SEPARATOR),
                explode(PATH_SEPARATOR, $__effectiveOpenBasedir),
            )));
            $__missingOpenBasedirPaths = array_values(array_filter(
                $__requiredOpenBasedirPaths,
                static fn(string $path): bool => !in_array(rtrim($path, DIRECTORY_SEPARATOR), $__effectiveOpenBasedirPaths, true),
            ));
            if ($__missingOpenBasedirPaths !== []) {
                throw new \RuntimeException('__PREFIX__ missing open_basedir paths: ' . implode(', ', $__missingOpenBasedirPaths));
            }

            $__requiredDisabledFunctions = __REQUIRED_DISABLED_FUNCTIONS__;
            $__effectiveDisabledFunctions = array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));
            $__missingDisabledFunctions = array_values(array_diff($__requiredDisabledFunctions, $__effectiveDisabledFunctions));
            if ($__missingDisabledFunctions !== []) {
                throw new \RuntimeException('__PREFIX__ missing disable_functions entries: ' . implode(', ', $__missingDisabledFunctions));
            }

            unset(
                $__requiredOpenBasedirPaths,
                $__effectiveOpenBasedir,
                $__effectiveOpenBasedirPaths,
                $__missingOpenBasedirPaths,
                $__requiredDisabledFunctions,
                $__effectiveDisabledFunctions,
                $__missingDisabledFunctions,
            );

            PHP;

        return str_replace(
            ['__REQUIRED_OPEN_BASEDIR__', '__REQUIRED_DISABLED_FUNCTIONS__', '__PREFIX__'],
            [
                var_export($this->buildOpenBasedirPaths(), true),
                var_export(self::DISABLED_WRITE_FUNCTIONS, true),
                self::RUNTIME_GUARD_PREFIX,
            ],
            $guard,
        );
    }

    /**
     * Build the open_basedir restriction string.
     *
     * Includes workspace (read/write), project root (for autoloader reads),
     * and /tmp. While open_basedir cannot distinguish read vs write, the
     * ScriptSanitizer blocks filesystem write functions at the static
     * analysis level before code runs.
     */
    private function buildOpenBasedir(): string
    {
        return implode(PATH_SEPARATOR, $this->buildOpenBasedirPaths());
    }

    /**
     * @return list<string>
     */
    private function buildOpenBasedirPaths(): array
    {
        $paths = [
            PathHelper::trimTrailingSlash($this->workspacePath),
            PathHelper::trimTrailingSlash($this->projectRoot),
            rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR),
        ];

        if ($this->mountManager !== null) {
            foreach ($this->mountManager->openBasedirPaths() as $path) {
                $paths[] = rtrim($path, DIRECTORY_SEPARATOR);
            }
        }

        return array_values(array_unique($paths));
    }

    private function runScript(string $scriptPath, int $timeout): ToolResult
    {
        $result = $this->runPhpProcess($this->buildPhpCommandLine($scriptPath), $timeout);

        if ($result['timedOut']) {
            return ToolResult::error(
                $this->formatProcessOutput(
                    phase: 'execution',
                    summary: "PHP execution timed out after {$timeout}s.",
                    stdout: $result['stdout'],
                    stderr: $result['stderr'],
                    exitCode: $result['exitCode'],
                    note: 'Inspect the snippet for blocking work or increase the timeout before rerunning php_execute.',
                ),
            );
        }

        if ($result['startError'] !== null) {
            return ToolResult::error(
                $this->formatProcessOutput(
                    phase: 'execution',
                    summary: 'Failed to start the PHP execution subprocess.',
                    stdout: '',
                    stderr: $result['startError'],
                    exitCode: $result['exitCode'],
                ),
            );
        }

        $summary = $result['exitCode'] === 0
            ? 'PHP snippet executed successfully after syntax check.'
            : $this->classifyExecutionFailure($result['stderr']);

        $content = $this->formatProcessOutput(
            phase: 'execution',
            summary: $summary,
            stdout: $result['stdout'],
            stderr: $result['stderr'],
            exitCode: $result['exitCode'],
            note: $result['exitCode'] === 0
                ? null
                : 'Inspect stderr, adjust the snippet, and rerun php_execute. Use shell for repository-wide commands, not ad hoc PHP execution.',
        );

        return $result['exitCode'] === 0
            ? ToolResult::success($content)
            : ToolResult::error($content);
    }

    private function buildPhpCommandLine(string $scriptPath, bool $lintOnly = false): string
    {
        $openBasedirDirective = 'open_basedir=' . $this->buildOpenBasedir();
        if (PHP_OS_FAMILY === 'Windows') {
            $openBasedirDirective = '"' . $openBasedirDirective . '"';
        }

        $disableFunctionsDirective = 'disable_functions=' . implode(',', self::DISABLED_WRITE_FUNCTIONS);
        $disableCliOpcacheDirective = 'opcache.enable_cli=0';
        $disableJitDirective = 'opcache.jit=0';
        $disableJitBufferDirective = 'opcache.jit_buffer_size=0';

        $commandLine = 'php'
            . ' -d ' . escapeshellarg($openBasedirDirective)
            . ' -d ' . escapeshellarg($disableFunctionsDirective)
            . ' -d ' . escapeshellarg($disableCliOpcacheDirective)
            . ' -d ' . escapeshellarg($disableJitDirective)
            . ' -d ' . escapeshellarg($disableJitBufferDirective);

        if ($lintOnly) {
            $commandLine .= ' -l';
        }

        return $commandLine . ' ' . escapeshellarg($scriptPath);
    }

    /**
     * @return array{exitCode: int, stdout: string, stderr: string, timedOut: bool, startError: ?string}
     */
    private function runPhpProcess(string $commandLine, int $timeout): array
    {
        $reactProcess = new ReactProcess($commandLine, $this->workspacePath);
        $deferred = new Deferred();
        $stdout = '';
        $stderr = '';
        $timedOut = false;

        try {
            $reactProcess->start();
        } catch (\Throwable $e) {
            return [
                'exitCode' => 1,
                'stdout' => '',
                'stderr' => '',
                'timedOut' => false,
                'startError' => 'Failed to start PHP process: ' . $e->getMessage(),
            ];
        }

        $reactProcess->stdout?->on('data', static function (string $chunk) use (&$stdout): void {
            $stdout .= $chunk;
        });

        $reactProcess->stderr?->on('data', static function (string $chunk) use (&$stderr): void {
            $stderr .= $chunk;
        });

        $timeoutTimer = Loop::addTimer($timeout, static function () use ($reactProcess, &$timedOut): void {
            $timedOut = true;
            $reactProcess->terminate(9);
        });

        $reactProcess->on('exit', static function (?int $code) use ($deferred, $timeoutTimer): void {
            Loop::cancelTimer($timeoutTimer);
            $deferred->resolve($code ?? 1);
        });

        $exitCode = (int) await($deferred->promise());

        return [
            'exitCode' => $exitCode,
            'stdout' => $this->truncateStream($stdout, 'output truncated'),
            'stderr' => $this->truncateStream($stderr, 'stderr truncated'),
            'timedOut' => $timedOut,
            'startError' => null,
        ];
    }

    private function truncateStream(string $stream, string $label): string
    {
        if (strlen($stream) <= self::MAX_OUTPUT_BYTES) {
            return $stream;
        }

        return substr($stream, 0, self::MAX_OUTPUT_BYTES) . "\n--- {$label} ---";
    }

    private function normalizeLintStream(string $stream, string $scriptPath): string
    {
        if ($stream === '') {
            return '';
        }

        $normalized = str_replace($scriptPath, 'snippet', $stream);

        $adjusted = preg_replace_callback(
            '/line (\d+)/i',
            static function (array $matches): string {
                $line = max(1, ((int) $matches[1]) - 1);

                return 'line ' . $line;
            },
            $normalized,
        );

        return $adjusted ?? $normalized;
    }

    private function classifyExecutionFailure(string $stderr): string
    {
        if (str_contains($stderr, self::RUNTIME_GUARD_PREFIX)) {
            return 'PHP execution failed before user code ran because the runtime safety bootstrap rejected the environment.';
        }

        return 'PHP execution failed while running the snippet.';
    }

    private function formatProcessOutput(
        string $phase,
        string $summary,
        string $stdout,
        string $stderr,
        int $exitCode,
        ?string $note = null,
    ): string {
        $output = "**Phase:** {$phase}\n";
        $output .= "**Summary:** {$summary}\n\n";

        if ($stdout !== '') {
            $output .= "**stdout:**\n```\n{$stdout}\n```\n\n";
        }

        if ($stderr !== '') {
            $output .= "**stderr:**\n```\n{$stderr}\n```\n\n";
        }

        $output .= "**Exit code:** {$exitCode}";

        if ($note !== null) {
            $output .= "\n\n{$note}";
        }

        return $output;
    }

    public function toFunctionSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => [
                            'type' => 'string',
                            'description' => 'PHP code to execute (without <?php tag). A syntax check runs automatically before execution.',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Brief description of what this code does. Use this to say what you are validating or debugging.',
                        ],
                        'timeout' => [
                            'type' => 'integer',
                            'description' => 'Timeout in seconds (default: 30).',
                        ],
                    ],
                    'required' => ['code'],
                ],
            ],
        ];
    }
}
