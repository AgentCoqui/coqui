<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Prompt;

use CoquiBot\Coqui\Config\ProfileParser;

/**
 * Discovers and composes system prompts from markdown files.
 *
 * Loads prompt sections from the `prompts/` directory, supports
 * placeholder substitution, and assembles the final system prompt
 * for the orchestrator agent.
 *
 * Soul resolution: soul.md defines the bot's core identity, values, and
 * personality. Users can override it by placing a soul.md in
 * workspace/prompts/. The resolution order is:
 *   1. Workspace prompts dir (e.g. workspace/prompts/soul.md)
 *   2. Default prompts/soul.md (shipped with Coqui)
 */
final readonly class PromptLoader
{
    /**
     * @param string $promptsDir Absolute path to the prompts/ directory.
     * @param array<string, string> $placeholders Map of {{key}} → value for substitution.
    * @param ?string $workspacePath Absolute path to the workspace directory (for prompts/soul.md override resolution).
     * @param list<string> $excludeToolPromptSlugs Tool prompt file slugs to skip (e.g. ['loops'] skips tools/loops.md).
     * @param ?string $profilePath Absolute path to the active profile directory (for 3-tier prompt override resolution).
     */
    public function __construct(
        private string $promptsDir,
        private array $placeholders = [],
        private ?string $workspacePath = null,
        private array $excludeToolPromptSlugs = [],
        private ?string $profilePath = null,
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
     * Resolve the soul.md file path with profile and user-override support.
     *
     * Resolution order (3-tier fallback):
     *   1. Active profile: {profilePath}/soul.md
     *   2. Workspace override: {workspace}/prompts/soul.md
     *   3. Default: {promptsDir}/soul.md
     *
     * @return ?string Absolute path to the resolved soul.md, or null if not found.
     */
    public function resolveSoulPath(): ?string
    {
        return $this->resolvePromptFile('soul.md');
    }

    /**
     * Resolve a prompt file path using the 3-tier fallback chain.
     *
     * Resolution order:
     *   1. Active profile directory (if set)
     *   2. Workspace prompts directory (if workspacePath set)
     *   3. Default prompts directory
     *
     * @return ?string Absolute path to the resolved file, or null if not found.
     */
    public function resolvePromptFile(string $filename): ?string
    {
        // Tier 1: Profile override
        if ($this->profilePath !== null) {
            $profileFile = rtrim($this->profilePath, '/') . '/' . $filename;
            if (is_file($profileFile)) {
                return $profileFile;
            }
        }

        // Tier 2: Workspace override
        if ($this->workspacePath !== null) {
            $workspaceFile = rtrim($this->workspacePath, '/') . '/prompts/' . $filename;
            if (is_file($workspaceFile)) {
                return $workspaceFile;
            }
        }

        // Tier 3: Default prompts directory
        $defaultPath = $this->promptsDir . '/' . $filename;
        if (is_file($defaultPath)) {
            return $defaultPath;
        }

        return null;
    }
    /**
     * Build just the soul.md content (core identity section).
     *
     * Returns the processed soul text (with placeholder substitution and
     * profile frontmatter stripped), or null if no soul.md exists.
     */
    public function buildSoulContent(): ?string
    {
        $soulPath = $this->resolveSoulPath();
        if ($soulPath === null) {
            return null;
        }

        $content = $this->readPromptContent($soulPath, supportsProfileSoulFrontmatter: true);
        if ($content === null) {
            return null;
        }

        return $this->substitutePlaceholders($content);
    }

    /**
     * Build the body content (everything except soul.md).
     *
     * Returns base.md + tool sections + security.md + done.md composed
     * in standard priority order.
     */
    public function buildBodyContent(): string
    {
        $sections = [];

        // Base — operational instructions, environment, delegation rules
        $sections[] = $this->loadWithFallback('base.md');

        // Tool-specific sections (auto-discovered, alphabetical, filtered by exclusions)
        // Profile tool overrides are merged: profile files replace same-named defaults.
        $toolEntries = $this->discoverSectionEntriesWithProfileMerge('tools');
        $filteredToolContent = [];
        foreach ($toolEntries as $entry) {
            $slug = pathinfo($entry['filename'], PATHINFO_FILENAME);
            if (in_array($slug, $this->excludeToolPromptSlugs, true)) {
                continue;
            }
            $filteredToolContent[] = $entry['content'];
        }
        if ($filteredToolContent !== []) {
            $sections[] = implode("\n\n", $filteredToolContent);
        }

        // Security — near the end
        $securityPath = $this->resolvePromptFile('security.md');
        if ($securityPath !== null) {
            $securityContent = file_get_contents($securityPath);
            if ($securityContent !== false) {
                $sections[] = $this->substitutePlaceholders(trim($securityContent));
            }
        }

        // Final guidelines and done instructions — always last
        $donePath = $this->resolvePromptFile('done.md');
        if ($donePath !== null) {
            $doneContent = file_get_contents($donePath);
            if ($doneContent !== false) {
                $sections[] = $this->substitutePlaceholders(trim($doneContent));
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * Build the complete orchestrator system prompt.
     *
     * Composes soul → body (base + tools + security + done) in standard order.
     */
    public function buildSystemPrompt(): string
    {
        $sections = [];

        $soul = $this->buildSoulContent();
        if ($soul !== null) {
            $sections[] = $soul;
        }

        $body = $this->buildBodyContent();
        if ($body !== '') {
            $sections[] = $body;
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

        // Soul — core identity, values, personality (profile → workspace → default)
        $soulPath = $this->resolveSoulPath();
        if ($soulPath !== null) {
            $content = $this->readPromptContent($soulPath, supportsProfileSoulFrontmatter: true);
            if ($content !== null) {
                $sections[] = [
                    'id' => 'soul',
                    'title' => 'Soul',
                    'content' => $this->substitutePlaceholders($content),
                    'source' => $soulPath,
                ];
            }
        }

        $basePath = $this->resolvePromptFile('base.md');
        if ($basePath !== null) {
            $baseContent = file_get_contents($basePath);
            if ($baseContent !== false) {
                $sections[] = [
                    'id' => 'base',
                    'title' => 'Base Prompt',
                    'content' => $this->substitutePlaceholders(trim($baseContent)),
                    'source' => $basePath,
                ];
            }
        }

        foreach ($this->discoverSectionEntriesWithProfileMerge('tools') as $entry) {
            $slug = pathinfo($entry['filename'], PATHINFO_FILENAME);
            if (in_array($slug, $this->excludeToolPromptSlugs, true)) {
                continue;
            }
            $sections[] = [
                'id' => 'tools.' . pathinfo($entry['filename'], PATHINFO_FILENAME),
                'title' => 'Tool Prompt: ' . $entry['title'],
                'content' => $entry['content'],
                'source' => $entry['source'],
            ];
        }

        $securityPath = $this->resolvePromptFile('security.md');
        if ($securityPath !== null) {
            $securityContent = file_get_contents($securityPath);
            if ($securityContent !== false) {
                $sections[] = [
                    'id' => 'security',
                    'title' => 'Security Guardrails',
                    'content' => $this->substitutePlaceholders(trim($securityContent)),
                    'source' => $securityPath,
                ];
            }
        }

        $donePath = $this->resolvePromptFile('done.md');
        if ($donePath !== null) {
            $doneContent = file_get_contents($donePath);
            if ($doneContent !== false) {
                $sections[] = [
                    'id' => 'done',
                    'title' => 'Completion Rules',
                    'content' => $this->substitutePlaceholders(trim($doneContent)),
                    'source' => $donePath,
                ];
            }
        }

        return $sections;
    }

    /**
     * Load a prompt file using the 3-tier fallback chain, with placeholder substitution.
     *
     * @throws PromptNotFoundException When the file does not exist in any tier.
     */
    private function loadWithFallback(string $filename): string
    {
        $path = $this->resolvePromptFile($filename);

        if ($path === null) {
            throw PromptNotFoundException::forFile($filename, $this->promptsDir);
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw PromptNotFoundException::forFile($filename, $this->promptsDir);
        }

        return $this->substitutePlaceholders(trim($content));
    }

    private function readPromptContent(string $path, bool $supportsProfileSoulFrontmatter = false): ?string
    {
        if ($supportsProfileSoulFrontmatter && $this->profilePath !== null) {
            $expectedProfileSoulPath = rtrim($this->profilePath, '/') . '/soul.md';
            if ($path === $expectedProfileSoulPath) {
                return (new ProfileParser())->readFile($path)['body'];
            }
        }

        $content = file_get_contents($path);

        return $content === false ? null : trim($content);
    }

    /**
     * Discover section entries with profile-aware merging.
     *
     * When a profile is active and provides files in the same section subdirectory
     * (e.g. profiles/{name}/tools/memory.md), those files override same-named
     * defaults from the base prompts directory.
     *
     * @return array<int, array{id: string, title: string, filename: string, content: string, source: string}>
     */
    private function discoverSectionEntriesWithProfileMerge(string $section): array
    {
        // Start with default entries (keyed by filename for merge)
        $entriesByFilename = [];
        foreach ($this->discoverSectionEntries($section) as $entry) {
            $entriesByFilename[$entry['filename']] = $entry;
        }

        // Merge profile overrides if a profile is active
        if ($this->profilePath !== null) {
            $profileSectionDir = rtrim($this->profilePath, '/') . '/' . $section;
            if (is_dir($profileSectionDir)) {
                $files = glob($profileSectionDir . '/*.md');
                if ($files !== false) {
                    sort($files);
                    foreach ($files as $file) {
                        $content = file_get_contents($file);
                        if ($content === false) {
                            continue;
                        }

                        $filename = basename($file);
                        $slug = pathinfo($filename, PATHINFO_FILENAME);

                        $entriesByFilename[$filename] = [
                            'id' => sprintf('%s.%s', str_replace('/', '.', $section), $slug),
                            'title' => $this->humanizeName($slug),
                            'filename' => $filename,
                            'content' => $this->substitutePlaceholders(trim($content)),
                            'source' => $file,
                        ];
                    }
                }
            }
        }

        // Re-sort by filename and return values
        ksort($entriesByFilename);
        return array_values($entriesByFilename);
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
