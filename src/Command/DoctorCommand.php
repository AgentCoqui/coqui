<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\Config\ToolkitVisibilityRegistry;
use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\CredentialResolver;
use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Config\WorkspaceComposerManager;
use CoquiBot\Coqui\Config\WorkspaceResolver;
use CoquiBot\Coqui\Command\WorkspaceOverrideResolver;
use CoquiBot\Coqui\Contract\CoquiDefaults;
use CoquiBot\Coqui\Storage\ArtifactStore;
use CoquiBot\Coqui\Storage\LoopStore;
use CoquiBot\Coqui\Storage\ProjectStore;
use CoquiBot\Coqui\Storage\ScheduleStore;
use CoquiBot\Coqui\Storage\SessionStorage;
use CoquiBot\Coqui\Storage\TodoStore;
use CoquiBot\Coqui\Storage\ToolUsageTracker;
use CoquiBot\Coqui\Storage\WebhookStore;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Diagnostic command that checks the health of the Coqui installation.
 *
 * Inspired by `composer diagnose` and `openclaw doctor`. Validates:
 * PHP environment, config, workspace, database integrity, credentials,
 * provider connectivity, toolkit discovery, and safety infrastructure.
 *
 * Use --repair to automatically fix detected issues.
 */
#[AsCommand(
    name: 'doctor',
    description: 'Health checks and diagnostics for Coqui',
)]
final class DoctorCommand extends Command
{
    private const REQUIRED_PHP_VERSION = '8.4.0';

    private const REQUIRED_EXTENSIONS = [
        'dom',
        'pdo_sqlite',
        'mbstring',
        'xml',
    ];

    private const RECOMMENDED_EXTENSIONS = [
        'curl' => 'recommended for best HTTP client performance and provider connectivity',
        'zip' => 'recommended for office document extraction',
        'gd' => 'recommended for bundled image previews and release packaging',
    ];

    private const UNIX_RECOMMENDED_EXTENSIONS = [
        'readline' => 'recommended for the interactive REPL',
        'pcntl' => 'recommended for background task cancellation',
        'posix' => 'recommended for background task process management',
    ];

    private int $okCount = 0;
    private int $warnCount = 0;
    private int $failCount = 0;

