# Source Map Removal + `coqui_docs_*` Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Delete `config/source.json` and all read-only source access, and route Coqui's self-knowledge through a genuinely generated docs index served by three `coqui_docs_*` tools.

**Architecture:** A new `src/Config/DocumentationIndex.php` derives the index from disk — globbing `docs/*.md` + `README.md` + `AGENTS.md`, taking `title`/`description` from YAML frontmatter (parsed by the existing `SkillParser`) with an H1 + first-paragraph fallback, and extracting H1–H4 section line ranges. `scripts/generate-documentation-index.php` becomes a thin wrapper that serialises `build()` to `config/documentation.json`; `CoquiDocsToolkit` calls `load()`, which uses that generated file as a cache and falls back to `build()` when it is absent. One derivation, two consumers — the hardcoded allowlist that hid eight docs cannot come back, because there is no list.

**Tech Stack:** PHP 8.4 (strict types, `final` by default, constructor injection), Pest, PHPStan level 8, Composer.

## Global Constraints

- `declare(strict_types=1);` in every PHP file; `final` by default; one class per file; 4-space indentation.
- Constructor injection over static state. Early returns over deep nesting. Explicit exceptions over `null` as an error signal.
- PHP 8.4. No new Composer dependencies — prefer PHP built-ins, SPL, and existing project utilities.
- Comments explain **why**, not what.
- Baseline that must not regress: `composer test` = **2370 passing**; `composer analyse` = **[OK] 382/382**.
- Work happens in the worktree `/home/carmelo/Projects/CoquiBot/Core/coqui-docs-tools` on branch `feat/coqui-docs-tools`. Never touch the main checkout at `Core/coqui`.
- **Before Task 1, run `composer install` in the worktree.** A fresh worktree has no `vendor/`, so `pest`, `phpstan`, and the php-agents classes are all absent until it does. Then run `composer test` once to confirm the 2370 baseline before changing anything.
- Commit order is **C → B → A** (generator → tools → removal). Every commit must leave the suite green.
- The replacement tool guidance must **not** reproduce a "start here, load everything" instruction — that framing is what made the source map expensive.

## Decisions on the Spec's Open Items

Resolved here so implementers do not re-litigate them:

1. **Fallback when `config/documentation.json` is absent — YES, for all three tools.** The file is git-ignored (`.gitignore:79`) and generated at build time, so a fresh checkout genuinely has none — this was verified, not assumed. Rather than bolting a fallback onto each tool, `DocumentationIndex::load()` owns the decision: return the generated JSON when it parses, otherwise `build()` from disk. The generated file stays as a **build-time cache** (it saves globbing and parsing ~20 files per tool call, and release/Docker builds already produce it), but nothing depends on its existence.
2. **Frontmatter parsing — reuse `SkillParser`.** `SkillParser::parseFrontmatter()` (`src/Config/SkillParser.php:67`) is already public, stateless, and handles the `key: value` + quoted-value subset needed for `title`/`description`. It **throws** `SkillParseException` when frontmatter is absent, which is exactly the signal for the H1 fallback. Do **not** write a second parser. Do **not** use `SkillParser::validate()` — its `ALLOWED_FIELDS` check is SKILL.md-specific and would reject doc frontmatter.
3. **Search scope — headings, titles, descriptions, and bodies, ranked heading-first.** A heading/title/description hit outranks a body hit; ties break on document path then line number, so results are deterministic.
4. **Search result bound — default 20, hard cap 50, and always reported when it truncates.** Never a silent cap. The whole point of this change is that silent truncation destroys trust.

## File Structure

**Created:**
- `src/Config/DocumentationIndex.php` — the single derivation of the docs index. Globs, parses frontmatter, extracts sections, and serves both the generator and the toolkit. One responsibility: *what documentation exists and where its sections are*.
- `tests/Unit/Config/DocumentationIndexTest.php` — builder behaviour against temp fixtures.
- `tests/Unit/Config/DocumentationIndexRealDocsTest.php` — regression tests against the **real** `docs/` tree (the check that would have caught the LOOPS.md blind spot).
- `prompts/tools/coqui-docs.md` — replaces `prompts/tools/coqui-source.md`.
- `tests/Unit/Toolkit/CoquiDocsToolkitTest.php` — replaces `CoquiSourceToolkitTest.php` (git mv, then rewrite).

**Modified:**
- `scripts/generate-documentation-index.php` — 89 lines of allowlist + inline parsing collapse to a wrapper around `DocumentationIndex::build()`.
- `src/Toolkit/CoquiSourceToolkit.php` → `src/Toolkit/CoquiDocsToolkit.php` (git mv in Task 6).
- All 18 `docs/*.md` — gain frontmatter.
- Wiring: `src/Agent/OrchestratorAgent.php:162, :484, :636-641`; `src/Contract/CoquiDefaults.php:200`; `src/Agent/AgentRunner.php:2172`; `src/Tool/SpawnAgentTool.php:319, :333, :338`.
- `prompts/tools/workspace.md:10-11`; `config/roles/plan.md:28`; `AGENTS.md`.
- Fixture strings in `tests/Unit/Config/RoleParserTest.php:134,141` and `tests/Unit/Config/RoleToolkitResolverTest.php:65,69`.

**Deleted:**
- `config/source.json`
- `tests/Unit/Config/SourceMapIntegrityTest.php`
- `prompts/tools/coqui-source.md`

---

### Task 1: `DocumentationIndex` — derive the index from disk

**Files:**
- Create: `src/Config/DocumentationIndex.php`
- Test: `tests/Unit/Config/DocumentationIndexTest.php`

