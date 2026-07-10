# Backstory Formats Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the three Composer dependencies only the backstory generator uses (`phpoffice/phpword`, `smalot/pdfparser`, `league/html-to-markdown`) by moving the Docx/Pdf/Html extractors into a new optional mod package, with no capability loss when that mod is installed.

**Architecture:** Add a tiny package-discovery hook (`BackstoryExtractorDiscovery`) that reads `extra.php-agents.backstoryExtractors` from installed packages' `composer.json` — mirroring the existing `extra.php-agents.toolkits` toolkit-discovery mechanism. `ExtractorFactory` gains an optional constructor parameter: when omitted it self-discovers mod extractors (so all five existing no-arg call sites keep working); when passed an explicit array it bypasses discovery (deterministic tests). The three dependency-carrying extractors move verbatim (re-namespaced) into a new sibling package `coqui-toolkit-backstory-formats` that carries the three Composer deps. All dependency-free extractors stay in core.

**Tech Stack:** PHP 8.4, Pest (tests), PHPStan level 8, Composer.

## Global Constraints

- **PHP 8.4**, `declare(strict_types=1);` in every file, `final` classes, one class per file, 4-space indentation.
- **PHPStan level 8 must pass**: `composer analyse` (runs `phpstan analyse --memory-limit=512M`).
- **Tests are Pest**: `composer test` (runs `pest`). Global test helpers live namespaceless in `tests/Pest.php`.
- **Never weaken the safety model** (catastrophic blacklist, audit logging, sandboxing, approvals).
- **Never `git add -A`.** The working tree has intentional unstaged edits (`.gitignore`, `.vscode/settings.json`) that MUST stay unstaged. Every commit stages only the exact paths listed.
- **Commit messages end with:** `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`
- **Repos:** core work is in the coqui repo (`/home/carmelo/Projects/CoquiBot/Core/coqui`). The mod is a NEW sibling repo `/home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-backstory-formats` (created in Part B), mirroring the existing `coqui-toolkit-mcp-client` sibling.
- **Core namespaces:** interface `CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface`, result `CoquiBot\Coqui\Backstory\Extractor\ExtractorResult`, text reader `CoquiBot\Coqui\Backstory\Extractor\BackstoryTextReader`.
- **Mod namespace:** `CoquiBot\Toolkits\BackstoryFormats\` (mirrors `CoquiBot\Toolkits\Mcp\`).
- **Reference spec:** `docs/superpowers/specs/2026-07-10-platform-thinning-roadmap-design.md` (item 2).

---

## Part A — Core: the extractor-registration hook (coqui repo)

Part A is fully testable in the coqui repo alone (using an in-test fake extractor). After Part A, core no longer supports `.docx/.docm/.pdf/.htm/.html` by default, the 3 deps are gone, and any package declaring `extra.php-agents.backstoryExtractors` is picked up automatically.

### Task A1: `BackstoryExtractorDiscovery`

**Files:**
- Create: `src/Backstory/Extractor/BackstoryExtractorDiscovery.php`
- Test: `tests/Unit/Backstory/BackstoryExtractorDiscoveryTest.php`

**Interfaces:**
- Consumes: `CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface` (existing).
- Produces: `final class BackstoryExtractorDiscovery` with `__construct(?string $projectRoot = null)` and `public function discover(): array` returning `list<ExtractorInterface>`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Backstory/BackstoryExtractorDiscoveryTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\BackstoryExtractorDiscovery;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorResult;

// A global-namespace fake extractor so class_exists() resolves it by name.
if (!class_exists('FakeUpperExtractor')) {
    class FakeUpperExtractor implements ExtractorInterface
    {
        public function extract(string $absolutePath): ExtractorResult
        {
            return ExtractorResult::ok('FAKE', 1);
        }

        public function supportedExtensions(): array
        {
            return ['fake'];
        }
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-extractor-discovery-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('discover returns empty when installed.json is missing', function () {
    $discovery = new BackstoryExtractorDiscovery($this->tempDir);
    expect($discovery->discover())->toBe([]);
});

test('discover instantiates a declared backstory extractor class', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/backstory-fake',
            'extra' => ['php-agents' => ['backstoryExtractors' => ['FakeUpperExtractor']]],
        ]],
    ]));

    $discovery = new BackstoryExtractorDiscovery($this->tempDir);
    $result = $discovery->discover();

    expect($result)->toHaveCount(1);
    expect($result[0])->toBeInstanceOf(FakeUpperExtractor::class);
});

test('discover skips classes that do not implement ExtractorInterface', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/bad',
            'extra' => ['php-agents' => ['backstoryExtractors' => ['stdClass', 'Nonexistent\\Class']]],
        ]],
    ]));

    $discovery = new BackstoryExtractorDiscovery($this->tempDir);
    expect($discovery->discover())->toBe([]);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Backstory/BackstoryExtractorDiscoveryTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Backstory\Extractor\BackstoryExtractorDiscovery" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/Backstory/Extractor/BackstoryExtractorDiscovery.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Discovers backstory extractors contributed by installed Composer packages.
 *
 * Packages declare their extractor classes under
 * extra.php-agents.backstoryExtractors in composer.json. Each declared class
 * must implement ExtractorInterface and be no-arg constructable. This mirrors
 * the toolkit discovery mechanism (extra.php-agents.toolkits) so that
 * dependency-heavy extractors live in optional mod packages instead of core.
 */
final class BackstoryExtractorDiscovery
{
    private readonly string $installedJsonPath;

    public function __construct(?string $projectRoot = null)
    {
        $root = $projectRoot ?? self::locateProjectRoot();
        $this->installedJsonPath = rtrim($root, '/') . '/vendor/composer/installed.json';
    }

    /**
     * @return list<ExtractorInterface>
     */
    public function discover(): array
    {
        if (!is_file($this->installedJsonPath)) {
            return [];
        }

        $raw = file_get_contents($this->installedJsonPath);
        if ($raw === false) {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return [];
        }

        // Composer 2.x wraps entries in a 'packages' key.
        $packages = $data['packages'] ?? $data;
        if (!is_array($packages)) {
            return [];
        }

        $extractors = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $declared = $package['extra']['php-agents']['backstoryExtractors'] ?? null;
            if (!is_array($declared)) {
                continue;
            }

            foreach ($declared as $className) {
                if (!is_string($className)) {
                    continue;
                }

                $extractor = self::tryInstantiate($className);
                if ($extractor !== null) {
                    $extractors[] = $extractor;
                }
            }
        }

        return $extractors;
    }

    private static function tryInstantiate(string $className): ?ExtractorInterface
    {
        try {
            if (!class_exists($className)) {
                return null;
            }

            /** @var class-string $className */
            $reflection = new \ReflectionClass($className);

            if (!$reflection->implementsInterface(ExtractorInterface::class) || $reflection->isAbstract()) {
                return null;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            $instance = $reflection->newInstance();

            return $instance instanceof ExtractorInterface ? $instance : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function locateProjectRoot(): string
    {
        $dir = __DIR__;

        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/vendor/composer/installed.json')) {
                return $dir;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        // Fallback: src/Backstory/Extractor -> project root is three levels up.
        return dirname(__DIR__, 3);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Backstory/BackstoryExtractorDiscoveryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Backstory/Extractor/BackstoryExtractorDiscovery.php tests/Unit/Backstory/BackstoryExtractorDiscoveryTest.php
git commit -m "feat(backstory): add BackstoryExtractorDiscovery hook

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task A2: `ExtractorFactory` accepts injected / discovered extractors

**Files:**
- Modify: `src/Backstory/Extractor/ExtractorFactory.php`
- Test: `tests/Unit/Backstory/ExtractorFactoryHookTest.php`

**Interfaces:**
- Consumes: `BackstoryExtractorDiscovery` (Task A1), `ExtractorInterface`.
- Produces: `ExtractorFactory::__construct(?array $additionalExtractors = null)` — `null` triggers discovery; an explicit `list<ExtractorInterface>` (including `[]`) bypasses discovery. Existing methods `get()`, `supportedExtensions()`, `isSupported()` unchanged.

> NOTE: This task keeps `HtmlExtractor`/`PdfExtractor`/`DocxExtractor` in the core list for now — Task A3 removes them. Splitting keeps the constructor-signature change reviewable on its own.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Backstory/ExtractorFactoryHookTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorResult;
use CoquiBot\Coqui\Backstory\Extractor\TextExtractor;

if (!class_exists('FakeHookExtractor')) {
    class FakeHookExtractor implements ExtractorInterface
    {
        public function extract(string $absolutePath): ExtractorResult
        {
            return ExtractorResult::ok('HOOK', 1);
        }

        public function supportedExtensions(): array
        {
            return ['hook'];
        }
    }
}

test('ExtractorFactory registers injected additional extractors', function () {
    $factory = new ExtractorFactory([new FakeHookExtractor()]);

    expect($factory->get('hook'))->toBeInstanceOf(FakeHookExtractor::class);
    // Core dep-free extractors are still present.
    expect($factory->get('txt'))->toBeInstanceOf(TextExtractor::class);
});

test('ExtractorFactory with an explicit empty array registers only core extractors', function () {
    $factory = new ExtractorFactory([]);

    expect($factory->get('txt'))->toBeInstanceOf(TextExtractor::class);
    expect($factory->get('hook'))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Backstory/ExtractorFactoryHookTest.php`
