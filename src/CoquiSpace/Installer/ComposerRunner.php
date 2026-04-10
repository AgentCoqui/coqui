<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace\Installer;

/**
 * Resolves and executes Composer commands in a given working directory.
 *
 * Handles composer binary resolution across platforms (including macOS
 * Homebrew paths) and uses proc_open() for proper stdout/stderr capture.
 */
final class ComposerRunner
{
    private const ALLOWED_COMMANDS = ['require', 'update', 'remove'];

    public function __construct(
        private readonly string $workingDirectory,
    ) {}

    /**
     * Run a Composer command and return stdout.
     *
      * @param list<string>|string $command
     * @throws \RuntimeException If the command fails
     */
    public function run(array|string $command): string
    {
        $arguments = $this->normalizeArguments($command);
        $composer = $this->resolveComposerBinary();
        $processCommand = $this->buildProcessCommand($composer, $arguments);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = $this->buildEnvironment();

        $process = @proc_open(
            $processCommand,
            $descriptors,
            $pipes,
            $this->workingDirectory,
            $env,
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start Composer process.');
        }

        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $output = trim(($stderr ?: '') . "\n" . ($stdout ?: ''));
            throw new \RuntimeException(
                "Composer command failed (exit {$exitCode}): {$output}",
            );
        }

        return (string) $stdout;
    }

    /**
     * @param array<int, string>|string $command
     * @return list<string>
     */
    private function normalizeArguments(array|string $command): array
    {
        if (!is_dir($this->workingDirectory)) {
            throw new \RuntimeException("Composer working directory not found: {$this->workingDirectory}");
        }

        if (!file_exists($this->workingDirectory . '/composer.json')) {
            throw new \RuntimeException('Composer working directory must contain composer.json.');
        }

        $arguments = is_array($command)
            ? array_values(array_filter($command, static fn(string $value): bool => $value !== ''))
            : (preg_split('/\s+/', trim($command)) ?: []);

        if ($arguments === []) {
            throw new \InvalidArgumentException('Composer command is required.');
        }

        if (!in_array($arguments[0], self::ALLOWED_COMMANDS, true)) {
            throw new \InvalidArgumentException(
                'Unsupported Composer command: ' . $arguments[0] . '. Allowed: ' . implode(', ', self::ALLOWED_COMMANDS),
            );
        }

        return $arguments;
    }

    /**
     * Resolve the Composer binary path.
     *
     * Checks in order: COMPOSER_BIN env, common system paths (including
     * Homebrew on macOS ARM/Intel), then falls back to bare 'composer'.
     */
    private function resolveComposerBinary(): string
    {
        $envBin = getenv('COMPOSER_BIN');
        if ($envBin !== false && $envBin !== '') {
            return $envBin;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $appData = getenv('APPDATA') ?: null;
            $userProfile = getenv('USERPROFILE') ?: null;
            $candidates = array_values(array_filter([
                $appData !== null ? $appData . '\\Composer\\vendor\\bin\\composer.bat' : null,
                $userProfile !== null ? $userProfile . '\\AppData\\Roaming\\Composer\\vendor\\bin\\composer.bat' : null,
                $appData !== null ? $appData . '\\Composer\\vendor\\bin\\composer' : null,
                $userProfile !== null ? $userProfile . '\\AppData\\Roaming\\Composer\\vendor\\bin\\composer' : null,
            ]));
        } else {
            $candidates = [
                '/opt/homebrew/bin/composer',   // macOS ARM (Homebrew)
                '/usr/local/bin/composer',      // macOS Intel / Linux
                '/usr/bin/composer',            // System-wide
            ];
        }

        foreach ($candidates as $path) {
            if (file_exists($path) && is_executable($path)) {
                return $path;
            }
        }

        return 'composer';
    }

    /**
     * @param list<string> $arguments
     * @return list<string>
     */
    private function buildProcessCommand(string $composer, array $arguments): array
    {
        if (preg_match('/\.(php|phar)$/i', $composer) === 1) {
            return [PHP_BINARY, $composer, ...$arguments];
        }

        if (PHP_OS_FAMILY === 'Windows' && preg_match('/\.(bat|cmd)$/i', $composer) === 1) {
            return ['cmd.exe', '/d', '/c', $composer, ...$arguments];
        }

        return [$composer, ...$arguments];
    }

    /**
     * Build environment variables for the child process.
     *
     * Passes through HOME and PATH to ensure Composer can find its
     * dependencies and configuration.
     *
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

        // Ensure non-interactive
        $env['COMPOSER_NO_INTERACTION'] = '1';

        return $env;
    }
}
