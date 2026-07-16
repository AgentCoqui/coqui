<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Toolkit;

use CarmeloSantana\PHPAgents\Contract\ToolInterface;
use CarmeloSantana\PHPAgents\Contract\ToolkitInterface;
use CarmeloSantana\PHPAgents\Tool\Tool;
use CarmeloSantana\PHPAgents\Tool\ToolResult;
use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;
use CarmeloSantana\PHPAgents\Tool\Parameter\StringParameter;
use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Config\DocumentationIndex;

/**
 * Read-only access to Coqui's own documentation.
 *
 * FileSystemToolkit is sandboxed to the workspace and cannot reach the install
 * directory, so these three tools are how an agent reaches the docs that ship
 * with it:
 * - coqui_docs_map: what documentation exists (compact) and what sections a doc has
 * - coqui_docs_read: one section of one doc
 * - coqui_docs_search: full-text search across all docs
 *
 * Everything served here is generated or curated-and-reviewed. There is no
 * hand-authored structural map: the 340-entry config/source.json this toolkit
 * used to serve drifted faster than it could be maintained and was removed.
 */
final class CoquiDocsToolkit implements ToolkitInterface
{
    private const int MAX_READ_BYTES = 65536;
    private const int SEARCH_DEFAULT_LIMIT = 20;
    private const int SEARCH_MAX_LIMIT = 50;
    private const int SEARCH_SNIPPET_CHARS = 200;

    private readonly string $normalizedRoot;