Expected: FAIL — `ExtractorFactory::__construct()` currently takes no arguments, so `get('hook')` returns null in the first test.

- [ ] **Step 3: Write minimal implementation**

Replace the constructor in `src/Backstory/Extractor/ExtractorFactory.php`. The full new file:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Backstory\Extractor;

/**
 * Maps file extensions to their content extractor.
 */
final class ExtractorFactory
{
    /** @var array<string, ExtractorInterface> Extension → extractor */
    private array $map = [];

    /**
     * @param list<ExtractorInterface>|null $additionalExtractors Extra extractors
     *        to register after the core set. When null, mod-provided extractors
     *        are discovered from installed packages. Pass an explicit array
     *        (including []) to bypass discovery — used by tests for determinism.
     */
    public function __construct(?array $additionalExtractors = null)
    {
        $extractors = [
            new TextExtractor(),
            new MarkdownExtractor(),
            new JsonExtractor(),
            new YamlExtractor(),
            new CsvExtractor(),
            new HtmlExtractor(),
            new XmlExtractor(),
            new RtfExtractor(),
            new SqlExtractor(),
            new CodeBlockExtractor(),
            new PdfExtractor(),
            new DocxExtractor(),
        ];

        if (XlsxExtractor::isRuntimeSupported()) {
            $extractors[] = new XlsxExtractor();
        }

        if (PptxExtractor::isRuntimeSupported()) {
            $extractors[] = new PptxExtractor();
        }

        if (OdtExtractor::isRuntimeSupported()) {
            $extractors[] = new OdtExtractor();
        }

        if (OdsExtractor::isRuntimeSupported()) {
            $extractors[] = new OdsExtractor();
        }

        if (OdpExtractor::isRuntimeSupported()) {
            $extractors[] = new OdpExtractor();
        }

        $additional = $additionalExtractors ?? (new BackstoryExtractorDiscovery())->discover();
        foreach ($additional as $extractor) {
            $extractors[] = $extractor;
        }

        foreach ($extractors as $extractor) {
            foreach ($extractor->supportedExtensions() as $ext) {
                $this->map[$ext] = $extractor;
            }
        }
    }

