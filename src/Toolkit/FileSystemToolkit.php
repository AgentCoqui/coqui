<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Parameter\BoolParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\EnumParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CoquiBot\Coqui\Storage\EditHistory;
use CoquiBot\Coqui\Support\FileSystemException;
use CoquiBot\Coqui\Support\FileSystemOperations;

/**
 * Unified filesystem toolkit providing file CRUD, surgical edits, and edit history.
 *
 * Read tools are always available. Write and edit tools are gated by the readOnly flag
 * (set for child agents with readonly access levels). All mutating operations record
 * edit history with backup for undo support.
 */
final class FileSystemToolkit implements ToolkitInterface
{
    private readonly FileSystemOperations $fs;

    /**
     * @param string $workspacePath  Root workspace directory.
     * @param bool   $readOnly       When true, only read tools are exposed.
     * @param array<int, array{realPath: string, readOnly: bool}> $allowedPaths  Mount definitions.
     * @param ?EditHistory $history   Edit history store. Pass null to disable history.
     */
    public function __construct(
        string $workspacePath,
        private readonly bool $readOnly = false,
        array $allowedPaths = [],
        private readonly ?EditHistory $history = null,
    ) {
        $this->fs = new FileSystemOperations($workspacePath, $allowedPaths);
    }

    /** @return ToolInterface[] */
    public function tools(): array
    {
        // Read tools — always available
        $tools = [
            $this->readFileTool(),
            $this->listDirTool(),
            $this->searchFilesTool(),
            $this->fileInfoTool(),
        ];

        if ($this->readOnly) {
            return $tools;
        }

        // Write tools
        $tools[] = $this->writeFileTool();
        $tools[] = $this->createDirTool();
        $tools[] = $this->deleteFileTool();

        // Surgical edit tools
        $tools[] = $this->replaceInFileTool();
        $tools[] = $this->insertBeforeTool();
        $tools[] = $this->insertAfterTool();
        $tools[] = $this->replaceBlockTool();
        $tools[] = $this->removeLinesTool();
        $tools[] = $this->writeLinesTool();
        $tools[] = $this->batchReplaceTool();
        $tools[] = $this->indentLinesTool();
        $tools[] = $this->appendToFileTool();

        // History tool
        if ($this->history !== null) {
            $tools[] = $this->editHistoryTool();
        }

        return $tools;
    }

    public function guidelines(): string
    {
        $mode = $this->readOnly ? 'READ-ONLY' : 'READ/WRITE';

        $guidelines = <<<GUIDELINES
        <filesystem mode="{$mode}">
        ## Filesystem ({$mode})

        ### Tool Selection Guide
        | Task | Tool |
        |------|------|
        | Read entire file or line range | `read_file` with optional `from`/`to` |
        | Browse directory structure | `list_dir` |
        | Find files by name/pattern | `search_files` |
        | Check file size/type/modified | `file_info` |
        GUIDELINES;

        if (!$this->readOnly) {
            $guidelines .= <<<'GUIDELINES'

            | Create/overwrite entire file | `write_file` |
            | Append content to end of file | `append_to_file` |
            | Create directory | `create_dir` |
            | Delete file | `delete_file` |
            | Replace specific text or regex | `replace_in_file` |
            | Insert line(s) before a match | `insert_before` |
            | Insert line(s) after a match | `insert_after` |
            | Replace region between markers | `replace_block` |
            | Remove a range of lines | `remove_lines` |
            | Overwrite a range of lines | `write_lines` |
            | Change indentation of lines | `indent_lines` |
            | Find-and-replace across files | `batch_replace` |
            | View/undo/diff edit history | `edit_history` |

            ### Best Practices
            - **Read before editing.** Always `read_file` first to understand file structure.
            - **Prefer surgical edits** over `write_file` to reduce token usage and avoid accidental data loss.
            - **Use line ranges** when possible: `read_file(path, from: 10, to: 30)` is cheaper than reading the whole file.
            - **Use anchors** (`replace_in_file`, `insert_before`, `insert_after`) for text-based targeting when line numbers are uncertain.
            - **Use `replace_block`** when replacing a region bounded by known start/end markers.
            - **Use `batch_replace`** to apply the same change across many files efficiently.
            - All edits are recorded in history and can be undone with `edit_history(action: "undo", edit_id: N)`.
            GUIDELINES;
        }

        $guidelines .= "\n</filesystem>";

        // Dedent — the heredoc is indented for readability
        return preg_replace('/^ {8}/m', '', $guidelines) ?? $guidelines;
    }

    // =======================================================================
    // Read tools
    // =======================================================================

