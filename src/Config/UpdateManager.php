<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;

/**
 * Manages Coqui self-updates and workspace dependency updates.
 *
 * Provides:
 * - Startup update check via `composer outdated`
 * - Self-update of Coqui and all workspace dependencies via `composer update`
 *
 * Controlled by two ENV vars (stored in the workspace .env):
 * - COQUI_CHECK_UPDATES — check for updates on startup (default: true)
 * - COQUI_AUTO_UPDATE — apply updates automatically on startup (default: false)
 */
final class UpdateManager
{
    public function __construct(
        private readonly string $projectRoot,
        private readonly string $workspacePath,
    ) {}

    /**
     * Check if update checking is enabled via ENV.
     */
    public function isCheckEnabled(): bool
    {
        $value = getenv('COQUI_CHECK_UPDATES');

        // Default to true if not set
        if ($value === false || $value === '') {
            return true;
        }

        return !in_array(strtolower($value), ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Check if auto-update is enabled via ENV.
     */
    public function isAutoUpdateEnabled(): bool
    {
        $value = getenv('COQUI_AUTO_UPDATE');

        // Default to false if not set
        if ($value === false || $value === '') {
            return false;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Check for available updates by running `composer outdated`.
     *
     * Checks both the project root and workspace for outdated packages.
     */
    public function checkForUpdates(): UpdateCheckResult
    {
        $packages = [];

        // Check project root
        $projectPackages = $this->runOutdated($this->projectRoot);
        if ($projectPackages !== null) {
            $packages = array_merge($packages, $projectPackages);
        }

        // Check workspace
        $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/composer.json';
        if (file_exists($composerJson)) {
            $workspacePackages = $this->runOutdated($this->workspacePath);
            if ($workspacePackages !== null) {
                $packages = array_merge($packages, $workspacePackages);
            }
        }

        if (empty($packages)) {
            return UpdateCheckResult::none();
        }

        return new UpdateCheckResult(hasUpdates: true, packages: $packages);
    }

    /**
     * Run `composer update` in the project root and workspace.
     *
     * @return UpdateCheckResult Result with any errors encountered.
     */
    public function applyUpdates(): UpdateCheckResult
    {
        $errors = [];

        // Update project root
        $result = $this->runComposerUpdate($this->projectRoot);
        if ($result !== null) {
            $errors[] = "Project update failed: {$result}";
        }

        // Update workspace if it has a composer.json
        $composerJson = PathHelper::trimTrailingSlash($this->workspacePath) . '/composer.json';
        if (file_exists($composerJson)) {
            $result = $this->runComposerUpdate($this->workspacePath);
            if ($result !== null) {
                $errors[] = "Workspace update failed: {$result}";
            }
        }

        if (!empty($errors)) {
            return UpdateCheckResult::error(implode('; ', $errors));
        }

        return UpdateCheckResult::none();
    }

    /**
     * Run `composer outdated --format=json --direct` and parse results.
     *
     * @return array<int, array{name: string, current: string, latest: string, description: string}>|null
     */
    private function runOutdated(string $directory): ?array
    {
        if (!$this->hasComposerProject($directory)) {
            return null;
        }

        $composerBin = $this->findComposer();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$composerBin, 'outdated', '--format=json', '--direct', '--no-interaction'],
            $descriptors,
            $pipes,
            $directory,
            $this->buildEnvironment(),
        );

        if (!is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        if ($stdout === false || $stdout === '') {
            return null;
        }

        $data = json_decode($stdout, true);
        if (!is_array($data) || !isset($data['installed'])) {
            return null;
        }

        $packages = [];
        foreach ($data['installed'] as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }

            // Skip path-repository packages — they are local symlinks that cannot
            // be updated via Packagist. Running composer update on them is a no-op,
            // which would otherwise trigger an infinite auto-update restart loop.
            $sourceType = $pkg['source']['type'] ?? $pkg['dist']['type'] ?? '';
            if ($sourceType === 'path') {
                continue;
            }

            $packages[] = [
                'name' => $pkg['name'] ?? '',
                'current' => $pkg['version'] ?? '',
                'latest' => $pkg['latest'] ?? '',
                'description' => $pkg['description'] ?? '',
            ];
        }

        return $packages;
    }

    /**
     * Run `composer update` in the given directory.
     *
     * @return string|null Error message, or null on success.
     */
    private function runComposerUpdate(string $directory): ?string
    {
        if (!$this->hasComposerProject($directory)) {
            return 'composer.json not found';
        }

        $composerBin = $this->findComposer();

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open(
            [$composerBin, 'update', '--no-interaction', '--no-progress'],
            $descriptors,
            $pipes,
            $directory,
            $this->buildEnvironment(),
        );

        if (!is_resource($process)) {
            return 'Failed to start composer process';
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $parts = [];
            if (is_string($stderr) && $stderr !== '') {
                $parts[] = trim($stderr);
            }
            if (is_string($stdout) && $stdout !== '') {
                $parts[] = trim($stdout);
            }
            $error = $parts !== [] ? implode('\n', $parts) : 'Unknown error';
            return "Exit code {$exitCode}: {$error}";
        }

        return null;
    }

    /**
     * Find the Composer binary.
     */
    private function findComposer(): string
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

        $local = $this->projectRoot . '/composer.phar';
        if (file_exists($local)) {
            return $local;
        }

        return 'composer';
    }

    private function hasComposerProject(string $directory): bool
    {
        return is_dir($directory) && file_exists(PathHelper::trimTrailingSlash($directory) . '/composer.json');
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
}