    public function get(string $extension): ?ExtractorInterface
    {
        return $this->map[strtolower($extension)] ?? null;
    }

    /**
     * @return list<string>
     */
    public function supportedExtensions(): array
    {
        return array_keys($this->map);
    }

    public function isSupported(string $extension): bool
    {
        return isset($this->map[strtolower($extension)]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Backstory/ExtractorFactoryHookTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the existing backstory suite + PHPStan to confirm no regression**

Run: `./vendor/bin/pest tests/Unit/Backstory/ && composer analyse`
Expected: PASS / `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Backstory/Extractor/ExtractorFactory.php tests/Unit/Backstory/ExtractorFactoryHookTest.php
git commit -m "feat(backstory): ExtractorFactory accepts injected/discovered extractors

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task A3: Remove Docx/Pdf/Html extractors + their Composer deps from core

**Files:**
- Delete: `src/Backstory/Extractor/DocxExtractor.php`, `src/Backstory/Extractor/PdfExtractor.php`, `src/Backstory/Extractor/HtmlExtractor.php`
- Modify: `src/Backstory/Extractor/ExtractorFactory.php` (drop the 3 `new` lines)
- Modify: `tests/Unit/Backstory/ExtractorTest.php` (remove Html + Docx cases + imports; update factory-mapping test)
- Modify: `tests/Pest.php` (remove `createTestDocx` helper)
- Modify: `composer.json`, `composer.lock` (remove 3 deps, via `composer remove`)
- Modify: `docs/PROFILES.md` (mark docx/pdf/html mod-provided)

**Interfaces:**
- Consumes: the Task A2 factory constructor. After this task, `new ExtractorFactory([])` maps none of `docx/docm/pdf/htm/html`.

- [ ] **Step 1: Update the failing test first (core factory no longer maps the 3 formats)**

In `tests/Unit/Backstory/ExtractorTest.php`:

1. Remove these imports (lines near the top):
```php
use CoquiBot\Coqui\Backstory\Extractor\DocxExtractor;
use CoquiBot\Coqui\Backstory\Extractor\HtmlExtractor;
```
2. Delete the entire `// --- HtmlExtractor ---` block (both `test(...)` cases, lines ~194–219).
3. Delete the entire `// --- DocxExtractor ---` block (the single `test('DocxExtractor reads docm files ...')` case, lines ~273–286).
4. Replace the `test('ExtractorFactory maps extensions to extractors', ...)` case so it (a) constructs with an explicit `[]` and (b) asserts the 3 formats are gone. New body:

```php
test('ExtractorFactory maps extensions to extractors', function () {
    $factory = new ExtractorFactory([]);

    expect($factory->get('txt'))->toBeInstanceOf(TextExtractor::class);
    expect($factory->get('md'))->toBeInstanceOf(MarkdownExtractor::class);
    expect($factory->get('json'))->toBeInstanceOf(JsonExtractor::class);
    expect($factory->get('yaml'))->toBeInstanceOf(YamlExtractor::class);
    expect($factory->get('yml'))->toBeInstanceOf(YamlExtractor::class);
    expect($factory->get('csv'))->toBeInstanceOf(CsvExtractor::class);
    expect($factory->get('tsv'))->toBeInstanceOf(CsvExtractor::class);
    expect($factory->get('mdx'))->toBeInstanceOf(MarkdownExtractor::class);
    expect($factory->get('xml'))->toBeInstanceOf(XmlExtractor::class);
    expect($factory->get('rtf'))->toBeInstanceOf(RtfExtractor::class);
    expect($factory->get('sql'))->toBeInstanceOf(SqlExtractor::class);
    expect($factory->get('py'))->toBeInstanceOf(CodeBlockExtractor::class);

    // Dependency-carrying formats now live in the backstory-formats mod.
    expect($factory->get('html'))->toBeNull();
    expect($factory->get('htm'))->toBeNull();
    expect($factory->get('pdf'))->toBeNull();
    expect($factory->get('docx'))->toBeNull();
    expect($factory->get('docm'))->toBeNull();

    if (OdtExtractor::isRuntimeSupported()) {
        expect($factory->get('odt'))->toBeInstanceOf(OdtExtractor::class);
    }
    if (OdsExtractor::isRuntimeSupported()) {
        expect($factory->get('ods'))->toBeInstanceOf(OdsExtractor::class);
    }
    if (OdpExtractor::isRuntimeSupported()) {
        expect($factory->get('odp'))->toBeInstanceOf(OdpExtractor::class);
    }
    if (XlsxExtractor::isRuntimeSupported()) {
        expect($factory->get('xlsx'))->toBeInstanceOf(XlsxExtractor::class);
        expect($factory->get('xlsm'))->toBeInstanceOf(XlsxExtractor::class);
    }
    if (PptxExtractor::isRuntimeSupported()) {
        expect($factory->get('pptx'))->toBeInstanceOf(PptxExtractor::class);
        expect($factory->get('pptm'))->toBeInstanceOf(PptxExtractor::class);
    }
});
```

- [ ] **Step 2: Guard against other stale assertions**

Run: `grep -rniE "docx|docm|'pdf'|\bhtml\b|htm'" tests/ --include=*.php | grep -viE "backstoryformats|ExtractorFactoryHook|BackstoryExtractorDiscovery"`
Expected: only matches inside `tests/Unit/Backstory/ExtractorTest.php` you already edited (and unrelated non-extractor uses). If any OTHER test asserts docx/pdf/html backstory support, update it to expect the format is now mod-provided (unsupported by default). Note what you changed.

- [ ] **Step 3: Remove the `createTestDocx` helper from `tests/Pest.php`**

First confirm it is only used by the docx test you deleted:

Run: `grep -rn "createTestDocx" tests/`
Expected: no remaining references after your ExtractorTest edit. If any remain, do not remove the helper — resolve those first.

Then delete the `createTestDocx` function (the `function createTestDocx(string $path, array $paragraphs): void { ... }` block, lines ~119–133 in `tests/Pest.php`). Leave `createTestOdt` intact (ODT stays in core).

- [ ] **Step 4: Delete the three extractor files and drop them from the factory**

```bash
git rm src/Backstory/Extractor/DocxExtractor.php src/Backstory/Extractor/PdfExtractor.php src/Backstory/Extractor/HtmlExtractor.php
```

In `src/Backstory/Extractor/ExtractorFactory.php`, remove these three lines from the `$extractors = [ ... ]` array:
```php
            new HtmlExtractor(),
            new PdfExtractor(),
            new DocxExtractor(),
```

- [ ] **Step 5: Remove the three Composer dependencies**

Run: `composer remove phpoffice/phpword smalot/pdfparser league/html-to-markdown`
Expected: `composer.json` and `composer.lock` updated; autoloader regenerated; no errors.

- [ ] **Step 6: Run the full suite + PHPStan**

Run: `composer test && composer analyse`
Expected: all tests PASS, `[OK] No errors`. In particular `tests/Unit/Backstory/ExtractorTest.php` passes with the 3 formats unsupported, and no code references the removed classes/deps.

- [ ] **Step 7: Update `docs/PROFILES.md`**

In the "Supported File Types" table, change the `.docx, .docm`, `.pdf`, and `.html, .htm` rows to note they require the `coqui-toolkit-backstory-formats` mod. Add a sentence under the table:

```markdown
> **Word, PDF, and HTML** (`.docx`, `.docm`, `.pdf`, `.html`, `.htm`) are provided by the optional
> `coqui-toolkit-backstory-formats` mod (it carries the `phpoffice/phpword`, `smalot/pdfparser`, and
> `league/html-to-markdown` dependencies). Install it with `/mods install coquibot/coqui-toolkit-backstory-formats`.
> Without the mod, these formats are listed as unsupported in `/backstory failed`.
```

- [ ] **Step 8: Commit**

```bash
git add src/Backstory/Extractor/ExtractorFactory.php tests/Unit/Backstory/ExtractorTest.php tests/Pest.php composer.json composer.lock docs/PROFILES.md
git commit -m "refactor(backstory): move Docx/Pdf/Html extractors + 3 deps out of core

Removes phpoffice/phpword, smalot/pdfparser, league/html-to-markdown from
core. Dependency-free extractors (incl. sql/xml/rtf and the ext-zip office
formats) stay. Docx/Pdf/Html now load from the coqui-toolkit-backstory-formats
mod via the extractor-registration hook.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Part B — The mod package (new sibling repo `coqui-toolkit-backstory-formats`)

Created at `/home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-backstory-formats`. It carries the 3 Composer deps and the 3 moved extractors, and self-declares them via `extra.php-agents.backstoryExtractors`.

### Task B1: Scaffold the package

**Files (all in the new sibling repo):**
- Create: `composer.json`, `phpstan.neon`, `README.md`, `.gitignore`

- [ ] **Step 1: Initialize the repo**

```bash
mkdir -p /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-backstory-formats/src
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-backstory-formats
git init
```

- [ ] **Step 2: Create `composer.json`**

```json
{
    "name": "coquibot/coqui-toolkit-backstory-formats",
    "description": "Backstory extractors for dependency-heavy formats: Word (.docx/.docm), PDF (.pdf), and HTML (.html/.htm).",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.4",
        "phpoffice/phpword": "^1.0",
        "smalot/pdfparser": "^2.0",
        "league/html-to-markdown": "^5.1"
    },
    "require-dev": {
        "coquibot/coqui": "@dev",
        "pestphp/pest": "^3.8",
        "phpstan/phpstan": "^2.1"
    },
    "autoload": {
        "psr-4": {
            "CoquiBot\\Toolkits\\BackstoryFormats\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "CoquiBot\\Toolkits\\BackstoryFormats\\Tests\\": "tests/"
        }
    },
    "repositories": [
        {
            "type": "path",
            "url": "../coqui",
            "options": { "symlink": true }
        }
    ],
    "extra": {
        "php-agents": {
            "backstoryExtractors": [
                "CoquiBot\\Toolkits\\BackstoryFormats\\DocxExtractor",
                "CoquiBot\\Toolkits\\BackstoryFormats\\PdfExtractor",
                "CoquiBot\\Toolkits\\BackstoryFormats\\HtmlExtractor"
            ],
            "description": "Backstory extractors for Word, PDF, and HTML (carries phpoffice/phpword, smalot/pdfparser, league/html-to-markdown)."
        }
    }
}
```

> If `composer install` reports a version mismatch for `pestphp/pest` or `phpstan/phpstan`, align these two `require-dev` constraints with the versions in `../coqui/composer.json`'s `require-dev`, then retry.

- [ ] **Step 3: Create `phpstan.neon`**

```neon
parameters:
    level: 8
    paths:
        - src
```

- [ ] **Step 4: Create `.gitignore`**

```gitignore
/vendor/
composer.lock
.phpunit.cache/
```

- [ ] **Step 5: Create `README.md`**

```markdown
# coqui-toolkit-backstory-formats

Optional [Coqui](https://github.com/carmelosantana/coqui) mod that adds backstory
extractors for dependency-heavy formats: **Word** (`.docx`, `.docm`), **PDF** (`.pdf`),
and **HTML** (`.html`, `.htm`).

Core Coqui ships extractors for all dependency-free formats (text, markdown, JSON,
YAML, CSV, code, SQL, XML, RTF, and the ext-zip office formats). This mod carries the
three Composer dependencies those three formats need — `phpoffice/phpword`,
`smalot/pdfparser`, and `league/html-to-markdown` — so they stay out of core.

## Install

```bash
/mods install coquibot/coqui-toolkit-backstory-formats
```

The extractors self-register with Coqui's backstory generator via
`extra.php-agents.backstoryExtractors`. No configuration needed.
```

- [ ] **Step 6: Install and commit**

```bash
composer install
git add composer.json phpstan.neon .gitignore README.md
git commit -m "chore: scaffold coqui-toolkit-backstory-formats mod

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

### Task B2: Move the three extractors + their tests into the mod

**Files (in the mod repo):**
- Create: `src/DocxExtractor.php`, `src/PdfExtractor.php`, `src/HtmlExtractor.php`
- Create: `tests/Pest.php`, `tests/ExtractorTest.php`

**Interfaces:**
- Each moved extractor implements `CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface` (resolved via the `../coqui` path repo at dev time; ambient at runtime inside Coqui) and returns `CoquiBot\Coqui\Backstory\Extractor\ExtractorResult`.

- [ ] **Step 1: Create `src/DocxExtractor.php`** (moved verbatim; only the namespace + imports change)

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\BackstoryFormats;

use CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorResult;
use PhpOffice\PhpWord\IOFactory;

/**
 * Extracts text content from Word OOXML documents.
 */
final class DocxExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        try {
            $phpWord = IOFactory::load($absolutePath, 'Word2007');
        } catch (\Throwable $e) {
            return ExtractorResult::fail('DOCX extraction failed: ' . $e->getMessage());
        }

        $textParts = [];
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text = $this->extractElementText($element);
                if ($text !== '') {
                    $textParts[] = $text;
                }
            }
        }

