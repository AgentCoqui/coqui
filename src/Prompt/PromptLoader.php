<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Prompt;

/**
 * Discovers and composes system prompts from markdown files.
 *
 * Loads prompt sections from the `prompts/` directory, supports
 * placeholder substitution, and assembles the final system prompt
 * for the orchestrator agent.
 *
 * Soul resolution: soul.md defines the bot's core identity, values, and
 * personality. Users can override it by placing a soul.md (case-insensitive)
 * in their workspace root or workspace/prompts/. The resolution order is:
 *   1. Workspace root (e.g. workspace/soul.md, workspace/SOUL.md)
 *   2. Workspace prompts dir (e.g. workspace/prompts/soul.md)
 *   3. Default prompts/soul.md (shipped with Coqui)
 */
final readonly class PromptLoader
{
    /**
     * @param string $promptsDir Absolute path to the prompts/ directory.
     * @param array<string, string> $placeholders Map of {{key}} → value for substitution.
     * @param ?string $workspacePath Absolute path to the workspace directory (for soul.md override resolution).
     */
    public function __construct(
        private string $promptsDir,
        private array $placeholders = [],
        private ?string $workspacePath = null,
    ) {}

    /**
     * Load and render a single prompt file with placeholder substitution.
     *
     * @throws PromptNotFoundException When the file does not exist.
     */
    public function load(string $filename): string
    {
        $path = $this->promptsDir . '/' . $filename;

        if (!is_file($path)) {
            throw PromptNotFoundException::forFile($filename, $this->promptsDir);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw PromptNotFoundException::forFile($filename, $this->promptsDir);
        }

        return $this->substitutePlaceholders(trim($content));
    }

    /**
     * Load a subsection file from a subdirectory (e.g. "tools/workspace.md").
     *
     * @throws PromptNotFoundException When the file does not exist.
     */
    public function loadSection(string $section, string $filename): string
    {
        return $this->load($section . '/' . $filename);
    }

    /**
     * Discover and load all markdown files in a subdirectory.
     *
     * @return string[] Loaded content of each file, sorted by filename.
     */
    public function discoverSection(string $section): array
    {
        $dir = $this->promptsDir . '/' . $section;

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $sections = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content !== false) {
                $sections[] = $this->substitutePlaceholders(trim($content));
            }
        }

        return $sections;
    }

    /**
     * Discover section entries with metadata.
     *
     * @return array<int, array{id: string, title: string, filename: string, content: string, source: string}>
     */
    public function discoverSectionEntries(string $section): array
    {
        $dir = $this->promptsDir . '/' . $section;

        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.md');

        if ($files === false || $files === []) {
            return [];
        }

        sort($files);

        $entries = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $filename = basename($file);
            $slug = pathinfo($filename, PATHINFO_FILENAME);

            $entries[] = [
                'id' => sprintf('%s.%s', str_replace('/', '.', $section), $slug),
                'title' => $this->humanizeName($slug),
                'filename' => $filename,
                'content' => $this->substitutePlaceholders(trim($content)),
                'source' => $file,
            ];
        }

        return $entries;
    }

    /**
     * Compose multiple files into a single string separated by blank lines.
     *
     * @param string[] $filenames Relative paths within the prompts directory.
     */
    public function compose(array $filenames): string
    {
        $sections = [];

        foreach ($filenames as $filename) {
            $sections[] = $this->load($filename);
        }

        return implode("\n\n", $sections);
    }

    /**
     * Resolve the soul.md file path with user-override support.
     *
     * Checks for a user-provided soul.md (case-insensitive) in the workspace
     * root and workspace/prompts/ directory, falling back to the default
     * prompts/soul.md. Returns null if no soul.md exists anywhere.
     *
     * @return ?string Absolute path to the resolved soul.md, or null if not found.
     */
    public function resolveSoulPath(): ?string
    {
        // Check workspace root for a user-override (case-insensitive)
        if ($this->workspacePath !== null) {
            $match = $this->findCaseInsensitive($this->workspacePath, 'soul.md');
            if ($match !== null) {
                return $match;
            }

            // Check workspace/prompts/ directory (case-insensitive)
            $promptsDir = rtrim($this->workspacePath, '/') . '/prompts';
            if (is_dir($promptsDir)) {
                $match = $this->findCaseInsensitive($promptsDir, 'soul.md');
                if ($match !== null) {
                    return $match;
                }
            }
        }

        // Fall back to the default prompts/soul.md
        $defaultPath = $this->promptsDir . '/soul.md';
        if (is_file($defaultPath)) {
            return $defaultPath;
        }

        return null;
    }

    /**
     * Find a file by name (case-insensitive) in a directory.
     *
     * @return ?string Absolute path to the matched file, or null if not found.
     */
    private function findCaseInsensitive(string $directory, string $filename): ?string
    {
        $target = strtolower($filename);
        $files = glob($directory . '/*.md');

        if ($files === false || $files === []) {
            return null;
        }

        foreach ($files as $file) {
            if (strtolower(basename($file)) === $target) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Build the complete orchestrator system prompt.
     *
     * Loads soul.md first (core identity), then base.md (operational instructions),
     * then all tool prompts from tools/, then security.md, then done.md.
     */
    public function buildSystemPrompt(): string
    {
        $sections = [];

        // Soul — core identity, values, and personality — always first
        $soulPath = $this->resolveSoulPath();
        if ($soulPath !== null) {
            $content = file_get_contents($soulPath);
            if ($content !== false) {
                $sections[] = $this->substitutePlaceholders(trim($content));
            }
        }

        // Base — operational instructions, environment, delegation rules
        $sections[] = $this->load('base.md');

        // Tool-specific sections (auto-discovered, alphabetical)
        $toolSections = $this->discoverSection('tools');
        if ($toolSections !== []) {
            $sections[] = implode("\n\n", $toolSections);
        }

        // Security — near the end
        if (is_file($this->promptsDir . '/security.md')) {
            $sections[] = $this->load('security.md');
        }

        // Final guidelines and done instructions — always last
        if (is_file($this->promptsDir . '/done.md')) {
            $sections[] = $this->load('done.md');
        }

        return implode("\n\n", $sections);
    }

    /**
     * Build the complete orchestrator system prompt as typed file sections.
     *
     * @return array<int, array{id: string, title: string, content: string, source: string}>
     */
    public function buildSystemPromptSections(): array
    {
        $sections = [];

        // Soul — core identity, values, personality (user-overridable)
        $soulPath = $this->resolveSoulPath();
        if ($soulPath !== null) {
            $content = file_get_contents($soulPath);
            if ($content !== false) {
                $sections[] = [
                    'id' => 'soul',
                    'title' => 'Soul',
                    'content' => $this->substitutePlaceholders(trim($content)),
                    'source' => $soulPath,
                ];
            }
        }

        $sections[] = [
            'id' => 'base',
            'title' => 'Base Prompt',
            'content' => $this->load('base.md'),
            'source' => $this->promptsDir . '/base.md',
        ];

        foreach ($this->discoverSectionEntries('tools') as $entry) {
            $sections[] = [
                'id' => 'tools.' . pathinfo($entry['filename'], PATHINFO_FILENAME),
                'title' => 'Tool Prompt: ' . $entry['title'],
                'content' => $entry['content'],
                'source' => $entry['source'],
            ];
        }

        if (is_file($this->promptsDir . '/security.md')) {
            $sections[] = [
                'id' => 'security',
                'title' => 'Security Guardrails',
                'content' => $this->load('security.md'),
                'source' => $this->promptsDir . '/security.md',
            ];
        }

        if (is_file($this->promptsDir . '/done.md')) {
            $sections[] = [
                'id' => 'done',
                'title' => 'Completion Rules',
                'content' => $this->load('done.md'),
                'source' => $this->promptsDir . '/done.md',
            ];
        }

        return $sections;
    }

    /**
     * Replace {{placeholder}} tokens with their configured values.
     */
    private function substitutePlaceholders(string $content): string
    {
        foreach ($this->placeholders as $key => $value) {
            $content = str_replace('{{' . $key . '}}', $value, $content);
        }

        return $content;
    }

    private function humanizeName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