    protected function configure(): void
    {
        $this
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to openclaw.json')
            ->addOption('workdir', 'w', InputOption::VALUE_REQUIRED, 'Working directory (project root)', getcwd() ?: '.')
            ->addOption('workspace', null, InputOption::VALUE_REQUIRED, 'Workspace directory (overrides config and default)')
            ->addOption('repair', null, InputOption::VALUE_NONE, 'Automatically fix detected issues')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $workDir = is_string($input->getOption('workdir'))
            ? $input->getOption('workdir')
            : (getcwd() ?: '.');
        $repair = (bool) $input->getOption('repair');
        $jsonOutput = (bool) $input->getOption('json');

        $configOption = $input->getOption('config');
        $configPath = is_string($configOption) ? $configOption : null;

        if (!$jsonOutput) {
            $io->title('Coqui Doctor');
            if ($repair) {
                $io->warning('Repair mode enabled — issues will be fixed automatically.');
            }
        }

        $results = [];

        // 1. PHP Environment
        $results['php_environment'] = $this->checkPhpEnvironment($io, $jsonOutput);

        // 2. Config file
        $results['config'] = $this->checkConfig($io, $workDir, $configPath, $jsonOutput);

        // 3. Workspace
        $workspaceOverride = WorkspaceOverrideResolver::resolve($input);
        $workspacePath = $workspaceOverride ?? $this->resolveWorkspacePath($workDir, $configPath);
        $results['workspace'] = $this->checkWorkspace($io, $workspacePath, $repair, $jsonOutput);

        // 4. Database
        $results['database'] = $this->checkDatabase($io, $workspacePath, $repair, $jsonOutput);

        // 5. Credentials
        $results['credentials'] = $this->checkCredentials($io, $workspacePath, $repair, $jsonOutput);

        // 6. Provider connectivity
        $results['providers'] = $this->checkProviders($io, $workDir, $configPath, $jsonOutput);

        // 7. Toolkit discovery
        $results['toolkits'] = $this->checkToolkits($io, $workDir, $workspacePath, $jsonOutput);

        // 8. Skills
        $results['skills'] = $this->checkSkills($io, $workDir, $workspacePath, $jsonOutput);

        // 9. Launcher
        $results['launcher'] = $this->checkLauncher($io, $jsonOutput);

        // 10. Disk space
        $results['disk_space'] = $this->checkDiskSpace($io, $workspacePath, $jsonOutput);

        // 11. Performance (OPcache, JIT)
        $results['performance'] = $this->checkPerformance($io, $jsonOutput);

        if ($jsonOutput) {
            $output->writeln(json_encode([
                'ok' => $this->failCount === 0,
                'summary' => [
                    'ok' => $this->okCount,
                    'warnings' => $this->warnCount,
                    'errors' => $this->failCount,
                ],
                'checks' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return $this->failCount > 0 ? 2 : ($this->warnCount > 0 ? 1 : 0);
        }

        // Summary
        $io->newLine();
        $io->section('Summary');
        $io->text([
            "<fg=green>OK: {$this->okCount}</>",
            "<fg=yellow>Warnings: {$this->warnCount}</>",
            "<fg=red>Errors: {$this->failCount}</>",
        ]);

        if ($this->failCount > 0) {
            $io->error('Doctor found issues that need attention.');
            return 2;
        }

        if ($this->warnCount > 0) {
            $io->warning('Doctor found warnings. Coqui should work but review items above.');
            return 1;
        }

        $io->success('All checks passed. Coqui is healthy.');
        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPhpEnvironment(SymfonyStyle $io, bool $jsonOutput): array
    {
        $results = [];

        // PHP version
        $phpVersion = PHP_VERSION;
        if (version_compare($phpVersion, self::REQUIRED_PHP_VERSION, '>=')) {
            $this->ok($io, "PHP version: {$phpVersion}", $jsonOutput);
            $results['php_version'] = ['status' => 'ok', 'value' => $phpVersion];
        } else {
            $this->fail($io, "PHP version: {$phpVersion} (requires >= " . self::REQUIRED_PHP_VERSION . ')', $jsonOutput);
            $results['php_version'] = ['status' => 'fail', 'value' => $phpVersion, 'required' => self::REQUIRED_PHP_VERSION];
        }

        // Required extensions
        foreach (self::REQUIRED_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) {
                $this->ok($io, "Extension: {$ext}", $jsonOutput);
                $results["ext_{$ext}"] = ['status' => 'ok'];
            } else {
                $this->fail($io, "Extension: {$ext} (missing — required)", $jsonOutput);
                $results["ext_{$ext}"] = ['status' => 'fail', 'issue' => 'missing'];
            }
        }

        // Recommended extensions
        foreach ($this->recommendedExtensions() as $ext => $reason) {
            if (extension_loaded($ext)) {
                $this->ok($io, "Extension: {$ext}", $jsonOutput);
                $results["ext_{$ext}"] = ['status' => 'ok'];
            } else {
                $this->warn($io, "Extension: {$ext} (missing — {$reason})", $jsonOutput);
                $results["ext_{$ext}"] = ['status' => 'warn', 'issue' => 'missing', 'reason' => $reason];
            }
        }

        // Memory limit
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = $this->parseMemoryLimit($memoryLimit ?: '-1');
        if ($memoryBytes === -1 || $memoryBytes >= 128 * 1024 * 1024) {
            $this->ok($io, "Memory limit: {$memoryLimit}", $jsonOutput);
            $results['memory_limit'] = ['status' => 'ok', 'value' => $memoryLimit];
        } else {
            $this->warn($io, "Memory limit: {$memoryLimit} (recommended >= 128M)", $jsonOutput);
            $results['memory_limit'] = ['status' => 'warn', 'value' => $memoryLimit];
        }

        // putenv availability
        $disabledFunctions = ini_get('disable_functions') ?: '';
        if (str_contains($disabledFunctions, 'putenv')) {
            $this->fail($io, 'putenv() is disabled — credential hot-reload will not work', $jsonOutput);
            $results['putenv'] = ['status' => 'fail'];
        } else {
            $this->ok($io, 'putenv() available', $jsonOutput);
            $results['putenv'] = ['status' => 'ok'];
        }

        return $results;
    }

    /**
     * @return array<string, string>
     */
    private function recommendedExtensions(): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return self::RECOMMENDED_EXTENSIONS;
        }

        return self::RECOMMENDED_EXTENSIONS + self::UNIX_RECOMMENDED_EXTENSIONS;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkConfig(SymfonyStyle $io, string $workDir, ?string $configPath, bool $jsonOutput): array
    {
        $results = [];

        // Resolve config path
        $paths = array_filter([
            $configPath,
            $workDir . '/openclaw.json',
            dirname(__DIR__, 2) . '/openclaw.json',
        ]);

        $foundPath = null;
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $foundPath = $path;
                break;
            }
        }

        if ($foundPath === null) {
            $this->warn($io, 'Config: openclaw.json not found (will use defaults)', $jsonOutput);
            $results['exists'] = ['status' => 'warn', 'issue' => 'not found'];
            return $results;
        }

        $this->ok($io, "Config: found at {$foundPath}", $jsonOutput);
        $results['exists'] = ['status' => 'ok', 'path' => $foundPath];

        // Valid JSON
        $content = file_get_contents($foundPath);
        if ($content === false) {
            $this->fail($io, 'Config: unable to read file', $jsonOutput);
            $results['valid_json'] = ['status' => 'fail'];
            return $results;
        }

        try {
            $data = json_decode($content, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
            $this->ok($io, 'Config: valid JSON', $jsonOutput);
            $results['valid_json'] = ['status' => 'ok'];
        } catch (\JsonException $e) {
            $this->fail($io, "Config: invalid JSON — {$e->getMessage()}", $jsonOutput);
            $results['valid_json'] = ['status' => 'fail', 'error' => $e->getMessage()];
            return $results;
        }

        // Check for primary model
        $primary = $data['agents']['defaults']['model']['primary'] ?? null;
        if ($primary !== null && $primary !== '') {
            $this->ok($io, "Config: primary model = {$primary}", $jsonOutput);
            $results['primary_model'] = ['status' => 'ok', 'value' => $primary];
        } else {
            $this->warn($io, 'Config: no primary model set (will use default)', $jsonOutput);
            $results['primary_model'] = ['status' => 'warn'];
        }

        // Check for roles
        $roles = $data['agents']['defaults']['roles'] ?? [];
        if (!empty($roles)) {
            $roleCount = count($roles);
            $this->ok($io, "Config: {$roleCount} role(s) configured", $jsonOutput);
            $results['roles'] = ['status' => 'ok', 'count' => $roleCount];
        } else {
            $this->warn($io, 'Config: no roles configured', $jsonOutput);
            $results['roles'] = ['status' => 'warn'];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkWorkspace(SymfonyStyle $io, ?string $workspacePath, bool $repair, bool $jsonOutput): array
    {
        $results = [];

        if ($workspacePath === null) {
            $this->fail($io, 'Workspace: unable to resolve path', $jsonOutput);
            $results['path'] = ['status' => 'fail'];
            return $results;
        }

        // Directory exists
        if (is_dir($workspacePath)) {
            $this->ok($io, "Workspace: {$workspacePath}", $jsonOutput);
            $results['exists'] = ['status' => 'ok', 'path' => $workspacePath];
        } elseif ($repair) {
            mkdir($workspacePath, CoquiDefaults::DIRECTORY_MODE, true);
            $this->ok($io, "Workspace: created {$workspacePath} (repaired)", $jsonOutput);
            $results['exists'] = ['status' => 'ok', 'repaired' => true];
        } else {
            $this->fail($io, "Workspace: directory missing — {$workspacePath}", $jsonOutput);
            $results['exists'] = ['status' => 'fail', 'path' => $workspacePath];
            return $results;
        }

        // Writable
        if (is_writable($workspacePath)) {
            $this->ok($io, 'Workspace: writable', $jsonOutput);
            $results['writable'] = ['status' => 'ok'];
        } else {
            $this->fail($io, 'Workspace: not writable', $jsonOutput);
            $results['writable'] = ['status' => 'fail'];
        }

        // composer.json
        $composerJson = $workspacePath . '/composer.json';
        if (file_exists($composerJson)) {
            $content = file_get_contents($composerJson);
            if ($content !== false) {
                try {
                    json_decode($content, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
                    $this->ok($io, 'Workspace: composer.json valid', $jsonOutput);
                    $results['composer_json'] = ['status' => 'ok'];
                } catch (\JsonException $e) {
                    $this->fail($io, "Workspace: composer.json invalid — {$e->getMessage()}", $jsonOutput);
                    $results['composer_json'] = ['status' => 'fail', 'error' => $e->getMessage()];
                }
            }
        } elseif ($repair) {
            $manager = new WorkspaceComposerManager($workspacePath);
            $manager->initialize();
            $this->ok($io, 'Workspace: composer.json created (repaired)', $jsonOutput);
            $results['composer_json'] = ['status' => 'ok', 'repaired' => true];
        } else {
            $this->warn($io, 'Workspace: composer.json missing (run --repair to create)', $jsonOutput);
            $results['composer_json'] = ['status' => 'warn'];
        }

        // autoloader
        $autoloader = $workspacePath . '/vendor/autoload.php';
        if (file_exists($autoloader)) {
            $this->ok($io, 'Workspace: vendor/autoload.php exists', $jsonOutput);
            $results['autoloader'] = ['status' => 'ok'];
        } else {
            $this->warn($io, 'Workspace: vendor/autoload.php missing (run composer install in workspace)', $jsonOutput);
            $results['autoloader'] = ['status' => 'warn'];
        }

        // Subdirectories
        foreach (['data', 'src', 'skills', 'roles', 'loops', 'schedules'] as $subdir) {
            $path = $workspacePath . '/' . $subdir;
            if (is_dir($path)) {
                $results["dir_{$subdir}"] = ['status' => 'ok'];
            } elseif ($repair) {
                mkdir($path, CoquiDefaults::DIRECTORY_MODE, true);
                $results["dir_{$subdir}"] = ['status' => 'ok', 'repaired' => true];
            } else {
                $this->warn($io, "Workspace: {$subdir}/ directory missing", $jsonOutput);
                $results["dir_{$subdir}"] = ['status' => 'warn'];
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDatabase(SymfonyStyle $io, string $workspacePath, bool $repair, bool $jsonOutput): array
    {
        $results = [];
        $dbPath = $workspacePath . '/data/coqui.db';

        // Database file
        if (!file_exists($dbPath)) {
            $this->warn($io, 'Database: coqui.db not found (will be created on first run)', $jsonOutput);
            $results['exists'] = ['status' => 'warn'];
            return $results;
        }

        $this->ok($io, 'Database: coqui.db exists', $jsonOutput);
        $results['exists'] = ['status' => 'ok'];

        // Writable
        if (is_writable($dbPath)) {
            $this->ok($io, 'Database: writable', $jsonOutput);
            $results['writable'] = ['status' => 'ok'];
        } else {
            $this->fail($io, 'Database: not writable', $jsonOutput);
            $results['writable'] = ['status' => 'fail'];
        }

        // Connection
        try {
            $storage = new SessionStorage($dbPath);
        } catch (\Throwable $e) {
            $this->fail($io, "Database: connection failed — {$e->getMessage()}", $jsonOutput);
            $results['connection'] = ['status' => 'fail', 'error' => $e->getMessage()];
            return $results;
        }

        $this->ok($io, 'Database: connection OK', $jsonOutput);
        $results['connection'] = ['status' => 'ok'];

        // Tables
        $tableCheck = $storage->checkTablesExist();
        if ($tableCheck['ok']) {
            $this->ok($io, 'Database: all tables present', $jsonOutput);
            $results['tables'] = ['status' => 'ok'];
        } else {
            $this->fail($io, 'Database: missing tables — ' . implode(', ', $tableCheck['missing']), $jsonOutput);
            $results['tables'] = ['status' => 'fail', 'missing' => $tableCheck['missing']];
        }

        $pdo = $storage->getPdo();

        $storeChecks = [
            'artifact_store' => static fn(PDO $db): mixed => new ArtifactStore($db),
            'todo_store' => static fn(PDO $db): mixed => new TodoStore($db),
            'project_store' => static fn(PDO $db): mixed => new ProjectStore($db),
            'loop_store' => static fn(PDO $db): mixed => new LoopStore($db),
            'schedule_store' => static fn(PDO $db): mixed => new ScheduleStore($db),
            'webhook_store' => static fn(PDO $db): mixed => new WebhookStore($db),
            'tool_usage_tracker' => static fn(PDO $db): mixed => (new ToolUsageTracker($db))->getTopTools(1),
        ];

        foreach ($storeChecks as $name => $factory) {
            try {
                $factory($pdo);
                $this->ok($io, 'Database: ' . str_replace('_', ' ', $name) . ' initialized', $jsonOutput);
                $results[$name] = ['status' => 'ok'];
            } catch (\Throwable $e) {
                $this->fail($io, 'Database: ' . str_replace('_', ' ', $name) . ' failed — ' . $e->getMessage(), $jsonOutput);
                $results[$name] = ['status' => 'fail', 'error' => $e->getMessage()];
            }
        }

        // Stats
        $stats = $storage->getDatabaseStats();
        $sizeKb = round($stats['db_size_bytes'] / 1024);
        $this->ok($io, "Database: {$stats['sessions']} sessions, {$stats['messages']} messages, {$stats['turns']} turns ({$sizeKb} KB)", $jsonOutput);
        $results['stats'] = ['status' => 'ok', 'data' => $stats];

        $extendedStats = [
            'artifacts' => $this->queryTableCount($pdo, 'artifacts'),
            'todos' => $this->queryTableCount($pdo, 'todos'),
            'projects' => $this->queryTableCount($pdo, 'projects'),
            'sprints' => $this->queryTableCount($pdo, 'sprints'),
            'loops' => $this->queryTableCount($pdo, 'loops'),
            'scheduled_tasks' => $this->queryTableCount($pdo, 'scheduled_tasks'),
            'webhook_subscriptions' => $this->queryTableCount($pdo, 'webhook_subscriptions'),
        ];
        $this->ok(
            $io,
            sprintf(
                'Database: %d artifacts, %d todos, %d projects, %d loops, %d schedules, %d webhooks',
                $extendedStats['artifacts'],
                $extendedStats['todos'],
                $extendedStats['projects'],
                $extendedStats['loops'],
                $extendedStats['scheduled_tasks'],
                $extendedStats['webhook_subscriptions'],
            ),
            $jsonOutput,
        );
        $results['extended_stats'] = ['status' => 'ok', 'data' => $extendedStats];

        // Message integrity check for current session
        $sessionFile = $workspacePath . '/.coqui-session';
        $currentSessionId = null;
        if (file_exists($sessionFile)) {
            $sessionId = trim(file_get_contents($sessionFile) ?: '');
            if ($sessionId !== '' && $storage->getSession($sessionId) !== null) {
                $currentSessionId = $sessionId;
                $integrity = $storage->checkMessageIntegrity(
                    $sessionId,
                    max(200, $this->countSessionMessages($pdo, $sessionId)),
                );

                if ($integrity['ok']) {
                    $this->ok($io, "Database: current session messages OK ({$sessionId})", $jsonOutput);
                    $results['message_integrity'] = ['status' => 'ok', 'session_id' => $sessionId];
                } else {
                    $issueCount = count($integrity['issues']);
                    $this->fail(
                        $io,
                        "Database: {$issueCount} corrupted message(s) in current session",
                        $jsonOutput,
                    );

                    if (!$jsonOutput) {
                        foreach ($integrity['issues'] as $issue) {
                            $io->text("  <fg=red>•</> [{$issue['role']}] {$issue['id']}: {$issue['issue']}");
                        }
                    }

                    $results['message_integrity'] = [
                        'status' => 'fail',
                        'session_id' => $sessionId,
                        'issues' => $integrity['issues'],
                    ];

                    // Repair: fix UTF-8 issues in-place
                    if ($repair) {
                        $repaired = $storage->repairUtf8Content($sessionId);
                        if ($repaired > 0) {
                            $this->ok($io, "Database: repaired {$repaired} message(s) with malformed UTF-8", $jsonOutput);
                            $results['repair_utf8'] = ['status' => 'ok', 'repaired' => $repaired];
                        }

                        // Re-check after UTF-8 repair — delete remaining unfixable rows
                        $recheck = $storage->checkMessageIntegrity($sessionId);
                        if (!$recheck['ok']) {
                            $badIds = array_map(fn(array $i): string => $i['id'], $recheck['issues']);
                            $deleted = $storage->deleteMessages($badIds);
                            $this->ok($io, "Database: deleted {$deleted} unfixable message(s)", $jsonOutput);
                            $results['repair_delete'] = ['status' => 'ok', 'deleted' => $deleted];
                        }
                    } else {
                        $io->text('  <fg=yellow>Run with --repair to fix corrupted messages</>');
                    }
                }
            }
        }

        // Check all non-task sessions for integrity issues
        $sessions = $this->listUserSessionIds($pdo, $currentSessionId);
        $badSessions = [];
        foreach ($sessions as $sessionId) {
            $check = $storage->checkMessageIntegrity(
                $sessionId,
                max(100, $this->countSessionMessages($pdo, $sessionId)),
            );
            if (!$check['ok']) {
                $badSessions[$sessionId] = count($check['issues']);
            }
        }

        if (!empty($badSessions)) {
            $totalBad = array_sum($badSessions);
            $sessionCount = count($badSessions);
            $this->warn(
                $io,
                "Database: {$totalBad} issue(s) across {$sessionCount} other session(s)",
                $jsonOutput,
            );
            $results['other_sessions_integrity'] = [
                'status' => 'warn',
                'affected_sessions' => $sessionCount,
                'total_issues' => $totalBad,
            ];

            if ($repair) {
                $totalRepaired = 0;
                $totalDeleted = 0;
                foreach (array_keys($badSessions) as $sid) {
                    $totalRepaired += $storage->repairUtf8Content($sid);
                        $recheck = $storage->checkMessageIntegrity(
                            $sid,
                            max(100, $this->countSessionMessages($pdo, $sid)),
                        );
                    if (!$recheck['ok']) {
                        $badIds = array_map(fn(array $i): string => $i['id'], $recheck['issues']);
                        $totalDeleted += $storage->deleteMessages($badIds);
                    }
                }
                if ($totalRepaired > 0 || $totalDeleted > 0) {
                    $this->ok($io, "Database: repaired {$totalRepaired}, deleted {$totalDeleted} across other sessions", $jsonOutput);
                }
            }
        } else {
            $checkedCount = count($sessions);
            $this->ok($io, "Database: all {$checkedCount} session(s) pass integrity check", $jsonOutput);
            $results['other_sessions_integrity'] = ['status' => 'ok', 'checked_sessions' => $checkedCount];
        }

        $results['session_integrity_scope'] = [
            'status' => 'ok',
            'mode' => 'all_sessions',
            'excluded_current_session' => $currentSessionId !== null,
        ];

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkCredentials(SymfonyStyle $io, string $workspacePath, bool $repair, bool $jsonOutput): array
    {
        $results = [];
        $envFile = $workspacePath . '/.env';

        if (!file_exists($envFile)) {
            $this->warn($io, 'Credentials: .env file not found (optional)', $jsonOutput);
            $results['env_file'] = ['status' => 'warn'];
            return $results;
        }

        $this->ok($io, 'Credentials: .env file exists', $jsonOutput);
        $results['env_file'] = ['status' => 'ok'];

        // Permissions
        $perms = fileperms($envFile) & 0777;
        if ($perms <= 0600) {
            $this->ok($io, 'Credentials: .env permissions OK (' . decoct($perms) . ')', $jsonOutput);
            $results['env_perms'] = ['status' => 'ok', 'mode' => decoct($perms)];
        } elseif ($repair) {
            chmod($envFile, 0600);
            $this->ok($io, 'Credentials: .env permissions fixed to 0600 (repaired)', $jsonOutput);
            $results['env_perms'] = ['status' => 'ok', 'repaired' => true];
        } else {
            $this->warn(
                $io,
                'Credentials: .env permissions too open (' . decoct($perms) . ') — should be 0600',
                $jsonOutput,
            );
            $results['env_perms'] = ['status' => 'warn', 'mode' => decoct($perms)];
        }

        // Check key count
        try {
            $resolver = new CredentialResolver(workspacePath: $workspacePath);
            $keys = $resolver->keys();
            $keyCount = count($keys);
            $this->ok($io, "Credentials: {$keyCount} key(s) stored", $jsonOutput);
            $results['key_count'] = ['status' => 'ok', 'count' => $keyCount];

            if (!$jsonOutput && $keyCount > 0) {
                foreach ($keys as $key) {
                    $io->text("  <fg=gray>•</> {$key}");
                }
            }
        } catch (\Throwable $e) {
            $this->fail($io, "Credentials: .env parse error — {$e->getMessage()}", $jsonOutput);
            $results['parse'] = ['status' => 'fail', 'error' => $e->getMessage()];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkProviders(SymfonyStyle $io, string $workDir, ?string $configPath, bool $jsonOutput): array
    {
        $results = [];

        // Try to resolve config to find provider URLs
        $configPath ??= $workDir . '/openclaw.json';
        if (!file_exists($configPath)) {
            $configPath = dirname(__DIR__, 2) . '/openclaw.json';
        }

        if (!file_exists($configPath)) {
            $this->warn($io, 'Providers: no config — skipping connectivity check', $jsonOutput);
            $results['config'] = ['status' => 'warn'];
            return $results;
        }

        $content = file_get_contents($configPath);
        if ($content === false) {
            return $results;
        }

        try {
            $data = json_decode($content, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $results;
        }

        $providers = $data['providers'] ?? [];

        // Check Ollama connectivity
        $ollamaUrl = $providers['ollama']['baseUrl']
            ?? $providers['ollama']['base_url']
            ?? 'http://localhost:11434/v1';

        // Strip /v1 suffix for health check
        $ollamaBase = PathHelper::trimTrailingSlash(preg_replace('#/v1/?$#', '', $ollamaUrl));
        $this->checkOllamaConnectivity($io, $ollamaBase, $results, $jsonOutput);

        // Check cloud provider API keys
        foreach (['openai', 'anthropic'] as $provider) {
            if (!isset($providers[$provider])) {
                continue;
            }

            $envKey = strtoupper($provider) . '_API_KEY';
            $hasKey = getenv($envKey) !== false && getenv($envKey) !== '';

            if ($hasKey) {
                $this->ok($io, "Provider: {$provider} — {$envKey} set", $jsonOutput);
                $results[$provider] = ['status' => 'ok'];
            } else {
                $configKey = $providers[$provider]['apiKey'] ?? $providers[$provider]['api_key'] ?? null;
                if ($configKey !== null && $configKey !== '') {
                    $this->ok($io, "Provider: {$provider} — API key in config", $jsonOutput);
                    $results[$provider] = ['status' => 'ok'];
                } else {
                    $this->warn($io, "Provider: {$provider} — {$envKey} not set", $jsonOutput);
                    $results[$provider] = ['status' => 'warn', 'issue' => "{$envKey} missing"];
                }
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $results
     */
    private function checkOllamaConnectivity(SymfonyStyle $io, string $baseUrl, array &$results, bool $jsonOutput): void
    {
        $ch = curl_init($baseUrl);
        if ($ch === false) {
            $this->warn($io, 'Provider: Ollama — curl_init failed', $jsonOutput);
            $results['ollama'] = ['status' => 'warn'];
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_NOBODY => false,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response !== false && $httpCode === 200) {
            $this->ok($io, "Provider: Ollama reachable at {$baseUrl}", $jsonOutput);
            $results['ollama'] = ['status' => 'ok', 'url' => $baseUrl];

            // Try to list models
            $this->checkOllamaModels($io, $baseUrl, $results, $jsonOutput);
        } else {
            $detail = $error ?: "HTTP {$httpCode}";
            $this->warn($io, "Provider: Ollama not reachable at {$baseUrl} ({$detail})", $jsonOutput);
            $results['ollama'] = ['status' => 'warn', 'url' => $baseUrl, 'error' => $detail];
        }
    }

    /**
     * @param array<string, mixed> $results
     */
    private function checkOllamaModels(SymfonyStyle $io, string $baseUrl, array &$results, bool $jsonOutput): void
    {
        $ch = curl_init("{$baseUrl}/api/tags");
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false || !is_string($response)) {
            return;
        }

        try {
            $data = json_decode($response, true, CoquiDefaults::JSON_DECODE_DEPTH, JSON_THROW_ON_ERROR);
            $models = $data['models'] ?? [];
            $modelNames = array_map(fn(array $m): string => $m['name'] ?? 'unknown', $models);
            $count = count($modelNames);
            $this->ok($io, "Provider: Ollama has {$count} model(s) available", $jsonOutput);
            $results['ollama_models'] = ['status' => 'ok', 'count' => $count, 'models' => $modelNames];

            if (!$jsonOutput && $count > 0) {
                foreach (array_slice($modelNames, 0, 10) as $name) {
                    $io->text("  <fg=gray>•</> {$name}");
                }
                if ($count > 10) {
                    $io->text("  <fg=gray>... and " . ($count - 10) . ' more</>');
                }
            }
        } catch (\JsonException) {
            // Not critical — just skip model listing
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function checkToolkits(SymfonyStyle $io, string $workDir, string $workspacePath, bool $jsonOutput): array
    {
        $results = [];

        try {
            $discovery = new ToolkitDiscovery(
                projectRoot: $workDir,
                workspacePath: $workspacePath,
                visibilityRegistry: new ToolkitVisibilityRegistry($workspacePath),
            );
            $registry = $discovery->loadRegistry();
        } catch (\Throwable $e) {
            $this->fail($io, 'Toolkits: discovery unavailable — ' . $e->getMessage(), $jsonOutput);
            $results['registry'] = ['status' => 'fail', 'error' => $e->getMessage()];
            return $results;
        }

        if ($registry === []) {
            $this->ok($io, 'Toolkits: no registered external toolkits', $jsonOutput);
            $results['registry'] = ['status' => 'ok', 'packages' => 0, 'toolkits' => 0];
            return $results;
        }

        $packageCount = count($registry);
        $toolkitCount = array_sum(array_map('count', $registry));
        $this->ok($io, "Toolkits: {$toolkitCount} toolkit(s) from {$packageCount} package(s)", $jsonOutput);
        $results['registry'] = ['status' => 'ok', 'packages' => $packageCount, 'toolkits' => $toolkitCount];

        $instantiated = [];
        try {
            foreach ($discovery->instantiateRegisteredGrouped() as $entry) {
                $instantiated[] = $entry['toolkit']::class;
            }
        } catch (\Throwable $e) {
            $this->fail($io, 'Toolkits: instantiation failed — ' . $e->getMessage(), $jsonOutput);
            $results['instantiation'] = ['status' => 'fail', 'error' => $e->getMessage()];
            return $results;
        }

        $registeredClasses = [];
        foreach ($registry as $classes) {
            foreach ($classes as $class) {
                $registeredClasses[] = $class;
            }
        }

        $missing = array_values(array_diff($registeredClasses, $instantiated));
        if ($missing === []) {
            $this->ok($io, 'Toolkits: all registered toolkits are instantiable', $jsonOutput);
            $results['instantiation'] = ['status' => 'ok', 'count' => count($instantiated)];
        } else {
            $this->warn($io, 'Toolkits: ' . count($missing) . ' registered toolkit(s) could not be instantiated', $jsonOutput);
            $results['instantiation'] = [
                'status' => 'warn',
                'count' => count($instantiated),
                'missing' => $missing,
            ];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSkills(SymfonyStyle $io, string $workDir, string $workspacePath, bool $jsonOutput): array
    {
        $results = [];

        try {
            $toolkitDiscovery = new ToolkitDiscovery(projectRoot: $workDir, workspacePath: $workspacePath);
            $skillDiscovery = new SkillDiscovery($workspacePath, $toolkitDiscovery->discoverPackageSkillPaths());
            $skills = $skillDiscovery->discoverAll();
        } catch (\Throwable $e) {
            $this->fail($io, 'Skills: discovery failed — ' . $e->getMessage(), $jsonOutput);
            $results['count'] = ['status' => 'fail', 'error' => $e->getMessage()];
            return $results;
        }

        $workspaceSkills = array_values(array_map(
            static fn($skill): string => $skill->name,
            array_filter($skills, static fn($skill): bool => !$skill->isPackageBundled),
        ));
        $packageSkills = array_values(array_map(
            static fn($skill): string => $skill->name,
            array_filter($skills, static fn($skill): bool => $skill->isPackageBundled),
        ));

        $skillCount = count($skills);
        if ($skillCount > 0) {
            $this->ok(
                $io,
                sprintf(
                    'Skills: %d skill(s) found (%d workspace, %d package)',
                    $skillCount,
                    count($workspaceSkills),
                    count($packageSkills),
                ),
                $jsonOutput,
            );
            $results['count'] = [
                'status' => 'ok',
                'total' => $skillCount,
                'workspace' => $workspaceSkills,
                'package' => $packageSkills,
            ];

            if (!$jsonOutput) {
                foreach ($skills as $skill) {
                    $source = $skill->isPackageBundled ? 'package' : 'workspace';
                    $io->text("  <fg=gray>•</> {$skill->name} <fg=gray>({$source})</>");
                }
            }
        } else {
            $this->ok($io, 'Skills: no skills installed (optional)', $jsonOutput);
            $results['count'] = ['status' => 'ok', 'total' => 0, 'workspace' => [], 'package' => []];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkLauncher(SymfonyStyle $io, bool $jsonOutput): array
    {
        $results = [];
        $launcherPath = dirname(__DIR__, 2) . '/bin/coqui';

        if (file_exists($launcherPath)) {
            if (is_executable($launcherPath)) {
                $this->ok($io, 'Launcher: bin/coqui exists and is executable', $jsonOutput);
                $results['launcher'] = ['status' => 'ok'];
            } else {
                $this->warn($io, 'Launcher: bin/coqui exists but is not executable', $jsonOutput);
                $results['launcher'] = ['status' => 'warn'];
            }
        } else {
            $this->warn($io, 'Launcher: bin/coqui not found', $jsonOutput);
            $results['launcher'] = ['status' => 'warn'];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkDiskSpace(SymfonyStyle $io, string $workspacePath, bool $jsonOutput): array
    {
        $results = [];
        $freeBytes = disk_free_space($workspacePath);

        if ($freeBytes === false) {
            $this->warn($io, 'Disk space: unable to determine', $jsonOutput);
            $results['free'] = ['status' => 'warn'];
            return $results;
        }

        $freeMb = round($freeBytes / 1024 / 1024);
        $freeGb = round($freeBytes / 1024 / 1024 / 1024, 1);

        if ($freeBytes >= 100 * 1024 * 1024) {
            $this->ok($io, "Disk space: {$freeGb} GB free", $jsonOutput);
            $results['free'] = ['status' => 'ok', 'free_mb' => $freeMb];
        } else {
            $this->warn($io, "Disk space: only {$freeMb} MB free", $jsonOutput);
            $results['free'] = ['status' => 'warn', 'free_mb' => $freeMb];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkPerformance(SymfonyStyle $io, bool $jsonOutput): array
    {
        $results = [];

        // OPcache
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
            if (is_array($status) && ($status['opcache_enabled'] ?? false)) {
                $this->ok($io, 'OPcache: enabled', $jsonOutput);
                $results['opcache'] = ['status' => 'ok', 'enabled' => true];
            } else {
                $this->warn($io, 'OPcache: disabled — enable for faster boot and lower latency', $jsonOutput);
                $results['opcache'] = ['status' => 'warn', 'enabled' => false];
            }
        } else {
            $this->warn($io, 'OPcache: extension not loaded — install php-opcache', $jsonOutput);
            $results['opcache'] = ['status' => 'warn', 'loaded' => false];
        }

        // JIT
        if (function_exists('opcache_get_status')) {
            $status = opcache_get_status(false);
            $jitEnabled = is_array($status)
                && isset($status['jit']['enabled'])
                && $status['jit']['enabled'];
            $jitBlockingExtensions = $this->jitBlockingExtensions();

            if ($jitEnabled) {
                $jitBuffer = $status['jit']['buffer_size'] ?? 0;
                $bufferMb = round($jitBuffer / 1024 / 1024);
                $this->ok($io, "JIT: enabled ({$bufferMb} MB buffer)", $jsonOutput);
                $results['jit'] = ['status' => 'ok', 'enabled' => true, 'buffer_mb' => $bufferMb];
            } elseif ($jitBlockingExtensions !== []) {
                $extensions = implode(', ', $jitBlockingExtensions);
                $this->ok($io, "JIT: disabled with {$extensions} loaded — expected for debugging/coverage CLI setups", $jsonOutput);
                $results['jit'] = [
                    'status' => 'ok',
                    'enabled' => false,
                    'blocked_by' => $jitBlockingExtensions,
                    'reason' => 'incompatible_extensions',
                ];
            } else {
                $this->warn($io, 'JIT: disabled — enable for improved loop performance (opcache.jit=1255)', $jsonOutput);
                $results['jit'] = ['status' => 'warn', 'enabled' => false];
            }
        } else {
            $results['jit'] = ['status' => 'warn', 'loaded' => false];
        }

        // Realpath cache
        $realpathCacheSize = ini_get('realpath_cache_size') ?: '4096k';
        $results['realpath_cache_size'] = ['status' => 'ok', 'value' => $realpathCacheSize];

        return $results;
    }

    /**
     * @return list<string>
     */
    private function jitBlockingExtensions(): array
    {
        $extensions = [];

        foreach (['xdebug', 'pcov', 'blackfire'] as $extension) {
            if (extension_loaded($extension)) {
                $extensions[] = $extension;
            }
        }

        return $extensions;
    }

    /**
     * @return list<string>
     */
    private function listUserSessionIds(PDO $pdo, ?string $excludeSessionId = null): array
    {
        $stmt = $pdo->prepare(<<<'SQL'
            SELECT s.id
            FROM sessions s
            LEFT JOIN background_tasks bt ON bt.session_id = s.id
            WHERE bt.id IS NULL
              AND (:exclude_session_id IS NULL OR s.id <> :exclude_session_id)
            ORDER BY s.updated_at DESC
        SQL);
        $stmt->bindValue('exclude_session_id', $excludeSessionId, $excludeSessionId === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        /** @var list<string> $ids */
        $ids = array_map(
            static fn(array $row): string => (string) $row['id'],
            $stmt->fetchAll(PDO::FETCH_ASSOC),
        );

        return $ids;
    }

    private function countSessionMessages(PDO $pdo, string $sessionId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE session_id = :session_id');
        $stmt->execute(['session_id' => $sessionId]);

        return (int) $stmt->fetchColumn();
    }

    private function queryTableCount(PDO $pdo, string $table): int
    {
        $stmt = $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $table));

        if ($stmt === false) {
            return 0;
        }

        return (int) $stmt->fetchColumn();
    }

    private function resolveWorkspacePath(string $workDir, ?string $configPath): string
    {
        try {
            $configPath ??= $workDir . '/openclaw.json';
            if (!file_exists($configPath)) {
                $configPath = dirname(__DIR__, 2) . '/openclaw.json';
            }

            if (file_exists($configPath)) {
                $config = \CoquiBot\Coqui\Config\OpenClawConfig::fromFile($configPath);
            } else {
                $config = \CoquiBot\Coqui\Config\OpenClawConfig::fromArray([]);
            }

            $resolver = new WorkspaceResolver($config, $workDir);
            return $resolver->resolve();
        } catch (\Throwable) {
            // Fallback to common default
            $home = \CoquiBot\Coqui\Config\HomeDirectory::resolve();
            if ($home !== sys_get_temp_dir()) {
                return $home . '/.coqui/.workspace';
            }

            return $workDir . '/workspace';
        }
    }

    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function ok(SymfonyStyle $io, string $message, bool $jsonOutput): void
    {
        $this->okCount++;
        if (!$jsonOutput) {
            $io->text("  <fg=green>✓</> {$message}");
        }
    }

    private function warn(SymfonyStyle $io, string $message, bool $jsonOutput): void
    {
        $this->warnCount++;
        if (!$jsonOutput) {
            $io->text("  <fg=yellow>!</> {$message}");
        }
    }

    private function fail(SymfonyStyle $io, string $message, bool $jsonOutput): void
    {
        $this->failCount++;
        if (!$jsonOutput) {
            $io->text("  <fg=red>✗</> {$message}");
        }
    }

}