        $content = trim(implode("\n\n", $textParts));
        if ($content === '') {
            return ExtractorResult::fail('DOCX contains no extractable text');
        }

        return ExtractorResult::ok($content, self::estimateTokens($content));
    }

    public function supportedExtensions(): array
    {
        return ['docx', 'docm'];
    }

    private function extractElementText(object $element): string
    {
        if (method_exists($element, 'getText')) {
            return trim((string) $element->getText());
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                if (!is_object($child)) {
                    continue;
                }
                $text = $this->extractElementText($child);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            return implode("\n", $parts);
        }

        return '';
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
```

- [ ] **Step 2: Create `src/PdfExtractor.php`**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\BackstoryFormats;

use CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorResult;
use Smalot\PdfParser\Parser;

/**
 * Extracts text content from PDF files.
 */
final class PdfExtractor implements ExtractorInterface
{
    public function extract(string $absolutePath): ExtractorResult
    {
        try {
            $parser = new Parser();
            $pdf = $parser->parseFile($absolutePath);
            $text = trim($pdf->getText());
        } catch (\Throwable $e) {
            return ExtractorResult::fail('PDF extraction failed: ' . $e->getMessage());
        }

        if ($text === '') {
            return ExtractorResult::fail('PDF contains no extractable text');
        }

        return ExtractorResult::ok($text, self::estimateTokens($text));
    }

    public function supportedExtensions(): array
    {
        return ['pdf'];
    }

    private static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }
}
```

- [ ] **Step 3: Create `src/HtmlExtractor.php`** (full content — the core copy is deleted in Task A3, so it is reproduced here in full)

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\BackstoryFormats;

use CoquiBot\Coqui\Backstory\Extractor\BackstoryTextReader;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorInterface;
use CoquiBot\Coqui\Backstory\Extractor\ExtractorResult;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

/**
 * Sanitizes HTML and converts it into markdown.
 */
final class HtmlExtractor implements ExtractorInterface
{
    /** @var list<string> */
    private const array UNSAFE_NODES = [
        'applet',
        'base',
        'canvas',
        'embed',
        'form',
        'head',
        'iframe',
        'input',
        'link',
        'meta',
        'noscript',
        'object',
        'option',
        'script',
        'select',
        'source',
        'style',
        'textarea',
    ];

    public function extract(string $absolutePath): ExtractorResult
    {
        $result = BackstoryTextReader::read($absolutePath);
        if (!$result->success || $result->content === null) {
            return $result;
        }

        $html = trim($result->content);
        if ($html === '') {
            return ExtractorResult::fail('File is empty');
        }

        $sanitized = $this->sanitizeHtml($html);
        if ($sanitized === '') {
            return ExtractorResult::fail('HTML contains no extractable content');
        }

        try {
            $converter = new HtmlConverter([
                'header_style' => 'atx',
                'hard_break' => true,
                'remove_nodes' => implode(' ', self::UNSAFE_NODES),
                'strip_placeholder_links' => true,
                'strip_tags' => true,
            ]);
            $converter->getEnvironment()->addConverter(new TableConverter());
            $markdown = trim($converter->convert($sanitized));
        } catch (\Throwable $e) {
            return ExtractorResult::fail('HTML conversion failed: ' . $e->getMessage());
        }

        if ($markdown === '') {
            return ExtractorResult::fail('HTML contains no extractable content');
        }

        return ExtractorResult::ok($markdown, BackstoryTextReader::estimateTokens($markdown));
    }

    public function supportedExtensions(): array
    {
        return ['htm', 'html'];
    }

    private function sanitizeHtml(string $html): string
    {
        $previousUseInternalErrors = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $loaded = $dom->loadHTML(
                '<!DOCTYPE html><html><body>' . $html . '</body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            if (!$loaded) {
                return '';
            }

            $xpath = new DOMXPath($dom);
            foreach (self::UNSAFE_NODES as $nodeName) {
                $nodes = $xpath->query('//'.$nodeName);
                if ($nodes === false) {
                    continue;
                }

                for ($index = $nodes->length - 1; $index >= 0; $index--) {
                    $node = $nodes->item($index);
                    if (!$node instanceof DOMNode || $node->parentNode === null) {
                        continue;
                    }

                    $node->parentNode->removeChild($node);
                }
            }

            $elements = $xpath->query('//*');
            if ($elements !== false) {
                foreach ($elements as $node) {
                    if ($node instanceof DOMElement) {
                        $this->sanitizeAttributes($node);
                    }
                }
            }

            $body = $dom->getElementsByTagName('body')->item(0);
            if ($body === null) {
                return '';
            }

            return trim($this->serializeChildren($body));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseInternalErrors);
        }
    }

    private function sanitizeAttributes(DOMElement $element): void
    {
        $attributesToRemove = [];

        foreach ($element->attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = trim($attribute->value);

            if (str_starts_with($name, 'on') || $name === 'srcdoc' || $name === 'style') {
                $attributesToRemove[] = $attribute->name;
                continue;
            }

            if (!in_array($name, ['href', 'src', 'xlink:href'], true)) {
                continue;
            }

            $normalized = strtolower($value);
            if (str_starts_with($normalized, 'javascript:') || str_starts_with($normalized, 'data:')) {
                $attributesToRemove[] = $attribute->name;
            }
        }

        foreach ($attributesToRemove as $name) {
            $element->removeAttribute($name);
        }
    }

    private function serializeChildren(DOMNode $node): string
    {
        $ownerDocument = $node->ownerDocument;
        if ($ownerDocument === null) {
            return '';
        }

        $html = '';
        foreach ($node->childNodes as $child) {
            $chunk = $ownerDocument->saveHTML($child);
            if ($chunk !== false) {
                $html .= $chunk;
            }
        }

        return $html;
    }
}
```

- [ ] **Step 4: Create `tests/Pest.php` with the `createTestDocx` helper**

```php
<?php

