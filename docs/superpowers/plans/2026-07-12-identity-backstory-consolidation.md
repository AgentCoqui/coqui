# Identity & Backstory Consolidation — Effort 1 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Strip the backstory *generator* out of Coqui core into a standalone `coqui-toolkit-backstory` package, leaving core with markdown-only identity loading — `soul.md` + `backstory.md` + a new `context/*.md` tier — with the `context` block gated and pinned.

**Architecture:** Core becomes a pure *consumer* of identity markdown files inside the persona directory; it never generates them. A new persona-scoped `context/` reader loads supplementary notes and joins the pinned identity tier (below backstory). Everything that *produces* `backstory.md` — extractors, assembler, manifest, inspection, `/backstory`, auto-regen — relocates to a separate optional Composer package that self-registers its REPL command and declares its extractors, absorbing the existing `coqui-toolkit-backstory-formats` mod.

**Tech Stack:** PHP 8.4 (strict types), Pest 4 (tests), PHPStan 2 (static analysis), Composer. Toolkit self-registration via `extra.php-agents.toolkits` / `extra.php-agents.backstoryExtractors` and the `ReplCommandProvider` contract.

**Source of truth:** `docs/superpowers/specs/2026-07-12-identity-backstory-consolidation-design.md` (both implementer's-choice flags resolved).

**Branch:** `feat/identity-backstory-consolidation` (off merged main). Do NOT touch `main` or the deleted `feat/artifacts-files-only`. `feat/backstory-formats-extraction` has no commits ahead of main — ignore it.

## Global Constraints

- **PHP 8.4**, `declare(strict_types=1);` in every file, `final` by default, constructor injection, one class per file, 4-space indent, concise "why" comments. (AGENTS.md coding standards.)
- **No new core dependencies.** No new DB columns. No new parallel systems. (Spec § "Less-is-more".)
- **Composition order (exact):** `soul → backstory → context → memories → preferences → body → deferred → project`. Context is inserted immediately after backstory in the pinned identity tier. (Spec § 3.)
- **Context is persona-owned:** read only from `{personaPath}/context/*.md` — no profile→workspace→default fallback (unlike soul/backstory). Missing dir ⇒ no section, no error. (Spec § 2.)
- **`context` is a first-class `prompt_sections` gate** added to the existing gate map — the budget escape valve, since soul + backstory + context are all pinned every turn. Keep context lean. (Spec § 3, review note 1.)
- **Core `/prompt` reports its own composition** (what core loaded: soul/backstory/context presence + tokens it already computes). Core does NOT inspect the generator; the rich source-level summary belongs to the toolkit. (Spec § 3.)
- **Behavior change (release note required):** after this change, a persona's `backstory/` source dir no longer auto-builds `backstory.md` unless the toolkit is installed. Existing personas relying on auto-regen must install `coqui-toolkit-backstory`. (Review note 2.)
- **API breaking change (release note required):** the 6 `…/profiles/{name}/backstory…` + `/server/backstory` HTTP routes are removed from core. No toolkit API-route provider exists today, so they are not transparently re-hosted; the `PUT /config` backstory-string write stays. Re-exposing HTTP is a future follow-up gated on an API extension point.
- **Effort 2 (`profile → persona` rename) is OUT OF SCOPE.** Code and docs keep saying "profile"/`profiles/` here.
- **Verification commands:** `./vendor/bin/pest <path>` (targeted), `composer test` (full), `./vendor/bin/phpstan analyse` (static).

---

## File Structure

### Phase 1 — Core: `context/` support (committed to core branch)

- Create `src/Prompt/PersonaContextReader.php` — globs `{personaPath}/context/*.md`, numbered-first natural sort, reads + concatenates into one `## Context` block. Single responsibility: turn a persona dir into context markdown (or null).
- Modify `src/Prompt/PromptLoader.php` — add `buildContextContent()` (mirrors `buildBackstoryContent()`); emit a `context` entry in `buildSystemPrompt()` and `buildSystemPromptSections()`; add `context` stub support.
- Modify `src/Agent/OrchestratorPrompt.php` — add `renderContext()`.
- Modify `src/Agent/OrchestratorAgent.php` — `buildProfileIdentityParts()` returns context; add a pinned `prompt.context` section in the role path and a `context` case in `classifyInstructionPromptSection`; add `context` to the stub map; insert context in the cached-instructions composition.
- Modify `src/Config/ProfilePreferences.php` — add `context` to `ALLOWED_PROMPT_SECTIONS` (and `context` to `ALLOWED_LABELS` — optional label).
- Tests: `tests/Unit/Prompt/PersonaContextReaderTest.php`, `tests/Unit/Prompt/PromptLoaderContextTest.php`, extend an orchestrator section test.

### Phase 2 — Core: excise the generator + rewire (committed to core branch)

- Create `src/Support/TimestampFormatter.php` — relocated `formatNullableTimestamp` (neutral helper; currently a static on the REPL BackstoryHandler but used by non-backstory prompt-source tables).
- Delete `src/Backstory/` (entire tree), `src/Api/Handler/BackstoryHandler.php`, `src/Repl/Handler/BackstoryHandler.php`.
- Modify `src/Command/ApiCommand.php` — remove backstory import/construct/param/routes.
- Modify `src/Command/RunCommand.php` — remove REPL BackstoryHandler injection + auto-regen block.
- Modify `src/Repl/SlashCommandRouter.php` — remove backstory import/ctor-param/dispatch/`handleBackstory`/`/prompt` summary; repoint `formatNullableTimestamp` to `TimestampFormatter`.
- Delete `tests/Unit/Backstory/*`, `tests/Unit/Repl/PromptBackstoryPresentationTest.php`, `tests/Fixtures/Backstory`.
- Modify `config/source.json` (drop `src/Backstory/*`), `docs/PROFILES.md`, `docs/COMMANDS.md`, `docs/API.md`, release notes.
- Keep `src/Api/Handler/ConfigHandler.php` (backstory string write) unchanged.

### Phase 3 — `coqui-toolkit-backstory` package (separate repo/package, NOT committed to the core branch)

- New package `coqui-toolkit-backstory/` with `composer.json`, PSR-4 `CoquiBot\Toolkits\Backstory\`, `extra.php-agents.toolkits` + `extra.php-agents.backstoryExtractors`, deps `phpoffice/phpword`, `smalot/pdfparser`, `league/html-to-markdown`.
- `src/BackstoryToolkit.php` (implements `ToolkitInterface` + `ReplCommandProvider`), `src/Command/BackstoryCommandHandler.php` (from the deleted REPL handler), the relocated assembler/manifest/inspection/discovery/inventory/result/entry classes, `src/Extractor/*` (all extractors + interface + factory + discovery, namespace-updated), and the relocated tests.

---

## Phase 1 — Core: `context/` support

### Task 1: `PersonaContextReader`

**Files:**
- Create: `src/Prompt/PersonaContextReader.php`
- Test: `tests/Unit/Prompt/PersonaContextReaderTest.php`

**Interfaces:**
- Produces: `final readonly class PersonaContextReader { public function read(string $personaPath): ?string }` — returns a single markdown block beginning with `## Context`, or `null` when `{personaPath}/context/` is absent or holds no readable `.md`. Files are ordered numbered-first natural sort; each file body is appended, separated by a blank line, under a `### <filename-without-ext>` subheading.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Prompt\PersonaContextReader;

it('returns null when no context dir exists', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir, 0777, true);

    expect((new PersonaContextReader())->read($dir))->toBeNull();
});

it('reads and orders context files numbered-first', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir . '/context', 0777, true);
    file_put_contents($dir . '/context/stack.md', "# Stack\nPHP 8.4");
    file_put_contents($dir . '/context/01-github.md', "# GitHub\nuser: carmelo");

    $out = (new PersonaContextReader())->read($dir);

    expect($out)->toStartWith('## Context');
    // Numbered file sorts before the unnumbered one.
    expect(strpos($out, 'GitHub'))->toBeLessThan(strpos($out, 'Stack'));
    // Per-file subheading derived from filename.
    expect($out)->toContain('### 01-github')->toContain('### stack');
});

it('returns null when context dir is empty of markdown', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir . '/context', 0777, true);
    file_put_contents($dir . '/context/notes.txt', 'ignored');

    expect((new PersonaContextReader())->read($dir))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Prompt/PersonaContextReaderTest.php`
Expected: FAIL — class `PersonaContextReader` not found.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Prompt;

/**
 * Reads a persona's supplementary context notes (context/*.md) into one
 * pinned markdown block. Persona-owned: no workspace/default fallback.
 */
final readonly class PersonaContextReader
{
    public function read(string $personaPath): ?string
    {
        $dir = rtrim($personaPath, '/') . '/context';
        if (!is_dir($dir)) {
            return null;
        }

        $files = glob($dir . '/*.md') ?: [];
        $this->naturalSort($files);

        $parts = [];
        foreach ($files as $file) {
            $body = file_get_contents($file);
            if ($body === false || trim($body) === '') {
                continue;
            }
            $title = pathinfo($file, PATHINFO_FILENAME);
            $parts[] = "### {$title}\n\n" . trim($body);
        }

        if ($parts === []) {
            return null;
        }

        return "## Context\n\n" . implode("\n\n", $parts);
    }

    /**
     * Numbered-prefixed files first (natural order), then the rest alphabetically.
     *
     * @param list<string> $files
     */
    private function naturalSort(array &$files): void
    {
        usort($files, static function (string $a, string $b): int {
            $an = pathinfo($a, PATHINFO_FILENAME);
            $bn = pathinfo($b, PATHINFO_FILENAME);
            $aNum = preg_match('/^\d+/', $an) === 1;
            $bNum = preg_match('/^\d+/', $bn) === 1;
            if ($aNum !== $bNum) {
                return $aNum ? -1 : 1;
            }
            return strnatcasecmp($an, $bn);
        });
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Prompt/PersonaContextReaderTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Prompt/PersonaContextReader.php tests/Unit/Prompt/PersonaContextReaderTest.php
git commit -m "feat(prompt): add PersonaContextReader for context/*.md notes"
```

---

### Task 2: `PromptLoader::buildContextContent()` + composition wiring

**Files:**
- Modify: `src/Prompt/PromptLoader.php` (add `buildContextContent()`; wire into `buildSystemPrompt()` after backstory at `:355-358`; wire a `context` entry into `buildSystemPromptSections()` after the backstory entry at `:397-415`)
- Test: `tests/Unit/Prompt/PromptLoaderContextTest.php`

**Interfaces:**
- Consumes: `PersonaContextReader::read()` (Task 1); the existing private `shouldIncludePromptSection(string)`, `isPromptSectionStubbed(string)`, `buildStubContent(string)`, `buildStubSectionEntry(string,string,string)`, and the `$this->profilePath` field.
- Produces: `PromptLoader::buildContextContent(): ?string`; a `{id:'context', title:'Context', content:..., source:...}` entry emitted immediately after `backstory` in `buildSystemPromptSections()`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Prompt\PromptLoader;

function makePersonaWithContext(): string {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir . '/context', 0777, true);
    file_put_contents($dir . '/soul.md', "# Soul\nBe kind.");
    file_put_contents($dir . '/context/github.md', "# GitHub\nuser: carmelo");
    return $dir;
}

it('builds context content from the persona context dir', function () {
    $persona = makePersonaWithContext();
    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        profilePath: $persona,
    );

    expect($loader->buildContextContent())->toContain('## Context')->toContain('GitHub');
});

it('emits a context section right after backstory in system prompt sections', function () {
    $persona = makePersonaWithContext();
    file_put_contents($persona . '/backstory.md', "# Backstory\nBorn in a repo.");
    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        profilePath: $persona,
    );

    $ids = array_column($loader->buildSystemPromptSections(), 'id');
    $backstoryPos = array_search('backstory', $ids, true);
    $contextPos = array_search('context', $ids, true);

    expect($contextPos)->not->toBeFalse();
    expect($contextPos)->toBe($backstoryPos + 1);
});

it('omits context when the persona has no context dir', function () {
    $dir = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($dir, 0777, true);
    file_put_contents($dir . '/soul.md', '# Soul');
    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        profilePath: $dir,
    );

    expect($loader->buildContextContent())->toBeNull();
    expect(array_column($loader->buildSystemPromptSections(), 'id'))->not->toContain('context');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Prompt/PromptLoaderContextTest.php`
Expected: FAIL — `buildContextContent` undefined / no `context` section.

- [ ] **Step 3: Add `buildContextContent()` after `buildBackstoryContent()` (near `src/Prompt/PromptLoader.php:272`)**

```php
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
```

Add the import at the top of the file:

```php
use CoquiBot\Coqui\Prompt\PersonaContextReader;
```

(If `PromptLoader` is already in the `CoquiBot\Coqui\Prompt` namespace, reference `PersonaContextReader` directly without a `use`.)

- [ ] **Step 4: Wire into `buildSystemPrompt()` — insert after the backstory block (`src/Prompt/PromptLoader.php:358`)**

```php
        $backstory = $this->buildBackstoryContent();
        if ($backstory !== null) {
            $sections[] = $backstory;
        }

        $context = $this->buildContextContent();
        if ($context !== null) {
            $sections[] = $context;
        }
```

- [ ] **Step 5: Wire a `context` entry into `buildSystemPromptSections()` — insert after the backstory entry block (`src/Prompt/PromptLoader.php:415`)**

```php
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
```

- [ ] **Step 6: Verify `buildStubContent('context')` / `buildStubSectionEntry` handle the new slug**

Read `buildStubContent()` and `buildStubSectionEntry()` in `src/Prompt/PromptLoader.php`. If they `match` on known slugs, add a `context` arm returning e.g. `"## Context\n\nSupplementary persona context is intentionally condensed for this profile."`. If they already have a generic default, no change needed.

- [ ] **Step 7: Run tests**

Run: `./vendor/bin/pest tests/Unit/Prompt/PromptLoaderContextTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Commit**

```bash
git add src/Prompt/PromptLoader.php tests/Unit/Prompt/PromptLoaderContextTest.php
git commit -m "feat(prompt): load persona context/*.md after backstory"
```

---

### Task 3: `context` prompt-section gate (and optional label)

**Files:**
- Modify: `src/Config/ProfilePreferences.php:25-36` (`ALLOWED_PROMPT_SECTIONS`) and `:39` (`ALLOWED_LABELS`)
- Test: `tests/Unit/Config/ProfilePreferencesContextGateTest.php`

**Interfaces:**
- Produces: `context` accepted as a valid `prompts.prompt_sections` key (values `true|false|"stub"`); `context` accepted as a valid `prompts.labels` key.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ProfilePreferences;

it('accepts context as a prompt_sections gate', function () {
    $prefs = ProfilePreferences::fromArray([
        'prompts' => ['prompt_sections' => ['context' => false]],
    ]);

    expect($prefs->validationErrors)->toBe([]);
    expect($prefs->isPromptSectionEnabled('context', true))->toBeFalse();
});

it('accepts context stub mode', function () {
    $prefs = ProfilePreferences::fromArray([
        'prompts' => ['prompt_sections' => ['context' => 'stub']],
    ]);

    expect($prefs->validationErrors)->toBe([]);
    expect($prefs->isPromptSectionStubbed('context'))->toBeTrue();
});
```

> If `ProfilePreferences` has no `fromArray()`, construct via its actual factory (check the class — it exposes `effectivePrompts()`/`isPromptSectionEnabled()`/`isPromptSectionStubbed()`). Adjust the test to the real constructor/parse entry point; the assertions on `context` validity are what matters.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/ProfilePreferencesContextGateTest.php`
Expected: FAIL — `Unknown prompts.prompt_sections entry "context"`.

- [ ] **Step 3: Add `context` to the allowed sections and labels**

In `src/Config/ProfilePreferences.php`, add `'context',` to `ALLOWED_PROMPT_SECTIONS` (place it right after `'backstory',` to mirror composition order):

```php
    private const array ALLOWED_PROMPT_SECTIONS = [
        'soul',
        'backstory',
        'context',
        'base',
        'memory',
        'preferences',
        'tools',
        'security',
        'done',
        'deferred_toolkits',
        'project_context',
    ];
```

And add `context` to labels:

```php
    private const array ALLOWED_LABELS = ['backstory', 'context'];
```

- [ ] **Step 4: Run tests**

Run: `./vendor/bin/pest tests/Unit/Config/ProfilePreferencesContextGateTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Config/ProfilePreferences.php tests/Unit/Config/ProfilePreferencesContextGateTest.php
git commit -m "feat(config): add first-class context prompt_sections gate + label"
```

---

### Task 4: Pin `context` in `OrchestratorAgent` (role path, orchestrator path, cached path, stub)

**Files:**
- Modify: `src/Agent/OrchestratorPrompt.php` (add `renderContext()`)
- Modify: `src/Agent/OrchestratorAgent.php`:
  - `buildProfileIdentityParts()` (`:1070-1107`) — also produce context.
  - `buildInstructionPromptSections()` role path (`:1682-1707`) — add a pinned `prompt.context` section after backstory.
  - `classifyInstructionPromptSection()` (`:1769`) — add a `context` case.
  - `buildProfilePromptSectionStub()` (`:1141`) — add a `context` arm.
  - Cached-instructions composition (`:957-964`) — insert context after backstory (with `downshiftHeadings`).
- Test: `tests/Unit/Agent/OrchestratorContextSectionTest.php` (or extend the nearest existing orchestrator section test)

**Interfaces:**
- Consumes: `PromptLoader::buildContextContent()` (Task 2); `OrchestratorPrompt::renderContext()`.
- Produces: a `PromptSection` with `id: 'prompt.context'`, `group: 'identity'`, `priority: PromptSectionPriority::Critical`, emitted immediately after `prompt.backstory` in both the role path and the classified orchestrator path.

- [ ] **Step 1: Add `renderContext()` to `OrchestratorPrompt` (`src/Agent/OrchestratorPrompt.php`, after `renderBackstory()` at `:70`)**

```php
    /**
     * Return the context section (supplementary persona notes).
     *
     * Returns null if no context/*.md exists in the active persona.
     */
    public function renderContext(): ?string
    {
        return $this->loader->buildContextContent();
    }
```

- [ ] **Step 2: Extend `buildProfileIdentityParts()` to also return context (`src/Agent/OrchestratorAgent.php:1070`)**

Change the return shape from `[$soul, $backstory]` to `[$soul, $backstory, $context]`. After the backstory block (`:1104`), add:

```php
        // Context — supplementary persona notes (context/*.md)
        $context = null;
        if ($this->isProfilePromptSectionEnabled('context')) {
            if ($this->isProfilePromptSectionStubbed('context')) {
                $context = $this->buildProfilePromptSectionStub('context');
            } else {
                $ctx = (new \CoquiBot\Coqui\Prompt\PersonaContextReader())->read($this->activeProfilePath);
                if ($ctx !== null && trim($ctx) !== '') {
                    $context = $ctx;
                }
            }
        }

        return [$soul, $backstory, $context];
```

Update the method's docblock return type to `array{?string, ?string, ?string}`. Update the two callers:
- `buildProfileIdentityPreamble()` (`:1157`) — `[$soul, $backstory, $context] = $this->buildProfileIdentityParts();` and include `$context` in the `array_filter`.
- `resolvePrimaryInstructionParts()` (`:1050`) — it destructures `[$soul, $backstory]`; change to `[$soul, $backstory, $context]` and thread context into the role-path return (see Step 4) — for now capture it.

- [ ] **Step 3: Add the pinned `prompt.context` section in the role path (`src/Agent/OrchestratorAgent.php`, after the backstory section at `:1707`)**

```php
            [$soul, $backstory, $context] = $this->buildProfileIdentityParts();
```

(replacing the existing `[$soul, $backstory] = ...` at `:1682`), then after the `if ($backstory !== null …)` block:

```php
            if ($context !== null && trim($context) !== '') {
                $sections[] = new PromptSection(
                    id: 'prompt.context',
                    title: 'Context',
                    content: $context,
                    priority: PromptSectionPriority::Critical,
                    rationale: 'Supplementary persona context is part of identity and stays pinned with soul and backstory.',
                    decision: 'pinned_critical',
                    group: 'identity',
                    source: $this->activeProfilePath !== null ? rtrim($this->activeProfilePath, '/') . '/context' : null,
                );
            }
```

- [ ] **Step 4: Add a `context` case to `classifyInstructionPromptSection()` (`src/Agent/OrchestratorAgent.php:1782`, after the `backstory` arm)**

```php
            'context' => new PromptSection(
                id: 'prompt.context',
                title: $title,
                content: $content,
                priority: PromptSectionPriority::Critical,
                rationale: 'Supplementary persona context is part of identity and stays pinned with soul and backstory.',
                decision: 'pinned_critical',
                group: 'identity',
                source: $source,
            ),
```

This handles the orchestrator (no-role) path: `buildSystemPromptSections()` (Task 2, Step 5) emits an `id: 'context'` entry that flows through `renderSections()` → `classifyInstructionPromptSection()`.

- [ ] **Step 5: Add a `context` arm to `buildProfilePromptSectionStub()` (`src/Agent/OrchestratorAgent.php:1141`)**

```php
            'context' => '## Context' . "\n\n" . 'Supplementary persona context is intentionally condensed for this profile.',
```

- [ ] **Step 6: Insert context in the cached-instructions composition (`src/Agent/OrchestratorAgent.php:957-964`)**

`resolvePrimaryInstructionParts()` now returns context. In the cached-path composition, after the backstory block:

```php
        if ($backstory !== null && trim($backstory) !== '') {
            $parts[] = $this->downshiftHeadings($backstory);
        }

        if ($context !== null && trim($context) !== '') {
            $parts[] = $this->downshiftHeadings($context);
        }
```

Update `resolvePrimaryInstructionParts()` to return `[$soul, $backstory, $context, $body]` (orchestrator path pulls `$context = $prompt->renderContext()`), and update the destructure at `:949` accordingly.

- [ ] **Step 7: Write the section test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Prompt\PromptLoader;

it('places context immediately after backstory in classified sections', function () {
    $persona = sys_get_temp_dir() . '/persona_' . uniqid();
    mkdir($persona . '/context', 0777, true);
    file_put_contents($persona . '/soul.md', '# Soul');
    file_put_contents($persona . '/backstory.md', '# Backstory');
    file_put_contents($persona . '/context/github.md', '# GitHub');

    $loader = new PromptLoader(
        promptsDir: dirname(__DIR__, 3) . '/prompts',
        placeholders: [],
        workspacePath: sys_get_temp_dir(),
        profilePath: $persona,
    );

    $ids = array_column($loader->buildSystemPromptSections(), 'id');
    expect(array_search('context', $ids, true))->toBe(array_search('backstory', $ids, true) + 1);
});
```

> This asserts the loader-level ordering that the orchestrator classifier consumes. If an orchestrator-level fixture test already exists (`grep -rl "prompt.backstory" tests/`), add a mirror assertion for `prompt.context` there too.

- [ ] **Step 8: Run tests + static analysis**

Run: `./vendor/bin/pest tests/Unit/Agent/ tests/Unit/Prompt/ && ./vendor/bin/phpstan analyse src/Agent/OrchestratorAgent.php src/Agent/OrchestratorPrompt.php src/Prompt/PromptLoader.php`
Expected: PASS; no PHPStan errors.

- [ ] **Step 9: Commit**

```bash
git add src/Agent/OrchestratorAgent.php src/Agent/OrchestratorPrompt.php tests/Unit/Agent/OrchestratorContextSectionTest.php
git commit -m "feat(agent): pin persona context in identity tier after backstory"
```

---

### Task 5: Phase 1 integration check

- [ ] **Step 1: Full suite green with context added**

Run: `composer test`
Expected: PASS (context is additive; no existing test should regress).

- [ ] **Step 2: Static analysis clean**

Run: `./vendor/bin/phpstan analyse`
Expected: no errors.

- [ ] **Step 3: Manual composition smoke check**

Create a throwaway persona with `soul.md` + `backstory.md` + `context/01-github.md`, point a session at it, run `/prompt`, and confirm a `## Context` block renders after backstory and before base. (Or assert via a Pest test that drives `OrchestratorPrompt::render()`.)

---

## Phase 2 — Core: excise the generator + rewire

> Do Phase 3 (author the toolkit) BEFORE deleting here if you want the code preserved in the toolkit repo first; the two repos are independent, so ordering is a safety preference, not a git dependency.

### Task 6: Relocate `formatNullableTimestamp` to a neutral helper

**Files:**
- Create: `src/Support/TimestampFormatter.php`
- Modify: `src/Repl/SlashCommandRouter.php:310,325` (repoint to the new helper)
- Test: `tests/Unit/Support/TimestampFormatterTest.php`

**Interfaces:**
- Produces: `final class TimestampFormatter { public static function formatNullable(?string $timestamp): string }` — same behavior as the current `BackstoryHandler::formatNullableTimestamp` (copy its body verbatim from `src/Repl/Handler/BackstoryHandler.php:318`).

- [ ] **Step 1: Read the current implementation**

Read `src/Repl/Handler/BackstoryHandler.php:318-340` and copy the exact formatting logic.

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Support\TimestampFormatter;

it('formats null as a dash placeholder', function () {
    expect(TimestampFormatter::formatNullable(null))->toBe(TimestampFormatter::formatNullable(null));
    expect(TimestampFormatter::formatNullable(null))->not->toBe('');
});

it('formats a valid ISO timestamp', function () {
    expect(TimestampFormatter::formatNullable('2026-07-12T10:00:00+00:00'))->toContain('2026');
});
```

> Match the exact placeholder string the original returns for null (read it in Step 1) and assert on that literal.

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Support/TimestampFormatterTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Create the helper (body copied verbatim from the original)**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Support;

/**
 * Formats nullable ISO-8601 timestamps for REPL tables.
 * Relocated from the (removed) backstory REPL handler; used by prompt-source tables.
 */
final class TimestampFormatter
{
    public static function formatNullable(?string $timestamp): string
    {
        // <copy the exact body of BackstoryHandler::formatNullableTimestamp here>
    }
}
```

- [ ] **Step 5: Repoint `SlashCommandRouter` usages (`:310`, `:325`)**

Replace `BackstoryHandler::formatNullableTimestamp(...)` with `TimestampFormatter::formatNullable(...)` at both call sites, and add `use CoquiBot\Coqui\Support\TimestampFormatter;`. (Do NOT yet remove the `BackstoryHandler` import — that happens in Task 8.)

- [ ] **Step 6: Run tests**

Run: `./vendor/bin/pest tests/Unit/Support/TimestampFormatterTest.php && ./vendor/bin/phpstan analyse src/Support/TimestampFormatter.php src/Repl/SlashCommandRouter.php`
Expected: PASS; no PHPStan errors.

- [ ] **Step 7: Commit**

```bash
git add src/Support/TimestampFormatter.php src/Repl/SlashCommandRouter.php tests/Unit/Support/TimestampFormatterTest.php
git commit -m "refactor(repl): extract TimestampFormatter from backstory handler"
```

---

### Task 7: Remove backstory HTTP routes from `ApiCommand`

**Files:**
- Modify: `src/Command/ApiCommand.php` — remove import `:17`, construction `:338`, `registerRoutes` arg `:362`, param `:556`, and routes `:624-628` + `:661`.

**Interfaces:**
- Produces: `ApiCommand` no longer references `BackstoryHandler`; the 6 backstory routes are gone.

- [ ] **Step 1: Remove the routes**

Delete these lines in `registerRoutes()`:

```php
        $router->get($v1 . '/profiles/{name}/backstory', [$backstory, 'getProfile']);
        $router->get($v1 . '/profiles/{name}/backstory/entries', [$backstory, 'getEntry']);
        $router->post($v1 . '/profiles/{name}/backstory/folders', [$backstory, 'createFolder']);
        $router->put($v1 . '/profiles/{name}/backstory/entries', [$backstory, 'putEntry']);
        $router->delete($v1 . '/profiles/{name}/backstory/entries', [$backstory, 'deleteEntry']);
        $router->get($v1 . '/server/backstory', [$backstory, 'get']);
```

- [ ] **Step 2: Remove the construction, the `registerRoutes` argument, the signature param, and the import**

Delete the `$backstoryHandler = new BackstoryHandler(...)` block at `:338`, drop `$backstoryHandler` from the `registerRoutes(...)` call at `:362`, remove `BackstoryHandler $backstory,` from the `registerRoutes` signature at `:556`, and delete `use CoquiBot\Coqui\Api\Handler\BackstoryHandler;` at `:17`.

- [ ] **Step 3: Verify no dangling references**

Run: `grep -n "backstory\|Backstory" src/Command/ApiCommand.php`
Expected: no output.

- [ ] **Step 4: Static analysis**

Run: `./vendor/bin/phpstan analyse src/Command/ApiCommand.php`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add src/Command/ApiCommand.php
git commit -m "feat(api)!: remove backstory HTTP routes (move to toolkit)"
```

---

### Task 8: Remove REPL `/backstory`, the `/prompt` backstory summary, and the handler injection

**Files:**
- Modify: `src/Repl/SlashCommandRouter.php` — remove import `:12`, ctor param `:64`, dispatch `:122`, `/prompt` summary block `:267-284`, `handleBackstory()` `:410`+
- Modify: `src/Command/RunCommand.php:413` — remove the `backstory: new BackstoryHandler(...)` constructor argument

**Interfaces:**
- Produces: `SlashCommandRouter` no longer imports/constructs/dispatches backstory; `/backstory` is unregistered in core (the toolkit re-adds it via `ReplCommandProvider`).

- [ ] **Step 1: Remove the `/prompt` backstory summary block (`src/Repl/SlashCommandRouter.php:267-284`)**

Delete the entire `// Show backstory summary if generated from source files` block (the `if ($activeProfile !== null) { $summary = $this->backstory->getManifestSummary(...) ... }`). Core `/prompt` keeps the token/source tables it already renders — that is core reporting its own composition.

- [ ] **Step 2: Remove the dispatch, `handleBackstory()`, the ctor param, and the import**

- Delete `'/backstory' => $this->handleBackstory($io, $arg, $activeProfile),` at `:122`.
- Delete the entire `handleBackstory(...)` method (`:410`+).
- Remove `private readonly BackstoryHandler $backstory,` from the constructor (`:64`).
- Remove `use CoquiBot\Coqui\Repl\Handler\BackstoryHandler;` at `:12`.
- Remove any `/backstory` entry from the command list / tab-completion / help table in this file (grep within the file).

- [ ] **Step 3: Remove the handler injection in `RunCommand` (`src/Command/RunCommand.php:413`)**

Delete the `backstory: new BackstoryHandler($this->boot->profileDiscovery(), $this->boot->workspacePath()),` argument from the `SlashCommandRouter` construction, and remove the now-unused `use` import for the REPL `BackstoryHandler` if present.

- [ ] **Step 4: Verify no dangling references**

Run: `grep -n "backstory\|Backstory" src/Repl/SlashCommandRouter.php src/Command/RunCommand.php | grep -iv "TimestampFormatter"`
Expected: only the auto-regen block in `RunCommand` (removed in Task 9) may remain — nothing referencing the removed handler.

- [ ] **Step 5: Static analysis**

Run: `./vendor/bin/phpstan analyse src/Repl/SlashCommandRouter.php src/Command/RunCommand.php`
Expected: no errors (auto-regen block still present is fine until Task 9).

- [ ] **Step 6: Commit**

```bash
git add src/Repl/SlashCommandRouter.php src/Command/RunCommand.php
git commit -m "feat(repl)!: remove core /backstory command and prompt summary"
```

---

### Task 9: Remove the startup auto-regen hook

**Files:**
- Modify: `src/Command/RunCommand.php:731-739` (and the call site that invokes the auto-regen method)

**Interfaces:**
- Produces: core no longer imports `BackstoryAssembler` or auto-generates `backstory.md` on startup.

- [ ] **Step 1: Read the surrounding method**

Read `src/Command/RunCommand.php:715-750` to see the method wrapping the `$assembler = new BackstoryAssembler(); if (!$assembler->needsRegeneration(...)) ...; $assembler->generate(...)` block and where it is called during profile load.

- [ ] **Step 2: Delete the auto-regen block and its invocation**

Remove the `BackstoryAssembler` construction + `needsRegeneration`/`generate` logic (`:731-739`) and the call to the enclosing method. Remove the `use ...\BackstoryAssembler;` import if present.

- [ ] **Step 3: Verify no core references to the generator remain**

Run: `grep -rn "BackstoryAssembler\|BackstoryInspectionService\|BackstoryManifest" src/ --include=*.php`
Expected: no output.

- [ ] **Step 4: Static analysis**

Run: `./vendor/bin/phpstan analyse src/Command/RunCommand.php`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add src/Command/RunCommand.php
git commit -m "feat!: drop core backstory auto-regeneration on startup"
```

---

### Task 10: Delete the `src/Backstory/` tree, both handlers, and their core tests

**Files:**
- Delete: `src/Backstory/` (entire directory), `src/Api/Handler/BackstoryHandler.php`, `src/Repl/Handler/BackstoryHandler.php`
- Delete: `tests/Unit/Backstory/`, `tests/Unit/Repl/PromptBackstoryPresentationTest.php`, `tests/Fixtures/Backstory`

**Interfaces:**
- Produces: zero `src/Backstory` references anywhere in core `src/`.

- [ ] **Step 1: Delete the source + tests**

```bash
git rm -r src/Backstory src/Api/Handler/BackstoryHandler.php src/Repl/Handler/BackstoryHandler.php
git rm -r tests/Unit/Backstory tests/Unit/Repl/PromptBackstoryPresentationTest.php tests/Fixtures/Backstory
```

- [ ] **Step 2: Hunt residual references (imports, ConfigHandler, API tests, doctor)**

Run: `grep -rn "Backstory" src/ tests/ --include=*.php | grep -v "buildBackstoryContent\|renderBackstory\|'backstory'\|\"backstory\"\|isProfilePromptSection\|labels.backstory\|context"`
Expected: no output. Investigate and fix any hit (e.g. an API feature test asserting backstory routes — delete/adjust it; `ConfigHandler` backstory *string* write must remain and must NOT reference the deleted classes — confirm it only does file I/O).

- [ ] **Step 3: Full suite + static analysis**

Run: `composer test && ./vendor/bin/phpstan analyse`
Expected: PASS; no errors. (If a test referenced the removed HTTP routes or REPL command, remove/adjust it here.)

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat!: delete backstory generator subsystem from core"
```

---

### Task 11: Update `config/source.json` and docs

**Files:**
- Modify: `config/source.json` (drop `src/Backstory/*` entries; note relocation to `coqui-toolkit-backstory`)
- Modify: `docs/PROFILES.md`, `docs/COMMANDS.md`, `docs/API.md`
- Modify/Create: release notes entry (locate the repo's changelog/release-notes file; if none, add a short "Breaking changes" note to `docs/PROFILES.md`)

**Interfaces:** docs-only; no code.

- [ ] **Step 1: `config/source.json`**

Remove every entry whose path is under `src/Backstory/` (including the extractor map entry at `:770`), plus the `src/Api/Handler/BackstoryHandler.php` and `src/Repl/Handler/BackstoryHandler.php` entries. Add `src/Prompt/PersonaContextReader.php` and `src/Support/TimestampFormatter.php` entries with one-line responsibilities. Run `composer regen-docs` if source.json feeds the generated index (per AGENTS.md, `config/documentation.json` is generated — do not hand-edit it).

- [ ] **Step 2: `docs/PROFILES.md`**

- Delete the entire "Backstory Generator" section (source layout, supported file types, sort order, change detection, auto-regeneration, `/backstory` commands).
- In the file-structure section, replace the `backstory/` source-dir depiction with: `backstory.md` (optional, hand/agent-authored) and the new `context/` subdir (`context/*.md`, optional supplementary notes, loaded after backstory in the pinned identity tier, gated by `prompt_sections.context`).
- Update the composition order line to `soul → backstory → context → memories → preferences → body → deferred → project`.
- Add a "Breaking changes" note: the backstory generator (`backstory/` ingestion, `/backstory`, auto-regen, backstory HTTP routes) moved to the optional `coqui-toolkit-backstory` package; install it to keep source-file ingestion. Users who had `coqui-toolkit-backstory-formats` should switch to `coqui-toolkit-backstory` (it absorbs `-formats`).

- [ ] **Step 3: `docs/COMMANDS.md`**

Remove `/backstory` from the core command reference; note it is provided by the `coqui-toolkit-backstory` toolkit when installed.

- [ ] **Step 4: `docs/API.md`**

Remove the `…/profiles/{name}/backstory…` and `/server/backstory` endpoint documentation; note their removal as a breaking change and that the `PUT /config` backstory field still sets `backstory.md` content.

- [ ] **Step 5: Verify + commit**

Run: `grep -rn "/backstory\|backstory/" docs/PROFILES.md docs/COMMANDS.md docs/API.md` and confirm only intentional mentions remain.

```bash
git add config/source.json docs/PROFILES.md docs/COMMANDS.md docs/API.md
git commit -m "docs: retire backstory generator from core docs; document context/ tier"
```

---

## Phase 3 — `coqui-toolkit-backstory` package (separate package)

> **Scope note:** this package is a separate Composer package (its own repo, like `coquibot/coqui-toolkit-mcp-client`), NOT part of the core repo's `src/`. It is published and installed via `/mods install coquibot/coqui-toolkit-backstory`. These tasks are authored in the package's own working tree; they are **not** committed to `feat/identity-backstory-consolidation`. Most of Effort 1's raw volume lives here (~30 files, ~130 KB moved), but it is mechanical relocation of already-tested code — the existing tests move with it. (Review note 3.)

### Task 12: Scaffold the package

**Files (in the new package repo):**
- Create: `composer.json`, `README.md`, `phpunit.xml`, `phpstan.neon`, `.gitignore`

**Interfaces:**
- Produces: an installable package `coquibot/coqui-toolkit-backstory` with PSR-4 `CoquiBot\Toolkits\Backstory\` → `src/`, declaring `extra.php-agents.toolkits` = `["CoquiBot\\Toolkits\\Backstory\\BackstoryToolkit"]` and `extra.php-agents.backstoryExtractors` = the full extractor class list.

- [ ] **Step 1: Author `composer.json`** (model on `vendor/coquibot/coqui-toolkit-mcp-client/composer.json`)

```json
{
    "name": "coquibot/coqui-toolkit-backstory",
    "description": "Backstory generator for Coqui — ingest source files (Office/PDF/HTML/etc.) into a persona backstory.md",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "carmelosantana/php-agents": ">=0.14",
        "coquibot/coqui": "^0.12",
        "phpoffice/phpword": "^1.2",
        "smalot/pdfparser": "^2.7",
        "league/html-to-markdown": "^5.1"
    },
    "require-dev": {
        "pestphp/pest": "^4.0",
        "phpstan/phpstan": "^2.0"
    },
    "autoload": { "psr-4": { "CoquiBot\\Toolkits\\Backstory\\": "src/" } },
    "autoload-dev": { "psr-4": { "CoquiBot\\Toolkits\\Backstory\\Tests\\": "tests/" } },
    "scripts": { "test": "pest", "analyse": "phpstan analyse --memory-limit=1G" },
    "extra": {
        "php-agents": {
            "toolkits": ["CoquiBot\\Toolkits\\Backstory\\BackstoryToolkit"],
            "backstoryExtractors": [
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\TextExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\MarkdownExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\JsonExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\YamlExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\CsvExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\CodeBlockExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\SqlExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\XmlExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\RtfExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\OdtExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\OdsExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\OdpExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\XlsxExtractor",
                "CoquiBot\\Toolkits\\Backstory\\Extractor\\PptxExtractor"
            ]
        }
    },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

> Pin exact dependency constraints to whatever `coqui-toolkit-backstory-formats` used for `phpword`/`pdfparser`/`html-to-markdown` (check its composer.json if available); the versions above are placeholders to confirm before publishing.

- [ ] **Step 2: Author `README.md`, `phpunit.xml`, `phpstan.neon`, `.gitignore`** — copy structure from `coqui-toolkit-mcp-client`. README covers: what the toolkit does, supported formats, `/backstory` commands, installation.

- [ ] **Step 3: Commit (in the package repo)**

```bash
git add composer.json README.md phpunit.xml phpstan.neon .gitignore
git commit -m "chore: scaffold coqui-toolkit-backstory package"
```

---

### Task 13: Relocate the extractor layer

**Files (package):**
- Create `src/Extractor/*` from the deleted core `src/Backstory/Extractor/*` — `ExtractorInterface`, `ExtractorResult`, `ExtractorFactory`, `BackstoryExtractorDiscovery`, `BackstoryTextReader`, `OpenDocumentArchiveReader`, and all format extractors (Text, Markdown, Json, Yaml, Csv, CodeBlock, Sql, Xml, Rtf, Odt, Ods, Odp, Xlsx, Pptx).
- Create `tests/Unit/Extractor/*` from core `tests/Unit/Backstory/BackstoryExtractorDiscoveryTest.php` (+ any extractor tests).

**Interfaces:**
- Produces: extractors under `CoquiBot\Toolkits\Backstory\Extractor\` implementing the package-local `ExtractorInterface`.

- [ ] **Step 1: Copy the files and rewrite namespaces**

Copy each file; change `namespace CoquiBot\Coqui\Backstory\Extractor;` → `namespace CoquiBot\Toolkits\Backstory\Extractor;` and update any cross-imports accordingly.

- [ ] **Step 2: Update `BackstoryExtractorDiscovery`**

It reads `extra.php-agents.backstoryExtractors` from `vendor/composer/installed.json` and instantiates classes implementing the (now package-local) `ExtractorInterface`. Confirm the interface FQCN check points at `CoquiBot\Toolkits\Backstory\Extractor\ExtractorInterface`.

- [ ] **Step 3: Run extractor tests**

Run (in the package): `./vendor/bin/pest tests/Unit/Extractor`
Expected: PASS.

- [ ] **Step 4: Commit (package repo)**

```bash
git add src/Extractor tests/Unit/Extractor
git commit -m "feat: relocate backstory extractors into toolkit"
```

---

### Task 14: Relocate the assembler / manifest / inspection / inventory

**Files (package):**
- Create `src/BackstoryAssembler.php`, `src/BackstoryFileDiscovery.php`, `src/BackstoryFileEntry.php`, `src/BackstoryManifest.php`, `src/BackstorySourceInventory.php`, `src/BackstoryResult.php`, `src/BackstoryUnsupportedFileEntry.php`, `src/BackstoryInspectionService.php` (namespaces → `CoquiBot\Toolkits\Backstory\`).
- Create `tests/Unit/*` from core `tests/Unit/Backstory/BackstoryAssemblerTest.php`, `BackstoryManifestTest.php`, `BackstoryFileDiscoveryTest.php`, and the `tests/Fixtures/Backstory` fixtures.

**Interfaces:**
- Consumes: the package-local `ExtractorFactory` (Task 13).
- Produces: `BackstoryAssembler::generate(string $profilePath, ?string $headingLabel): BackstoryResult` writing `{profilePath}/backstory.md`; `needsRegeneration()`, `getManifest()` unchanged in behavior.

- [ ] **Step 1: Copy files + rewrite namespaces + fix imports** (point `ExtractorFactory` use-statements at the package namespace).

- [ ] **Step 2: Run tests**

Run: `./vendor/bin/pest tests/Unit`
Expected: PASS.

- [ ] **Step 3: Commit (package repo)**

```bash
git add src tests
git commit -m "feat: relocate backstory assembler/manifest/inspection into toolkit"
```

---

### Task 15: The toolkit class, `/backstory` command, and regen hook

**Files (package):**
- Create `src/BackstoryToolkit.php` (implements `ToolkitInterface` + `ReplCommandProvider`)
- Create `src/Command/BackstoryCommandHandler.php` (from core `src/Repl/Handler/BackstoryHandler.php`, adapted to the `ToolkitCommandHandler` contract)
- Optionally `src/Api/BackstoryApiHandler.php` (retain the source-management handler for a future API extension point; not wired without one)
- Create tests under `tests/`

**Interfaces:**
- Consumes: `ToolkitInterface`, `ReplCommandProvider`, `ToolkitCommandHandler`, `ToolkitReplContext` (from `coquibot/coqui`; see `docs/TOOLKIT-EXTENSIBILITY.md`).
- Produces: a self-registering `/backstory` command with subcommands `generate`, `failed`; the toolkit exposes it via `commandHandlers()`.

- [ ] **Step 1: Author `BackstoryCommandHandler`** implementing `ToolkitCommandHandler` (`commandName(): 'backstory'`, `subcommands(): ['generate','failed']`, `usage()`, `description()`, `handle(ToolkitReplContext $context, string $arg)`). Port the display logic from the old REPL handler; obtain the persona path from `$context->activeProfile` + `$context->workspacePath`; use its own `TimestampFormatter` copy (or inline) since the core static is gone. Optionally implement `ToolkitCommandHelpProvider`.

- [ ] **Step 2: Author `BackstoryToolkit`**

```php
final class BackstoryToolkit implements ToolkitInterface, ReplCommandProvider
{
    public function tools(): array { return []; }
    public function guidelines(): string { return ''; }
    public function commandHandlers(): array { return [new BackstoryCommandHandler()]; }
}
```

- [ ] **Step 3: Regen entry point** — expose `generate` via the `/backstory generate` subcommand (on-demand). If the package wants boot-time auto-regen, document that it requires a boot hook; for Effort 1, on-demand regen through the command is sufficient (core no longer auto-regenerates).

- [ ] **Step 4: Run the package suite + static analysis**

Run: `composer test && composer analyse`
Expected: PASS; no errors.

- [ ] **Step 5: Commit (package repo)**

```bash
git add src tests
git commit -m "feat: BackstoryToolkit self-registers /backstory command"
```

---

### Task 16: Cross-repo integration verification

- [ ] **Step 1: Install the toolkit into a Coqui checkout**

In a Coqui workspace: `/mods install coquibot/coqui-toolkit-backstory` (or a path/VCS repo entry during development).

- [ ] **Step 2: Verify `/backstory` is available and generates**

Create a persona with a `backstory/` source dir containing a `.md` and a `.docx`; run `/backstory generate`; confirm `backstory.md` is produced and core loads it (`/prompt` shows the backstory section).

- [ ] **Step 3: Verify core-only path still works without the toolkit**

Uninstall the toolkit; confirm a persona with a hand-written `backstory.md` + `context/*.md` still renders both, and `/backstory` is absent (no error).

- [ ] **Step 4: Confirm the absorbed `-formats` deps work**

Ingest a `.pdf` and `.html` source via `/backstory generate`; confirm extraction succeeds (validates `smalot/pdfparser` + `league/html-to-markdown`).

---

## Self-Review

**Spec coverage:**
- § 1 vocabulary (context as new term) → Tasks 1–4, 11 (docs). Persona rename is Effort 2 (out of scope) ✓.
- § 2 cross-profile (context persona-owned, no fallback) → Task 1 (persona-dir only), Global Constraints ✓.
- § 3 storage (core reads soul/backstory/context; generator → toolkit) → Phase 1 (read), Phase 2 (excise), Phase 3 (toolkit) ✓.
- § 3 context gate → Task 3 ✓. Pinning below backstory → Task 4 ✓. `/prompt` self-composition → Task 8 Step 1 ✓.
- Savings / cut list → Phase 2 deletions (Task 10) ✓.
- Toolkit absorbs `-formats` + deps → Task 12 ✓.
- Review note 1 (budget-bound context via gate) → Task 3 + Global Constraints ✓.
- Review note 2 (auto-regen behavior change → release note) → Task 9 + Task 11 Step 2 ✓.
- Review note 3 (bulk of work is the package move) → Phase 3 scope note ✓.

**Placeholder scan:** dependency version constraints in Task 12 are flagged as "confirm before publishing" (an explicit verification step, not a silent TODO); the `formatNullableTimestamp` body is copied verbatim in Task 6 Step 1 (read-then-copy, not invented). No `TODO`/"handle edge cases"/"similar to Task N" placeholders.

**Type consistency:** `PersonaContextReader::read()` (Task 1) is consumed identically in Tasks 2 and 4. `buildContextContent()` (Task 2) is consumed by `renderContext()` (Task 4). `buildProfileIdentityParts()` return arity changes 2→3 tuple consistently across all its callers (Task 4 Steps 2, 3, 6). `TimestampFormatter::formatNullable()` (Task 6) matches both `SlashCommandRouter` call sites.

**Known follow-ups (not gaps):** backstory HTTP endpoints are dropped, not re-hosted (no toolkit API-route provider exists) — documented as a breaking change and a future follow-up. `labels.context` is included as a cheap symmetric addition (Task 3) though the spec marks it optional.
