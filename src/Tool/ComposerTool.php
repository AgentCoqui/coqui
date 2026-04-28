<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\PackageEventListenerInterface;
use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PathHelper\PathHelper;

/**
 * Tool that manages Composer dependencies in the workspace.
 *
 * Provides full CRUD over the workspace composer.json — adding/removing
 * dependencies, managing path repositories for local packages, running
 * install/update/validate, and diagnosing common issues. All operations
 * target the workspace only — the host project is never modified.
 *
 * Structural changes (repositories, require entries) are done via native
 * PHP JSON manipulation for precision. Dependency resolution and installation
 * use the Composer CLI.
 */
final class ComposerTool implements ToolInterface
{
    private const PACKAGE_NAME_PATTERN = '#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#i';

    private const VERSION_CONSTRAINT_PATTERN = '/^[a-zA-Z0-9^~><=|.*@\- ]+$/';

    private const array DENYLIST_PATTERNS = [
        'laravel/*',
        'illuminate/*',
        'symfony/symfony',
        'symfony/framework-bundle',
        'laminas/*',
        'yiisoft/yii2',
        'cakephp/cakephp',
        'slim/slim',
    ];

    private readonly string $backupDir;
    private readonly string $composerJsonPath;
    private readonly string $workspaceRoot;

    public function __construct(
        private readonly string $workspacePath,
        private readonly ?PackageEventListenerInterface $listener = null,
    ) {
        $root = PathHelper::trimTrailingSlash($this->workspacePath);
        $this->workspaceRoot = $root;
        $this->backupDir = $root . '/backups/composer';
        $this->composerJsonPath = $root . '/composer.json';
    }

    public function name(): string
    {
        return 'composer';
    }

    public function description(): string
    {
        return <<<'DESC'
            Manage Composer dependencies in the workspace.

            Use this tool to extend capabilities by installing new PHP packages,
            managing local path repositories, and maintaining the workspace dependency tree.
            All operations target the workspace composer project only.

            Available actions:
            - show: Display the current workspace composer.json contents
            - add: Add a dependency (supports Packagist, local path, and VCS repositories).
              For local packages, specify repository_type="path" and repository_url="/absolute/path".
              Use version="@dev" for development packages.
            - remove: Remove a dependency
            - update: Update a specific package or all packages (creates backup first)
            - install: Run composer install to sync dependencies from lock file
            - dump-autoload: Regenerate autoloader files after structural changes
            - validate: Validate composer.json for syntax errors and schema compliance
            - doctor: Diagnose common workspace issues (lock freshness, autoloader, etc.)
            - search: Search installed packages by name or description
            - show-package: Show detailed info about a specific installed package
            - outdated: Show packages with available updates
            - audit: Check installed packages for known security vulnerabilities

            **Tip:** Use the `packagist` tool first to search and evaluate packages
            before installing them with the `add` action.
            DESC;
    }

    public function parameters(): array
    {
        return [
            new EnumParameter(
                name: 'action',
                description: 'The composer action to perform',
                values: [
                    'show', 'add', 'remove', 'update', 'install', 'dump-autoload',
                    'validate', 'doctor', 'search', 'show-package', 'outdated', 'audit',
                ],
                required: true,
            ),
            new StringParameter(
                name: 'package',
                description: 'Package name (vendor/package). Required for add, remove, show-package. Optional for update (omit to update all).',
                required: false,
            ),
            new StringParameter(
                name: 'version',
                description: 'Version constraint for add (e.g. "^2.0", "@dev"). Defaults to latest stable.',
                required: false,
            ),
            new BoolParameter(
                name: 'dev',
                description: 'Whether to add/remove as a dev dependency (require-dev). Default: false.',
                required: false,
            ),
            new StringParameter(
                name: 'repository_type',
                description: 'Repository type when adding a local or VCS package: "path" or "vcs". Not needed for Packagist packages.',
                required: false,
            ),
            new StringParameter(
                name: 'repository_url',
                description: 'Repository URL or path. Required when repository_type is set. For path repos, use absolute path to the package directory.',
                required: false,
            ),
            new StringParameter(
                name: 'query',
                description: 'Search query for the search action.',
                required: false,
            ),
        ];
    }