declare(strict_types=1);

/**
 * @param list<string> $paragraphs
 */
function createTestDocx(string $path, array $paragraphs): void
{
    $document = new \PhpOffice\PhpWord\PhpWord();
    $section = $document->addSection();

    foreach ($paragraphs as $paragraph) {
        $section->addText($paragraph);
    }

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($document, 'Word2007');
    $writer->save($path);
}

function cleanupTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}
```

- [ ] **Step 5: Create `tests/ExtractorTest.php`** (docx + html moved from core, pdf added)

```php
<?php

declare(strict_types=1);

use CoquiBot\Toolkits\BackstoryFormats\DocxExtractor;
use CoquiBot\Toolkits\BackstoryFormats\HtmlExtractor;
use CoquiBot\Toolkits\BackstoryFormats\PdfExtractor;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/backstory-formats-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTree($this->tempDir);
});

test('DocxExtractor reads docm files as Word OOXML documents', function () {
    $path = $this->tempDir . '/story.docm';
    createTestDocx($path, ['First paragraph', 'Second paragraph']);

    $extractor = new DocxExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('First paragraph');
    expect($result->content)->toContain('Second paragraph');
    expect($extractor->supportedExtensions())->toContain('docm');
});

test('HtmlExtractor sanitizes and converts html to markdown', function () {
    $path = $this->tempDir . '/profile.html';
    file_put_contents($path, '<h1>Title</h1><script>alert(1)</script><p>Hello <strong>world</strong>.</p>');

    $extractor = new HtmlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('# Title');
    expect($result->content)->toContain('Hello **world**.');
    expect($result->content)->not->toContain('alert(1)');
});

