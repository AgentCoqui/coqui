<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Prompt;

use CoquiBot\Coqui\Config\ProfilePreferences;
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
                if ($filename !== 'security.md' || $this->hasUsableSecurityOverride($profileFile)) {
                    return $profileFile;
                }
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
        if (!$this->shouldIncludePromptSection('soul')) {
            return null;
        }

        if ($this->isPromptSectionStubbed('soul')) {
            return $this->buildStubContent('soul');
        }

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
     * Build the backstory.md content (identity context, continuity markers).
     *
     * Resolves backstory.md via the standard 3-tier chain (profile → workspace → default).
     * Returns null if no backstory.md exists in any tier.
     */
    public function buildBackstoryContent(): ?string
    {
        if (!$this->shouldIncludePromptSection('backstory')) {
            return null;
        }

        if ($this->isPromptSectionStubbed('backstory')) {
            return $this->buildStubContent('backstory');
        }

        $path = $this->resolvePromptFile('backstory.md');
        if ($path === null) {
            return null;
        }

        $content = $this->readPromptContent($path);
        if ($content === null) {
            return null;
        }

        return $this->substitutePlaceholders($content);
    }

    /**
     * Build the persona context block from context/*.md.
     *
     * Persona-owned: read only from the active profile dir (no fallback).
     * Returns null when disabled, stubbed-empty, or no context files exist.
     */
    public function buildContextContent(): ?string
    {
        if (!$this->shouldIncludePromptSection('context')) {
            return null;
        }

        if ($this->isPromptSectionStubbed('context')) {
            return $this->buildStubContent('context');
        }

        if ($this->profilePath === null) {
            return null;
        }

        $content = (new PersonaContextReader())->read($this->profilePath);
        if ($content === null) {
            return null;
        }

        return $this->substitutePlaceholders($content);
    }

    /**
     * Build the body content (everything except soul.md and backstory.md).
     *
     * Returns base.md + tool sections + security.md + done.md composed
     * in standard priority order.
     */
    public function buildBodyContent(): string
    {
        $sections = [];

        // Base — operational instructions, environment, delegation rules
        if ($this->shouldIncludePromptSection('base')) {
            if ($this->isPromptSectionStubbed('base')) {
                $sections[] = $this->buildStubContent('base');
            } else {
                $sections[] = $this->loadWithFallback('base.md');
            }
        }

        // Tool-specific sections (auto-discovered, alphabetical, filtered by exclusions)
        // Profile tool overrides are merged: profile files replace same-named defaults.
        if ($this->shouldIncludePromptSection('tools')) {
            if ($this->isPromptSectionStubbed('tools')) {
                $sections[] = $this->buildStubContent('tools');
            } else {
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
            }
        }

        // Security — near the end
        $securityPath = $this->resolvePromptFile('security.md');
        if ($securityPath !== null) {
            $securityContent = file_get_contents($securityPath);
            if ($securityContent !== false && trim($securityContent) !== '') {
                $sections[] = $this->substitutePlaceholders(trim($securityContent));
            }
        }

        // Final guidelines and done instructions — always last
        if ($this->shouldIncludePromptSection('done')) {
            if ($this->isPromptSectionStubbed('done')) {
                $sections[] = $this->buildStubContent('done');
            } else {
                $donePath = $this->resolvePromptFile('done.md');
                if ($donePath !== null) {
                    $doneContent = file_get_contents($donePath);
                    if ($doneContent !== false) {
                        $sections[] = $this->substitutePlaceholders(trim($doneContent));
                    }
                }
            }
        }

        return implode("\n\n", $sections);
    }

    /**
     * Build the complete orchestrator system prompt.
     *
     * Composes soul → backstory → body (base + tools + security + done) in standard order.
     */
    public function buildSystemPrompt(): string
    {
        $sections = [];

        $soul = $this->buildSoulContent();
        if ($soul !== null) {
            $sections[] = $soul;
        }

        $backstory = $this->buildBackstoryContent();
        if ($backstory !== null) {
            $sections[] = $backstory;
        }

        $context = $this->buildContextContent();
        if ($context !== null) {
            $sections[] = $context;
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
        if ($this->shouldIncludePromptSection('soul')) {
            if ($this->isPromptSectionStubbed('soul')) {
                $sections[] = $this->buildStubSectionEntry('soul', 'Soul', 'soul');
            } else {
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
            }
        }

        // Backstory — identity context, continuity markers, relational anchors
        if ($this->shouldIncludePromptSection('backstory')) {
            if ($this->isPromptSectionStubbed('backstory')) {
                $sections[] = $this->buildStubSectionEntry('backstory', 'Backstory', 'backstory');
            } else {
                $backstoryPath = $this->resolvePromptFile('backstory.md');
                if ($backstoryPath !== null) {
                    $backstoryContent = file_get_contents($backstoryPath);
                    if ($backstoryContent !== false && trim($backstoryContent) !== '') {
                        $sections[] = [
                            'id' => 'backstory',
                            'title' => 'Backstory',
                            'content' => $this->substitutePlaceholders(trim($backstoryContent)),
                            'source' => $backstoryPath,
                        ];
                    }
                }
            }
        }

        // Context — supplementary persona notes (persona dir only)
        if ($this->shouldIncludePromptSection('context')) {
            if ($this->isPromptSectionStubbed('context')) {
                $sections[] = $this->buildStubSectionEntry('context', 'Context', 'context');
            } elseif ($this->profilePath !== null) {
                $contextContent = (new PersonaContextReader())->read($this->profilePath);
                if ($contextContent !== null) {
                    $sections[] = [
                        'id' => 'context',
                        'title' => 'Context',
                        'content' => $this->substitutePlaceholders($contextContent),
                        'source' => rtrim($this->profilePath, '/') . '/context',
                    ];
                }
            }
        }

        if ($this->shouldIncludePromptSection('base')) {
            if ($this->isPromptSectionStubbed('base')) {
                $sections[] = $this->buildStubSectionEntry('base', 'Base Prompt', 'base');
            } else {
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
            }
        }

        if ($this->shouldIncludePromptSection('tools')) {
            if ($this->isPromptSectionStubbed('tools')) {
                $sections[] = $this->buildStubSectionEntry('tools.stub', 'Tool Prompts', 'tools');
            } else {
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
            }
        }

        $securityPath = $this->resolvePromptFile('security.md');
        if ($securityPath !== null) {
            $securityContent = file_get_contents($securityPath);
            if ($securityContent !== false && trim($securityContent) !== '') {
                $sections[] = [
                    'id' => 'security',
                    'title' => 'Security Guardrails',
                    'content' => $this->substitutePlaceholders(trim($securityContent)),
                    'source' => $securityPath,
                ];
            }
        }

        if ($this->shouldIncludePromptSection('done')) {
            if ($this->isPromptSectionStubbed('done')) {
                $sections[] = $this->buildStubSectionEntry('done', 'Completion Rules', 'done');
            } else {
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

    private function shouldIncludePromptSection(string $section): bool
    {
        return $this->profilePreferences()?->isPromptSectionEnabled($section, true) ?? true;
    }

    private function isPromptSectionStubbed(string $section): bool
    {
        return $this->profilePreferences()?->isPromptSectionStubbed($section) === true;
    }

    private function profilePreferences(): ?ProfilePreferences
    {
        if ($this->profilePath === null) {
            return null;
        }

        return ProfilePreferences::fromProfilePath($this->profilePath);
    }

    private function hasUsableSecurityOverride(string $path): bool
    {
        $content = file_get_contents($path);

        return $content !== false && trim($content) !== '';
    }

    private function promptPolicySource(): string
    {
        return $this->profilePath !== null
            ? rtrim($this->profilePath, '/') . '/preferences.json'
            : 'profile_preferences';
    }

    private function buildStubContent(string $section): string
    {
        return match ($section) {
            'soul' => '# Soul' . "\n\n" . 'Core identity instructions are intentionally condensed for this profile.',
            'backstory' => '## Backstory' . "\n\n" . 'Narrative continuity is intentionally condensed for this profile.',
            'base' => '## Base Prompt' . "\n\n" . 'Operational guidance is intentionally condensed for this profile.',
            'tools' => '## Tool Prompts' . "\n\n" . 'Tool guidance is intentionally condensed for this profile. Use tool schemas and discovery surfaces to choose tools.',
            'done' => '## Completion Rules' . "\n\n" . 'Completion guidance is intentionally condensed for this profile.',
            default => '## Prompt Section' . "\n\n" . 'This prompt section is intentionally condensed for this profile.',
        };
    }

    /**
     * @return array{id: string, title: string, content: string, source: string}
     */
    private function buildStubSectionEntry(string $id, string $title, string $section): array
    {
        return [
            'id' => $id,
            'title' => $title,
            'content' => $this->buildStubContent($section),
            'source' => $this->promptPolicySource(),
        ];
    }

    private function humanizeName(string $name): string
    {
        return ucwords(str_replace(['-', '_'], ' ', $name));
    }
}