**Interfaces:**
- Consumes: `CoquiBot\Coqui\Config\SkillParser::parseFrontmatter(string $content): array{metadata: array<string, mixed>, body: string}` (throws `CoquiBot\Coqui\Exception\SkillParseException`).
- Produces:
  - `new DocumentationIndex(string $projectRoot, ?SkillParser $frontmatter = null)`
  - `build(): array{version: string, files: list<DocFile>}` — derive from disk.
  - `load(): array{version: string, files: list<DocFile>}` — generated JSON if valid, else `build()`.
  - Where `DocFile = array{path: string, title: string, description: string, sections: list<DocSection>}` and `DocSection = array{heading: string, level: int, line_start: int, line_end: int}`.
  - `DocumentationIndex::VERSION` = `'1.0.0'` (unchanged from today's index, so consumers see no schema break).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/DocumentationIndexTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\DocumentationIndex;

beforeEach(function () {
    $this->root = sys_get_temp_dir() . '/coqui-docidx-' . bin2hex(random_bytes(8));
    mkdir($this->root . '/docs', 0755, true);
    mkdir($this->root . '/config', 0755, true);
});

afterEach(function () {
    exec('rm -rf ' . escapeshellarg($this->root));
});

function writeDoc(string $root, string $relative, string $content): void
{
    file_put_contents($root . '/' . $relative, $content);
}

it('globs every docs/*.md rather than an allowlist', function () {
    writeDoc($this->root, 'docs/ALPHA.md', "# Alpha\n\nFirst doc.\n");
    writeDoc($this->root, 'docs/BETA.md', "# Beta\n\nSecond doc.\n");
    writeDoc($this->root, 'docs/GAMMA.md', "# Gamma\n\nThird doc.\n");

    $index = (new DocumentationIndex($this->root))->build();

    expect(array_column($index['files'], 'path'))
        ->toBe(['docs/ALPHA.md', 'docs/BETA.md', 'docs/GAMMA.md']);
});

it('includes README.md and AGENTS.md from the project root', function () {
    writeDoc($this->root, 'README.md', "# Coqui Bot\n\nOverview.\n");
    writeDoc($this->root, 'AGENTS.md', "# Contributor Guide\n\nRules.\n");

    $index = (new DocumentationIndex($this->root))->build();
    $paths = array_column($index['files'], 'path');

    expect($paths)->toContain('README.md')->toContain('AGENTS.md');
});

it('takes title and description from frontmatter when present', function () {
    writeDoc($this->root, 'docs/LOOPS.md', <<<'MD'
        ---
        title: Loops
        description: Loop system reference — stages, policies, and scheduling
        ---

        # Loops Heading That Is Not The Title

        Body text.
        MD);

    $index = (new DocumentationIndex($this->root))->build();

    expect($index['files'][0]['title'])->toBe('Loops')
        ->and($index['files'][0]['description'])
        ->toBe('Loop system reference — stages, policies, and scheduling');
});

it('falls back to the H1 and first paragraph when frontmatter is absent', function () {
    writeDoc($this->root, 'docs/PLAIN.md', <<<'MD'
        # Plain Doc

        The first paragraph describes the doc.

        ## A Section

        More text.
        MD);

    $index = (new DocumentationIndex($this->root))->build();

    expect($index['files'][0]['title'])->toBe('Plain Doc')
        ->and($index['files'][0]['description'])->toBe('The first paragraph describes the doc.');
});

it('strips frontmatter from the H1 fallback search but keeps line numbers absolute', function () {
    writeDoc($this->root, 'docs/FM.md', <<<'MD'
        ---
        title: Front
        ---

        # Front Matter Doc

        ## Section One

        Text.
        MD);

    $index = (new DocumentationIndex($this->root))->build();
    $sections = $index['files'][0]['sections'];

    // line_start is 1-based against the file on disk, frontmatter included.
    expect($sections[0]['heading'])->toBe('Front Matter Doc')
        ->and($sections[0]['line_start'])->toBe(5)
        ->and($sections[1]['heading'])->toBe('Section One')
        ->and($sections[1]['line_start'])->toBe(7);
});

it('extracts H1 through H4 with line ranges and skips fenced code blocks', function () {
    writeDoc($this->root, 'docs/CODE.md', <<<'MD'
        # Top

        ## Real Section

        ```bash
        # Not A Heading
        ```

        #### Deep Section

        Tail.
        MD);

    $index = (new DocumentationIndex($this->root))->build();
    $headings = array_column($index['files'][0]['sections'], 'heading');

    expect($headings)->toBe(['Top', 'Real Section', 'Deep Section']);
});

it('closes each section at the next heading and the last at EOF', function () {
    writeDoc($this->root, 'docs/RANGE.md', "# One\n\nA\n\n## Two\n\nB\n");

    $sections = (new DocumentationIndex($this->root))->build()['files'][0]['sections'];

    expect($sections[0]['line_start'])->toBe(1)
        ->and($sections[0]['line_end'])->toBe(4)
        ->and($sections[1]['line_start'])->toBe(5)
        ->and($sections[1]['line_end'])->toBe(7);
});

it('ignores nested directories such as docs/superpowers', function () {
    mkdir($this->root . '/docs/superpowers/plans', 0755, true);
    writeDoc($this->root, 'docs/superpowers/plans/old-plan.md', "# A Plan\n\nText.\n");
    writeDoc($this->root, 'docs/REAL.md', "# Real\n\nText.\n");

    $index = (new DocumentationIndex($this->root))->build();

    expect(array_column($index['files'], 'path'))->toBe(['docs/REAL.md']);
});

it('load() returns the generated index when it is present and valid', function () {
    writeDoc($this->root, 'docs/ONDISK.md', "# On Disk\n\nText.\n");
    file_put_contents($this->root . '/config/documentation.json', json_encode([
        'version' => '1.0.0',
        'files' => [['path' => 'docs/CACHED.md', 'title' => 'Cached', 'description' => 'From cache', 'sections' => []]],
    ]));

    $index = (new DocumentationIndex($this->root))->load();

    expect(array_column($index['files'], 'path'))->toBe(['docs/CACHED.md']);
});

it('load() falls back to build() when the generated index is absent', function () {
    writeDoc($this->root, 'docs/ONDISK.md', "# On Disk\n\nText.\n");

    $index = (new DocumentationIndex($this->root))->load();

    expect(array_column($index['files'], 'path'))->toBe(['docs/ONDISK.md']);
});

it('load() falls back to build() when the generated index is corrupt', function () {
    writeDoc($this->root, 'docs/ONDISK.md', "# On Disk\n\nText.\n");
    file_put_contents($this->root . '/config/documentation.json', '{not valid json');

    $index = (new DocumentationIndex($this->root))->load();

    expect(array_column($index['files'], 'path'))->toBe(['docs/ONDISK.md']);
});

it('uses the filename as the title when a doc has neither frontmatter nor an H1', function () {
    writeDoc($this->root, 'docs/NOHEAD.md', "Just prose, no heading at all.\n");

    $index = (new DocumentationIndex($this->root))->build();

    expect($index['files'][0]['title'])->toBe('NOHEAD.md')
        ->and($index['files'][0]['description'])->toBe('Just prose, no heading at all.');
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd /home/carmelo/Projects/CoquiBot/Core/coqui-docs-tools && ./vendor/bin/pest tests/Unit/Config/DocumentationIndexTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Config\DocumentationIndex" not found`.

- [ ] **Step 3: Write the implementation**

Create `src/Config/DocumentationIndex.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PathHelper\PathHelper;
use CoquiBot\Coqui\Exception\SkillParseException;

/**
 * Derives the documentation index from disk.
 *
 * Structure is globbed, never listed: every `docs/*.md` plus README.md and
 * AGENTS.md is indexed the moment the file exists. A hardcoded allowlist here
 * previously hid eight docs — including LOOPS.md and PROFILES.md — from the
 * agent, so the file list must stay derived.
 *
 * Intent is authored where it belongs: per-doc title/description come from the
 * doc's own frontmatter, falling back to its H1 and first paragraph.
 *
 * @phpstan-type DocSection array{heading: string, level: int, line_start: int, line_end: int}
 * @phpstan-type DocFile array{path: string, title: string, description: string, sections: list<DocSection>}
 * @phpstan-type DocIndex array{version: string, files: list<DocFile>}
 */
final class DocumentationIndex
{
    public const string VERSION = '1.0.0';

    /** Docs that live at the project root rather than under docs/. */
    private const array ROOT_DOCS = ['README.md', 'AGENTS.md'];

    private readonly string $normalizedRoot;

    private readonly SkillParser $frontmatter;

    public function __construct(
        private readonly string $projectRoot,
        ?SkillParser $frontmatter = null,
    ) {
        $this->normalizedRoot = PathHelper::trimTrailingSlash($this->projectRoot);
        $this->frontmatter = $frontmatter ?? new SkillParser();
    }

    /**
     * Load the generated index, deriving it from disk if unavailable.
     *
     * config/documentation.json is a build-time cache: it is git-ignored and
     * generated by release/Docker builds, so a fresh checkout has none. A
     * missing cache must degrade to a slower path, never to a dead tool.
     *
     * @return DocIndex
     */
    public function load(): array
    {
        $cached = $this->readGenerated();

        return $cached ?? $this->build();
    }

    /**
     * Derive the index by globbing and parsing the docs on disk.
     *
     * @return DocIndex
     */
    public function build(): array
    {
        $files = [];

        foreach ($this->docPaths() as $relativePath) {
            $entry = $this->buildEntry($relativePath);

            if ($entry !== null) {
                $files[] = $entry;
            }
        }

        return ['version' => self::VERSION, 'files' => $files];
    }

    /**
     * Every indexable doc path, relative to the project root, sorted.
     *
     * Only docs/*.md — not docs/**\/*.md — so working artefacts such as
     * docs/superpowers/{specs,plans} stay out of the agent's index.
     *
     * @return list<string>
     */
    private function docPaths(): array
    {
        $matches = glob($this->normalizedRoot . '/docs/*.md') ?: [];
        sort($matches);

        $paths = [];

        foreach ($matches as $absolute) {
            $paths[] = 'docs/' . basename($absolute);
        }

        foreach (self::ROOT_DOCS as $rootDoc) {
            if (is_file($this->normalizedRoot . '/' . $rootDoc)) {
                $paths[] = $rootDoc;
            }
        }

        return $paths;
    }

    /**
     * @return DocFile|null
     */
    private function buildEntry(string $relativePath): ?array
    {
        $absolute = $this->normalizedRoot . '/' . $relativePath;
        $content = file_get_contents($absolute);

        if ($content === false) {
            return null;
        }

        $lines = file($absolute, FILE_IGNORE_NEW_LINES);

        if ($lines === false) {
            return null;
        }

        $meta = $this->extractMetadata($content, $relativePath);

        return [
            'path' => $relativePath,
            'title' => $meta['title'],
            'description' => $meta['description'],
            'sections' => $this->extractSections($lines),
        ];
    }

    /**
     * Resolve title/description from frontmatter, falling back to H1 + first paragraph.
     *
     * @return array{title: string, description: string}
     */
    private function extractMetadata(string $content, string $relativePath): array
    {
        $body = $content;
        $title = null;
        $description = null;

        try {
            $parsed = $this->frontmatter->parseFrontmatter($content);
            $body = $parsed['body'];

            $meta = $parsed['metadata'];

            if (isset($meta['title']) && is_string($meta['title']) && $meta['title'] !== '') {
                $title = $meta['title'];
            }

            if (isset($meta['description']) && is_string($meta['description']) && $meta['description'] !== '') {
                $description = $meta['description'];
            }
        } catch (SkillParseException) {
            // No frontmatter — expected for docs that have not adopted it yet.
        }

        return [
            'title' => $title ?? $this->firstHeading($body) ?? basename($relativePath),
            'description' => $description ?? $this->firstParagraph($body) ?? '',
        ];
    }

    /**
     * The first H1 in the body, or null.
     */
    private function firstHeading(string $body): ?string
    {
        if (preg_match('/^#\s+(.+)$/m', $body, $matches) === 1) {
            return trim($matches[1], " `");
        }

        return null;
    }

    /**
     * The first non-heading, non-fence, non-empty paragraph in the body, or null.
     */
    private function firstParagraph(string $body): ?string
    {
        $inCodeBlock = false;

        foreach (explode("\n", $body) as $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '```')) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if ($inCodeBlock || $trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            return $trimmed;
        }

        return null;
    }

    /**
     * Extract H1–H4 headings with 1-based line ranges against the file on disk.
     *
     * Line numbers stay absolute (frontmatter included) because coqui_docs_read
     * slices the raw file by these ranges.
     *
     * @param list<string> $lines
     *
     * @return list<DocSection>
     */
    private function extractSections(array $lines): array
    {
        $headings = [];
        $inCodeBlock = false;

        foreach ($lines as $i => $line) {
            if (str_starts_with($line, '```')) {
                $inCodeBlock = !$inCodeBlock;
                continue;
            }

            if ($inCodeBlock) {
                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.+)$/', $line, $matches) === 1) {
                $headings[] = [
                    'heading' => trim($matches[2]),
                    'level' => strlen($matches[1]),
                    'line_start' => $i + 1,
                ];
            }
        }

        $totalLines = count($lines);
        $sections = [];
        $count = count($headings);

        for ($i = 0; $i < $count; $i++) {
            $sections[] = [
                'heading' => $headings[$i]['heading'],
                'level' => $headings[$i]['level'],
                'line_start' => $headings[$i]['line_start'],
                'line_end' => $i + 1 < $count
                    ? $headings[$i + 1]['line_start'] - 1
                    : $totalLines,
            ];
        }

        return $sections;
    }

    /**
     * Read the generated index, or null when absent/unreadable/malformed.
     *
     * @return DocIndex|null
     */
    private function readGenerated(): ?array
    {
        $path = $this->normalizedRoot . '/config/documentation.json';

        if (!is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);

        if (!is_array($data) || !isset($data['files']) || !is_array($data['files'])) {
            return null;
        }

        /** @var DocIndex $data */
        return $data;
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Config/DocumentationIndexTest.php`
Expected: PASS — 12 passed.

- [ ] **Step 5: Run PHPStan on the new class**

Run: `./vendor/bin/phpstan analyse src/Config/DocumentationIndex.php --memory-limit=512M`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Config/DocumentationIndex.php tests/Unit/Config/DocumentationIndexTest.php
git commit -m "feat(docs): derive the documentation index from disk

Globs docs/*.md + README + AGENTS instead of a hardcoded list, takes
title/description from frontmatter with an H1 fallback, and degrades to a
live build when the generated cache is absent."
```

---

### Task 2: Frontmatter on the docs + generator rewired to the builder

**Files:**
- Modify: `scripts/generate-documentation-index.php` (full rewrite — currently 89 lines)
- Modify: all 18 `docs/*.md` (add frontmatter)
- Create: `tests/Unit/Config/DocumentationIndexRealDocsTest.php`

**Interfaces:**
- Consumes: `DocumentationIndex::build(): array{version: string, files: list<DocFile>}` from Task 1.
- Produces: `config/documentation.json` containing **all 18 `docs/*.md` + README.md + AGENTS.md** (20 entries).

**Context:** `config/documentation.json` is git-ignored (`.gitignore:79`) and regenerated by release/Docker builds. Never commit it. The frontmatter added here is a **no-op for the published docs site** — `sync-docs.mjs` already calls `stripFrontmatter()` on every synced doc (verified; spec Open Item 5).

- [ ] **Step 1: Write the failing regression test**

Create `tests/Unit/Config/DocumentationIndexRealDocsTest.php`. This is the test that would have caught the eight-doc blind spot:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\DocumentationIndex;

$projectRoot = dirname(__DIR__, 3);

it('indexes every docs/*.md that exists on disk', function () use ($projectRoot) {
    $onDisk = array_map(
        fn (string $path): string => 'docs/' . basename($path),
        glob($projectRoot . '/docs/*.md') ?: [],
    );
    sort($onDisk);

    $indexed = array_values(array_filter(
        array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path'),
        fn (string $path): bool => str_starts_with($path, 'docs/'),
    ));
    sort($indexed);

    // A doc that exists but is not indexed is invisible to the agent — the exact
    // regression that hid LOOPS.md and PROFILES.md behind a hardcoded allowlist.
    expect($indexed)->toBe($onDisk)
        ->and($indexed)->toHaveCount(18);
});

it('indexes the docs that the old hardcoded allowlist omitted', function () use ($projectRoot) {
    $indexed = array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path');

    expect($indexed)
        ->toContain('docs/LOOPS.md')
        ->toContain('docs/PROFILES.md')
        ->toContain('docs/QUESTIONS.md')
        ->toContain('docs/ARTIFACTS.md')
        ->toContain('docs/PROJECTS.md')
        ->toContain('docs/CHAT.md')
        ->toContain('docs/DATA_FLOW.md')
        ->toContain('docs/TOOLKIT-EXTENSIBILITY.md');
});

it('includes README.md and AGENTS.md', function () use ($projectRoot) {
    $indexed = array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path');

    expect($indexed)->toContain('README.md')->toContain('AGENTS.md');
});

it('gives every indexed doc a non-empty title and description', function () use ($projectRoot) {
    foreach ((new DocumentationIndex($projectRoot))->build()['files'] as $file) {
        expect($file['title'])->not->toBe('', "{$file['path']} has no title")
            ->and($file['description'])->not->toBe('', "{$file['path']} has no description");
    }
});

it('gives every docs/*.md frontmatter-sourced metadata', function () use ($projectRoot) {
    foreach (glob($projectRoot . '/docs/*.md') ?: [] as $path) {
        $content = file_get_contents($path);

        expect($content)->toStartWith("---\n", basename($path) . ' is missing frontmatter');
    }
});

it('never indexes working artefacts under docs/superpowers', function () use ($projectRoot) {
    $indexed = array_column((new DocumentationIndex($projectRoot))->build()['files'], 'path');

    foreach ($indexed as $path) {
        expect($path)->not->toContain('superpowers/');
    }
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/DocumentationIndexRealDocsTest.php`
Expected: FAIL — the frontmatter test fails for all 18 docs (none have frontmatter today; verified `head -1 docs/LOOPS.md` = `# Loops`).

- [ ] **Step 3: Add frontmatter to all 18 docs**

Prepend a `---` block to each `docs/*.md`, **above the existing H1, leaving the H1 in place**. Reuse the curated `description` text from the old generator allowlist where one exists (`scripts/generate-documentation-index.php:13-24`) — those descriptions are good and must not be lost when the allowlist dies. Write fresh ones for the eight docs the allowlist never covered.

Exact blocks to prepend:

```yaml
# docs/API.md
---
title: Coqui HTTP API
description: Complete REST API reference with all endpoints, authentication, SSE streaming, rate limiting, CORS, and safety documentation
---

# docs/BACKGROUND-TASKS.md
---
title: Background Tasks
description: Background task system: lifecycle, concurrency, crash recovery, agent tools, REPL commands, and API endpoints
---

# docs/COMMANDS.md
---
title: Commands Reference
description: All REPL slash commands, CLI commands, launcher modes, signal handling, and exit code behavior
---

# docs/CONFIGURATION.md
---
title: Configuration
description: openclaw.json schema, agent defaults, model providers, API config, environment overrides, and setup wizard
---

# docs/FEATURES.md
---
title: Coqui Features
description: High-level overview of all Coqui capabilities: multi-model orchestration, memory, extensibility, scheduling, vision, and more
---

# docs/GITHUB-ACTIONS.md
---
title: GitHub Actions CI
description: CI workflow overview, PHP version matrix, local testing instructions, and troubleshooting
---

# docs/ROLES.md
---
title: Roles Reference
description: Built-in role definitions, access levels, role-to-model mapping, custom role creation, and frontmatter schema
---

# docs/SKILLS.md
---
title: Coqui Skills
description: Skills system: SKILL.md format, creation workflow, discovery, validation, progressive disclosure, and examples
---

# docs/TESTING.md
---
title: Testing
description: Test layout, local commands, coverage workflow, and PCOV/Xdebug setup for Linux and macOS
---

# docs/TOOLKITS.md
---
title: Coqui Toolkits
description: Toolkit development guide: anatomy, parameter types, credential management, auto-discovery, testing, and API reference
---
```

For the eight docs the allowlist hid, derive the description from each doc's own opening prose — read the file first, then write a one-line description in the same register as the ten above:

```yaml
# docs/LOOPS.md
---
title: Loops
description: Loop system: definitions, stages, iteration control, on_question policy, REPL commands, and API endpoints
---

# docs/PROFILES.md
---
title: Personality Profiles
description: Profile system: built-in profiles, tone and behavior shaping, profile files, and selection at runtime
---

# docs/QUESTIONS.md
---
title: Structured Questions
description: The ask_user tool, question responders, and on_question loop policy for agent-initiated clarification
---

# docs/ARTIFACTS.md
---
title: Artifacts
description: Artifact lifecycle: creation, promotion to memory, storage, and usage from agents and the REPL
---

# docs/PROJECTS.md
---
title: Projects
description: Lean project working scopes: creation, activation, project context, and per-project session isolation
---

# docs/CHAT.md
---
title: Chat
description: The chat surface: conversation flow, session handling, and interaction model
---

# docs/DATA_FLOW.md
---
title: Data Flow
description: How a turn moves through Coqui: boot, orchestration, tool execution, storage, and context-window handling
---

# docs/TOOLKIT-EXTENSIBILITY.md
---
title: Toolkit Extensibility
description: Self-registering REPL commands from toolkits: registration contract, discovery, and command lifecycle
---
```

**Verify each description against the doc's actual content before writing it** — an invented description is exactly the drift this change exists to kill. If a doc's real content contradicts the line above, write what the doc actually says.

- [ ] **Step 4: Rewrite the generator as a wrapper**

Replace the entire contents of `scripts/generate-documentation-index.php`:

```php
<?php

declare(strict_types=1);

/**
 * Generates config/documentation.json from the docs on disk.
 *
 * The file list is globbed and per-doc metadata comes from frontmatter — this
 * script holds no list of what exists. Run: composer regen-docs
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use CoquiBot\Coqui\Config\DocumentationIndex;

$projectRoot = dirname(__DIR__);
$index = (new DocumentationIndex($projectRoot))->build();

$json = json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false) {
    fwrite(STDERR, "Failed to encode documentation index\n");
    exit(1);
}

$outPath = $projectRoot . '/config/documentation.json';

if (file_put_contents($outPath, $json . "\n") === false) {
    fwrite(STDERR, "Failed to write {$outPath}\n");
    exit(1);
}

$totalSections = array_sum(array_map(
    static fn (array $file): int => count($file['sections']),
    $index['files'],
));

echo "Written to {$outPath}\n";
echo count($index['files']) . " files indexed\n";
echo "{$totalSections} total sections\n";
```

Note `JSON_UNESCAPED_UNICODE` is added: the curated descriptions contain em dashes, and escaping them to `—` makes the generated file needlessly unreadable.

- [ ] **Step 5: Regenerate and verify the index covers all 20 docs**

Run: `composer regen-docs`
Expected output: `20 files indexed` (18 docs/*.md + README.md + AGENTS.md). If it says 12, the glob did not take effect.

- [ ] **Step 6: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Config/DocumentationIndexRealDocsTest.php`
Expected: PASS — 6 passed.

- [ ] **Step 7: Confirm the generated index is still ignored, never staged**

Run: `git status --porcelain config/documentation.json`
Expected: **empty output**. If the file shows up, `.gitignore:79` has been broken — fix that rather than committing the file.

- [ ] **Step 8: Run the full suite**

Run: `composer test`
Expected: 2370 + 18 new = all passing, no failures.

- [ ] **Step 9: Commit**

```bash
git add scripts/generate-documentation-index.php docs/*.md tests/Unit/Config/DocumentationIndexRealDocsTest.php
git commit -m "feat(docs): generate the docs index by globbing, not by allowlist

The generator's hardcoded 12-path array hid eight docs from the agent,
including LOOPS.md and PROFILES.md. It now globs docs/*.md + README +
AGENTS and reads title/description from each doc's frontmatter. A test
asserts every docs/*.md on disk is indexed."
```

---

### Task 3: `coqui_docs_map` — compact discovery

**Files:**
- Modify: `src/Toolkit/CoquiSourceToolkit.php` (add the new tool; old tools stay until Task 6)
- Test: `tests/Unit/Toolkit/CoquiSourceToolkitTest.php`

**Interfaces:**
- Consumes: `DocumentationIndex::load()` from Task 1.
- Produces: tool `coqui_docs_map`, params `file` (optional string). No-arg → compact summary. With `file` → that doc's full entry including sections.

**Context:** Today's no-arg `coqui_doc_map` returns the entire index (`:310-312`) — ~27K tokens. The class is still named `CoquiSourceToolkit` at this point; the rename lands in Task 6.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Toolkit/CoquiSourceToolkitTest.php`:

```php
// ---------------------------------------------------------------
// coqui_docs_map
// ---------------------------------------------------------------

it('coqui_docs_map returns a compact summary with no arguments', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute([]);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['files'][0])->toHaveKeys(['path', 'title', 'description', 'section_count'])
        // Compact means compact: no heading list in the no-arg response.
        ->and($data['files'][0])->not->toHaveKey('sections');
});

it('coqui_docs_map stays under a hard byte ceiling with no arguments', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    // The full index is ~27K tokens. Discovery must cost ~600, not 27,000.
    expect(strlen($tool->execute([])->content))->toBeLessThan(8192);
});

it('coqui_docs_map returns full sections for a named file', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md']);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['path'])->toBe('docs/CONFIGURATION.md')
        ->and($data['sections'])->not->toBeEmpty()
        ->and(array_column($data['sections'], 'heading'))->toContain('Model Configuration');
});

it('coqui_docs_map errors with the available list for an unknown file', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute(['file' => 'docs/NOPE.md']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('docs/CONFIGURATION.md');
});

it('coqui_docs_map works when config/documentation.json is absent', function () {
    unlink($this->root . '/config/documentation.json');
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_map');

    $result = $tool->execute([]);
    $data = json_decode($result->content, true);

    // A fresh checkout has no generated index. Discovery must still work.
    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and(array_column($data['files'], 'path'))->toContain('docs/CONFIGURATION.md');
});
```

The `beforeEach` fixture must also write a `config/documentation.json` for `$this->root` (the existing fixture at `:28-49` writes `source.json` and a docs file). Add to the fixture, after the doc file is created:

```php
    // Generated index for the fixture docs — mirrors what composer regen-docs produces.
    file_put_contents(
        $this->root . '/config/documentation.json',
        json_encode((new \CoquiBot\Coqui\Config\DocumentationIndex($this->root))->build(), JSON_PRETTY_PRINT),
    );
```

If the existing fixture already writes `documentation.json` by hand, replace that hand-written literal with the line above — the fixture and the generator must not drift apart.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Toolkit/CoquiSourceToolkitTest.php --filter=coqui_docs_map`
Expected: FAIL — `Tool 'coqui_docs_map' not found in CoquiSourceToolkit`.

- [ ] **Step 3: Implement the tool**

In `src/Toolkit/CoquiSourceToolkit.php`, add a `DocumentationIndex` to the constructor and register the new tool.

Constructor — add the field:

```php
    private readonly DocumentationIndex $docsIndex;

    public function __construct(
        private readonly string $projectRoot,
    ) {
        $root = PathHelper::trimTrailingSlash($this->projectRoot);
        $this->normalizedRoot = $root;
        $this->sourceMapPath = $root . '/config/source.json';
        $this->docMapPath = $root . '/config/documentation.json';
        $this->docsIndex = new DocumentationIndex($root);
    }
```

Add `use CoquiBot\Coqui\Config\DocumentationIndex;` to the imports, and add `$this->docsMapTool(),` to the `tools()` array.

```php
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
```

Note the description carries no "start here" / "load everything" framing — that instruction is what made the source map expensive, and it must not be reproduced.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Toolkit/CoquiSourceToolkitTest.php --filter=coqui_docs_map`
Expected: PASS — 5 passed.

- [ ] **Step 5: Commit**

```bash
git add src/Toolkit/CoquiSourceToolkit.php tests/Unit/Toolkit/CoquiSourceToolkitTest.php
git commit -m "feat(docs): add coqui_docs_map with a compact default response

No-arg discovery returns one line per doc (~600 tokens) instead of the
full 27K-token index. Falls back to a live build when the generated
index is absent."
```

---

### Task 4: `coqui_docs_read` — section retrieval without silent truncation

**Files:**
- Modify: `src/Toolkit/CoquiSourceToolkit.php`
- Test: `tests/Unit/Toolkit/CoquiSourceToolkitTest.php`

**Interfaces:**
- Consumes: `DocumentationIndex::load()`; the existing private helpers `extractSectionFromIndex()`, `extractSectionFromFile()`, `extractHeadings()`, `findClosestHeading()`, `readLineRange()`.
- Produces: tool `coqui_docs_read`, params `file` (required string), `section` (optional string).

**Context:** `MAX_READ_BYTES` is 65,536 (`:32`) and `docs/API.md` is **143,927 B** — a section-less read today silently returns ~46% of the file with no signal. That is the same false-confidence failure this whole change exists to eliminate.

`extractSectionFromIndex()` (`:409`) currently re-reads and re-parses `documentation.json` itself. Refactor it to take the loaded index so the fallback in `load()` applies to reads too — otherwise a fresh checkout silently loses index-based line ranges.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Toolkit/CoquiSourceToolkitTest.php`. The fixture needs an oversized doc, so add to `beforeEach`:

```php
    // A doc larger than MAX_READ_BYTES, mirroring docs/API.md at 143,927 B.
    $huge = "# Huge Doc\n\nIntro.\n\n## First Big Section\n\n"
        . str_repeat("Filler line of prose to exceed the read cap.\n", 2000)
        . "\n## Second Big Section\n\nTail content.\n";
    file_put_contents($this->root . '/docs/HUGE.md', $huge);
```

Rebuild the fixture index after writing `HUGE.md` (the `documentation.json` line from Task 3 must come **after** all fixture docs are written).

```php
// ---------------------------------------------------------------
// coqui_docs_read
// ---------------------------------------------------------------

it('coqui_docs_read returns a section by exact heading', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Model Configuration']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('Set the model in openclaw.json')
        ->and($result->content)->not->toContain('Configure shell access');
});

it('coqui_docs_read matches headings case- and backtick-insensitively', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'shellallowedcommands']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('An array of allowed commands');
});

it('coqui_docs_read suggests the closest heading when a section is not found', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Model Configurashun']);

    expect($result->status)->toBe(ToolResultStatus::Error)
        ->and($result->content)->toContain('Did you mean')
        ->and($result->content)->toContain('Model Configuration');
});

it('coqui_docs_read returns the section list instead of truncating an oversized file', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/HUGE.md']);

    // The old behaviour returned ~46% of docs/API.md with no signal at all.
    expect($result->content)->not->toContain('truncated at')
        ->and($result->content)->toContain('First Big Section')
        ->and($result->content)->toContain('Second Big Section')
        ->and($result->content)->toContain('section')
        ->and(strlen($result->content))->toBeLessThan(65536);
});

it('coqui_docs_read returns a whole small file unchanged', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('# Configuration Guide')
        ->and($result->content)->toContain('An array of allowed commands');
});

it('coqui_docs_read falls back to direct parsing when the index is absent', function () {
    unlink($this->root . '/config/documentation.json');
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => 'docs/CONFIGURATION.md', 'section' => 'Model Configuration']);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($result->content)->toContain('Set the model in openclaw.json');
});

it('coqui_docs_read rejects paths escaping the project root', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_read');

    $result = $tool->execute(['file' => '../../../etc/passwd']);

    expect($result->status)->toBe(ToolResultStatus::Error);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Toolkit/CoquiSourceToolkitTest.php --filter=coqui_docs_read`
Expected: FAIL — `Tool 'coqui_docs_read' not found in CoquiSourceToolkit`.

- [ ] **Step 3: Implement the tool**

Add `$this->docsReadTool(),` to `tools()` and implement:

```php
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
```

Refactor `extractSectionFromIndex()` (`:409-461`) to source its map from `DocumentationIndex::load()` instead of reading `documentation.json` directly — replace its opening block (`:411-433`) with:

```php
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

        // ... existing Pass 1 / Pass 2 matching, unchanged
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Toolkit/CoquiSourceToolkitTest.php --filter=coqui_docs_read`
Expected: PASS — 7 passed.

- [ ] **Step 5: Verify against the real docs/API.md**

Run:
```bash
php -r 'require "vendor/autoload.php";
$tk = new CoquiBot\Coqui\Toolkit\CoquiSourceToolkit(projectRoot: __DIR__);
foreach ($tk->tools() as $t) {
  if ($t->toFunctionSchema()["function"]["name"] === "coqui_docs_read") {
    $r = $t->execute(["file" => "docs/API.md"]);
    echo substr($r->content, 0, 300), "\n";
  }
}'
```
Expected: a "too large to return whole" message plus a heading list — **not** a wall of truncated API docs.

- [ ] **Step 6: Commit**

```bash
git add src/Toolkit/CoquiSourceToolkit.php tests/Unit/Toolkit/CoquiSourceToolkitTest.php
git commit -m "feat(docs): add coqui_docs_read, returning section lists over truncation

A section-less read of docs/API.md (143,927 B against a 65,536 B cap)
silently returned ~46% of the file. It now returns the section list.
Index lookups route through DocumentationIndex so they survive a missing
generated index."
```

---

### Task 5: `coqui_docs_search` — the capability that does not exist today

**Files:**
- Modify: `src/Toolkit/CoquiSourceToolkit.php`
- Test: `tests/Unit/Toolkit/CoquiSourceToolkitTest.php`

**Interfaces:**
- Consumes: `DocumentationIndex::load()`.
- Produces: tool `coqui_docs_search`, params `query` (required string), `limit` (optional int, default 20, hard cap 50). Each result: `path`, `heading`, `line`, `snippet`.

**Context:** No documentation search exists today — the old `coqui_search` was filename-glob only (`glob()`/`fnmatch()`, `:629`/`:645`). This is the redesign's one capability gain. Results are derived at call time from the `.md` files, so they cannot drift.

Ranking (spec Open Item 3, decided): heading/title/description matches outrank body matches; ties break on path, then line number, so output is deterministic.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Toolkit/CoquiSourceToolkitTest.php`:

```php
// ---------------------------------------------------------------
// coqui_docs_search
// ---------------------------------------------------------------

it('coqui_docs_search finds a term in a doc body and reports its heading', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $result = $tool->execute(['query' => 'openclaw.json']);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['results'][0]['path'])->toBe('docs/CONFIGURATION.md')
        ->and($data['results'][0]['heading'])->toBe('Model Configuration')
        ->and($data['results'][0]['snippet'])->toContain('openclaw.json')
        ->and($data['results'][0]['line'])->toBeGreaterThan(0);
});

it('coqui_docs_search ranks heading matches above body matches', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'Shell Configuration'])->content, true);

    expect($data['results'][0]['heading'])->toBe('Shell Configuration');
});

it('coqui_docs_search is case-insensitive', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'OPENCLAW.JSON'])->content, true);

    expect($data['results'])->not->toBeEmpty();
});

it('coqui_docs_search returns empty results rather than an error for no match', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $result = $tool->execute(['query' => 'zzzznotpresentanywhere']);
    $data = json_decode($result->content, true);

    expect($result->status)->toBe(ToolResultStatus::Success)
        ->and($data['results'])->toBe([])
        ->and($data['total_matches'])->toBe(0);
});

it('coqui_docs_search requires a query', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    expect($tool->execute(['query' => ''])->status)->toBe(ToolResultStatus::Error);
});

it('coqui_docs_search bounds results and reports the truncation', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    // 'Filler' appears 2000 times in the HUGE.md fixture.
    $data = json_decode($tool->execute(['query' => 'Filler', 'limit' => 5])->content, true);

    expect($data['results'])->toHaveCount(5)
        ->and($data['total_matches'])->toBeGreaterThan(5)
        // A silent cap is the failure mode this whole change exists to kill.
        ->and($data['truncated'])->toBeTrue();
});

it('coqui_docs_search caps limit at 50', function () {
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'Filler', 'limit' => 9999])->content, true);

    expect($data['results'])->toHaveCount(50);
});

it('coqui_docs_search works when config/documentation.json is absent', function () {
    unlink($this->root . '/config/documentation.json');
    $tool = coquiSourceFindTool(new CoquiSourceToolkit(projectRoot: $this->root), 'coqui_docs_search');

    $data = json_decode($tool->execute(['query' => 'openclaw.json'])->content, true);

    expect($data['results'])->not->toBeEmpty();
});
```

Add the **8-doc blind-spot regression test** to `tests/Unit/Config/DocumentationIndexRealDocsTest.php` — it must run against the real docs tree, not fixtures:

```php
it('finds a loops-only term in the real docs/LOOPS.md', function () use ($projectRoot) {
    $toolkit = new \CoquiBot\Coqui\Toolkit\CoquiSourceToolkit(projectRoot: $projectRoot);
    $tool = null;

    foreach ($toolkit->tools() as $candidate) {
        if ($candidate->toFunctionSchema()['function']['name'] === 'coqui_docs_search') {
            $tool = $candidate;
        }
    }

    $data = json_decode($tool->execute(['query' => 'on_question'])->content, true);
    $paths = array_column($data['results'], 'path');

    // docs/LOOPS.md was invisible to the agent under the hardcoded allowlist.
    // This is the direct regression test for that eight-doc blind spot.
    expect($paths)->toContain('docs/LOOPS.md');
});
```

`on_question` is verified present in `docs/LOOPS.md` (5 occurrences) and also in `docs/QUESTIONS.md` — which is fine, and in fact better: both docs were in the eight-doc blind spot, so a hit in either proves the fix. The assertion uses `toContain`, not equality, so it does not depend on the term being unique.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Toolkit/CoquiSourceToolkitTest.php --filter=coqui_docs_search`
Expected: FAIL — `Tool 'coqui_docs_search' not found in CoquiSourceToolkit`.

- [ ] **Step 3: Implement the tool**

Add `use CarmeloSantana\PHPAgents\Tool\Parameter\NumberParameter;` to the imports — **there is no `IntParameter` in php-agents**; integer params are `NumberParameter(..., integer: true)`, as used at `src/Tool/ToolSearchTool.php:72`. Add `$this->docsSearchTool(),` to `tools()`, and implement:

```php
    private const int SEARCH_DEFAULT_LIMIT = 20;
    private const int SEARCH_MAX_LIMIT = 50;
    private const int SEARCH_SNIPPET_CHARS = 200;

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

    private function snippet(string $line): string
    {
        $trimmed = trim($line);

        if (strlen($trimmed) <= self::SEARCH_SNIPPET_CHARS) {
            return $trimmed;
        }

        return substr($trimmed, 0, self::SEARCH_SNIPPET_CHARS) . '…';
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Toolkit/CoquiSourceToolkitTest.php tests/Unit/Config/DocumentationIndexRealDocsTest.php`
Expected: PASS — all green, including the LOOPS.md regression.

- [ ] **Step 5: Run PHPStan**

Run: `composer analyse`
Expected: `[OK]` with no new errors.

- [ ] **Step 6: Commit**

```bash
git add src/Toolkit/CoquiSourceToolkit.php tests/Unit/Toolkit/CoquiSourceToolkitTest.php tests/Unit/Config/DocumentationIndexRealDocsTest.php
git commit -m "feat(docs): add coqui_docs_search over the documentation index

Full-text search across all indexed docs, ranked heading-first, with an
explicit result bound that reports truncation rather than capping
silently. The old coqui_search matched filenames only — no documentation
search existed."
```

---

### Task 6: Part A — remove the source map, rename the toolkit, rewire every site

**Files:**
- Delete: `config/source.json`, `tests/Unit/Config/SourceMapIntegrityTest.php`, `prompts/tools/coqui-source.md`
- Rename: `src/Toolkit/CoquiSourceToolkit.php` → `src/Toolkit/CoquiDocsToolkit.php`; `tests/Unit/Toolkit/CoquiSourceToolkitTest.php` → `tests/Unit/Toolkit/CoquiDocsToolkitTest.php`
- Create: `prompts/tools/coqui-docs.md`
- Modify: `src/Agent/OrchestratorAgent.php:162, :484, :636-641`; `src/Contract/CoquiDefaults.php:200`; `src/Agent/AgentRunner.php:2172`; `src/Tool/SpawnAgentTool.php:319, :333, :338`; `prompts/tools/workspace.md:10-11`; `config/roles/plan.md:28`; `AGENTS.md`; `tests/Unit/Config/RoleParserTest.php:134,141`; `tests/Unit/Config/RoleToolkitResolverTest.php:65,69`

**Interfaces:**
- Consumes: the three `coqui_docs_*` tools proven in Tasks 3–5.
- Produces: `CoquiBot\Coqui\Toolkit\CoquiDocsToolkit`, constructor `new CoquiDocsToolkit(projectRoot: string)`, exposing exactly three tools. Prompt slug `coqui-docs`.

**Context:** Nothing is destroyed — `config/source.json` stays in git history (`git show 31937bc:config/source.json`) if its 340 descriptions are ever wanted. `config/source.json` is referenced by no build, release, Docker, or composer path (verified), so removal is clean.

- [ ] **Step 1: Write the failing removal test**

Create `tests/Unit/Toolkit/DocsToolkitRemovalTest.php`:

```php
<?php

declare(strict_types=1);

$projectRoot = dirname(__DIR__, 3);

it('has deleted the source map and its integrity test', function () use ($projectRoot) {
    expect(file_exists($projectRoot . '/config/source.json'))->toBeFalse()
        ->and(file_exists($projectRoot . '/tests/Unit/Config/SourceMapIntegrityTest.php'))->toBeFalse()
        ->and(file_exists($projectRoot . '/prompts/tools/coqui-source.md'))->toBeFalse();
});

it('leaves no reference to the removed toolkit, tools, or slug', function () use ($projectRoot) {
    $stale = ['CoquiSourceToolkit', 'coqui_source_map', 'coqui_read', 'coqui_list', 'coqui_search', 'coqui-source', 'coqui_doc_map', 'coqui_doc_read'];
    $found = [];

    foreach (['src', 'prompts', 'config', 'tests'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot . '/' . $dir, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() === basename(__FILE__)) {
                continue;
            }

            $content = file_get_contents($file->getPathname());

            foreach ($stale as $needle) {
                if (str_contains($content, $needle)) {
                    $found[] = $file->getPathname() . ' → ' . $needle;
                }
            }
        }
    }

    expect($found)->toBe([]);
});

it('exposes exactly the three coqui_docs_* tools', function () use ($projectRoot) {
    $toolkit = new \CoquiBot\Coqui\Toolkit\CoquiDocsToolkit(projectRoot: $projectRoot);

    $names = array_map(
        static fn ($tool): string => $tool->toFunctionSchema()['function']['name'],
        $toolkit->tools(),
    );
    sort($names);

    expect($names)->toBe(['coqui_docs_map', 'coqui_docs_read', 'coqui_docs_search']);
});
```

Note the test skips itself when scanning — it necessarily contains the stale names as needles.

- [ ] **Step 2: Run the test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Toolkit/DocsToolkitRemovalTest.php`
Expected: FAIL — `config/source.json` still exists; `CoquiDocsToolkit` not found.

- [ ] **Step 3: Delete the map and rename the files**

```bash
git rm config/source.json tests/Unit/Config/SourceMapIntegrityTest.php
git mv prompts/tools/coqui-source.md prompts/tools/coqui-docs.md
git mv src/Toolkit/CoquiSourceToolkit.php src/Toolkit/CoquiDocsToolkit.php
git mv tests/Unit/Toolkit/CoquiSourceToolkitTest.php tests/Unit/Toolkit/CoquiDocsToolkitTest.php
```

- [ ] **Step 4: Strip the toolkit down to the three docs tools**

In `src/Toolkit/CoquiDocsToolkit.php`:

- Rename the class to `CoquiDocsToolkit`.
- Delete `sourceMapTool()`, `readTool()`, `listTool()`, `searchTool()`, `docMapTool()`, `docReadTool()`, `resolveGlobStandard()`, `resolveGlobRecursive()`, `isWithinProjectRoot()`, `makeRelative()`, and the `MAX_GLOB_RESULTS` constant.
- Delete the `$sourceMapPath` and `$docMapPath` fields (`DocumentationIndex` owns index location now).
- Drop the now-unused `BoolParameter` import; keep `resolvePath()` (still guards `coqui_docs_read`).
- `tools()` returns exactly `[$this->docsMapTool(), $this->docsReadTool(), $this->docsSearchTool()]`.

Replace the class docblock and constructor:

```php
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
```

Replace `guidelines()` entirely. The old text said "*Start with `coqui_source_map`*" and "*The source map describes every core file*" — that "start here, load everything" framing must not survive:

```php
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
```

- [ ] **Step 5: Rewrite the prompt file**

Replace the contents of `prompts/tools/coqui-docs.md`:

```markdown
## Coqui Documentation Access

You have read-only access to your own shipped documentation via the `coqui_docs_*` tools:

- `coqui_docs_search`: Full-text search across all Coqui docs — returns the doc path, nearest heading, and a snippet
- `coqui_docs_map`: List what documentation exists; pass `file` to see one doc's section headings
- `coqui_docs_read`: Read one section of a doc (e.g. file: "docs/CONFIGURATION.md", section: "model")

Search first when you know what you are looking for, then read the section it points to. Read only what the question needs.

These tools are read-only. To write files, use the workspace file tools.
```

- [ ] **Step 6: Rewire every call site**

`src/Agent/OrchestratorAgent.php:162` — in `TOOLKIT_PROMPT_SLUG_MAP`:
```php
        'CoquiDocsToolkit' => 'coqui-docs',
```

`src/Agent/OrchestratorAgent.php:484`:
```php
        // Docs toolkit — read-only access to Coqui's own shipped documentation
        $this->addSystemToolkit('CoquiDocsToolkit', "Read Coqui's own documentation", new CoquiDocsToolkit(projectRoot: $this->projectRoot));
```
Update the `use` import for `CoquiSourceToolkit` in the same file. `:636-641` needs no edit — it iterates `TOOLKIT_PROMPT_SLUG_MAP`, so fixing `:162` carries it.

`src/Contract/CoquiDefaults.php:200` — in `SYSTEM_TOOLKITS`, `'CoquiSourceToolkit'` → `'CoquiDocsToolkit'`. Do **not** add it to `LEAN_CORE_TOOLKITS` — it must keep deferring under the lean default.

`src/Agent/AgentRunner.php:2172`:
```php
                new \CoquiBot\Coqui\Toolkit\CoquiDocsToolkit(projectRoot: $this->projectRoot),
```

`src/Tool/SpawnAgentTool.php:319, :333, :338` — all three presets:
```php
                new CoquiDocsToolkit(projectRoot: $this->projectRoot),
```
plus the `use` import.

`prompts/tools/workspace.md:10-11` — replace:
```markdown
To read Coqui's own documentation, use the `coqui_docs_search`, `coqui_docs_map`,
and `coqui_docs_read` tools. These provide read-only access
```
(preserve whatever sentence continues on the following line).

`config/roles/plan.md:28` — replace:
```markdown
- Use `coqui_docs_search` to find the documentation for a subsystem before planning changes to it.
```

`tests/Unit/Config/RoleParserTest.php:134,141` and `tests/Unit/Config/RoleToolkitResolverTest.php:65,69` — `CoquiSourceToolkit` → `CoquiDocsToolkit` in the fixture strings and assertions.

`tests/Unit/Toolkit/CoquiDocsToolkitTest.php` — rename the helper `coquiSourceFindTool` → `coquiDocsFindTool` (and its call sites), swap the `use` import and every `new CoquiSourceToolkit` to `CoquiDocsToolkit`, and **delete every test for the removed tools** (`coqui_source_map`, `coqui_read`, `coqui_list`, `coqui_search`, `coqui_doc_map`, `coqui_doc_read`), including the `source.json` fixture at `:34-49`.

- [ ] **Step 7: Update AGENTS.md**

Three edits:

1. **Delete** the entire `## Source Map Maintenance` section.
2. **Delete** item 5 of the Practical Change Checklist (`5. Update config/source.json if source structure or responsibility changed.`) and renumber item 6 → 5.
3. **Replace** the `## Generated: config/documentation.json` section body:

```markdown
`config/documentation.json` is a **generated** index of the project's documentation, produced by `scripts/generate-documentation-index.php` (a thin wrapper over `src/Config/DocumentationIndex.php`). It is intentionally **not tracked in git** — never hand-edit or commit it.

The file list is **globbed**, not listed: every `docs/*.md` plus `README.md` and `AGENTS.md` is indexed the moment it exists. Per-doc `title` and `description` come from each doc's own YAML frontmatter, falling back to its H1 and first paragraph. There is no allowlist to update — adding a doc is enough.

When you add a doc under `docs/`, give it frontmatter:

```yaml
---
title: Loops
description: Loop system: definitions, stages, iteration control, and API endpoints
---
```

The index is regenerated automatically in the release and Docker builds, so shipped artifacts always carry a current one. Run `composer regen-docs` to refresh it locally. `CoquiDocsToolkit` treats it as a cache — when it is absent (a fresh checkout), the index is derived from disk at call time. Keeping it out of version control stops parallel doc branches from colliding on a machine-generated file.
```

- [ ] **Step 8: Run the removal test**

Run: `./vendor/bin/pest tests/Unit/Toolkit/DocsToolkitRemovalTest.php`
Expected: PASS — 3 passed. If the reference scan fails, it prints the exact file and stale needle.

- [ ] **Step 9: Verify the toolkit still registers and still defers under lean**

Run: `./vendor/bin/pest tests/Unit/Agent tests/Unit/Config tests/Unit/Tool tests/Unit/Toolkit`
Expected: PASS. This covers the DoD requirements that the toolkit registers, defers under the lean profile, and that `SpawnAgentTool` presets and `AgentRunner` reviewer toolkits still construct. If no existing test asserts lean-profile deferral for this toolkit, add one:

```php
it('defers the docs toolkit under the lean profile', function () {
    expect(\CoquiBot\Coqui\Contract\CoquiDefaults::SYSTEM_TOOLKITS)->toContain('CoquiDocsToolkit')
        ->and(\CoquiBot\Coqui\Contract\CoquiDefaults::LEAN_CORE_TOOLKITS)->not->toContain('CoquiDocsToolkit');
});
```

- [ ] **Step 10: Full validation**

```bash
composer regen-docs   # expect: 20 files indexed
composer test         # expect: >= 2370 passing, 0 failures
composer analyse      # expect: [OK] 382/382 (or more files, 0 errors)
```

If the PHPStan file count dropped below 382, that is expected — `CoquiSourceToolkit.php` and `SourceMapIntegrityTest.php` are gone and `DocumentationIndex.php` is new. What must hold is **0 errors**.

- [ ] **Step 11: Commit**

```bash
git add -A
git commit -m "feat(docs)!: remove the source map, rename to CoquiDocsToolkit

Deletes config/source.json (340 hand-written entries, ~76K tokens, 15
ghost methods, three phantom API endpoints) and all read-only source
access, which ShellToolkit and FileSystemToolkit already cover where
they are enabled.

CoquiSourceToolkit becomes CoquiDocsToolkit and serves three tools over
the generated docs index: coqui_docs_map, coqui_docs_read, and
coqui_docs_search. The map stays recoverable from git history."
```

---

## Final Verification

- [ ] `composer regen-docs` reports **20 files indexed**
- [ ] `composer test` — no regression against the 2370 baseline
- [ ] `composer analyse` — `[OK]`, 0 errors
- [ ] `git status --porcelain config/documentation.json` is empty (still ignored)
- [ ] `grep -rn 'CoquiSourceToolkit\|coqui_source_map\|coqui-source\|coqui_doc_map' src/ prompts/ config/ tests/` returns nothing but `DocsToolkitRemovalTest.php`
- [ ] Whole-branch review via superpowers:requesting-code-review before the PR

**Note on `docs/superpowers/**`:** the historical specs and plans under that tree mention the removed names throughout. They are a record of past decisions and are **out of scope** — the removal test scans `src/`, `prompts/`, `config/`, and `tests/` only, and the index globs `docs/*.md`, not `docs/**/*.md`.