    public function execute(array $input): ToolResult
    {
        $action = $input['action'] ?? '';

        if (!is_dir($this->workspaceRoot)) {
            return ToolResult::error('Workspace directory not found. Composer operations are limited to the workspace only.');
        }

        if (!file_exists($this->composerJsonPath)) {
            return ToolResult::error(
                'Workspace composer.json not found. The workspace project should be initialized at startup.',
            );
        }

        return match ($action) {
            'show' => $this->showComposerJson(),
            'add' => $this->addPackage($input),
            'remove' => $this->removePackage($input),
            'update' => $this->updatePackage($input),
            'install' => $this->install(),
            'dump-autoload' => $this->dumpAutoload(),
            'validate' => $this->validate(),
            'doctor' => $this->doctor(),
            'search' => $this->searchInstalled($input),
            'show-package' => $this->showPackage($input),
            'outdated' => $this->showOutdated(),
            'audit' => $this->runAudit(),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    private function showComposerJson(): ToolResult
    {
        $content = file_get_contents($this->composerJsonPath);
        if ($content === false) {
            return ToolResult::error('Failed to read workspace composer.json.');
        }

        return ToolResult::success("## Workspace composer.json\n\n```json\n{$content}\n```");
    }

    /**
     * @param array<string, mixed> $input
     */
    private function addPackage(array $input): ToolResult
    {
        $package = $input['package'] ?? '';
        if ($package === '') {
            return ToolResult::error('Package name is required for add action.');
        }

        if (($error = $this->validatePackageName($package)) !== null) {
            return ToolResult::error($error);
        }

        $blocked = $this->checkDenylist($package);
        if ($blocked !== null) {
            return ToolResult::error($blocked);
        }

        $version = $input['version'] ?? '';
        if ($version !== '' && ($error = $this->validateVersionConstraint($version)) !== null) {
            return ToolResult::error($error);
        }

        $dev = (bool) ($input['dev'] ?? false);
        $repoType = $input['repository_type'] ?? '';
        $repoUrl = $input['repository_url'] ?? '';

        // If a repository type is specified, add the repository declaration first
        if ($repoType !== '' && $repoUrl !== '') {
            $repoResult = $this->addRepository($repoType, $repoUrl, $package);
            if ($repoResult !== null) {
                return $repoResult;
            }
        }

        $backupPath = $this->backup();
        if ($backupPath === null) {
            return ToolResult::error('Failed to create backup before installing package.');
        }

        $packageArg = $version !== '' ? "{$package}:{$version}" : $package;
        $arguments = ['require', $packageArg, '--no-interaction', '--no-ansi'];
        if ($dev) {
            $arguments[] = '--dev';
        }

        $result = $this->runCommand($arguments);

        $output = "## Composer Add\n\n";
        $output .= "**Package:** {$package}" . ($version !== '' ? ":{$version}" : '') . "\n";
        $output .= "**Target:** workspace\n";
        if ($repoType !== '') {
            $output .= "**Repository:** {$repoType} ({$repoUrl})\n";
        }
        $output .= "**Backup:** {$backupPath}\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        if ($result['exit_code'] !== 0) {
            return ToolResult::error($output);
        }

        // Notify listener about the newly installed package
        $this->listener?->onPackageInstalled($package);

        // Check for php-agents metadata in the installed package
        $metadata = $this->readPackageMetadata($package);
        if ($metadata !== null) {
            $output .= "\n\n### Package Metadata\n\n{$metadata}";
        }

        // Run a security audit on the newly installed package
        $auditResult = $this->runCommand(['audit', '--no-ansi']);
        if ($auditResult['exit_code'] !== 0 && str_contains($auditResult['output'], 'advisories')) {
            $output .= "\n\n### Security Advisory\n\n";
            $output .= "```\n{$auditResult['output']}\n```";
        }

        return ToolResult::success($output);
    }

    /**
     * Add a repository declaration to composer.json before requiring a package.
     *
     * Returns a ToolResult on error, null on success.
     */
    private function addRepository(string $type, string $url, string $package): ?ToolResult
    {
        if (!in_array($type, ['path', 'vcs'], true)) {
            return ToolResult::error("Unsupported repository type: {$type}. Use 'path' or 'vcs'.");
        }

        if ($url === '') {
            return ToolResult::error('Repository URL/path is required when repository_type is set.');
        }

        // For path repositories, verify the directory exists
        if ($type === 'path' && !is_dir($url)) {
            return ToolResult::error("Path repository directory does not exist: {$url}");
        }

        $composerData = $this->readComposerJson();
        if ($composerData === null) {
            return ToolResult::error('Failed to parse workspace composer.json.');
        }

        // Build the repository entry
        $repoEntry = [
            'type' => $type,
            'url' => $url,
        ];

        if ($type === 'path') {
            $repoEntry['options'] = ['symlink' => false];
        }

        // Initialize repositories array if absent
        if (!isset($composerData['repositories']) || !is_array($composerData['repositories'])) {
            $composerData['repositories'] = [];
        }

        // Check for duplicate — don't add the same URL twice
        foreach ($composerData['repositories'] as $existing) {
            if (is_array($existing) && ($existing['url'] ?? '') === $url) {
                return null; // Already exists, skip
            }
        }

        $composerData['repositories'][] = $repoEntry;

        if (!$this->writeComposerJson($composerData)) {
            return ToolResult::error('Failed to write updated composer.json with repository declaration.');
        }

        return null;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function removePackage(array $input): ToolResult
    {
        $package = $input['package'] ?? '';
        if ($package === '') {
            return ToolResult::error('Package name is required for remove action.');
        }

        if (($error = $this->validatePackageName($package)) !== null) {
            return ToolResult::error($error);
        }

        $dev = (bool) ($input['dev'] ?? false);

        $backupPath = $this->backup();
        if ($backupPath === null) {
            return ToolResult::error('Failed to create backup before removing package.');
        }

        $arguments = ['remove', $package, '--no-interaction', '--no-ansi'];
        if ($dev) {
            $arguments[] = '--dev';
        }

        $result = $this->runCommand($arguments);

        if ($result['exit_code'] === 0) {
            $this->listener?->onPackageRemoved($package);

            // Clean up orphaned repository entries for the removed package
            $this->cleanupOrphanedRepositories($package);
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

    /**
     * @param array<string, mixed> $input
     */
    private function updatePackage(array $input): ToolResult
    {
        $package = $input['package'] ?? '';

        if ($package !== '' && ($error = $this->validatePackageName($package)) !== null) {
            return ToolResult::error($error);
        }

        $backupPath = $this->backup();
        if ($backupPath === null) {
            return ToolResult::error('Failed to create backup before updating.');
        }

        $arguments = ['update', '--no-interaction', '--no-ansi'];
        if ($package !== '') {
            array_splice($arguments, 1, 0, [$package]);
        }

        $result = $this->runCommand($arguments);

        $output = "## Composer Update\n\n";
        $output .= "**Package:** " . ($package !== '' ? $package : 'all') . "\n";
        $output .= "**Backup:** {$backupPath}\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    private function install(): ToolResult
    {
        $result = $this->runCommand(['install', '--no-interaction', '--no-ansi']);

        $output = "## Composer Install\n\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    private function dumpAutoload(): ToolResult
    {
        $result = $this->runCommand(['dump-autoload', '--optimize', '--no-ansi']);

        $output = "## Composer Dump-Autoload\n\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    private function validate(): ToolResult
    {
        $result = $this->runCommand(['validate', '--no-ansi']);

        $output = "## Composer Validate\n\n";
        $output .= "**Exit code:** {$result['exit_code']}\n\n";
        $output .= "```\n{$result['output']}\n```";

        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    private function doctor(): ToolResult
    {
        $root = PathHelper::trimTrailingSlash($this->workspacePath);
        $issues = [];
        $ok = [];

        // 1. Validate composer.json syntax
        $validateResult = $this->runCommand(['validate', '--no-ansi']);
        if ($validateResult['exit_code'] === 0) {
            $ok[] = '✓ composer.json is valid';
        } else {
            $issues[] = '✗ composer.json validation failed: ' . trim($validateResult['output']);
        }

        // 2. Check lock file freshness
        $lockPath = $root . '/composer.lock';
        if (file_exists($lockPath)) {
            $jsonMtime = filemtime($this->composerJsonPath);
            $lockMtime = filemtime($lockPath);
            if ($jsonMtime !== false && $lockMtime !== false && $jsonMtime > $lockMtime) {
                $issues[] = '✗ composer.lock is out of date — run `composer(action: "update")` to sync';
            } else {
                $ok[] = '✓ composer.lock is up to date';
            }
        } else {
            $issues[] = '✗ composer.lock not found — run `composer(action: "install")`';
        }

        // 3. Check vendor directory
        $vendorDir = $root . '/vendor';
        if (is_dir($vendorDir)) {
            $autoloader = $vendorDir . '/autoload.php';
            if (file_exists($autoloader)) {
                $ok[] = '✓ Autoloader exists';
            } else {
                $issues[] = '✗ Autoloader missing — run `composer(action: "dump-autoload")`';
            }
        } else {
            $issues[] = '✗ vendor/ directory missing — run `composer(action: "install")`';
        }

        // 4. Check for abandoned packages
        $showResult = $this->runCommand(['show', '--format=json', '--no-ansi']);
        if ($showResult['exit_code'] === 0) {
            $data = json_decode($showResult['output'], true);
            $installed = is_array($data) ? ($data['installed'] ?? []) : [];
            $abandoned = [];
            foreach ($installed as $pkg) {
                if (is_array($pkg) && ($pkg['abandoned'] ?? false)) {
                    $abandoned[] = $pkg['name'] ?? 'unknown';
                }
            }
            if ($abandoned !== []) {
                $issues[] = '⚠ Abandoned packages found: ' . implode(', ', $abandoned);
            } else {
                $ok[] = '✓ No abandoned packages';
            }
        }

        // 5. Security audit
        $auditResult = $this->runCommand(['audit', '--no-ansi']);
        if ($auditResult['exit_code'] === 0) {
            $ok[] = '✓ No security advisories';
        } else {
            if (str_contains($auditResult['output'], 'advisories')) {
                $issues[] = '✗ Security vulnerabilities found — run `composer(action: "audit")` for details';
            }
        }

        // 6. Check repositories for broken path references
        $composerData = $this->readComposerJson();
        if ($composerData !== null && isset($composerData['repositories']) && is_array($composerData['repositories'])) {
            foreach ($composerData['repositories'] as $repo) {
                if (is_array($repo) && ($repo['type'] ?? '') === 'path') {
                    $repoPath = $repo['url'] ?? '';
                    if ($repoPath !== '' && !is_dir($repoPath)) {
                        $issues[] = "✗ Path repository not found: {$repoPath}";
                    }
                }
            }
        }

        $output = "## Workspace Doctor\n\n";

        if ($ok !== []) {
            $output .= "### Passing\n\n" . implode("\n", $ok) . "\n\n";
        }

        if ($issues !== []) {
            $output .= "### Issues Found\n\n" . implode("\n", $issues) . "\n";
            return ToolResult::success($output);
        }

        $output .= "All checks passed. Workspace is healthy.\n";

        return ToolResult::success($output);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function searchInstalled(array $input): ToolResult
    {
        $query = $input['query'] ?? '';
        if ($query === '') {
            return ToolResult::error('The `query` parameter is required for the search action.');
        }

        $result = $this->runCommand(['show', '--format=json', '--no-ansi']);

        if ($result['exit_code'] !== 0) {
            return ToolResult::error($result['output']);
        }

        $data = json_decode($result['output'], true);
        if (!is_array($data) || !isset($data['installed'])) {
            return ToolResult::error('Failed to parse installed packages.');
        }

        $queryLower = strtolower($query);
        $matches = [];
        foreach ($data['installed'] as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $name = strtolower($pkg['name'] ?? '');
            $desc = strtolower($pkg['description'] ?? '');
            if (str_contains($name, $queryLower) || str_contains($desc, $queryLower)) {
                $matches[] = $pkg;
            }
        }

        if ($matches === []) {
            return ToolResult::success("No installed packages matching \"{$query}\".");
        }

        $output = "## Installed Packages Matching \"{$query}\"\n\n";
        $output .= "| Package | Version | Description |\n";
        $output .= "|---------|---------|-------------|\n";

        foreach ($matches as $pkg) {
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

    /**
     * @param array<string, mixed> $input
     */
    private function showPackage(array $input): ToolResult
    {
        $package = $input['package'] ?? '';
        if ($package === '') {
            return ToolResult::error('Package name is required for show-package action.');
        }

        if (($error = $this->validatePackageName($package)) !== null) {
            return ToolResult::error($error);
        }

        $result = $this->runCommand(['show', $package, '--no-ansi']);

        return $result['exit_code'] === 0
            ? ToolResult::success("## Package: {$package}\n\n```\n{$result['output']}\n```")
            : ToolResult::error($result['output']);
    }

    private function showOutdated(): ToolResult
    {
        $result = $this->runCommand(['outdated', '--no-ansi']);

        // Exit code 0 = no outdated, 1 = has outdated (not an error)
        $output = $result['output'] !== '' ? $result['output'] : 'All packages are up to date.';

        return ToolResult::success("## Outdated Packages\n\n{$output}");
    }

    private function runAudit(): ToolResult
    {
        $result = $this->runCommand(['audit', '--no-ansi']);

        $output = "## Security Audit\n\n```\n{$result['output']}\n```";

        return $result['exit_code'] === 0
            ? ToolResult::success($output)
            : ToolResult::error($output);
    }

    // -----------------------------------------------------------------
    // composer.json manipulation helpers
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    private function readComposerJson(): ?array
    {
        $content = file_get_contents($this->composerJsonPath);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeComposerJson(array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->composerJsonPath, $json . "\n") !== false;
    }

    /**
     * Remove path/VCS repository entries that were added for a removed package.
     *
     * Heuristic: if a path repository URL contains the package name's last segment,
     * and the package is no longer in require or require-dev, remove the repo entry.
     */
    private function cleanupOrphanedRepositories(string $removedPackage): void
    {
        $composerData = $this->readComposerJson();
        if ($composerData === null || !isset($composerData['repositories']) || !is_array($composerData['repositories'])) {
            return;
        }

        // Check if the package still exists in require or require-dev
        $require = $composerData['require'] ?? [];
        $requireDev = $composerData['require-dev'] ?? [];
        $allDeps = array_merge(
            is_array($require) ? array_keys($require) : [],
            is_array($requireDev) ? array_keys($requireDev) : [],
        );

        if (in_array($removedPackage, $allDeps, true)) {
            return;
        }

        // Extract the package short name for matching (e.g. "vendor/toolkit-foo" → "toolkit-foo")
        $parts = explode('/', $removedPackage);
        $shortName = end($parts);

        $repositories = $composerData['repositories'];
        $filtered = array_values(array_filter($repositories, static function (mixed $repo) use ($shortName): bool {
            if (!is_array($repo)) {
                return true;
            }
            $url = $repo['url'] ?? '';
            if (!is_string($url)) {
                return true;
            }
            // Only clean up path/vcs repos whose URL contains the package short name
            $type = $repo['type'] ?? '';
            if (!in_array($type, ['path', 'vcs'], true)) {
                return true;
            }

            return !str_contains($url, $shortName);
        }));

        if (count($filtered) !== count($repositories)) {
            $composerData['repositories'] = $filtered;
            if ($filtered === []) {
                unset($composerData['repositories']);
            }
            $this->writeComposerJson($composerData);
        }
    }

    /**
     * Read php-agents metadata from a package's composer.json extra key.
     */
    private function readPackageMetadata(string $package): ?string
    {
        $root = PathHelper::trimTrailingSlash($this->workspacePath);
        $composerJson = $root . '/vendor/' . $package . '/composer.json';

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

        if (isset($extra['description'])) {
            $output .= "**Description:** {$extra['description']}\n";
        }

        if (isset($extra['credentials']) && is_array($extra['credentials'])) {
            $keys = array_keys($extra['credentials']);
            $output .= "**Required credentials:** " . implode(', ', array_map(fn($k) => "`{$k}`", $keys)) . "\n";
        }

        return $output !== '' ? $output : null;
    }

    private function checkDenylist(string $package): ?string
    {
        foreach (self::DENYLIST_PATTERNS as $pattern) {
            if (fnmatch($pattern, $package, FNM_CASEFOLD)) {
                return "Package '{$package}' is blocked by the denylist. "
                     . 'Full frameworks and framework bundles are not allowed to prevent '
                     . 'dependency conflicts and maintain a minimal architecture.';
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

        $lockPath = $this->workspaceRoot . '/composer.lock';

        if (file_exists($this->composerJsonPath)) {
            copy($this->composerJsonPath, $backupPath . '/composer.json');
        }

        if (file_exists($lockPath)) {
            copy($lockPath, $backupPath . '/composer.lock');
        }

        return $backupPath;
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, output: string}
     */
    private function runCommand(array $arguments): array
    {
        $command = [$this->resolveComposerBinary(), ...$arguments];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->workspaceRoot, $this->buildEnvironment());

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

    private function resolveComposerBinary(): string
    {
        $envBin = getenv('COMPOSER_BIN');
        if ($envBin !== false && $envBin !== '') {
            return $envBin;
        }

        $candidates = PHP_OS_FAMILY === 'Windows'
            ? [
                getenv('APPDATA') . '\\Composer\\vendor\\bin\\composer',
                getenv('USERPROFILE') . '\\AppData\\Roaming\\Composer\\vendor\\bin\\composer',
            ]
            : [
                '/opt/homebrew/bin/composer',
                '/usr/local/bin/composer',
                '/usr/bin/composer',
            ];

        foreach ($candidates as $candidate) {
            if (file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'composer';
    }

    /**
     * @return array<string, string>
     */
    private function buildEnvironment(): array
    {
        $env = [];

        $keys = ['HOME', 'PATH', 'COMPOSER_HOME', 'COMPOSER_ALLOW_SUPERUSER'];
        if (PHP_OS_FAMILY === 'Windows') {
            array_push($keys, 'USERPROFILE', 'APPDATA', 'LOCALAPPDATA', 'SystemRoot', 'TEMP', 'TMP');
        }

        foreach ($keys as $key) {
            $value = getenv($key);
            if ($value !== false) {
                $env[$key] = $value;
            }
        }

        $env['COMPOSER_NO_INTERACTION'] = '1';

        return $env;
    }

    private function validatePackageName(string $package): ?string
    {
        if (preg_match(self::PACKAGE_NAME_PATTERN, $package) !== 1) {
            return "Invalid package name: '{$package}'. Expected format: vendor/package.";
        }

        return null;
    }

    private function validateVersionConstraint(string $version): ?string
    {
        if (preg_match(self::VERSION_CONSTRAINT_PATTERN, $version) !== 1) {
            return "Invalid version constraint: '{$version}'. Contains unsafe characters.";
        }

        return null;
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
                            'enum' => [
                                'show', 'add', 'remove', 'update', 'install', 'dump-autoload',
                                'validate', 'doctor', 'search', 'show-package', 'outdated', 'audit',
                            ],
                        ],
                        'package' => [
                            'type' => 'string',
                            'description' => 'Package name (vendor/package). Required for add, remove, show-package.',
                        ],
                        'version' => [
                            'type' => 'string',
                            'description' => 'Version constraint for add (e.g. "^2.0", "@dev").',
                        ],
                        'dev' => [
                            'type' => 'boolean',
                            'description' => 'Whether to use --dev flag. Default: false.',
                        ],
                        'repository_type' => [
                            'type' => 'string',
                            'description' => 'Repository type: "path" or "vcs". Not needed for Packagist packages.',
                        ],
                        'repository_url' => [
                            'type' => 'string',
                            'description' => 'Repository URL or absolute path.',
                        ],
                        'query' => [
                            'type' => 'string',
                            'description' => 'Search query for the search action.',
                        ],
                    ],
                    'required' => ['action'],
                ],
            ],
        ];
    }
}
