<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

final class AppVersion
{
    private const string ENV_VAR = 'COQUI_VERSION';

    private const string VERSION_FILE = 'config/version.txt';

    public static function current(?string $projectRoot = null): string
    {
        $version = self::readEnvVersion();
        if ($version !== null) {
            return $version;
        }

        $root = self::resolveProjectRoot($projectRoot);

        $version = self::readFileVersion($root);
        if ($version !== null) {
            return $version;
        }

        $version = self::readGitTagVersion($root);
        if ($version !== null) {
            return $version;
        }

        return 'dev';
    }

    private static function resolveProjectRoot(?string $projectRoot): string
    {
        if ($projectRoot === null || $projectRoot === '') {
            return dirname(__DIR__, 2);
        }

        $resolved = realpath($projectRoot);

        return $resolved !== false ? $resolved : rtrim($projectRoot, '/\\');
    }

    private static function readEnvVersion(): ?string
    {
        $version = getenv(self::ENV_VAR);
        if (!is_string($version)) {
            return null;
        }

        $version = trim($version);

        return $version !== '' ? $version : null;
    }

    private static function readFileVersion(string $projectRoot): ?string
    {
        $path = $projectRoot . '/' . self::VERSION_FILE;
        if (!is_file($path)) {
            return null;
        }

        $version = file_get_contents($path);
        if (!is_string($version)) {
            return null;
        }

        $version = trim($version);

        return $version !== '' ? $version : null;
    }

    private static function readGitTagVersion(string $projectRoot): ?string
    {
        if (!file_exists($projectRoot . '/.git')) {
            return null;
        }

        $process = @proc_open(
            ['git', '-C', $projectRoot, 'describe', '--tags', '--exact-match', '--match', 'v*'],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $projectRoot,
        );

        if (!is_resource($process)) {
            return null;
        }

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || !is_string($output)) {
            return null;
        }

        $tag = trim($output);
        if ($tag === '') {
            return null;
        }

        return str_starts_with($tag, 'v') ? substr($tag, 1) : $tag;
    }
}