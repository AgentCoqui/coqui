<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace\Installer;

use CoquiBot\Coqui\Config\SkillDiscovery;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\SpaceRegistry;
use CoquiBot\Coqui\Support\StringHelper;

/**
 * Manages the local skill lifecycle: download, install, update, disable, enable, remove.
 *
 * Integrated with SkillDiscovery to invalidate the cache after every
 * modification so new skills are immediately visible without restart.
 */
final class SkillInstaller
{
    public function __construct(
        private readonly SpaceClient $client,
        private readonly SkillDiscovery $skillDiscovery,
        private readonly string $skillsDirectory,
    ) {}

    /**
     * Download and install a skill from Coqui Space.
     *
     * @return array{name: string, version: string, path: string, message: string}
     */
    public function install(string $owner, string $name, ?string $version = null, bool $force = false): array
    {
        $archive = $this->client->downloadSkill($owner, $name, $version);

        $tmpDir = $this->skillsDirectory . '/.tmp';
        $tmpZip = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.zip';
        $tmpExtract = $tmpDir . '/extract-' . bin2hex(random_bytes(8));

        try {
            if (!is_dir($tmpDir)) {
                mkdir($tmpDir, 0755, true);
            }

            file_put_contents($tmpZip, $archive['bytes']);

            $this->extractZip($tmpZip, $tmpExtract);

            $skillRoot = $this->detectSkillRoot($tmpExtract);
            $skillName = $this->resolveSkillName($skillRoot, $name);
            $targetDir = $this->skillsDirectory . '/' . $skillName;

            if (is_dir($targetDir) && !$force) {
                throw new \RuntimeException(
                    "Skill '{$skillName}' is already installed. Use force=true to overwrite.",
                );
            }

            if (is_dir($targetDir)) {
                $this->removeDirectory($targetDir);
            }

            $this->copyDirectory($skillRoot, $targetDir);

            $resolvedVersion = $version ?? $this->resolveLatestVersion($owner, $name);

            $this->writeOrigin($targetDir, [
                'source' => 'coqui.space',
                'owner' => $owner,
                'name' => $name,
                'version' => $resolvedVersion,
                'downloadedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'suggestedFilename' => $archive['filename'],
            ]);

            // Invalidate skill discovery cache so the new skill is immediately visible
            $this->skillDiscovery->invalidateCache();

            return [
                'name' => $skillName,
                'version' => $resolvedVersion,
                'path' => $targetDir,
                'message' => "Skill '{$skillName}' v{$resolvedVersion} installed successfully.",
            ];
        } finally {
            if (file_exists($tmpZip)) {
                @unlink($tmpZip);
            }
            if (is_dir($tmpExtract)) {
                $this->removeDirectory($tmpExtract);
            }
        }
    }

    /**
     * Update an installed skill to the latest version.
     *
     * @return array{name: string, version: string, message: string}
     */
    public function update(string $skillName, bool $force = false): array
    {
        $dir = $this->skillsDirectory . '/' . $skillName;

        if (!is_dir($dir)) {
            throw new \RuntimeException("Skill '{$skillName}' is not installed.");
        }

        $origin = $this->readOrigin($dir);
        if ($origin === []) {
            throw new \RuntimeException(
                "Skill '{$skillName}' has no origin metadata — it may not have been installed from Coqui Space.",
            );
        }

        $owner = (string) ($origin['owner'] ?? '');
        $name = (string) ($origin['name'] ?? '');
        $currentVersion = (string) ($origin['version'] ?? '');

        if ($owner === '' || $name === '') {
            throw new \RuntimeException("Skill '{$skillName}' has incomplete origin metadata.");
        }

        $latestVersion = $this->resolveLatestVersion($owner, $name);

        if (!$force && $currentVersion === $latestVersion) {
            return [
                'name' => $skillName,
                'version' => $currentVersion,
                'message' => "Skill '{$skillName}' is already at the latest version ({$currentVersion}).",
            ];
        }

        $result = $this->install($owner, $name, null, true);

        return [
            'name' => $result['name'],
            'version' => $result['version'],
            'message' => $force && $currentVersion === $latestVersion
                ? "Skill '{$skillName}' reinstalled at v{$result['version']}."
                : "Skill '{$skillName}' updated from v{$currentVersion} to v{$result['version']}.",
        ];
    }

    /**
     * List all installed skills.
     *
     * @return list<array{name: string, version: string, status: string, source: string, owner: string, slug: string}>
     */
    public function list(): array
    {
        if (!is_dir($this->skillsDirectory)) {
            return [];
        }

        $skills = [];
        $entries = scandir($this->skillsDirectory);

        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.tmp') {
                continue;
            }

