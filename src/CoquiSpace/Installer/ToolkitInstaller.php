<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\CoquiSpace\Installer;

use CoquiBot\Coqui\Config\ToolkitDiscovery;
use CoquiBot\Coqui\CoquiSpace\SpaceClient;
use CoquiBot\Coqui\CoquiSpace\SpaceRegistry;

/**
 * Manages the toolkit lifecycle via Composer: install, update, disable, enable, remove.
 *
 * Integrated with ToolkitDiscovery to update toolkits.json after every
 * Composer operation, and with ComposerRunner for proper binary resolution.
 */
final class ToolkitInstaller
{
    public function __construct(
        private readonly SpaceClient $client,
        private readonly ComposerRunner $composer,
        private readonly ToolkitDiscovery $discovery,
        private readonly string $workspacePath,
    ) {}

    /**
     * Install a toolkit via Composer and register it in toolkits.json.
     *
     * @return array{package: string, message: string}
     */
    public function install(string $package, ?string $version = null): array
    {
        self::validatePackageName($package);

        if (SpaceRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be managed.");
        }

        $constraint = $version !== null ? "{$package}:{$version}" : $package;

        if ($version !== null && !preg_match('/^[a-zA-Z0-9^~><=|.*@ -]+$/', $version)) {
            throw new \InvalidArgumentException(
                "Invalid version constraint: '{$version}'. Contains unsafe characters.",
            );
        }

        try {
            $this->composer->run("require {$constraint} --no-interaction");
        } catch (\RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Could not find a matching version')) {
                throw new \RuntimeException($this->diagnosePackageNotFound($package));
            }
            throw $e;
        }

        // Remove from disabled state if it was previously disabled
        $this->removeDisabledState($package);

        // Discover toolkits in the newly installed package and update toolkits.json
        $this->discovery->discover($package);

        // Log the install (fire-and-forget)
        $this->logInstall($package);

