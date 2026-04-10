<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Tool;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Config\PathHelper;

/**
 * Standalone system tool that lists all workspace toolkit packages.
 *
 * Promoted from ToolkitGeneratorToolkit to ensure it's always available
 * (never deferred), so the LLM can always discover installed packages.
 */
final class ToolkitListTool
{
    public function __construct(
        private readonly string $workspacePath,
    ) {}

    public function tool(): ToolInterface
    {
        return new Tool(
            name: 'toolkit_list',
            description: 'List all toolkit packages in the workspace with their tools, dependencies, and credential requirements.',
            parameters: [],
            callback: fn(array $input): ToolResult => $this->execute(),
        );
    }

    private function execute(): ToolResult
    {
        $packagesDir = PathHelper::trimTrailingSlash($this->workspacePath) . '/packages';

        if (!is_dir($packagesDir)) {
            return ToolResult::success("No toolkit packages found. Use `toolkit_create` to create one.");
        }

        $entries = scandir($packagesDir);
        if ($entries === false) {
            return ToolResult::error("Cannot read packages directory.");
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

        if (empty($packages)) {
            return ToolResult::success("No toolkit packages found. Use `toolkit_create` to create one.");
        }

        $output = "## Workspace Toolkit Packages\n\n";
        $output .= implode("\n", $packages);

        return ToolResult::success($output);
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
}