test('HtmlExtractor strips dangerous href values', function () {
    $path = $this->tempDir . '/links.html';
    file_put_contents($path, '<p><a href="javascript:alert(1)">Click</a></p>');

    $extractor = new HtmlExtractor();
    $result = $extractor->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('Click');
    expect($result->content)->not->toContain('javascript:');
});

test('PdfExtractor reports its supported extension', function () {
    expect((new PdfExtractor())->supportedExtensions())->toBe(['pdf']);
});

test('PdfExtractor fails cleanly on a non-pdf file', function () {
    $path = $this->tempDir . '/not.pdf';
    file_put_contents($path, 'this is not a pdf');

    $result = (new PdfExtractor())->extract($path);

    expect($result->success)->toBeFalse();
    expect($result->error)->toContain('PDF');
});
```

- [ ] **Step 6: Run the mod's tests + PHPStan**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-backstory-formats
./vendor/bin/pest
./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: all tests PASS; `[OK] No errors`. (PHPStan resolves the core `ExtractorInterface`/`ExtractorResult`/`BackstoryTextReader` via the `../coqui` path repo.)

- [ ] **Step 7: Commit**

```bash
git add src/ tests/
git commit -m "feat: Docx/Pdf/Html backstory extractors

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Part C — Integration verification (coqui repo, not committed)

