<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\ModManager\Installer\ToolkitInstaller;

/**
 * Unified tool for browsing and managing installed toolkits.
 *
 * Merges the old ToolkitListTool (workspace packages) with the lifecycle
 * actions formerly in SpaceManageTool (disable, enable, remove). Always
 * loaded — never budget-gated or deferred.
 */
final class CoquiToolkitsTool
{
    public function __construct(
        private readonly string $workspacePath,
        private readonly ?ToolkitInstaller $installer = null,
    ) {}

    public function tool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_toolkits',
            description: 'Browse and manage installed toolkits. '
                . 'Actions: list (show workspace packages and Composer-installed toolkits), '
                . 'disable (deactivate a toolkit without removing), '
                . 'enable (reactivate a disabled toolkit), '
                . 'remove (uninstall a toolkit package).',
            parameters: [
                new EnumParameter(
                    'action',
                    'The operation to perform',
                    ['list', 'disable', 'enable', 'remove'],
                ),
                new StringParameter('name', 'Toolkit identifier: vendor/package for Composer toolkits (e.g. "coquibot/coqui-toolkit-brave-search").', required: false),
                new EnumParameter('type', 'Filter for list action', ['all', 'workspace', 'installed', 'disabled'], required: false),
            ],
            callback: fn(array $input): ToolResult => $this->execute($input),
        );
    }

    /**
     * @param array<string, mixed> $input
     */
    private function execute(array $input): ToolResult
    {
        $action = (string) ($input['action'] ?? 'list');

        try {
            return match ($action) {
                'list' => $this->list($input),
                'disable' => $this->disable($input),
                'enable' => $this->enable($input),
                'remove' => $this->remove($input),
                default => ToolResult::error("Unknown action: '{$action}'. Valid: list, disable, enable, remove"),
            };
        } catch (\Throwable $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    // ── List ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function list(array $input): ToolResult
    {
        $type = (string) ($input['type'] ?? 'all');
        $sections = [];

        // Workspace packages (in-development)
        if ($type === 'all' || $type === 'workspace') {
            $workspaceSection = $this->listWorkspacePackages();
            if ($workspaceSection !== null) {
                $sections[] = $workspaceSection;
            }
        }

        // Composer-installed and disabled toolkits
        if ($this->installer !== null && ($type === 'all' || $type === 'installed' || $type === 'disabled')) {
            $installedSection = $this->listInstalledToolkits($type);
            if ($installedSection !== null) {
                $sections[] = $installedSection;
            }
        }

        if ($sections === []) {
            return ToolResult::success(
                "No toolkits found. Use `/mods search <query>` to discover community toolkits, "
                . "or install a toolkit package with `composer`.",
            );
        }

        return ToolResult::success(implode("\n\n", $sections));
    }

    private function listWorkspacePackages(): ?string
    {
        $packagesDir = PathHelper::trimTrailingSlash($this->workspacePath) . '/packages';

        if (!is_dir($packagesDir)) {
            return null;
        }

        $entries = scandir($packagesDir);
        if ($entries === false) {
            return null;
        }

        $packages = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $composerPath = $packagesDir . '/' . $entry . '/composer.json';
            if (!file_exists($composerPath)) {
                continue;
            }

            $data = json_decode((string) file_get_contents($composerPath), true);
            if (!is_array($data)) {
                continue;
            }

            $packages[] = $this->formatPackageInfo($entry, $data);
        }

        if ($packages === []) {
            return null;
        }

        return "## Workspace Packages (in-development)\n\n" . implode("\n", $packages);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function formatPackageInfo(string $dirName, array $data): string
    {
        $name = $data['name'] ?? 'coquibot/' . $dirName;
        $desc = $data['description'] ?? 'No description';
        $toolkits = $data['extra']['php-agents']['toolkits'] ?? [];
        $credentials = $data['extra']['php-agents']['credentials'] ?? [];
        $requires = $data['require'] ?? [];

        unset($requires['php'], $requires['carmelosantana/php-agents']);

        $output = "### {$name}\n";
        $output .= "{$desc}\n\n";

        if (!empty($toolkits)) {
            $output .= "**Classes:** " . implode(', ', array_map(fn(string $c) => '`' . $c . '`', $toolkits)) . "\n";
        }

        if (!empty($requires)) {
            $deps = [];
            foreach ($requires as $pkg => $ver) {
                $deps[] = "`{$pkg}` ({$ver})";
            }
            $output .= "**Dependencies:** " . implode(', ', $deps) . "\n";
        }

        if (!empty($credentials)) {
            $creds = [];
            foreach ($credentials as $key => $cdesc) {
                $creds[] = "`{$key}`";
            }
            $output .= "**Credentials:** " . implode(', ', $creds) . "\n";
        }

        $output .= "\n";

        return $output;
    }

    private function listInstalledToolkits(string $type): ?string
    {
        if ($this->installer === null) {
            return null;
        }

        $toolkits = $this->installer->list();
        if ($toolkits === []) {
            return null;
        }

        // Filter by type
        if ($type === 'installed') {
            $toolkits = array_filter($toolkits, static fn(array $t): bool => $t['status'] === 'enabled');
        } elseif ($type === 'disabled') {
            $toolkits = array_filter($toolkits, static fn(array $t): bool => $t['status'] === 'disabled');
        }

        if ($toolkits === []) {
            return null;
        }

        $lines = ['## Installed Toolkits', '', '| Package | Constraint | Status |', '|---------|------------|--------|'];

        foreach ($toolkits as $toolkit) {
            $statusIcon = $toolkit['status'] === 'enabled' ? '✓' : '○';
            $lines[] = "| `{$toolkit['package']}` | {$toolkit['constraint']} | {$statusIcon} {$toolkit['status']} |";
        }

        return implode("\n", $lines);
    }

    // ── Lifecycle ────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     */
    private function disable(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for disable (e.g. "vendor/package").');
        }

        if ($this->installer === null) {
            return ToolResult::error('Toolkit management is not available (mod manager toolkit not loaded).');
        }

        $message = $this->installer->disable($name);

        return ToolResult::success($message);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function enable(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for enable (e.g. "vendor/package").');
        }

        if ($this->installer === null) {
            return ToolResult::error('Toolkit management is not available (mod manager toolkit not loaded).');
        }

        $message = $this->installer->enable($name);

        return ToolResult::success($message);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function remove(array $input): ToolResult
    {
        $name = (string) ($input['name'] ?? '');
        if ($name === '') {
            return ToolResult::error('Parameter "name" is required for remove (e.g. "vendor/package").');
        }

        if ($this->installer === null) {
            return ToolResult::error('Toolkit management is not available (mod manager toolkit not loaded).');
        }

        $message = $this->installer->remove($name);

        return ToolResult::success($message);
    }
}
