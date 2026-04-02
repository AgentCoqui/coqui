<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\MountManager;
use CoquiBot\Coqui\Config\PathHelper;
use CoquiBot\Coqui\Config\ScriptSanitizer;
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
            Execute PHP code to interact with installed SDK packages.
            
            The code runs in a subprocess with:
            - strict_types=1 enabled
            - Composer autoloader loaded automatically
            - Workspace .env file loaded (credentials available via getenv())
            
            Use this for quick SDK interactions like API calls, data processing, etc.
            For complex multi-file scripts, prefer writing files and running via shell.
            
            IMPORTANT:
            - Access credentials via getenv('KEY_NAME') — never hardcode secrets
            - The code is validated for safety before execution
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
                "Code failed safety validation:\n- {$issueList}\n\n"
                . 'Rewrite the code without using denied functions or patterns.',
            );
        }

        // Build the full script with bootstrap preamble
        $script = $this->buildScript($code);

        // Write to temp file
        $tmpDir = PathHelper::trimTrailingSlash($this->workspacePath) . '/tmp';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $tmpFile = $tmpDir . '/exec_' . bin2hex(random_bytes(8)) . '.php';
        file_put_contents($tmpFile, $script);

        try {
            $result = $this->runScript($tmpFile, $timeout);
        } finally {
            // Always clean up
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }

        return $result;
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

        $preamble .= "\n// --- User code begins ---\n\n";

        return $preamble . $code . "\n";
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
        $paths = [
            PathHelper::trimTrailingSlash($this->workspacePath),
            PathHelper::trimTrailingSlash($this->projectRoot),
            sys_get_temp_dir(),
        ];

        // Append mounted directories so PHP subprocesses can read/write them
        if ($this->mountManager !== null) {
            array_push($paths, ...$this->mountManager->openBasedirPaths());
        }

        return implode(PATH_SEPARATOR, $paths);
    }

    /**
     * @return ToolResult
     */
    private function runScript(string $scriptPath, int $timeout): ToolResult
    {
        $openBasedirDirective = 'open_basedir=' . $this->buildOpenBasedir();
        if (PHP_OS_FAMILY === 'Windows') {
            $openBasedirDirective = '"' . $openBasedirDirective . '"';
        }

        $commandLine = 'php -d ' . escapeshellarg($openBasedirDirective) . ' ' . escapeshellarg($scriptPath);
        $reactProcess = new ReactProcess($commandLine, $this->workspacePath);
        $deferred = new Deferred();
        $stdout = '';
        $stderr = '';
        $timedOut = false;

        try {
            $reactProcess->start();
        } catch (\Throwable $e) {
            return ToolResult::error('Failed to start PHP process: ' . $e->getMessage());
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

        if ($timedOut) {
            return ToolResult::error("Script timed out after {$timeout}s.");
        }

        // Truncate output
        if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
            $stdout = substr($stdout, 0, self::MAX_OUTPUT_BYTES) . "\n--- output truncated ---";
        }

        if (strlen($stderr) > self::MAX_OUTPUT_BYTES) {
            $stderr = substr($stderr, 0, self::MAX_OUTPUT_BYTES) . "\n--- stderr truncated ---";
        }

        $output = '';

        if ($stdout !== '') {
            $output .= "**stdout:**\n```\n{$stdout}\n```\n\n";
        }

        if ($stderr !== '') {
            $output .= "**stderr:**\n```\n{$stderr}\n```\n\n";
        }

        $output .= "**Exit code:** {$exitCode}";

        return $exitCode === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
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
                            'description' => 'PHP code to execute (without <?php tag).',
                        ],
                        'description' => [
                            'type' => 'string',
                            'description' => 'Brief description of what this code does.',
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