Proves that installing the mod restores `.docx/.pdf/.html` support end-to-end via self-discovery. The composer wiring here is LOCAL verification only and is reverted at the end — core ships WITHOUT the mod required (it is optional).

### Task C1: End-to-end verify, then revert the local wiring

- [ ] **Step 1: Temporarily install the local mod into coqui**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
composer config repositories.backstory-formats path ../coqui-toolkit-backstory-formats
composer require coquibot/coqui-toolkit-backstory-formats:@dev
```
Expected: the mod (and its 3 deps) install into coqui's `vendor/`.

- [ ] **Step 2: Verify self-discovery picks up the mod extractors**

Run:
```bash
./vendor/bin/pest --filter="registers only core extractors" 2>/dev/null
php -r 'require "vendor/autoload.php"; $f = new CoquiBot\Coqui\Backstory\Extractor\ExtractorFactory(); var_dump($f->get("docm") !== null, $f->get("pdf") !== null, $f->get("html") !== null);'
```
Expected: the `php -r` prints `bool(true)` three times — the no-arg factory now discovers the mod's Docx/Pdf/Html extractors from `vendor/composer/installed.json`.

- [ ] **Step 3: End-to-end backstory generation with a .docx source**

Run:
```bash
php -r '
require "vendor/autoload.php";
$dir = sys_get_temp_dir() . "/bs-int-" . bin2hex(random_bytes(3));
mkdir($dir . "/backstory", 0755, true);
$w = new PhpOffice\PhpWord\PhpWord(); $s = $w->addSection(); $s->addText("Integration doc paragraph");
PhpOffice\PhpWord\IOFactory::createWriter($w, "Word2007")->save($dir . "/backstory/01-intro.docx");
$r = (new CoquiBot\Coqui\Backstory\BackstoryAssembler())->generate($dir);
echo "files=" . $r->totalFiles . " failed=" . $r->failedFiles . PHP_EOL;
echo file_get_contents($dir . "/backstory.md");
'
```
Expected: `files=1 failed=0` and the generated `backstory.md` contains `Integration doc paragraph`. This proves the mod's DocxExtractor was discovered and used by the real backstory pipeline.

- [ ] **Step 4: Full suite + PHPStan with the mod installed**

Run: `composer test && composer analyse`
Expected: green. (Note: the core `ExtractorTest` "maps extensions" test constructs `new ExtractorFactory([])`, so it stays core-only and still passes even with the mod installed.)

- [ ] **Step 5: Revert the local wiring (core stays mod-optional)**

```bash
composer remove coquibot/coqui-toolkit-backstory-formats   # remove from json/lock/vendor
composer config --unset repositories.backstory-formats     # drop the path-repo entry
git checkout composer.json composer.lock                    # guarantee byte-identical to the A3 commit
composer install                                            # resync vendor/ to the restored lock
git status --short
```
Expected: `composer.json` / `composer.lock` back to their Part A3 state (3 deps absent, mod NOT required), and `vendor/` no longer contains the mod or the 3 deps. `git status` shows only your intentional unstaged edits (`.gitignore`, `.vscode/settings.json`). No commit in this task.

---

## Part D — Harden the kept extractors (coqui repo, optional/deferrable)

The dependency-free extractors now permanently live in core. This part adds characterization test coverage and does ONLY behavior-preserving cleanups. Do NOT rewrite parser logic. Every step must keep `composer test` green. The reviewer may defer this part.

### Task D1: Characterization coverage for `SqlExtractor` (931 LOC, currently thinly tested)

**Files:**
- Test: `tests/Unit/Backstory/SqlExtractorCharacterizationTest.php`
- Modify (only if safe): `src/Backstory/Extractor/SqlExtractor.php`

- [ ] **Step 1: Add characterization tests that lock current behavior**

Create `tests/Unit/Backstory/SqlExtractorCharacterizationTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Backstory\Extractor\SqlExtractor;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-sql-char-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('SqlExtractor renders CREATE TABLE + INSERT as a markdown table', function () {
    $path = $this->tempDir . '/data.sql';
    file_put_contents($path, <<<SQL
    CREATE TABLE people (id INT, name VARCHAR(50));
    INSERT INTO people (id, name) VALUES (1, 'Alice'), (2, 'Bob');
    SQL);

    $result = (new SqlExtractor())->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('Alice');
    expect($result->content)->toContain('Bob');
});