            $fullPath = $this->skillsDirectory . '/' . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }

            $disabled = str_ends_with($entry, '.disabled');
            $displayName = $disabled ? substr($entry, 0, -9) : $entry;

            $origin = $this->readOrigin($fullPath);

            $skills[] = [
                'name' => $displayName,
                'version' => (string) ($origin['version'] ?? 'unknown'),
                'status' => $disabled ? 'disabled' : 'enabled',
                'source' => $origin !== [] ? 'coqui.space' : 'local',
                'owner' => (string) ($origin['owner'] ?? ''),
                'slug' => (string) ($origin['name'] ?? ''),
            ];
        }

        usort($skills, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $skills;
    }

    /**
     * Disable a skill by renaming its directory.
     */
    public function disable(string $skillName): string
    {
        $dir = $this->skillsDirectory . '/' . $skillName;
        $disabledDir = $dir . '.disabled';

        if (!is_dir($dir)) {
            if (is_dir($disabledDir)) {
                return "Skill '{$skillName}' is already disabled.";
            }
            throw new \RuntimeException("Skill '{$skillName}' not found.");
        }

        rename($dir, $disabledDir);
        $this->skillDiscovery->invalidateCache();

        return "Skill '{$skillName}' disabled.";
    }

    /**
     * Re-enable a disabled skill.
     */
    public function enable(string $skillName): string
    {
        $disabledDir = $this->skillsDirectory . '/' . $skillName . '.disabled';
        $enabledDir = $this->skillsDirectory . '/' . $skillName;

        if (!is_dir($disabledDir)) {
            if (is_dir($enabledDir)) {
                return "Skill '{$skillName}' is already enabled.";
            }
            throw new \RuntimeException("Skill '{$skillName}' not found.");
        }

        if (is_dir($enabledDir)) {
            throw new \RuntimeException(
                "Cannot enable '{$skillName}' — an enabled skill with that name already exists.",
            );
        }

        rename($disabledDir, $enabledDir);
        $this->skillDiscovery->invalidateCache();

        return "Skill '{$skillName}' enabled.";
    }

    /**
     * Remove a skill. purge=false disables it; purge=true deletes the directory.
     */
    public function remove(string $skillName, bool $purge = false): string
    {
        if (!$purge) {
            return $this->disable($skillName);
        }

        $dir = $this->skillsDirectory . '/' . $skillName;
        $disabledDir = $dir . '.disabled';

        $target = is_dir($dir) ? $dir : (is_dir($disabledDir) ? $disabledDir : null);

        if ($target === null) {
            throw new \RuntimeException("Skill '{$skillName}' not found.");
        }

        $this->removeDirectory($target);
        $this->skillDiscovery->invalidateCache();

        return "Skill '{$skillName}' permanently removed.";
    }

    // -- Helpers --------------------------------------------------------------

    private function resolveLatestVersion(string $owner, string $name): string
    {
        try {
            $details = $this->client->skillDetails($owner, $name);

            return (string) ($details['latestVersion']['version'] ?? '0.0.0');
        } catch (\Throwable) {
            return '0.0.0';
        }
    }

    private function extractZip(string $zipPath, string $extractTo): void
    {
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);

        if ($result !== true) {
            throw new \RuntimeException("Failed to open ZIP archive (error code: {$result}).");
        }

        try {
            if (!is_dir($extractTo)) {
                mkdir($extractTo, 0755, true);
            }

            $zip->extractTo($extractTo);
        } finally {
            $zip->close();
        }
    }

    private function detectSkillRoot(string $extractDir): string
    {
        if (file_exists($extractDir . '/SKILL.md')) {
            return $extractDir;
        }

        $entries = scandir($extractDir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $subDir = $extractDir . '/' . $entry;
                if (is_dir($subDir) && file_exists($subDir . '/SKILL.md')) {
                    return $subDir;
                }
            }
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($extractDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === 'SKILL.md') {
                return dirname($file->getPathname());
            }
        }

        throw new \RuntimeException('No SKILL.md found in the downloaded archive.');
    }

    private function resolveSkillName(string $skillRoot, string $fallbackSlug): string
    {
        $skillMd = $skillRoot . '/SKILL.md';

        if (!file_exists($skillMd)) {
            return $this->sanitizeName($fallbackSlug);
        }

        $content = file_get_contents($skillMd);
        if ($content === false) {
            return $this->sanitizeName($fallbackSlug);
        }

        if (preg_match('/^---\s*\n(.*?)\n---/s', $content, $fmMatch)) {
            if (preg_match('/^name:\s*([a-z0-9][a-z0-9-]*[a-z0-9])\s*$/mi', $fmMatch[1], $nameMatch)) {
                return $nameMatch[1];
            }
        }

        return $this->sanitizeName($fallbackSlug);
    }

    private function sanitizeName(string $value): string
    {
        $clean = StringHelper::slug($value);

        return $clean !== '' ? $clean : 'skill-' . bin2hex(random_bytes(4));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeOrigin(string $dir, array $data): void
    {
        file_put_contents(
            $dir . '/' . SpaceRegistry::ORIGIN_FILE,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readOrigin(string $dir): array
    {
        $file = $dir . '/' . SpaceRegistry::ORIGIN_FILE;

        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return [];
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : [];
        } catch (\JsonException) {
            return [];
        }
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0755, true);
                }
            } else {
                copy($item->getPathname(), $target);
            }
        }
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
    }
}
