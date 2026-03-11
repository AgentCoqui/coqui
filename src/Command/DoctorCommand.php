<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Command;

use CoquiBot\Coqui\Config\BootManager;
use CoquiBot\Coqui\Config\CredentialResolver;
use CoquiBot\Coqui\Config\WorkspaceComposerManager;
use CoquiBot\Coqui\Config\WorkspaceResolver;
use CoquiBot\Coqui\Storage\SessionStorage;
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
        'pdo_sqlite',
        'json',
        'mbstring',
        'curl',
    ];

    private const RECOMMENDED_EXTENSIONS = [
        'readline',
        'pcntl',
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
        $workspaceOverride = $this->resolveWorkspaceOverride($input);
        $workspacePath = $workspaceOverride ?? $this->resolveWorkspacePath($workDir, $configPath);
        $results['workspace'] = $this->checkWorkspace($io, $workspacePath, $repair, $jsonOutput);

        // 4. Database
        $results['database'] = $this->checkDatabase($io, $workspacePath, $repair, $jsonOutput);

        // 5. Credentials
        $results['credentials'] = $this->checkCredentials($io, $workspacePath, $repair, $jsonOutput);

        // 6. Provider connectivity
        $results['providers'] = $this->checkProviders($io, $workDir, $configPath, $jsonOutput);

        // 7. Toolkit discovery
        $results['toolkits'] = $this->checkToolkits($io, $workspacePath, $jsonOutput);

        // 8. Skills
        $results['skills'] = $this->checkSkills($io, $workspacePath, $jsonOutput);

        // 9. Launcher
        $results['launcher'] = $this->checkLauncher($io, $jsonOutput);

        // 10. Disk space
        $results['disk_space'] = $this->checkDiskSpace($io, $workspacePath, $jsonOutput);

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
        foreach (self::RECOMMENDED_EXTENSIONS as $ext) {
            if (extension_loaded($ext)) {
                $this->ok($io, "Extension: {$ext}", $jsonOutput);
                $results["ext_{$ext}"] = ['status' => 'ok'];
            } else {
                $this->warn($io, "Extension: {$ext} (missing — recommended for REPL)", $jsonOutput);
                $results["ext_{$ext}"] = ['status' => 'warn', 'issue' => 'missing'];
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
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
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
            mkdir($workspacePath, 0755, true);
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
                    json_decode($content, true, 512, JSON_THROW_ON_ERROR);
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
        foreach (['data', 'src', 'skills'] as $subdir) {
            $path = $workspacePath . '/' . $subdir;
            if (is_dir($path)) {
                $results["dir_{$subdir}"] = ['status' => 'ok'];
            } elseif ($repair) {
                mkdir($path, 0755, true);
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

        // Stats
        $stats = $storage->getDatabaseStats();
        $sizeKb = round($stats['db_size_bytes'] / 1024);
        $this->ok($io, "Database: {$stats['sessions']} sessions, {$stats['messages']} messages, {$stats['turns']} turns ({$sizeKb} KB)", $jsonOutput);
        $results['stats'] = ['status' => 'ok', 'data' => $stats];

        // Message integrity check for current session
        $sessionFile = $workspacePath . '/.coqui-session';
        if (file_exists($sessionFile)) {
            $sessionId = trim(file_get_contents($sessionFile) ?: '');
            if ($sessionId !== '' && $storage->getSession($sessionId) !== null) {
                $integrity = $storage->checkMessageIntegrity($sessionId);

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

        // Check ALL sessions for integrity issues
        $sessions = $storage->listSessions(50);
        $badSessions = [];
        foreach ($sessions as $session) {
            $check = $storage->checkMessageIntegrity($session['id'], 100);
            if (!$check['ok']) {
                $badSessions[$session['id']] = count($check['issues']);
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
                    $recheck = $storage->checkMessageIntegrity($sid, 100);
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
            $results['other_sessions_integrity'] = ['status' => 'ok'];
        }

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
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $results;
        }

        $providers = $data['providers'] ?? [];

        // Check Ollama connectivity
        $ollamaUrl = $providers['ollama']['baseUrl']
            ?? $providers['ollama']['base_url']
            ?? 'http://localhost:11434/v1';

        // Strip /v1 suffix for health check
        $ollamaBase = rtrim(preg_replace('#/v1/?$#', '', $ollamaUrl), '/');
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
            $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
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
    private function checkToolkits(SymfonyStyle $io, string $workspacePath, bool $jsonOutput): array
    {
        $results = [];
        $registryPath = $workspacePath . '/toolkits.json';

        if (!file_exists($registryPath)) {
            $this->warn($io, 'Toolkits: toolkits.json not found', $jsonOutput);
            $results['registry'] = ['status' => 'warn'];
            return $results;
        }

        $content = file_get_contents($registryPath);
        if ($content === false) {
            $this->fail($io, 'Toolkits: unable to read toolkits.json', $jsonOutput);
            $results['registry'] = ['status' => 'fail'];
            return $results;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            $packageCount = count($data);
            $toolkitCount = array_sum(array_map('count', $data));
            $this->ok($io, "Toolkits: {$toolkitCount} toolkit(s) from {$packageCount} package(s)", $jsonOutput);
            $results['registry'] = ['status' => 'ok', 'packages' => $packageCount, 'toolkits' => $toolkitCount];

            if (!$jsonOutput) {
                foreach ($data as $package => $classes) {
                    foreach ($classes as $class) {
                        $loadable = class_exists($class, true);
                        $status = $loadable ? '<fg=green>✓</>' : '<fg=red>✗</>';
                        $io->text("  {$status} {$class} <fg=gray>({$package})</>");

                        if (!$loadable) {
                            $this->warn($io, "Toolkit class not autoloadable: {$class}", $jsonOutput);
                        }
                    }
                }
            }
        } catch (\JsonException $e) {
            $this->fail($io, "Toolkits: invalid JSON — {$e->getMessage()}", $jsonOutput);
            $results['registry'] = ['status' => 'fail', 'error' => $e->getMessage()];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkSkills(SymfonyStyle $io, string $workspacePath, bool $jsonOutput): array
    {
        $results = [];
        $skillsDir = $workspacePath . '/skills';

        if (!is_dir($skillsDir)) {
            $this->warn($io, 'Skills: skills/ directory not found', $jsonOutput);
            $results['dir'] = ['status' => 'warn'];
            return $results;
        }

        $entries = scandir($skillsDir);
        if ($entries === false) {
            $this->fail($io, 'Skills: unable to read skills/ directory', $jsonOutput);
            $results['dir'] = ['status' => 'fail'];
            return $results;
        }

        $skills = [];
        $issues = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.tmp') {
                continue;
            }

            $skillDir = $skillsDir . '/' . $entry;
            if (!is_dir($skillDir)) {
                continue;
            }

            $skillMd = $skillDir . '/SKILL.md';
            if (file_exists($skillMd)) {
                $skills[] = $entry;
            } else {
                $issues[] = $entry;
            }
        }

        $skillCount = count($skills);
        if ($skillCount > 0) {
            $this->ok($io, "Skills: {$skillCount} skill(s) found", $jsonOutput);
            $results['count'] = ['status' => 'ok', 'skills' => $skills];

            if (!$jsonOutput) {
                foreach ($skills as $name) {
                    $io->text("  <fg=gray>•</> {$name}");
                }
            }
        } else {
            $this->ok($io, 'Skills: no skills installed (optional)', $jsonOutput);
            $results['count'] = ['status' => 'ok', 'skills' => []];
        }

        if (!empty($issues)) {
            $this->warn($io, 'Skills: ' . count($issues) . ' skill dir(s) missing SKILL.md', $jsonOutput);
            $results['issues'] = ['status' => 'warn', 'dirs' => $issues];
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function checkLauncher(SymfonyStyle $io, bool $jsonOutput): array
    {
        $results = [];
        $launcherPath = dirname(__DIR__, 2) . '/bin/coqui-launcher';

        if (file_exists($launcherPath)) {
            if (is_executable($launcherPath)) {
                $this->ok($io, 'Launcher: bin/coqui-launcher exists and is executable', $jsonOutput);
                $results['launcher'] = ['status' => 'ok'];
            } else {
                $this->warn($io, 'Launcher: bin/coqui-launcher exists but is not executable', $jsonOutput);
                $results['launcher'] = ['status' => 'warn'];
            }
        } else {
            $this->warn($io, 'Launcher: bin/coqui-launcher not found', $jsonOutput);
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

    private function resolveWorkspacePath(string $workDir, ?string $configPath): string
    {
        try {
            $configPath ??= $workDir . '/openclaw.json';
            if (!file_exists($configPath)) {
                $configPath = dirname(__DIR__, 2) . '/openclaw.json';
            }

            if (file_exists($configPath)) {
                $config = \CarmeloSantana\PHPAgents\Config\OpenClawConfig::fromFile($configPath);
            } else {
                $config = \CarmeloSantana\PHPAgents\Config\OpenClawConfig::fromArray([]);
            }

            $resolver = new WorkspaceResolver($config, $workDir);
            return $resolver->resolve();
        } catch (\Throwable) {
            // Fallback to common default
            $home = $_SERVER['HOME'] ?? $_ENV['HOME'] ?? '';
            if ($home !== '') {
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

    private function resolveWorkspaceOverride(InputInterface $input): ?string
    {
        $option = $input->getOption('workspace');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $env = getenv('COQUI_WORKSPACE');

        if (is_string($env) && $env !== '') {
            return $env;
        }

        return null;
    }
}
