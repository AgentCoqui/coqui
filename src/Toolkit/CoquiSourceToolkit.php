<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CoquiBot\Coqui\Config\PathHelper;

/**
 * Read-only toolkit providing structured access to the Coqui project source code and documentation.
 *
 * Gives the agent self-awareness of its own codebase through six tools:
 * - coqui_source_map: Returns the structured codebase map (config/source.json)
 * - coqui_read: Reads any file from the project root (read-only, sandboxed)
 * - coqui_list: Lists directory contents in the project
 * - coqui_search: Glob-based file search across the project (supports ** recursive)
 * - coqui_doc_map: Returns a structured map of documentation sections (config/documentation.json)
 * - coqui_doc_read: Reads specific sections of documentation by heading
 *
 * All operations are read-only. Writing to project files is not permitted —
 * file writes are restricted to the workspace directory via FilesystemToolkit.
 */
final class CoquiSourceToolkit implements ToolkitInterface
{
    private const int MAX_GLOB_RESULTS = 500;
    private const int MAX_READ_BYTES = 65536;

    private readonly string $sourceMapPath;
    private readonly string $docMapPath;
    private readonly string $normalizedRoot;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $root = PathHelper::trimTrailingSlash($this->projectRoot);
        $this->normalizedRoot = $root;
        $this->sourceMapPath = $root . '/config/source.json';
        $this->docMapPath = $root . '/config/documentation.json';
    }

    public function tools(): array
    {
        return [
            $this->sourceMapTool(),
            $this->readTool(),
            $this->listTool(),
            $this->searchTool(),
            $this->docMapTool(),
            $this->docReadTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <COQUI-SOURCE-GUIDELINES>
            Mode: READ-ONLY
            These tools provide structured access to the Coqui project source code and documentation.

            Source Code:
            - Start with `coqui_source_map` to understand the codebase structure before reading individual files.
            - Use `coqui_read` to read specific source files (paths relative to project root).
            - Use `coqui_list` to explore directory contents.
            - Use `coqui_search` with glob patterns to find files (e.g. "src/**/*.php", "config/*.json").

            Documentation:
            - Use `coqui_doc_map` to see all available documentation sections and their topics.
            - Use `coqui_doc_read` to read specific documentation sections by heading (e.g. file: "docs/CONFIGURATION.md", section: "model").
            - When asked about configuration, features, or usage, check the docs first.

            General:
            - All operations are read-only — use workspace file tools to write files.
            - When extending Coqui, study relevant source files first to understand existing patterns.
            - The source map describes every core file, its class, layer, and key methods.
            </COQUI-SOURCE-GUIDELINES>
            GUIDELINES;
    }

    // ──────────────────────────────────────────────
    //  Source Code Tools
    // ──────────────────────────────────────────────

    private function sourceMapTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_source_map',
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
            name: 'coqui_read',
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
                if (strlen($content) > self::MAX_READ_BYTES) {
                    $content = substr($content, 0, self::MAX_READ_BYTES) . "\n\n[… truncated at " . self::MAX_READ_BYTES . ' bytes]';
                }

                return ToolResult::success($content);
            },
        );
    }

    private function listTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_list',
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

                        // Skip vendor/ and workspace/ in recursive listings
                        if (str_starts_with($relPath, 'vendor/') || str_starts_with($relPath, 'workspace/')) {
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
            name: 'coqui_search',
            description: 'Search for files in the Coqui project matching a glob pattern (read-only). Supports ** for recursive directory traversal.',
            parameters: [
                new StringParameter('pattern', 'Glob pattern to match (e.g. "src/**/*.php", "config/*.json", "**/*Test.php")', required: true),
            ],
            callback: function (array $input): ToolResult {
                $pattern = $input['pattern'] ?? '';
                if ($pattern === '') {
                    return ToolResult::error('Pattern is required');
                }

                $pattern = ltrim($pattern, "/\\");

                $matches = str_contains($pattern, '**')
                    ? $this->resolveGlobRecursive($pattern)
                    : $this->resolveGlobStandard($pattern);

                if ($matches === []) {
                    return ToolResult::success("No files found matching: {$pattern}");
                }

                $relativePaths = [];
                foreach ($matches as $file) {
                    $rel = $this->makeRelative($file);
                    if ($rel !== null) {
                        $relativePaths[] = $rel;
                    }
                }

                sort($relativePaths);

                return ToolResult::success(implode("\n", $relativePaths) . "\n\n[" . count($relativePaths) . ' files found]');
            },
        );
    }

    // ──────────────────────────────────────────────
    //  Documentation Tools
    // ──────────────────────────────────────────────

    private function docMapTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_doc_map',
            description: 'Returns a structured map of Coqui documentation sections (config/documentation.json). Shows all available docs with their section headings and descriptions. Use this to discover what documentation is available before reading specific sections.',
            parameters: [
                new StringParameter('file', 'Optional: filter to show sections of a specific doc file (e.g. "docs/CONFIGURATION.md"). If omitted, returns the full index.', required: false),
            ],
            callback: function (array $input): ToolResult {
                if (!file_exists($this->docMapPath)) {
                    return ToolResult::error('Documentation map not found at config/documentation.json');
                }

                $content = file_get_contents($this->docMapPath);
                if ($content === false) {
                    return ToolResult::error('Failed to read documentation map');
                }

                $file = $input['file'] ?? '';
                if ($file === '') {
                    return ToolResult::success($content);
                }

                $data = json_decode($content, true);
                if (!is_array($data) || !isset($data['files'])) {
                    return ToolResult::error('Failed to parse documentation map JSON');
                }

                foreach ($data['files'] as $entry) {
                    if (($entry['path'] ?? '') === $file) {
                        return ToolResult::success(
                            json_encode($entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
                        );
                    }
                }

                $available = array_column($data['files'], 'path');

                return ToolResult::error("File not found in documentation index: {$file}. Available: " . implode(', ', $available));
            },
        );
    }

    private function docReadTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_doc_read',
            description: 'Read specific sections of Coqui documentation by heading. Returns the content of a documentation section without needing to read the entire file. Use coqui_doc_map first to discover available sections.',
            parameters: [
                new StringParameter('file', 'Path to the documentation file relative to project root (e.g. "docs/CONFIGURATION.md", "AGENTS.md")', required: true),
                new StringParameter('section', 'Section heading to extract (case-insensitive, e.g. "model", "mounts", "shellAllowedCommands"). If omitted, returns the full file.', required: false),
            ],
            callback: function (array $input): ToolResult {
                $file = $input['file'] ?? '';
                if ($file === '') {
                    return ToolResult::error('File path is required');
                }

                $filePath = $this->resolvePath($file);
                if ($filePath === null) {
                    return ToolResult::error("Path escapes project root: {$file}");
                }

                if (!file_exists($filePath) || !is_file($filePath)) {
                    return ToolResult::error("File not found: {$file}");
                }

                $section = $input['section'] ?? '';

                // No section requested — return the full file (truncated)
                if ($section === '') {
                    $content = file_get_contents($filePath);
                    if ($content === false) {
                        return ToolResult::error("Failed to read file: {$file}");
                    }

                    if (strlen($content) > self::MAX_READ_BYTES) {
                        $content = substr($content, 0, self::MAX_READ_BYTES) . "\n\n[… truncated at " . self::MAX_READ_BYTES . ' bytes]';
                    }

                    return ToolResult::success($content);
                }

                // Look up section in the documentation index for line ranges
                $sectionContent = $this->extractSectionFromIndex($file, $section, $filePath);
                if ($sectionContent !== null) {
                    return ToolResult::success($sectionContent);
                }

                // Fallback: parse the file directly for the heading
                $sectionContent = $this->extractSectionFromFile($filePath, $section);
                if ($sectionContent !== null) {
                    return ToolResult::success($sectionContent);
                }

                // Section not found — suggest closest match or list available sections
                $headings = $this->extractHeadings($filePath);
                if ($headings !== []) {
                    $closest = $this->findClosestHeading($section, $headings);
                    $msg = "Section '{$section}' not found in {$file}.";

                    if ($closest !== null) {
                        $msg .= " Did you mean: \"{$closest}\"?";
                    }

                    $msg .= ' Available sections: ' . implode(', ', $headings);

                    return ToolResult::error($msg);
                }

                return ToolResult::error("Section '{$section}' not found in {$file}");
            },
        );
    }

    // ──────────────────────────────────────────────
    //  Documentation Helpers
    // ──────────────────────────────────────────────

    /**
     * Extract a section using line ranges from the documentation index.
     *
     * Matching priority: exact heading (case-insensitive) → substring match.
     */
    private function extractSectionFromIndex(string $file, string $section, string $filePath): ?string
    {
        if (!file_exists($this->docMapPath)) {
            return null;
        }

        $mapContent = file_get_contents($this->docMapPath);
        if ($mapContent === false) {
            return null;
        }

        $map = json_decode($mapContent, true);
        if (!is_array($map) || !isset($map['files'])) {
            return null;
        }

        $sectionLower = strtolower($section);
        $fileSections = null;

        foreach ($map['files'] as $entry) {
            if (($entry['path'] ?? '') === $file) {
                $fileSections = $entry['sections'] ?? [];
                break;
            }
        }

        if ($fileSections === null) {
            return null;
        }

        // Pass 1: exact match (case-insensitive, backtick-stripped)
        foreach ($fileSections as $sec) {
            $heading = $sec['heading'] ?? '';
            $headingLower = strtolower($heading);
            $headingStripped = strtolower(trim($heading, '`'));

            if ($headingLower === $sectionLower || $headingStripped === $sectionLower) {
                return $this->readSectionLines($sec, $filePath);
            }
        }

        // Pass 2: substring match (first heading containing the search term)
        foreach ($fileSections as $sec) {
            $heading = $sec['heading'] ?? '';
            $headingStripped = strtolower(trim($heading, '`'));

            if (str_contains($headingStripped, $sectionLower)) {
                return $this->readSectionLines($sec, $filePath);
            }
        }

        return null;
    }

    /**
     * Read the line range for a section entry from the index.
     *
     * @param array{heading: string, level: int, line_start: int, line_end: int} $sec
     */
    private function readSectionLines(array $sec, string $filePath): ?string
    {
        $lineStart = $sec['line_start'] ?? null;
        $lineEnd = $sec['line_end'] ?? null;

        if ($lineStart !== null && $lineEnd !== null) {
            return $this->readLineRange($filePath, (int) $lineStart, (int) $lineEnd);
        }

        return null;
    }

    /**
     * Extract a section by scanning the file for matching headings.
     *
     * Tracks fenced code blocks to avoid matching headings inside code examples.
     */
    private function extractSectionFromFile(string $filePath, string $section): ?string
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        $sectionLower = strtolower($section);
        $startLine = null;
        $startLevel = 0;
        $inCodeBlock = false;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '```')) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if ($inCodeBlock || !str_starts_with($line, '#')) {
                continue;
            }

            // Extract heading level and text
            preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches);
            if ($matches === []) {
                continue;
            }

            $level = strlen($matches[1]);
            $headingText = strtolower(trim($matches[2], '`'));

            if ($startLine === null) {
                // Looking for the target section
                if ($headingText === $sectionLower || str_contains($headingText, $sectionLower)) {
                    $startLine = $i;
                    $startLevel = $level;
                }
            } else {
                // Found target — looking for next heading at same or higher level
                if ($level <= $startLevel) {
                    $extracted = array_slice($lines, $startLine, $i - $startLine);
                    $content = implode("\n", $extracted);

                    if (strlen($content) > self::MAX_READ_BYTES) {
                        $content = substr($content, 0, self::MAX_READ_BYTES) . "\n\n[… truncated at " . self::MAX_READ_BYTES . ' bytes]';
                    }

                    return $content;
                }
            }
        }

        // Section extends to end of file
        if ($startLine !== null) {
            $extracted = array_slice($lines, $startLine);
            $content = implode("\n", $extracted);

            if (strlen($content) > self::MAX_READ_BYTES) {
                $content = substr($content, 0, self::MAX_READ_BYTES) . "\n\n[… truncated at " . self::MAX_READ_BYTES . ' bytes]';
            }

            return $content;
        }

        return null;
    }

    /**
     * Read a specific line range from a file.
     */
    private function readLineRange(string $filePath, int $startLine, int $endLine): ?string
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return null;
        }

        // Line numbers are 1-based in the index
        $start = max(0, $startLine - 1);
        $length = $endLine - $startLine + 1;
        $extracted = array_slice($lines, $start, $length);

        $content = implode("\n", $extracted);

        if (strlen($content) > self::MAX_READ_BYTES) {
            $content = substr($content, 0, self::MAX_READ_BYTES) . "\n\n[… truncated at " . self::MAX_READ_BYTES . ' bytes]';
        }

        return $content;
    }

    /**
     * Extract all headings from a markdown file for error suggestions.
     *
     * Extracts H1–H4 to match the documentation index scope. Skips code-fenced blocks.
     */
    private function extractHeadings(string $filePath): array
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $headings = [];
        $inCodeBlock = false;

        foreach ($lines as $line) {
            if (str_starts_with($line, '```')) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if (!$inCodeBlock && preg_match('/^#{1,4}\s+(.+)$/', $line, $matches)) {
                $headings[] = trim($matches[1], '`');
            }
        }

        return $headings;
    }

    /**
     * Find the closest matching heading using similarity scoring.
     *
     * @param string[] $headings
     */
    private function findClosestHeading(string $input, array $headings): ?string
    {
        $inputLower = strtolower($input);
        $best = null;
        $bestScore = 0;

        foreach ($headings as $heading) {
            $headingLower = strtolower($heading);
            similar_text($inputLower, $headingLower, $percent);

            if ($percent > $bestScore && $percent >= 50.0) {
                $best = $heading;
                $bestScore = $percent;
            }
        }

        return $best;
    }

    // ──────────────────────────────────────────────
    //  Glob Helpers
    // ──────────────────────────────────────────────

    /**
     * Standard glob for patterns without **.
     *
     * @return list<string>
     */
    private function resolveGlobStandard(string $pattern): array
    {
        $globPattern = $this->normalizedRoot . '/' . $pattern;
        $matches = glob($globPattern, GLOB_NOSORT | GLOB_BRACE) ?: [];

        return array_values(array_filter(
            $matches,
            fn(string $path): bool => $this->isWithinProjectRoot($path),
        ));
    }

    /**
     * Recursive glob for patterns with **. Uses RecursiveDirectoryIterator + fnmatch().
     *
     * Mirrors the pattern from FileSystemOperations::resolveGlobRecursive() for consistency.
     *
     * @return list<string>
     */
    private function resolveGlobRecursive(string $pattern): array
    {
        $realRoot = realpath($this->projectRoot);
        if ($realRoot === false || !is_dir($realRoot)) {
            return [];
        }

        $matches = [];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(
                    $realRoot,
                    \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS,
                ),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
        } catch (\UnexpectedValueException) {
            return [];
        }

        foreach ($iterator as $file) {
            if (count($matches) >= self::MAX_GLOB_RESULTS) {
                break;
            }

            $absolutePath = $file->getPathname();
            $relativePath = $this->makeRelative($absolutePath);

            if ($relativePath === null) {
                continue;
            }

            // Skip heavy directories
            if (str_starts_with($relativePath, 'vendor/') || str_starts_with($relativePath, 'node_modules/') || str_starts_with($relativePath, 'BUILD/')) {
                continue;
            }

            if (fnmatch($pattern, $relativePath, FNM_PATHNAME)) {
                if ($this->isWithinProjectRoot($absolutePath)) {
                    $matches[] = $absolutePath;
                }
            }
        }

        return $matches;
    }

    // ──────────────────────────────────────────────
    //  Path Resolution
    // ──────────────────────────────────────────────

    /**
     * Resolve a relative path to an absolute path within the project root.
     *
     * Returns null if the resolved path escapes the project root (directory traversal protection).
     */
    private function resolvePath(string $relativePath): ?string
    {
        $path = $this->normalizedRoot . '/' . $relativePath;
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
     * Check if a path falls within the project root.
     */
    private function isWithinProjectRoot(string $absolutePath): bool
    {
        $realRoot = realpath($this->projectRoot);
        if ($realRoot === false) {
            return false;
        }

        $realPath = realpath($absolutePath);
        if ($realPath === false) {
            return false;
        }

        return str_starts_with($realPath, $realRoot);
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