        return [
            'package' => $package,
            'message' => "Toolkit '{$package}' installed. Restart Coqui to activate the new tools.",
        ];
    }

    /**
     * Update a toolkit via Composer.
     *
     * @return array{package: string, message: string}
     */
    public function update(string $package): array
    {
        self::validatePackageName($package);

        if (SpaceRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be managed.");
        }

        if (!$this->isInstalled($package)) {
            throw new \RuntimeException("Toolkit '{$package}' is not installed.");
        }

        $this->composer->run("update {$package} --no-interaction");

        // Re-discover in case toolkit classes changed
        $this->discovery->discover($package);

        return [
            'package' => $package,
            'message' => "Toolkit '{$package}' updated. Restart Coqui to load the new version.",
        ];
    }

    /**
     * List installed toolkits from the discovery registry.
     *
     * @return list<array{package: string, constraint: string, status: string}>
     */
    public function list(): array
    {
        $registry = $this->discovery->loadRegistry();
        $installed = $this->readRequire();
        $disabled = $this->readState();
        $toolkits = [];

        // Active packages from discovery registry
        foreach ($registry as $package => $classes) {
            if (SpaceRegistry::isExcluded($package)) {
                continue;
            }

            $constraint = $installed[$package] ?? '*';

            $toolkits[] = [
                'package' => $package,
                'constraint' => $constraint,
                'status' => 'enabled',
            ];
        }

        // Disabled packages from state file
        foreach ($disabled as $package => $info) {
            if (SpaceRegistry::isExcluded($package)) {
                continue;
            }

            $toolkits[] = [
                'package' => (string) $package,
                'constraint' => (string) ($info['constraint'] ?? '*'),
                'status' => 'disabled',
            ];
        }

        usort($toolkits, static fn(array $a, array $b): int => strcasecmp($a['package'], $b['package']));

        return $toolkits;
    }

    /**
     * Disable a toolkit by removing it from Composer and saving the constraint.
     */
    public function disable(string $package): string
    {
        self::validatePackageName($package);

        if (SpaceRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be disabled.");
        }

        $installed = $this->readRequire();
        $constraint = $installed[$package] ?? null;

        if ($constraint === null) {
            $state = $this->readState();
            if (isset($state[$package])) {
                return "Toolkit '{$package}' is already disabled.";
            }
            throw new \RuntimeException("Toolkit '{$package}' is not installed.");
        }

        // Save constraint before removing
        $this->saveDisabledState($package, $constraint);

        $this->composer->run("remove {$package} --no-interaction");

        return "Toolkit '{$package}' disabled. Restart Coqui to apply. Use space(action: \"enable\", name: \"{$package}\") to re-enable.";
    }

    /**
     * Re-enable a previously disabled toolkit.
     */
    public function enable(string $package): string
    {
        self::validatePackageName($package);

        $state = $this->readState();

        if (!isset($state[$package])) {
            if ($this->isInstalled($package)) {
                return "Toolkit '{$package}' is already enabled.";
            }
            throw new \RuntimeException(
                "Toolkit '{$package}' has no saved state. Use space_toolkits(action: \"install\", package: \"{$package}\") to install it.",
            );
        }

        $constraint = (string) ($state[$package]['constraint'] ?? '*');

        $this->composer->run("require {$package}:{$constraint} --no-interaction");
        $this->removeDisabledState($package);

        // Re-discover after re-install
        $this->discovery->discover($package);

        return "Toolkit '{$package}' re-enabled. Restart Coqui to activate.";
    }

    /**
     * Remove a toolkit entirely.
     */
    public function remove(string $package): string
    {
        self::validatePackageName($package);

        if (SpaceRegistry::isExcluded($package)) {
            throw new \RuntimeException("Package '{$package}' is a core dependency and cannot be removed.");
        }

        if ($this->isInstalled($package)) {
            $this->composer->run("remove {$package} --no-interaction");
        }

        // Clean up registry and disabled state
        $this->discovery->unregister($package);
        $this->removeDisabledState($package);

        return "Toolkit '{$package}' removed. Restart Coqui to apply.";
    }

    // -- Helpers --------------------------------------------------------------

    private static function validatePackageName(string $package): void
    {
        if (!preg_match('#^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$#i', $package)) {
            throw new \InvalidArgumentException(
                "Invalid package name: '{$package}'. Expected format: vendor/package (alphanumeric, hyphens, dots, underscores).",
            );
        }
    }

    private function isInstalled(string $package): bool
    {
        return isset($this->readRequire()[$package]);
    }

    /**
     * @return array<string, string>
     */
    private function readRequire(): array
    {
        $composerFile = $this->workspacePath . '/composer.json';

        if (!file_exists($composerFile)) {
            return [];
        }

        $content = file_get_contents($composerFile);
        if ($content === false) {
            return [];
        }

        try {
            $data = json_decode($content, true, 16, JSON_THROW_ON_ERROR);

            return is_array($data) ? (array) ($data['require'] ?? []) : [];
        } catch (\JsonException) {
            return [];
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readState(): array
    {
        $stateFile = $this->workspacePath . '/' . SpaceRegistry::STATE_FILE;

        if (!file_exists($stateFile)) {
            return [];
        }

        $content = file_get_contents($stateFile);
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

    private function saveDisabledState(string $package, string $constraint): void
    {
        $state = $this->readState();
        $state[$package] = [
            'constraint' => $constraint,
            'disabledAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
        $this->writeState($state);
    }

    private function removeDisabledState(string $package): void
    {
        $state = $this->readState();
        if (!isset($state[$package])) {
            return;
        }

        unset($state[$package]);
        $this->writeState($state);
    }

    /**
     * @param array<string, array<string, mixed>> $state
     */
    private function writeState(array $state): void
    {
        $stateFile = $this->workspacePath . '/' . SpaceRegistry::STATE_FILE;

        if ($state === []) {
            if (file_exists($stateFile)) {
                @unlink($stateFile);
            }

            return;
        }

        file_put_contents(
            $stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
        );
    }

    /**
     * Check Packagist for a package and return a helpful diagnostic message.
     */
    private function diagnosePackageNotFound(string $package): string
    {
        $url = "https://packagist.org/packages/{$package}.json";

        $context = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true],
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusLine = $http_response_header[0] ?? '';

        if ($response === false || str_contains($statusLine, '404')) {
            return "Package '{$package}' was not found on Packagist. "
                . "It may not have been published yet, or may require a custom repository. ";
                // . "Check https://packagist.org/packages/{$package} or contact the package author.";
        }

        // Exists on Packagist but Composer still rejected it — likely a stability constraint
        return "Package '{$package}' exists on Packagist but could not be resolved. "
            // . "It may only have development releases (dev-main). "
            . "Try specifying a version: /space install {$package}@dev";
    }

    private function logInstall(string $package): void
    {
        if (!str_contains($package, '/')) {
            return;
        }

        try {
            $results = $this->client->searchToolkits($package, 1);
            $items = $results['results'] ?? [];

            if ($items === []) {
                return;
            }

            $first = $items[0];
            $owner = SpaceRegistry::extractOwner($first);
            $urlName = (string) ($first['urlName'] ?? '');

            if ($owner !== '' && $urlName !== '') {
                $this->client->logToolkitInstall($owner, $urlName, 'coqui/0.1.0');
            }
        } catch (\Throwable) {
            // Fire-and-forget
        }
    }
}
