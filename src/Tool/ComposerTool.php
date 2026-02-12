<?php

declare(strict_types=1);

namespace Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use Coqui\Config\ToolkitDiscovery;

/**
 * Tool that manages Coqui's own composer dependencies.
 *
 * Allows the agent to install, remove, inspect, and update composer packages
 * in the Coqui project. All mutating operations create backups of composer.json
 * and composer.lock before executing.
 *
 * A denylist prevents installation of packages that could break Coqui
 * (e.g. full frameworks that conflict with the minimal-dependency approach).
 */
final class ComposerTool implements ToolInterface
{
    private const DENYLIST_PATTERNS = [
        'laravel/*',
        'illuminate/*',
        'symfony/symfony',
        'symfony/framework-bundle',
        'laminas/*',
        'yiisoft/yii2',
        'cakephp/cakephp',
        'slim/slim',
    ];

    private string $backupDir;

    public function __construct(
        private readonly string $projectRoot,
        private readonly string $workspacePath,
        private readonly ?ToolkitDiscovery $discovery = null,
    ) {
        $this->backupDir = rtrim($this->workspacePath, '/') . '/backups/composer';
    }

    public function name(): string
    {
        return 'composer';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage Coqui's own composer dependencies.
            
            Use this tool to extend Coqui's capabilities by installing new PHP packages,
            or to inspect currently installed dependencies. All mutating operations (require,
            remove, update) automatically create backups before executing.
            
            Available actions:
            - require: Install a new package (creates backup first)
            - remove: Remove a package (creates backup first)
            - show: Show details about a specific package
            - installed: List all installed packages
            - update: Update a specific package or all packages (creates backup first)
            - validate: Validate composer.json
            - outdated: Show outdated packages
            - audit: Check installed packages for known security vulnerabilities
            
            Some packages are blocked by a denylist to prevent breaking Coqui
            (e.g. full frameworks like Laravel, Laminas).
            
            **Tip:** Use the `packagist` tool first to search for and evaluate packages
            before installing them with `require`.
            
            After installing a package, use the `package_info` tool to learn its API
            before writing code that uses it.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The composer action to perform',
                values: ['require', 'remove', 'show', 'installed', 'update', 'validate', 'outdated', 'audit'],
                required: true,
            ),
            new StringParameter(
                name: 'package',
                description: 'Package name (vendor/package). Required for require, remove, show, update.',
                required: false,
            ),
            new StringParameter(
                name: 'version',
                description: 'Version constraint for require (e.g. "^2.0", "~1.5"). Defaults to latest.',
                required: false,
            ),
            new BoolParameter(
                name: 'dev',
                description: 'Whether to use --dev flag (for require/remove). Default: false.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';
        $package = $input['package'] ?? '';
        $version = $input['version'] ?? '';
        $dev = (bool) ($input['dev'] ?? false);

        return match ($action) {
            'require' => $this->requirePackage($package, $version, $dev),
            'remove' => $this->removePackage($package, $dev),
            'show' => $this->showPackage($package),
            'installed' => $this->listInstalled(),
            'update' => $this->updatePackage($package),
            'validate' => $this->validate(),
            'outdated' => $this->showOutdated(),
            'audit' => $this->runAudit(),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function requirePackage(string $package, string $version, bool $dev): ToolResult
    {
        if ($package === '') {
            return ToolResult::error('Package name is required for require action.');
        }

        $blocked = $this->checkDenylist($package);
        if ($blocked !== null) {
            return ToolResult::error($blocked);
        }

        $backupPath = $this->backup();
        if ($backupPath === null) {
            return ToolResult::error('Failed to create backup before installing package.');
        }

        $packageArg = $version !== '' ? "{$package}:{$version}" : $package;
        $devFlag = $dev ? ' --dev' : '';
        $command = "composer require {$packageArg}{$devFlag} --no-interaction --no-ansi 2>&1";

        $result = $this->runCommand($command);

        $output = "## Composer Require\n\n";
        $output .= "**Package:** {$packageArg}\n";
        $output .= "**Backup:** {$backupPath}\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        if ($result['exit_code'] !== 0) {
            return ToolResult::error($output);
        }

        // Run toolkit discovery on the newly installed package
        if ($this->discovery !== null) {
            $discovered = $this->discovery->discover($package);
            if (!empty($discovered)) {
                $output .= "\n\n### Discovered Toolkits\n\n";
                foreach ($discovered as $class) {
                    $output .= "- `{$class}`\n";
                }
                $output .= "\nThese toolkits will be available in future sessions.";
            }
        }

        // Check for php-agents metadata in the installed package
        $metadata = $this->readPackageMetadata($package);
        if ($metadata !== null) {
            $output .= "\n\n### Package Metadata\n\n{$metadata}";
        }

        // Run a security audit on the newly installed package
        $auditResult = $this->runCommand('composer audit --no-ansi 2>&1');
        if ($auditResult['exit_code'] !== 0 && str_contains($auditResult['output'], 'advisories')) {
            $output .= "\n\n### ⚠ Security Advisory\n\n";
            $output .= "```\n{$auditResult['output']}\n```";
        }

        return ToolResult::success($output);
    }

    private function removePackage(string $package, bool $dev): ToolResult
    {
        if ($package === '') {
            return ToolResult::error('Package name is required for remove action.');
        }

        $backupPath = $this->backup();
        if ($backupPath === null) {
            return ToolResult::error('Failed to create backup before removing package.');
        }

        $devFlag = $dev ? ' --dev' : '';
        $command = "composer remove {$package}{$devFlag} --no-interaction --no-ansi 2>&1";

        $result = $this->runCommand($command);

        // Remove from toolkit registry if discovery is available
        if ($result['exit_code'] === 0 && $this->discovery !== null) {
            $this->discovery->unregister($package);
        }

        $output = "## Composer Remove\n\n";
        $output .= "**Package:** {$package}\n";
        $output .= "**Backup:** {$backupPath}\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    private function showPackage(string $package): ToolResult
    {
        if ($package === '') {
            return ToolResult::error('Package name is required for show action.');
        }

        $command = "composer show {$package} --no-ansi 2>&1";
        $result = $this->runCommand($command);

        return $result['exit_code'] === 0
            ? ToolResult::success($result['output'])
            : ToolResult::error($result['output']);
    }

    private function listInstalled(): ToolResult
    {
        $command = 'composer show --format=json --no-ansi 2>&1';
        $result = $this->runCommand($command);

        if ($result['exit_code'] !== 0) {
            return ToolResult::error($result['output']);
        }

        $data = json_decode($result['output'], true);
        if (!is_array($data) || !isset($data['installed'])) {
            return ToolResult::success($result['output']);
        }

        $output = "## Installed Packages\n\n";
        $output .= "| Package | Version | Description |\n";
        $output .= "|---------|---------|-------------|\n";

        foreach ($data['installed'] as $pkg) {
            $name = $pkg['name'] ?? 'unknown';
            $ver = $pkg['version'] ?? '?';
            $desc = $pkg['description'] ?? '';
            if (strlen($desc) > 60) {
                $desc = substr($desc, 0, 57) . '...';
            }
            $output .= "| {$name} | {$ver} | {$desc} |\n";
        }

        return ToolResult::success($output);
    }

    private function updatePackage(string $package): ToolResult
    {
        $backupPath = $this->backup();
        if ($backupPath === null) {
            return ToolResult::error('Failed to create backup before updating.');
        }

        $pkgArg = $package !== '' ? " {$package}" : '';
        $command = "composer update{$pkgArg} --no-interaction --no-ansi 2>&1";

        $result = $this->runCommand($command);

        $output = "## Composer Update\n\n";
        $output .= "**Package:** " . ($package !== '' ? $package : 'all') . "\n";
        $output .= "**Backup:** {$backupPath}\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    private function validate(): ToolResult
    {
        $command = 'composer validate --no-ansi 2>&1';
        $result = $this->runCommand($command);

        return $result['exit_code'] === 0
            ? ToolResult::success($result['output'])
            : ToolResult::error($result['output']);
    }

    private function showOutdated(): ToolResult
    {
        $command = 'composer outdated --no-ansi 2>&1';
        $result = $this->runCommand($command);

        // Exit code 0 = no outdated, 1 = has outdated (not an error)
        return ToolResult::success($result['output'] !== '' ? $result['output'] : 'All packages are up to date.');
    }

    private function runAudit(): ToolResult
    {
        $command = 'composer audit --no-ansi 2>&1';
        $result = $this->runCommand($command);

        $output = "## Security Audit\n\n";
        $output .= "```\n{$result['output']}\n```";

        // Exit code 0 = no issues found
        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    /**
     * Read php-agents metadata from a package's composer.json extra key.
     */
    private function readPackageMetadata(string $package): ?string
    {
        $composerJson = $this->projectRoot . '/vendor/' . $package . '/composer.json';

        if (!file_exists($composerJson)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($composerJson), true);
        if (!is_array($data)) {
            return null;
        }

        $extra = $data['extra']['php-agents'] ?? null;
        if (!is_array($extra)) {
            return null;
        }

        $output = '';

        if (isset($extra['toolkits']) && is_array($extra['toolkits'])) {
            $output .= "**Declared toolkits:** " . implode(', ', array_map(fn($c) => "`{$c}`", $extra['toolkits'])) . "\n";
        }

        if (isset($extra['agents']) && is_array($extra['agents'])) {
            $output .= "**Declared agents:** " . implode(', ', array_map(fn($c) => "`{$c}`", $extra['agents'])) . "\n";
        }

        if (isset($extra['description'])) {
            $output .= "**Description:** {$extra['description']}\n";
        }

        return $output !== '' ? $output : null;
    }

    private function checkDenylist(string $package): ?string
    {
        foreach (self::DENYLIST_PATTERNS as $pattern) {
            if (fnmatch($pattern, $package, FNM_CASEFOLD)) {
                return "Package '{$package}' is blocked by the denylist. "
                     . 'Full frameworks and framework bundles are not allowed to prevent '
                     . 'dependency conflicts and maintain Coqui\'s minimal architecture.';
            }
        }

        return null;
    }

    /**
     * Backup composer.json and composer.lock before a mutating operation.
     *
     * @return string|null The backup directory path, or null on failure.
     */
    private function backup(): ?string
    {
        $timestamp = date('Y-m-d_His');
        $backupPath = $this->backupDir . '/' . $timestamp;

        if (!is_dir($backupPath)) {
            if (!mkdir($backupPath, 0755, true)) {
                return null;
            }
        }

        $composerJson = $this->projectRoot . '/composer.json';
        $composerLock = $this->projectRoot . '/composer.lock';

        if (file_exists($composerJson)) {
            copy($composerJson, $backupPath . '/composer.json');
        }

        if (file_exists($composerLock)) {
            copy($composerLock, $backupPath . '/composer.lock');
        }

        return $backupPath;
    }

    /**
     * @return array{exit_code: int, output: string}
     */
    private function runCommand(string $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->projectRoot);

        if (!is_resource($process)) {
            return ['exit_code' => 1, 'output' => 'Failed to start composer process.'];
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = trim($stdout);
        if ($stderr !== '') {
            $output .= "\n" . trim($stderr);
        }

        return ['exit_code' => $exitCode, 'output' => $output];
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
                        'action' => [
                            'type' => 'string',
                            'description' => 'The composer action to perform',
                            'enum' => ['require', 'remove', 'show', 'installed', 'update', 'validate', 'outdated', 'audit'],
                        ],
                        'package' => [
                            'type' => 'string',
                            'description' => 'Package name (vendor/package). Required for require, remove, show, update.',
                        ],
                        'version' => [
                            'type' => 'string',
                            'description' => 'Version constraint for require (e.g. "^2.0"). Defaults to latest.',
                        ],
                        'dev' => [
                            'type' => 'boolean',
                            'description' => 'Whether to use --dev flag. Default: false.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