    private function readFileTool(): ToolInterface
    {
        return new Tool(
            name: 'read_file',
            description: 'Read the contents of a file. Use optional `from` and `to` (1-based, inclusive) to read a specific line range. Returns numbered lines, total line count, and truncation indicator.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new NumberParameter('from', 'Start line number (1-based, inclusive).', integer: true, minimum: 1, required: false),
                new NumberParameter('to', 'End line number (1-based, inclusive).', integer: true, minimum: 1, required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeReadFile($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeReadFile(array $args): ToolResult
    {
        $path = $args['path'] ?? '';

        try {
            $content = $this->fs->read($path);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        $from = isset($args['from']) ? (int) $args['from'] : null;
        $to = isset($args['to']) ? (int) $args['to'] : null;

        $lines = explode("\n", $content);
        $totalLines = count($lines);

        if ($from !== null || $to !== null) {
            $from ??= 1;
            $to ??= $totalLines;

            if ($from < 1 || $to < $from) {
                return ToolResult::error("Invalid range: from={$from} to={$to} (total lines: {$totalLines})");
            }

            $to = min($to, $totalLines);
            $selected = array_slice($lines, $from - 1, $to - $from + 1);

            $numbered = [];
            foreach ($selected as $i => $line) {
                $lineNum = $from + $i;
                $numbered[] = sprintf('%4d | %s', $lineNum, $line);
            }

            $truncated = ($from > 1 || $to < $totalLines) ? 'true' : 'false';

            return ToolResult::success(implode("\n", $numbered) . "\n\n[Lines {$from}–{$to} of {$totalLines} | truncated: {$truncated}]");
        }

        // Full file — add line numbers
        $numbered = [];
        foreach ($lines as $i => $line) {
            $numbered[] = sprintf('%4d | %s', $i + 1, $line);
        }

        return ToolResult::success(implode("\n", $numbered) . "\n\n[{$totalLines} lines total]");
    }

    private function listDirTool(): ToolInterface
    {
        return new Tool(
            name: 'list_dir',
            description: 'List directory contents. Returns entries with [f] (file) or [d] (directory) prefix. Defaults to workspace root.',
            parameters: [
                new StringParameter('path', 'Directory path relative to workspace. Defaults to root.', required: false),
                new BoolParameter('recursive', 'List recursively. Default false.', required: false),
                new NumberParameter('max_depth', 'Maximum recursion depth (1–10). Default 3. Only used when recursive is true.', required: false, integer: true),
            ],
            callback: fn(array $args): ToolResult => $this->executeListDir($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeListDir(array $args): ToolResult
    {
        $path = $args['path'] ?? '.';
        $recursive = (bool) ($args['recursive'] ?? false);
        $maxDepth = min(max((int) ($args['max_depth'] ?? 3), 1), 10);

        try {
            $resolved = $this->fs->resolvePath($path);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        if (!is_dir($resolved)) {
            return ToolResult::error("Not a directory: {$path}");
        }

        $entries = $this->scanDir($resolved, $recursive ? $maxDepth : 0, 0);

        if ($entries === []) {
            return ToolResult::success("(empty directory)");
        }

        $output = [];
        foreach ($entries as $entry) {
            $relPath = $this->fs->makeRelative($entry['path']);
            $prefix = $entry['type'] === 'dir' ? '[d]' : '[f]';
            $indent = str_repeat('  ', $entry['depth']);
            $output[] = "{$indent}{$prefix} {$relPath}";
        }

        return ToolResult::success(implode("\n", $output));
    }

    /**
     * @return list<array{path: string, type: string, depth: int}>
     */
    private function scanDir(string $dir, int $maxDepth, int $currentDepth): array
    {
        $entries = [];
        $items = @scandir($dir);
        if ($items === false) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $full = $dir . DIRECTORY_SEPARATOR . $item;
            $isDir = is_dir($full);

            $entries[] = [
                'path' => $full,
                'type' => $isDir ? 'dir' : 'file',
                'depth' => $currentDepth,
            ];

            if ($isDir && $currentDepth < $maxDepth) {
                $entries = array_merge($entries, $this->scanDir($full, $maxDepth, $currentDepth + 1));
            }
        }

        return $entries;
    }

    private function searchFilesTool(): ToolInterface
    {
        return new Tool(
            name: 'search_files',
            description: 'Search for files matching a glob pattern within the workspace. Supports ** for recursive directory traversal. Returns a list of matching file paths.',
            parameters: [
                new StringParameter('pattern', 'Glob pattern. Use ** to match any directory depth (e.g. "src/**/*.php", "**/*Controller.php", "*.json").'),
            ],
            callback: fn(array $args): ToolResult => $this->executeSearchFiles($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeSearchFiles(array $args): ToolResult
    {
        $pattern = $args['pattern'] ?? '';

        if ($pattern === '') {
            return ToolResult::error('Pattern is required.');
        }

        $matches = $this->fs->resolveGlob($pattern);

        if ($matches === []) {
            return ToolResult::success("No files found matching: {$pattern}");
        }

        $relative = array_map(fn(string $p) => $this->fs->makeRelative($p), $matches);
        sort($relative);

        return ToolResult::success(implode("\n", $relative) . "\n\n[" . count($relative) . ' files found]');
    }

    private function fileInfoTool(): ToolInterface
    {
        return new Tool(
            name: 'file_info',
            description: 'Get metadata about a file or directory: size, type, last modified, permissions.',
            parameters: [
                new StringParameter('path', 'File or directory path relative to workspace.'),
            ],
            callback: fn(array $args): ToolResult => $this->executeFileInfo($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeFileInfo(array $args): ToolResult
    {
        $path = $args['path'] ?? '';

        try {
            $resolved = $this->fs->resolvePath($path);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        if (!file_exists($resolved)) {
            return ToolResult::error("Path not found: {$path}");
        }

        $stat = @stat($resolved);
        if ($stat === false) {
            return ToolResult::error("Cannot stat: {$path}");
        }

        $info = [
            'path' => $path,
            'type' => is_dir($resolved) ? 'directory' : (is_link($resolved) ? 'symlink' : 'file'),
            'size' => $stat['size'],
            'modified' => date('c', $stat['mtime']),
            'permissions' => substr(sprintf('%o', $stat['mode']), -4),
        ];

        if (is_file($resolved)) {
            $info['lines'] = count(file($resolved, FILE_IGNORE_NEW_LINES) ?: []);
            $info['mime'] = mime_content_type($resolved) ?: 'unknown';
        }

        return ToolResult::success(json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    // =======================================================================
    // Write tools
    // =======================================================================

    private function writeFileTool(): ToolInterface
    {
        return new Tool(
            name: 'write_file',
            description: 'Create or overwrite a file with the given content. Parent directories are created automatically. Edit history is recorded for existing files. Prefer surgical edit tools over write_file for targeted changes.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new StringParameter('content', 'Full file content to write.'),
            ],
            callback: fn(array $args): ToolResult => $this->executeWriteFile($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeWriteFile(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $content = $args['content'] ?? '';

        try {
            // Record history if file already exists
            if ($this->history !== null && $this->fs->isFile($path)) {
                $original = $this->fs->read($path);
                $this->history->record(
                    $this->fs->resolvePath($path),
                    'write_file',
                    $original,
                    ['replaced' => true],
                );
            }

            $this->fs->write($path, $content);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        $lines = substr_count($content, "\n") + 1;

        return ToolResult::success("Written: {$path} ({$lines} lines)");
    }

    private function createDirTool(): ToolInterface
    {
        return new Tool(
            name: 'create_dir',
            description: 'Create a directory recursively. No-op if it already exists.',
            parameters: [
                new StringParameter('path', 'Directory path relative to workspace.'),
            ],
            callback: fn(array $args): ToolResult => $this->executeCreateDir($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeCreateDir(array $args): ToolResult
    {
        $path = $args['path'] ?? '';

        try {
            $this->fs->createDir($path);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success("Created directory: {$path}");
    }

    private function deleteFileTool(): ToolInterface
    {
        return new Tool(
            name: 'delete_file',
            description: 'Delete a file. Cannot delete directories. Edit history is recorded.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
            ],
            callback: fn(array $args): ToolResult => $this->executeDeleteFile($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeDeleteFile(array $args): ToolResult
    {
        $path = $args['path'] ?? '';

        try {
            // Record history before deletion
            if ($this->history !== null && $this->fs->isFile($path)) {
                $original = $this->fs->read($path);
                $this->history->record(
                    $this->fs->resolvePath($path),
                    'delete_file',
                    $original,
                    ['deleted' => true],
                );
            }

            $this->fs->delete($path);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success("Deleted: {$path}");
    }

    // =======================================================================
    // Surgical edit tools
    // =======================================================================

    private function replaceInFileTool(): ToolInterface
    {
        return new Tool(
            name: 'replace_in_file',
            description: 'Replace text or regex matches in a file. Returns the number of replacements made. Use `is_regex: true` for PCRE patterns.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new StringParameter('search', 'Text or PCRE regex pattern to find.'),
                new StringParameter('replace', 'Replacement text. Supports backreferences ($1, $2) when using regex.'),
                new BoolParameter('is_regex', 'Treat search as a PCRE regex pattern. Default false.', required: false),
                new StringParameter('flags', 'PCRE modifier flags (e.g. "i" for case-insensitive). Only used with is_regex.', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeReplaceInFile($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeReplaceInFile(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $search = $args['search'] ?? '';
        $replace = $args['replace'] ?? '';
        $isRegex = (bool) ($args['is_regex'] ?? false);
        $flags = $args['flags'] ?? '';

        if ($search === '') {
            return ToolResult::error('Search pattern is required.');
        }

        try {
            $content = $this->fs->read($path);
            $original = $content;

            if ($isRegex) {
                $pattern = '/' . str_replace('/', '\\/', $search) . '/' . $flags;
                if (@preg_match($pattern, '') === false) {
                    return ToolResult::error("Invalid regex: {$pattern}");
                }
                $result = @preg_replace($pattern, $replace, $content, -1, $count);
                if ($result === null) {
                    return ToolResult::error("Regex replacement failed.");
                }
                $content = $result;
            } else {
                $count = substr_count($content, $search);
                if ($count > 0) {
                    $content = str_replace($search, $replace, $content);
                }
            }

            if ($count === 0) {
                return ToolResult::error("No matches found for the search pattern in {$path}.");
            }

            $this->recordAndWrite($path, $original, $content, 'replace_in_file', [
                'search' => mb_substr($search, 0, 100),
                'replacements' => $count,
            ]);

            return ToolResult::success("Replaced {$count} occurrence(s) in {$path}.");
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function insertBeforeTool(): ToolInterface
    {
        return new Tool(
            name: 'insert_before',
            description: 'Insert content before lines matching an anchor pattern. By default inserts before the first match.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new StringParameter('anchor', 'Text or regex pattern to match target lines.'),
                new StringParameter('content', 'Content to insert before each matched line. Trailing newline is added automatically.'),
                new BoolParameter('is_regex', 'Treat anchor as a PCRE regex. Default false.', required: false),
                new NumberParameter('occurrences', 'Number of matches to process (0 = all). Default 1.', integer: true, minimum: 0, required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeInsertBefore($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeInsertBefore(array $args): ToolResult
    {
        return $this->executeInsertRelative($args, 'before');
    }

    private function insertAfterTool(): ToolInterface
    {
        return new Tool(
            name: 'insert_after',
            description: 'Insert content after lines matching an anchor pattern. By default inserts after the first match.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new StringParameter('anchor', 'Text or regex pattern to match target lines.'),
                new StringParameter('content', 'Content to insert after each matched line. Trailing newline is added automatically.'),
                new BoolParameter('is_regex', 'Treat anchor as a PCRE regex. Default false.', required: false),
                new NumberParameter('occurrences', 'Number of matches to process (0 = all). Default 1.', integer: true, minimum: 0, required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeInsertAfter($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeInsertAfter(array $args): ToolResult
    {
        return $this->executeInsertRelative($args, 'after');
    }

    /** @param array<string, mixed> $args */
    private function executeInsertRelative(array $args, string $position): ToolResult
    {
        $path = $args['path'] ?? '';
        $anchor = $args['anchor'] ?? '';
        $content = $args['content'] ?? '';
        $isRegex = (bool) ($args['is_regex'] ?? false);
        $occurrences = (int) ($args['occurrences'] ?? 1);

        if ($anchor === '') {
            return ToolResult::error('Anchor pattern is required.');
        }

        try {
            ['lines' => $lines, 'eol' => $eol] = $this->fs->readLines($path);
            $original = implode($eol, $lines);
            $insertLines = explode("\n", $content);

            $matched = 0;
            $result = [];

            foreach ($lines as $line) {
                $isMatch = $this->lineMatches($line, $anchor, $isRegex);

                if ($isMatch && ($occurrences === 0 || $matched < $occurrences)) {
                    $matched++;
                    if ($position === 'before') {
                        foreach ($insertLines as $insertLine) {
                            $result[] = $insertLine;
                        }
                        $result[] = $line;
                    } else {
                        $result[] = $line;
                        foreach ($insertLines as $insertLine) {
                            $result[] = $insertLine;
                        }
                    }
                } else {
                    $result[] = $line;
                }
            }

            if ($matched === 0) {
                return ToolResult::error("No lines matched the anchor pattern in {$path}.");
            }

            $newContent = implode($eol, $result);
            $toolName = "insert_{$position}";
            $this->recordAndWrite($path, $original, $newContent, $toolName, [
                'anchor' => mb_substr($anchor, 0, 100),
                'matched' => $matched,
                'position' => $position,
            ]);

            return ToolResult::success("Inserted {$position} {$matched} match(es) in {$path}.");
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function replaceBlockTool(): ToolInterface
    {
        return new Tool(
            name: 'replace_block',
            description: 'Replace everything between (and including) start_marker and end_marker with new content. Useful for replacing function bodies, regions between comments, etc.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new StringParameter('start_marker', 'Text or regex matching the start of the region.'),
                new StringParameter('end_marker', 'Text or regex matching the end of the region.'),
                new StringParameter('new_content', 'Replacement content for the entire region (markers inclusive).'),
                new BoolParameter('is_regex', 'Treat markers as PCRE regex. Default false.', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeReplaceBlock($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeReplaceBlock(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $startMarker = $args['start_marker'] ?? '';
        $endMarker = $args['end_marker'] ?? '';
        $newContent = $args['new_content'] ?? '';
        $isRegex = (bool) ($args['is_regex'] ?? false);

        if ($startMarker === '' || $endMarker === '') {
            return ToolResult::error('Both start_marker and end_marker are required.');
        }

        try {
            ['lines' => $lines, 'eol' => $eol] = $this->fs->readLines($path);
            $original = implode($eol, $lines);

            $startIdx = null;
            $endIdx = null;

            foreach ($lines as $i => $line) {
                if ($startIdx === null && $this->lineMatches($line, $startMarker, $isRegex)) {
                    $startIdx = (int) $i;
                } elseif ($startIdx !== null && $this->lineMatches($line, $endMarker, $isRegex)) {
                    $endIdx = (int) $i;
                    break;
                }
            }

            if ($startIdx === null) {
                return ToolResult::error("Start marker not found in {$path}.");
            }
            if ($endIdx === null) {
                return ToolResult::error("End marker not found after start marker in {$path}.");
            }

            $replacementLines = $newContent !== '' ? explode("\n", $newContent) : [];
            $result = array_merge(
                array_slice($lines, 0, $startIdx),
                $replacementLines,
                array_slice($lines, $endIdx + 1),
            );

            $removedCount = $endIdx - $startIdx + 1;
            $newContentStr = implode($eol, $result);
            $this->recordAndWrite($path, $original, $newContentStr, 'replace_block', [
                'start_line' => $startIdx + 1,
                'end_line' => $endIdx + 1,
                'lines_removed' => $removedCount,
                'lines_added' => count($replacementLines),
            ]);

            return ToolResult::success("Replaced lines {$startIdx}–{$endIdx} ({$removedCount} lines → " . count($replacementLines) . " lines) in {$path}.");
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function removeLinesTool(): ToolInterface
    {
        return new Tool(
            name: 'remove_lines',
            description: 'Remove a contiguous range of lines from a file. Lines are 1-based, inclusive.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new NumberParameter('from', 'First line to remove (1-based).', integer: true, minimum: 1),
                new NumberParameter('to', 'Last line to remove (1-based, inclusive).', integer: true, minimum: 1),
            ],
            callback: fn(array $args): ToolResult => $this->executeRemoveLines($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeRemoveLines(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $from = (int) ($args['from'] ?? 0);
        $to = (int) ($args['to'] ?? 0);

        if ($from < 1 || $to < $from) {
            return ToolResult::error("Invalid range: from={$from} to={$to}");
        }

        try {
            ['lines' => $lines, 'eol' => $eol] = $this->fs->readLines($path);
            $original = implode($eol, $lines);
            $totalLines = count($lines);

            if ($from > $totalLines) {
                return ToolResult::error("Line {$from} exceeds file length ({$totalLines} lines).");
            }

            $to = min($to, $totalLines);
            $removed = $to - $from + 1;

            array_splice($lines, $from - 1, $removed);

            $newContent = implode($eol, $lines);
            $this->recordAndWrite($path, $original, $newContent, 'remove_lines', [
                'from' => $from,
                'to' => $to,
                'lines_removed' => $removed,
            ]);

            return ToolResult::success("Removed {$removed} line(s) ({$from}–{$to}) from {$path}. File now has " . count($lines) . " lines.");
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function writeLinesTool(): ToolInterface
    {
        return new Tool(
            name: 'write_lines',
            description: 'Overwrite a contiguous range of lines with new content. Lines are 1-based, inclusive. The new content replaces lines from→to.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new NumberParameter('from', 'First line to replace (1-based).', integer: true, minimum: 1),
                new NumberParameter('to', 'Last line to replace (1-based, inclusive).', integer: true, minimum: 1),
                new StringParameter('content', 'New content for the line range. Use newlines to span multiple lines.'),
            ],
            callback: fn(array $args): ToolResult => $this->executeWriteLines($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeWriteLines(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $from = (int) ($args['from'] ?? 0);
        $to = (int) ($args['to'] ?? 0);
        $content = $args['content'] ?? '';

        if ($from < 1 || $to < $from) {
            return ToolResult::error("Invalid range: from={$from} to={$to}");
        }

        try {
            ['lines' => $lines, 'eol' => $eol] = $this->fs->readLines($path);
            $original = implode($eol, $lines);
            $totalLines = count($lines);

            if ($from > $totalLines) {
                return ToolResult::error("Line {$from} exceeds file length ({$totalLines} lines).");
            }

            $to = min($to, $totalLines);
            $newLines = explode("\n", $content);

            array_splice($lines, $from - 1, $to - $from + 1, $newLines);

            $newContent = implode($eol, $lines);
            $this->recordAndWrite($path, $original, $newContent, 'write_lines', [
                'from' => $from,
                'to' => $to,
                'lines_replaced' => $to - $from + 1,
                'lines_written' => count($newLines),
            ]);

            return ToolResult::success("Overwrote lines {$from}–{$to} with " . count($newLines) . " line(s) in {$path}. File now has " . count($lines) . " lines.");
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function batchReplaceTool(): ToolInterface
    {
        return new Tool(
            name: 'batch_replace',
            description: 'Find and replace text or regex across multiple files matching a glob pattern. Returns total replacements per file.',
            parameters: [
                new StringParameter('glob', 'Glob pattern for target files (e.g. "src/**/*.php").'),
                new StringParameter('search', 'Text or PCRE regex pattern to find.'),
                new StringParameter('replace', 'Replacement text.'),
                new BoolParameter('is_regex', 'Treat search as PCRE regex. Default false.', required: false),
                new StringParameter('flags', 'PCRE modifier flags. Only used with is_regex.', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeBatchReplace($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeBatchReplace(array $args): ToolResult
    {
        $glob = $args['glob'] ?? '';
        $search = $args['search'] ?? '';
        $replace = $args['replace'] ?? '';
        $isRegex = (bool) ($args['is_regex'] ?? false);
        $flags = $args['flags'] ?? '';

        if ($glob === '' || $search === '') {
            return ToolResult::error('Both glob and search are required.');
        }

        $files = $this->fs->resolveGlob($glob);
        $files = array_filter($files, 'is_file');

        if ($files === []) {
            return ToolResult::error("No files found matching: {$glob}");
        }

        if ($isRegex) {
            $pattern = '/' . str_replace('/', '\\/', $search) . '/' . $flags;
            if (@preg_match($pattern, '') === false) {
                return ToolResult::error("Invalid regex: {$pattern}");
            }
        }

        $results = [];
        $totalReplacements = 0;
        $filesModified = 0;

        foreach ($files as $fullPath) {
            $relPath = $this->fs->makeRelative($fullPath);

            try {
                $content = $this->fs->read($relPath);
                $original = $content;

                if ($isRegex) {
                    $content = preg_replace($pattern, $replace, $content, -1, $count);
                    if ($content === null) {
                        continue;
                    }
                } else {
                    $count = substr_count($content, $search);
                    if ($count > 0) {
                        $content = str_replace($search, $replace, $content);
                    }
                }

                if ($count > 0) {
                    $this->recordAndWrite($relPath, $original, $content, 'batch_replace', [
                        'search' => mb_substr($search, 0, 100),
                        'replacements' => $count,
                    ]);
                    $results[] = "{$relPath}: {$count} replacement(s)";
                    $totalReplacements += $count;
                    $filesModified++;
                }
            } catch (FileSystemException) {
                // Skip files that can't be processed (read-only mounts, etc.)
                continue;
            }
        }

        if ($totalReplacements === 0) {
            return ToolResult::error("No matches found across " . count($files) . " file(s).");
        }

        return ToolResult::success(
            implode("\n", $results) .
            "\n\nTotal: {$totalReplacements} replacement(s) in {$filesModified} file(s).",
        );
    }

    private function indentLinesTool(): ToolInterface
    {
        return new Tool(
            name: 'indent_lines',
            description: 'Adjust indentation of a line range. Use "indent" to add indentation or "outdent" to remove it.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new NumberParameter('from', 'First line (1-based).', integer: true, minimum: 1),
                new NumberParameter('to', 'Last line (1-based, inclusive).', integer: true, minimum: 1),
                new EnumParameter('direction', 'Whether to indent or outdent.', ['indent', 'outdent']),
                new NumberParameter('size', 'Number of spaces per indent level. Default 4.', integer: true, minimum: 1, required: false),
                new BoolParameter('use_tabs', 'Use tabs instead of spaces. Default false.', required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeIndentLines($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeIndentLines(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $from = (int) ($args['from'] ?? 0);
        $to = (int) ($args['to'] ?? 0);
        $direction = $args['direction'] ?? 'indent';
        $size = (int) ($args['size'] ?? 4);
        $useTabs = (bool) ($args['use_tabs'] ?? false);

        if ($from < 1 || $to < $from) {
            return ToolResult::error("Invalid range: from={$from} to={$to}");
        }

        try {
            ['lines' => $lines, 'eol' => $eol] = $this->fs->readLines($path);
            $original = implode($eol, $lines);
            $totalLines = count($lines);

            if ($from > $totalLines) {
                return ToolResult::error("Line {$from} exceeds file length ({$totalLines} lines).");
            }

            $to = min($to, $totalLines);
            $indent = $useTabs ? "\t" : str_repeat(' ', $size);
            $modified = 0;

            for ($i = $from - 1; $i < $to; $i++) {
                $line = $lines[$i];
                if ($direction === 'indent') {
                    $lines[$i] = $indent . $line;
                    $modified++;
                } else {
                    // Remove one level of indentation
                    if ($useTabs && str_starts_with($line, "\t")) {
                        $lines[$i] = substr($line, 1);
                        $modified++;
                    } elseif (!$useTabs && str_starts_with($line, $indent)) {
                        $lines[$i] = substr($line, $size);
                        $modified++;
                    } elseif (preg_match('/^(\s+)/', $line, $m)) {
                        // Remove up to $size spaces
                        $spaces = min(strlen($m[1]), $size);
                        $lines[$i] = substr($line, $spaces);
                        $modified++;
                    }
                }
            }

            $newContent = implode($eol, $lines);
            $this->recordAndWrite($path, $original, $newContent, 'indent_lines', [
                'from' => $from,
                'to' => $to,
                'direction' => $direction,
                'lines_modified' => $modified,
            ]);

            return ToolResult::success("{$direction} {$modified} line(s) ({$from}–{$to}) in {$path}.");
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }
    }

    private function appendToFileTool(): ToolInterface
    {
        return new Tool(
            name: 'append_to_file',
            description: 'Append content to the end of a file. Creates the file if it does not exist.',
            parameters: [
                new StringParameter('path', 'File path relative to workspace.'),
                new StringParameter('content', 'Content to append.'),
            ],
            callback: fn(array $args): ToolResult => $this->executeAppendToFile($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeAppendToFile(array $args): ToolResult
    {
        $path = $args['path'] ?? '';
        $content = $args['content'] ?? '';

        try {
            // Record empty backup for new files so undo creates an empty file or removes it
            if ($this->history !== null) {
                $originalContent = $this->fs->isFile($path) ? $this->fs->read($path) : '';
                $this->history->record(
                    $this->fs->resolvePath($path),
                    'append_to_file',
                    $originalContent,
                    ['appended_bytes' => strlen($content)],
                );
            }

            $bytes = $this->fs->append($path, $content);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success("Appended {$bytes} bytes to {$path}.");
    }

    // =======================================================================
    // Edit History tool
    // =======================================================================

    private function editHistoryTool(): ToolInterface
    {
        return new Tool(
            name: 'edit_history',
            description: 'View, undo, diff, or prune filesystem edit history. Actions: "list" shows recent edits, "undo" restores a backup, "diff" shows unified diff for an edit, "prune" removes old entries.',
            parameters: [
                new EnumParameter('action', 'Action to perform.', ['list', 'undo', 'diff', 'prune']),
                new NumberParameter('edit_id', 'Edit ID for undo or diff actions.', integer: true, minimum: 1, required: false),
                new StringParameter('file', 'Filter by file path (for list action).', required: false),
                new NumberParameter('count', 'Number of edits to list (default 20) or undo (default 1).', integer: true, minimum: 1, required: false),
                new NumberParameter('prune_days', 'Days to keep when pruning (default 7).', integer: true, minimum: 1, required: false),
            ],
            callback: fn(array $args): ToolResult => $this->executeEditHistory($args),
        );
    }

    /** @param array<string, mixed> $args */
    private function executeEditHistory(array $args): ToolResult
    {
        $action = $args['action'] ?? '';

        if ($this->history === null) {
            return ToolResult::error('Edit history is not available.');
        }

        return match ($action) {
            'list' => $this->historyList($args),
            'undo' => $this->historyUndo($args),
            'diff' => $this->historyDiff($args),
            'prune' => $this->historyPrune($args),
            default => ToolResult::error("Unknown action: {$action}"),
        };
    }

    /** @param array<string, mixed> $args */
    private function historyList(array $args): ToolResult
    {
        assert($this->history !== null);
        $file = $args['file'] ?? null;
        $count = (int) ($args['count'] ?? 20);

        $edits = $this->history->list($file !== '' ? $file : null, $count);

        if ($edits === []) {
            return ToolResult::success('No edit history found.');
        }

        $lines = [];
        foreach ($edits as $edit) {
            $relPath = $this->fs->makeRelative($edit['file_path']);
            $lines[] = sprintf('#%d  %-20s  %s  %s', $edit['id'], $edit['operation'], $relPath, $edit['timestamp']);
        }

        return ToolResult::success(implode("\n", $lines));
    }

    /** @param array<string, mixed> $args */
    private function historyUndo(array $args): ToolResult
    {
        assert($this->history !== null);
        $editId = isset($args['edit_id']) ? (int) $args['edit_id'] : null;
        $file = $args['file'] ?? null;
        $count = (int) ($args['count'] ?? 1);

        if ($editId !== null) {
            // Undo a specific edit
            return $this->undoSingleEdit($editId);
        }

        if ($file !== null && $file !== '') {
            // Undo the last N edits on a file
            $edits = $this->history->getLastEdits($file, $count);
            if ($edits === []) {
                return ToolResult::error("No edits found for: {$file}");
            }

            $undone = 0;
            foreach ($edits as $edit) {
                $result = $this->undoSingleEdit($edit['id']);
                if (!str_contains($result->content, 'Error')) {
                    $undone++;
                }
            }

            return ToolResult::success("Undid {$undone} edit(s) on {$file}.");
        }

        return ToolResult::error('Provide edit_id or file for undo.');
    }

    private function undoSingleEdit(int $editId): ToolResult
    {
        assert($this->history !== null);
        try {
            $backup = $this->history->getBackup($editId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }

        $filePath = $backup['file_path'];
        $relPath = $this->fs->makeRelative($filePath);

        try {
            // Record the undo itself for re-undo capability
            if ($this->fs->isFile($relPath)) {
                $currentContent = $this->fs->read($relPath);
                $this->history->record($filePath, 'undo', $currentContent, ['undid_edit_id' => $editId]);
            }

            $this->fs->write($relPath, $backup['content']);
            $this->history->removeEdit($editId);
        } catch (FileSystemException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success("Undid edit #{$editId} ({$backup['operation']}) on {$relPath}.");
    }

    /** @param array<string, mixed> $args */
    private function historyDiff(array $args): ToolResult
    {
        assert($this->history !== null);
        $editId = isset($args['edit_id']) ? (int) $args['edit_id'] : null;

        if ($editId === null) {
            return ToolResult::error('edit_id is required for diff action.');
        }

        try {
            $diff = $this->history->generateDiff($editId);
        } catch (\RuntimeException $e) {
            return ToolResult::error($e->getMessage());
        }

        return ToolResult::success($diff);
    }

    /** @param array<string, mixed> $args */
    private function historyPrune(array $args): ToolResult
    {
        assert($this->history !== null);
        $days = (int) ($args['prune_days'] ?? 7);

        $pruned = $this->history->prune($days);

        return ToolResult::success("Pruned {$pruned} edit(s) older than {$days} day(s).");
    }

    // =======================================================================
    // Internal helpers
    // =======================================================================

    /**
     * Record original content in history and write new content to disk.
     *
     * @param array<string, mixed> $metadata
     */
    private function recordAndWrite(
        string $path,
        string $originalContent,
        string $newContent,
        string $operation,
        array $metadata = [],
    ): void {
        if ($this->history !== null) {
            $this->history->record(
                $this->fs->resolvePath($path),
                $operation,
                $originalContent,
                $metadata,
            );
        }

        $this->fs->write($path, $newContent);
    }

    /**
     * Test whether a line matches a pattern (plain text substring or regex).
     */
    private function lineMatches(string $line, string $pattern, bool $isRegex): bool
    {
        if ($isRegex) {
            $regex = '/' . str_replace('/', '\\/', $pattern) . '/';

            return @preg_match($regex, $line) === 1;
        }

        return str_contains($line, $pattern);
    }
}
