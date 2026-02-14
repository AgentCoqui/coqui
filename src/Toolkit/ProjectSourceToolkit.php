<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;

/**
 * Read-only toolkit providing structured access to the Coqui project source code.
 *
 * Gives the agent self-awareness of its own codebase through four tools:
 * - project_source_map: Returns the structured codebase map (config/source.json)
 * - project_read: Reads any file from the project root (read-only, sandboxed)
 * - project_list: Lists directory contents in the project
 * - project_search: Glob-based file search across the project
 *
 * All operations are read-only. Writing to project files is not permitted —
 * file writes are restricted to the workspace directory via FilesystemToolkit.
 */
final class ProjectSourceToolkit implements ToolkitInterface
{
    private readonly string $sourceMapPath;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $this->sourceMapPath = rtrim($this->projectRoot, '/') . '/config/source.json';
    }

    public function tools(): array
    {
        return [
            $this->sourceMapTool(),
            $this->readTool(),
            $this->listTool(),
            $this->searchTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <PROJECT-SOURCE-GUIDELINES>
            Mode: READ-ONLY
            These tools provide structured access to the Coqui project source code.

            - Start with `project_source_map` to understand the codebase structure before reading individual files.
            - Use `project_read` to read specific source files (paths relative to project root).
            - Use `project_list` to explore directory contents.
            - Use `project_search` with glob patterns to find files (e.g. "src/**/*.php", "config/*.json").
            - All operations are read-only — use workspace file tools to write files.
            - When extending Coqui, study relevant source files first to understand existing patterns.
            - The source map describes every core file, its class, layer, and key methods.
            </PROJECT-SOURCE-GUIDELINES>
            GUIDELINES;
    }

    private function sourceMapTool(): ToolInterface
    {
        return new Tool(
            name: 'project_source_map',
            description: 'Returns the Coqui codebase map (config/source.json) — a structured index of every core source file with descriptions, layers, and key methods. Use this first to understand where to look.',
            parameters: [
                new StringParameter('section', 'Optional section to filter: "files", "layers", "externalDependencies". If omitted, returns the full map.', required: false),
            ],
            callback: function (array $input): ToolResult {
                if (!file_exists($this->sourceMapPath)) {
                    return ToolResult::error('Source map not found at config/source.json');
                }

                $content = file_get_contents($this->sourceMapPath);
                if ($content === false) {
                    return ToolResult::error('Failed to read source map');
                }

                $section = $input['section'] ?? '';
                if ($section !== '') {
                    $data = json_decode($content, true);
                    if (!is_array($data)) {
                        return ToolResult::error('Failed to parse source map JSON');
                    }

                    if (!isset($data[$section])) {
                        $available = implode(', ', array_keys($data));
                        return ToolResult::error("Unknown section '{$section}'. Available: {$available}");
                    }

                    return ToolResult::success(
                        json_encode($data[$section], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                    );
                }

                return ToolResult::success($content);
            },
        );
    }

    private function readTool(): ToolInterface
    {
        return new Tool(
            name: 'project_read',
            description: 'Read a file from the Coqui project root (read-only). Use for studying source code, configs, or documentation.',
            parameters: [
                new StringParameter('path', 'Path to the file relative to project root (e.g. "src/Agent/OrchestratorAgent.php")', required: true),
            ],
            callback: function (array $input): ToolResult {
                $relativePath = $input['path'] ?? '';
                if ($relativePath === '') {
                    return ToolResult::error('Path is required');
                }

                $path = $this->resolvePath($relativePath);
                if ($path === null) {
                    return ToolResult::error("Path escapes project root: {$relativePath}");
                }

                if (!file_exists($path)) {
                    return ToolResult::error("File not found: {$relativePath}");
                }

                if (!is_file($path)) {
                    return ToolResult::error("Not a file: {$relativePath}");
                }

                $content = file_get_contents($path);
                if ($content === false) {
                    return ToolResult::error("Failed to read file: {$relativePath}");
                }

                // Truncate very large files to prevent context overflow
                $maxBytes = 65536;
                if (strlen($content) > $maxBytes) {
                    $content = substr($content, 0, $maxBytes) . "\n\n[… truncated at {$maxBytes} bytes]";
                }

                return ToolResult::success($content);
            },
        );
    }

    private function listTool(): ToolInterface
    {
        return new Tool(
            name: 'project_list',
            description: 'List files and directories in the Coqui project (read-only).',
            parameters: [
                new StringParameter('path', 'Path to the directory relative to project root', required: false),
                new BoolParameter('recursive', 'List recursively (default: false)', required: false),
            ],
            callback: function (array $input): ToolResult {
                $relativePath = $input['path'] ?? '.';
                $recursive = $input['recursive'] ?? false;

                $path = $this->resolvePath($relativePath);
                if ($path === null) {
                    return ToolResult::error("Path escapes project root: {$relativePath}");
                }

                if (!is_dir($path)) {
                    return ToolResult::error("Directory not found: {$relativePath}");
                }

                $entries = [];

                if ($recursive) {
                    $iterator = new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                    );

                    foreach ($iterator as $file) {
                        $relPath = $this->makeRelative($file->getPathname());
                        if ($relPath === null) {
                            continue;
                        }

                        // Skip vendor/ and .workspace/ in recursive listings
                        if (str_starts_with($relPath, 'vendor/') || str_starts_with($relPath, '.workspace/')) {
                            continue;
                        }

                        $type = $file->isDir() ? 'd' : 'f';
                        $entries[] = "[{$type}] {$relPath}";
                    }
                } else {
                    $items = scandir($path);
                    if ($items === false) {
                        return ToolResult::error("Failed to list directory: {$relativePath}");
                    }

                    foreach ($items as $item) {
                        if ($item === '.' || $item === '..') {
                            continue;
                        }

                        $fullPath = "{$path}/{$item}";
                        $type = is_dir($fullPath) ? 'd' : 'f';
                        $entries[] = "[{$type}] {$item}";
                    }
                }

                if ($entries === []) {
                    return ToolResult::success("Directory is empty: {$relativePath}");
                }

                sort($entries);

                return ToolResult::success(implode("\n", $entries));
            },
        );
    }

    private function searchTool(): ToolInterface
    {
        return new Tool(
            name: 'project_search',
            description: 'Search for files in the Coqui project matching a glob pattern (read-only).',
            parameters: [
                new StringParameter('pattern', 'Glob pattern to match (e.g. "src/**/*.php", "config/*.json")', required: true),
            ],
            callback: function (array $input): ToolResult {
                $pattern = $input['pattern'] ?? '';
                if ($pattern === '') {
                    return ToolResult::error('Pattern is required');
                }

                $fullPattern = rtrim($this->projectRoot, '/') . '/' . $pattern;
                $files = glob($fullPattern, GLOB_BRACE) ?: [];

                if ($files === []) {
                    return ToolResult::success("No files found matching: {$pattern}");
                }

                $relativePaths = [];
                foreach ($files as $file) {
                    $rel = $this->makeRelative($file);
                    if ($rel !== null) {
                        $relativePaths[] = $rel;
                    }
                }

                sort($relativePaths);

                return ToolResult::success(implode("\n", $relativePaths));
            },
        );
    }

    /**
     * Resolve a relative path to an absolute path within the project root.
     *
     * Returns null if the resolved path escapes the project root (directory traversal protection).
     */
    private function resolvePath(string $relativePath): ?string
    {
        $path = rtrim($this->projectRoot, '/') . '/' . $relativePath;
        $realRoot = realpath($this->projectRoot);

        if ($realRoot === false) {
            return $path;
        }

        $realPath = realpath($path);

        // If the file doesn't exist yet, realpath returns false — allow the raw path
        // but verify the directory portion doesn't escape
        if ($realPath === false) {
            $dirPath = realpath(dirname($path));
            if ($dirPath !== false && !str_starts_with($dirPath, $realRoot)) {
                return null;
            }

            return $path;
        }

        if (!str_starts_with($realPath, $realRoot)) {
            return null;
        }

        return $realPath;
    }

    /**
     * Convert an absolute path to a path relative to the project root.
     */
    private function makeRelative(string $absolutePath): ?string
    {
        $realRoot = realpath($this->projectRoot);
        if ($realRoot === false) {
            return null;
        }

        $realPath = realpath($absolutePath) ?: $absolutePath;

        if (!str_starts_with($realPath, $realRoot)) {
            return null;
        }

        $relative = substr($realPath, strlen($realRoot));

        return ltrim($relative, '/');
    }
}