test('SqlExtractor preserves unsupported statements as fenced sql', function () {
    $path = $this->tempDir . '/proc.sql';
    file_put_contents($path, "CREATE PROCEDURE do_thing() BEGIN SELECT 1; END;");

    $result = (new SqlExtractor())->extract($path);

    expect($result->success)->toBeTrue();
    expect($result->content)->toContain('```sql');
});

test('SqlExtractor reports its supported extension', function () {
    expect((new SqlExtractor())->supportedExtensions())->toBe(['sql']);
});
```

> Before asserting exact strings, run the test once and adjust the `toContain(...)` expectations to match the extractor's ACTUAL current output — the goal is to lock existing behavior, not to change it.

- [ ] **Step 2: Run to confirm they pass against current behavior**

Run: `./vendor/bin/pest tests/Unit/Backstory/SqlExtractorCharacterizationTest.php`
Expected: PASS (after adjusting expectations to real output in Step 1).

- [ ] **Step 3: Safe cleanups only (optional)**

With the characterization net in place, apply ONLY behavior-preserving cleanups to `SqlExtractor.php` if clearly warranted (remove dead private methods, split an over-long method, tighten types for PHPStan). After ANY edit:

Run: `./vendor/bin/pest tests/Unit/Backstory/ && composer analyse`
Expected: green. If a cleanup changes any output, revert it — coverage, not rewrites, is the deliverable.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Backstory/SqlExtractorCharacterizationTest.php src/Backstory/Extractor/SqlExtractor.php
git commit -m "test(backstory): characterize SqlExtractor; safe tidy-ups

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final Verification (coqui repo)

- [ ] Run `composer test` — full Pest suite green.
- [ ] Run `composer analyse` — PHPStan level 8, `[OK] No errors`.
- [ ] Confirm `grep -rn "phpoffice/phpword\|smalot/pdfparser\|league/html-to-markdown" composer.json` returns nothing.
- [ ] Confirm `grep -rln "PhpOffice\\\\PhpWord\|Smalot\\\\PdfParser\|League\\\\HTMLToMarkdown" src/ tests/` returns nothing.
- [ ] Confirm `git status --short` shows only the intentional unstaged edits (`.gitignore`, `.vscode/settings.json`).
- [ ] Update `config/source.json`: add `src/Backstory/Extractor/BackstoryExtractorDiscovery.php`; remove the three deleted extractor entries if present. Commit separately:

```bash
git add config/source.json
git commit -m "docs(source-map): backstory extractor discovery + removed dep extractors

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

## Notes for the reviewer (this session)

- **Primary success metric:** the 3 Composer deps are gone from core `composer.json`; capability is preserved via the mod (Part C proves end-to-end).
- **Blast radius:** Part A touches only `Backstory/Extractor/*`, two test files, `tests/Pest.php`, `composer.*`, and `docs/PROFILES.md`. The 5 no-arg `ExtractorFactory` construction sites are intentionally NOT touched — self-discovery handles them.
- **Known limitation:** `BackstoryExtractorDiscovery` scans the project `vendor/composer/installed.json` only (not workspace-local vendor). Mods installed by the mod-manager land in project vendor, so this covers the mod-manager path. Workspace-vendor discovery is a future enhancement if needed.
- **Deferrable:** Part D is coverage + safe tidy-ups; it can be dropped from this branch without affecting the dependency win.
