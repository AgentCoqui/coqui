<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Prompt;

/**
 * Discovers and composes system prompts from markdown files.
 *
 * Loads prompt sections from the `prompts/` directory, supports
 * placeholder substitution, and assembles the final system prompt
 * for the orchestrator agent.
 */
final readonly class PromptLoader
{
    /**
     * @param string $promptsDir Absolute path to the prompts/ directory.
     * @param array<string, string> $placeholders Map of {{key}} → value for substitution.
     */
    public function __construct(
        private string $promptsDir,
        private array $placeholders = [],
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
     * Build the complete orchestrator system prompt.
     *
     * Loads base.md first, then all tool prompts from tools/,
     * then security.md, then done.md.
     */
    public function buildSystemPrompt(): string
    {
        $sections = [];

        // Core identity and conversational behavior — always first
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