    private readonly DocumentationIndex $docsIndex;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $this->normalizedRoot = PathHelper::trimTrailingSlash($this->projectRoot);
        $this->docsIndex = new DocumentationIndex($this->normalizedRoot);
    }

    public function tools(): array
    {
        return [
            $this->docsMapTool(),
            $this->docsReadTool(),
            $this->docsSearchTool(),
        ];
    }

    public function guidelines(): string
    {
        return <<<'GUIDELINES'
            <COQUI-DOCS-GUIDELINES>
            Mode: READ-ONLY
            These tools read Coqui's own shipped documentation.

            - Reach for them when asked about Coqui's configuration, commands, features, or usage — the docs answer those better than guessing.
            - `coqui_docs_search` is the fastest way in when you know roughly what you are looking for. It returns a doc path and heading; pass both to `coqui_docs_read`.
            - `coqui_docs_map` lists what documentation exists when you do not yet know which doc is relevant.
            - `coqui_docs_read` retrieves one section. Prefer a section over a whole file.

            Read only what the question needs. These tools are read-only — use the workspace file tools to write.
            </COQUI-DOCS-GUIDELINES>
            GUIDELINES;
    }

    // ──────────────────────────────────────────────
    //  Documentation Tools
    // ──────────────────────────────────────────────

    private function docsMapTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_docs_map',
            description: 'Lists Coqui documentation: one line per doc with its title, description, and section count. Pass `file` to get one doc\'s section headings. Use it to find which doc answers a question, then read that doc\'s section with coqui_docs_read.',
            parameters: [
                new StringParameter('file', 'Optional: a doc path (e.g. "docs/CONFIGURATION.md") to list that doc\'s section headings. Omit for the summary of all docs.', required: false),
            ],
            callback: function (array $input): ToolResult {
                $index = $this->docsIndex->load();
                $file = $input['file'] ?? '';

                if ($file === '') {
                    $summary = [];

                    foreach ($index['files'] as $entry) {
                        $summary[] = [
                            'path' => $entry['path'],
                            'title' => $entry['title'],
                            'description' => $entry['description'],
                            'section_count' => count($entry['sections']),
                        ];
                    }

                    return ToolResult::json(['files' => $summary]);
                }

                foreach ($index['files'] as $entry) {
                    if ($entry['path'] === $file) {
                        return ToolResult::json($entry);
                    }
                }

                $available = implode(', ', array_column($index['files'], 'path'));

                return ToolResult::error("File not found in documentation index: {$file}. Available: {$available}");
            },
        );
    }

    private function docsReadTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_docs_read',
            description: 'Read one section of a Coqui documentation file by heading. Omit `section` to read a whole file — for files too large to return, the section list is returned instead so you can pick one.',
            parameters: [
                new StringParameter('file', 'Doc path relative to the project root (e.g. "docs/CONFIGURATION.md", "AGENTS.md")', required: true),
                new StringParameter('section', 'Section heading to extract (case-insensitive, e.g. "model", "mounts"). Omit to read the whole file.', required: false),
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

                if ($section === '') {
                    return $this->readWholeDoc($file, $filePath);
                }

                $sectionContent = $this->extractSectionFromIndex($file, $section, $filePath);

                if ($sectionContent !== null) {
                    return ToolResult::success($sectionContent);
                }

                $sectionContent = $this->extractSectionFromFile($filePath, $section);

                if ($sectionContent !== null) {
                    return ToolResult::success($sectionContent);
                }

                $headings = $this->extractHeadings($filePath);

                if ($headings === []) {
                    return ToolResult::error("Section '{$section}' not found in {$file}");
                }

                $closest = $this->findClosestHeading($section, $headings);
                $msg = "Section '{$section}' not found in {$file}.";

                if ($closest !== null) {
                    $msg .= " Did you mean: \"{$closest}\"?";
                }

                return ToolResult::error($msg . ' Available sections: ' . implode(', ', $headings));
            },
        );
    }

    private function docsSearchTool(): ToolInterface
    {
        return new Tool(
            name: 'coqui_docs_search',
            description: 'Full-text search across Coqui documentation. Returns matching docs with the nearest heading, line number, and a snippet — feed the path and heading straight into coqui_docs_read.',
            parameters: [
                new StringParameter('query', 'Text to search for (case-insensitive substring)', required: true),
                // No schema minimum/maximum: an out-of-range limit should clamp to the
                // bound, not fail the call. The callback clamps.
                new NumberParameter('limit', 'Maximum results to return (default 20, clamped to a maximum of 50)', required: false, integer: true),
            ],
            callback: function (array $input): ToolResult {
                $query = trim((string) ($input['query'] ?? ''));

                if ($query === '') {
                    return ToolResult::error('Query is required');
                }

                $limit = (int) ($input['limit'] ?? self::SEARCH_DEFAULT_LIMIT);
                $limit = max(1, min($limit, self::SEARCH_MAX_LIMIT));

                $matches = $this->searchDocs($query);
                $total = count($matches);
                $results = array_slice($matches, 0, $limit);

                return ToolResult::json([
                    'query' => $query,
                    'total_matches' => $total,
                    'truncated' => $total > $limit,
                    'results' => $results,
                ]);
            },
        );
    }

    /**
     * Search every indexed doc for a case-insensitive substring.
     *
     * Heading, title, and description hits rank above body hits; ties break on
     * path then line, so results are deterministic.
     *
     * @return list<array{path: string, heading: string, line: int, snippet: string}>
     */
    private function searchDocs(string $query): array
    {
        $needle = strtolower($query);
        $ranked = [];

        foreach ($this->docsIndex->load()['files'] as $entry) {
            $filePath = $this->normalizedRoot . '/' . $entry['path'];
            $lines = file($filePath, FILE_IGNORE_NEW_LINES);

            if ($lines === false) {
                continue;
            }

            $metaHit = str_contains(strtolower($entry['title']), $needle)
                || str_contains(strtolower($entry['description']), $needle);

            foreach ($lines as $i => $line) {
                if (!str_contains(strtolower($line), $needle)) {
                    continue;
                }

                $heading = $this->headingForLine($entry['sections'], $i + 1);
                $isHeadingHit = str_contains(strtolower($heading), $needle);

                $ranked[] = [
                    'rank' => $isHeadingHit || $metaHit ? 0 : 1,
                    'result' => [
                        'path' => $entry['path'],
                        'heading' => $heading,
                        'line' => $i + 1,
                        'snippet' => $this->snippet($line),
                    ],
                ];
            }
        }

        usort($ranked, static function (array $a, array $b): int {
            return [$a['rank'], $a['result']['path'], $a['result']['line']]
                <=> [$b['rank'], $b['result']['path'], $b['result']['line']];
        });

        return array_map(static fn (array $row): array => $row['result'], $ranked);
    }

    /**
     * The nearest heading at or above a 1-based line number.
     *
     * @param list<array{heading: string, level: int, line_start: int, line_end: int}> $sections
     */
    private function headingForLine(array $sections, int $line): string
    {
        $heading = '';

        foreach ($sections as $section) {
            if ($section['line_start'] > $line) {
                break;
            }

            $heading = $section['heading'];
        }

        return $heading;
    }

    /**
     * A single-line excerpt, cut to a character bound rather than a byte one.
     *
     * Slicing by byte can split a multibyte sequence — the docs are full of
     * em-dashes — and ToolResult::json degrades an unencodable payload to a
     * bare '{}'. One stray character would silently empty the whole response.
     */
    private function snippet(string $line): string
    {
        $trimmed = trim($line);

        if (mb_strlen($trimmed) <= self::SEARCH_SNIPPET_CHARS) {
            return $trimmed;
        }

        return mb_substr($trimmed, 0, self::SEARCH_SNIPPET_CHARS) . '…';
    }

    /**
     * Return a whole doc, or — when it exceeds the read cap — its section list.
     *
     * Truncating silently returned ~46% of docs/API.md with no signal that
     * anything was missing. An honest section list is strictly more useful
     * than a headless half of a file.
     */
    private function readWholeDoc(string $file, string $filePath): ToolResult
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            return ToolResult::error("Failed to read file: {$file}");
        }

        if (strlen($content) <= self::MAX_READ_BYTES) {
            return ToolResult::success($content);
        }

        $headings = $this->extractHeadings($filePath);

        if ($headings === []) {
            return ToolResult::error(sprintf(
                '%s is %d bytes, over the %d byte read limit, and has no headings to select from.',
                $file,
                strlen($content),
                self::MAX_READ_BYTES,
            ));
        }

        return ToolResult::success(sprintf(
            "%s is %d bytes — too large to return whole. Re-read it with a `section` from this list:\n\n%s",
            $file,
            strlen($content),
            implode("\n", array_map(static fn (string $h): string => "- {$h}", $headings)),
        ));
    }

    // ──────────────────────────────────────────────
    //  Documentation Helpers
    // ──────────────────────────────────────────────

    /**
     * Extract a section using line ranges from the documentation index.
     *
     * Matching priority: exact heading (case-insensitive) → substring match.
     *
     * Sourced from DocumentationIndex rather than config/documentation.json so a
     * fresh checkout — where the generated index does not exist — still gets
     * index-based line ranges instead of silently losing them.
     */
    private function extractSectionFromIndex(string $file, string $section, string $filePath): ?string
    {
        $sectionLower = strtolower($section);
        $fileSections = null;

        foreach ($this->docsIndex->load()['files'] as $entry) {
            if ($entry['path'] === $file) {
                $fileSections = $entry['sections'];
                break;
            }
        }

        if ($fileSections === null) {
            return null;
        }

        // Pass 1: exact match (case-insensitive, backtick-stripped)
        foreach ($fileSections as $sec) {
            $heading = $sec['heading'];
            $headingLower = strtolower($heading);
            $headingStripped = strtolower(trim($heading, '`'));

            if ($headingLower === $sectionLower || $headingStripped === $sectionLower) {
                return $this->readSectionLines($sec, $filePath);
            }
        }

        // Pass 2: substring match (first heading containing the search term)
        foreach ($fileSections as $sec) {
            $headingStripped = strtolower(trim($sec['heading'], '`'));

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
        return $this->readLineRange($filePath, $sec['line_start'], $sec['line_end']);
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
     *
     * @return list<string>
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
}
