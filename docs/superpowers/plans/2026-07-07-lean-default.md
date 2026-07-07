# The Lean Default — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make a fresh no-role Coqui session lean by default (~30 eager tools / ~1.5–2k prompt tokens) by deferring all non-core toolkits and standalone tools behind `tool_search`, tuned for small local Ollama models, with a `lean|full` profile switch that defaults to `lean` and preserves today's behavior under `full`.

**Architecture:** A new `ToolProfileResolver` reads `agents.defaults.toolProfile` + optional `agents.defaults.coreToolkits` and resolves the always-eager core set (toolkit basenames + standalone tool names). `OrchestratorAgent` consults it when registering the built-in ("system") toolkits and when building its LLM-facing `tools()` list: core stays eager, everything else is wrapped as `StubToolkit` / omitted from the visible tool list while remaining in the BM25 `toolRegistry` for `tool_search`. Deferred tool-prompt guidance auto-drops via the existing slug-exclusion path, and a rewritten deferred hint renders a concise capability index covering both deferred toolkits and deferred standalone tools. `full` resolves the core set to today's full `SYSTEM_TOOLKITS` + all standalone tools, making deferral a no-op.

**Tech Stack:** PHP 8.4, Pest (namespaceless tests in core), PHPStan level 8, existing `StubToolkit`/`StubTool`/`ToolSearchTool` deferral machinery.

## Global Constraints

- PHP 8.4; `declare(strict_types=1);` in every file; `final` by default; one class per file; 4-space indent; constructor injection.
- PHPStan level 8 must pass: `./vendor/bin/phpstan analyse`.
- Tests are Pest, **namespaceless** in core, under `tests/Unit/...`; run `./vendor/bin/pest` (or `composer test`).
- **No live Ollama endpoint during implementation.** All required tests are synthetic (mocked provider) and must pass with no model. The only model-dependent test is env-gated (`COQUI_OLLAMA_IT=1`) and skipped by default.
- **Never `git add -A`.** The working tree has intentional unstaged user edits (`.gitignore`, `.vscode/settings.json`) that MUST remain unstaged. Every commit stages exact paths only.
- Branch: `feat_lean-default` (off `origin/main`). Commit messages end with `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Shipped default is `toolProfile: "lean"`. `toolProfile: "full"` must reproduce today's eager set exactly (regression-guarded in Task 8).
- On-disk paths and existing config keys are unchanged; only new keys (`agents.defaults.toolProfile`, `agents.defaults.coreToolkits`) are added.
- Design source of truth: `docs/superpowers/specs/2026-07-07-lean-default-design.md`.

### Core set (shipped `lean`)

- **Core toolkits (always eager):** `FileSystemToolkit`, `ShellToolkit`.
- **Core standalone tools (always eager):** `tool_search`, `credentials`, `config`, `coqui_toolkits`, `coqui_skills`, `php_execute`.
- **Deferred toolkits (lean):** `WebToolkit`, `MemoryToolkit`, `ArtifactToolkit`, `ProjectToolkit`, `CoquiSourceToolkit`, `ComposerToolkit`, `PackagistToolkit`, `LoopToolkit`, `ScheduleToolkit`.
- **Deferred standalone tools (lean):** `spawn_agent`, `package_info`, `vision_analyze`, `summarize_conversation`, `extract_memories`, `restart_coqui`.
- Passive value retained regardless of profile: memory recall block, active-project context injection, internal auto-summarization.

> **Decision flagged for maintainer:** `php_execute` is placed in the core set (running PHP is the defining capability of a hackable PHP agent); `package_info` is deferred (SDK introspection is nice-to-have). Both are one line to move in `CoquiDefaults::LEAN_CORE_TOOLS`. Neither was enumerated in the spec's audit.

---

## File Structure

**Create:**
- `src/Config/ToolProfileResolver.php` — resolves effective core toolkit/tool sets from config. One responsibility: profile → core sets.
- `tests/Unit/Config/ToolProfileResolverTest.php`
- `tests/Unit/Agent/LeanDefaultToolkitDeferralTest.php`
- `tests/Unit/Agent/LeanDefaultStandaloneToolDeferralTest.php`
- `tests/Unit/Agent/LeanDefaultCapabilityIndexTest.php`
- `tests/Unit/Agent/LeanDefaultProfilePrecedenceTest.php`
- `tests/Unit/Agent/LeanDefaultDiscoverThenCallTest.php`
- `tests/Unit/Agent/LeanDefaultSizeGuardTest.php`
- `tests/Integration/OllamaDiscoverThenCallTest.php` — env-gated, skipped by default.

**Modify:**
- `src/Contract/CoquiDefaults.php` — add profile constants + core-set lists; remove dead `SYSTEM_TOOLS`.
- `src/Config/ToolkitLoadingRegistry.php` — resolve "system/immutable" from the profile core set, not the hardcoded `SYSTEM_TOOLKITS`.
- `src/Agent/OrchestratorAgent.php` — profile-aware toolkit + standalone-tool deferral; concise capability index.
- `config/defaults.json` — add `agents.defaults.toolProfile: "lean"`.
- `docs/CONFIGURATION.md` — document `toolProfile` / `coreToolkits`.
- `config/source.json` — register the new class.

---

## Task 1: Profile constants + `ToolProfileResolver`

**Files:**
- Modify: `src/Contract/CoquiDefaults.php:203-243` (add constants; remove dead `SYSTEM_TOOLS`)
- Create: `src/Config/ToolProfileResolver.php`
- Modify: `config/defaults.json:8743-8752` (add `toolProfile`)
- Test: `tests/Unit/Config/ToolProfileResolverTest.php`

**Interfaces:**
- Consumes: `CarmeloSantana\PHPAgents\Contract\ConfigInterface::get(string $key, mixed $default = null): mixed`.
- Produces:
  - `CoquiBot\Coqui\Contract\CoquiDefaults::TOOL_PROFILE_DEFAULT` (`'lean'`), `::LEAN_CORE_TOOLKITS` (`list<string>`), `::LEAN_CORE_TOOLS` (`list<string>`).
  - `CoquiBot\Coqui\Config\ToolProfileResolver` with:
    - `__construct(ConfigInterface $config)`
    - `profile(): string` — `'lean'` or `'full'` (unknown values fall back to `'lean'`).
    - `isFull(): bool`
    - `coreToolkits(): list<string>` — toolkit basenames that stay eager.
    - `coreTools(): list<string>` — standalone tool names that stay eager.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/ToolProfileResolverTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolProfileResolver;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Build a real config from an agents.defaults fragment. Use OpenClawConfig
 * (not a hand-rolled ConfigInterface double — the interface has 7 methods)
 * so dot-notation resolution is exercised for real.
 */
function leanConfig(array $agentsDefaults): OpenClawConfig
{
    return OpenClawConfig::fromArray(['agents' => ['defaults' => $agentsDefaults]]);
}

it('defaults to the lean profile and lean core sets', function () {
    $r = new ToolProfileResolver(leanConfig([]));

    expect($r->profile())->toBe('lean');
    expect($r->isFull())->toBeFalse();
    expect($r->coreToolkits())->toBe(CoquiDefaults::LEAN_CORE_TOOLKITS);
    expect($r->coreTools())->toBe(CoquiDefaults::LEAN_CORE_TOOLS);
});

it('resolves the full profile to every system toolkit and no tool deferral', function () {
    $r = new ToolProfileResolver(leanConfig(['toolProfile' => 'full']));

    expect($r->isFull())->toBeTrue();
    expect($r->coreToolkits())->toBe(CoquiDefaults::SYSTEM_TOOLKITS);
    // full => every standalone tool is core (nothing defers).
    expect($r->coreTools())->toBe(CoquiDefaults::ALL_STANDALONE_TOOLS);
    expect($r->coreTools())->toContain('php_execute')->toContain('spawn_agent');
});

it('treats an unknown profile as lean', function () {
    $r = new ToolProfileResolver(leanConfig(['toolProfile' => 'bogus']));
    expect($r->profile())->toBe('lean');
});

it('lets an explicit coreToolkits list override the profile preset', function () {
    $r = new ToolProfileResolver(leanConfig([
        'coreToolkits' => ['FileSystemToolkit', 'MemoryToolkit'],
    ]));
    expect($r->coreToolkits())->toBe(['FileSystemToolkit', 'MemoryToolkit']);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/ToolProfileResolverTest.php`
Expected: FAIL — `Class "CoquiBot\Coqui\Config\ToolProfileResolver" not found`.

- [ ] **Step 3a: Add constants to `CoquiDefaults`**

In `src/Contract/CoquiDefaults.php`, replace the dead `SYSTEM_TOOLS` const block (lines 226-242) with the profile constants:

```php
    /**
     * Default tool profile — the shipped lean-by-default core set.
     *
     * Config: agents.defaults.toolProfile ('lean' | 'full').
     */
    public const string TOOL_PROFILE_DEFAULT = 'lean';
    public const string TOOL_PROFILE_LEAN = 'lean';
    public const string TOOL_PROFILE_FULL = 'full';

    /**
     * Toolkit basenames that stay eager under the lean profile.
     * Everything in SYSTEM_TOOLKITS not listed here is deferred.
     *
     * @var list<string>
     */
    public const array LEAN_CORE_TOOLKITS = [
        'FileSystemToolkit',
        'ShellToolkit',
    ];

    /**
     * Standalone tool names that stay eager under the lean profile.
     * Everything else surfaced by OrchestratorAgent::tools() is deferred
     * (still registered in the tool_search index).
     *
     * @var list<string>
     */
    public const array LEAN_CORE_TOOLS = [
        'tool_search',
        'credentials',
        'config',
        'coqui_toolkits',
        'coqui_skills',
        'php_execute',
    ];

    /**
     * Every standalone tool OrchestratorAgent::tools() can surface, used to
     * compute the deferred set (all names not in the active core-tools list).
     *
     * @var list<string>
     */
    public const array ALL_STANDALONE_TOOLS = [
        'tool_search',
        'credentials',
        'config',
        'coqui_toolkits',
        'coqui_skills',
        'php_execute',
        'package_info',
        'spawn_agent',
        'vision_analyze',
        'summarize_conversation',
        'extract_memories',
        'restart_coqui',
    ];
```

Keep the existing `SYSTEM_TOOLKITS` const (lines 211-224) as-is — it is the `full`-profile core set and the `McpToolkit` entry is harmless (it is never instantiated in core).

- [ ] **Step 3b: Create `ToolProfileResolver`**

Create `src/Config/ToolProfileResolver.php`:

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CarmeloSantana\PHPAgents\Contract\ConfigInterface;
use CoquiBot\Coqui\Contract\CoquiDefaults;

/**
 * Resolves the always-eager core tool set for the active tool profile.
 *
 * The lean profile (default) keeps only a bootstrap core eager and defers
 * everything else behind tool_search. The full profile reproduces the legacy
 * everything-on behavior. An explicit agents.defaults.coreToolkits list
 * overrides the profile's toolkit preset for advanced self-hosters.
 */
final class ToolProfileResolver
{
    public function __construct(private readonly ConfigInterface $config)
    {
    }

    public function profile(): string
    {
        $raw = $this->config->get('agents.defaults.toolProfile', CoquiDefaults::TOOL_PROFILE_DEFAULT);
        $value = is_string($raw) ? strtolower(trim($raw)) : '';

        return $value === CoquiDefaults::TOOL_PROFILE_FULL
            ? CoquiDefaults::TOOL_PROFILE_FULL
            : CoquiDefaults::TOOL_PROFILE_LEAN;
    }

    public function isFull(): bool
    {
        return $this->profile() === CoquiDefaults::TOOL_PROFILE_FULL;
    }

    /**
     * Toolkit basenames that stay eager. An explicit coreToolkits config list
     * wins over the profile preset.
     *
     * @return list<string>
     */
    public function coreToolkits(): array
    {
        $override = $this->config->get('agents.defaults.coreToolkits');
        if (is_array($override)) {
            return array_values(array_filter($override, 'is_string'));
        }

        return $this->isFull()
            ? CoquiDefaults::SYSTEM_TOOLKITS
            : CoquiDefaults::LEAN_CORE_TOOLKITS;
    }

    /**
     * Standalone tool names that stay eager. Under full, all standalone tools
     * are eager (nothing deferred).
     *
     * @return list<string>
     */
    public function coreTools(): array
    {
        return $this->isFull()
            ? CoquiDefaults::ALL_STANDALONE_TOOLS
            : CoquiDefaults::LEAN_CORE_TOOLS;
    }
}
```

- [ ] **Step 3c: Add the config default**

In `config/defaults.json`, add `"toolProfile": "lean",` to the `agents.defaults` object (after `"model"`, around line 8744):

```json
    "defaults": {
        "model": "ollama/qwen3.5:9b",
        "toolProfile": "lean",
        "imageModel": "ollama/jmorgan/z-image-turbo:fp8",
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/ToolProfileResolverTest.php`
Expected: PASS (4 tests).

- [ ] **Step 5: Verify no dead-const breakage + static analysis**

Run: `grep -rn "SYSTEM_TOOLS\b" src/ tests/` — expected: no matches (the const was dead).
Run: `./vendor/bin/phpstan analyse src/Config/ToolProfileResolver.php src/Contract/CoquiDefaults.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Contract/CoquiDefaults.php src/Config/ToolProfileResolver.php config/defaults.json tests/Unit/Config/ToolProfileResolverTest.php
git commit -m "feat(lean-default): add tool-profile constants and ToolProfileResolver

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 2: Resolve "system/immutable" toolkits from the active profile

`ToolkitLoadingRegistry::isSystem()` currently hardcodes `SYSTEM_TOOLKITS`, so under lean a user could never promote `MemoryToolkit` back to eager (it would still report `System`). Make the immutable set the *resolved core toolkit* list, defaulting to `SYSTEM_TOOLKITS` for backward compatibility.

**Files:**
- Modify: `src/Config/ToolkitLoadingRegistry.php:37-40` (constructor), `:113-118` (`isSystem`)
- Test: `tests/Unit/Config/ToolProfileResolverTest.php` (extend) — new file `tests/Unit/Config/ToolkitLoadingRegistrySystemSetTest.php`

**Interfaces:**
- Consumes: `CoquiDefaults::SYSTEM_TOOLKITS` (default), a caller-supplied `list<string>` core set.
- Produces: `ToolkitLoadingRegistry::__construct(string $workspacePath, ?array $systemToolkits = null)` — when `$systemToolkits` is null, falls back to `CoquiDefaults::SYSTEM_TOOLKITS` (unchanged behavior).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Config/ToolkitLoadingRegistrySystemSetTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Config\ToolkitLoadingRegistry;
use CoquiBot\Coqui\Contract\ToolkitLoadingMode;

it('treats only the supplied core toolkits as immutable system toolkits', function () {
    $dir = sys_get_temp_dir() . '/lean-reg-' . uniqid();
    mkdir($dir);

    $registry = new ToolkitLoadingRegistry($dir, ['FileSystemToolkit', 'ShellToolkit']);

    expect($registry->isSystem('FileSystemToolkit'))->toBeTrue();
    // Under lean, Memory is no longer system — it can be overridden.
    expect($registry->isSystem('MemoryToolkit'))->toBeFalse();

    $registry->setMode('MemoryToolkit', ToolkitLoadingMode::Eager);
    expect($registry->getMode('MemoryToolkit'))->toBe(ToolkitLoadingMode::Eager);
});

it('falls back to the legacy SYSTEM_TOOLKITS when no core set is supplied', function () {
    $dir = sys_get_temp_dir() . '/lean-reg-' . uniqid();
    mkdir($dir);

    $registry = new ToolkitLoadingRegistry($dir);

    expect($registry->isSystem('MemoryToolkit'))->toBeTrue();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/ToolkitLoadingRegistrySystemSetTest.php`
Expected: FAIL — constructor does not accept a second argument / `MemoryToolkit` reported system.

- [ ] **Step 3: Implement**

In `src/Config/ToolkitLoadingRegistry.php`, add a property and update the constructor:

```php
    private string $filePath;

    /** @var list<string> Toolkit basenames that are immutable/never-deferred. */
    private array $systemToolkits;

    /** @var array<string, string>|null classBasename => mode string */
    private ?array $cache = null;

    /**
     * @param list<string>|null $systemToolkits Immutable core toolkits; defaults to
     *        CoquiDefaults::SYSTEM_TOOLKITS for backward compatibility.
     */
    public function __construct(string $workspacePath, ?array $systemToolkits = null)
    {
        $this->filePath = PathHelper::trimTrailingSlash($workspacePath) . '/toolkit-loading.json';
        $this->systemToolkits = $systemToolkits ?? CoquiDefaults::SYSTEM_TOOLKITS;
    }
```

Update `isSystem()`:

```php
    public function isSystem(string $classBasename): bool
    {
        return in_array($classBasename, $this->systemToolkits, true);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Config/ToolkitLoadingRegistrySystemSetTest.php`
Expected: PASS (2 tests).

- [ ] **Step 5: Wire boot to pass the resolved core set**

Find where `ToolkitLoadingRegistry` is constructed:

Run: `grep -rn "new ToolkitLoadingRegistry(" src/`

For each construction site in `src/Config/BootManager.php` (and any command factory), pass the resolved core set:

```php
$toolProfileResolver = new \CoquiBot\Coqui\Config\ToolProfileResolver($config);
$loadingRegistry = new ToolkitLoadingRegistry($workspacePath, $toolProfileResolver->coreToolkits());
```

(Use the `$config`/`$workspacePath` variables already in scope at each site; if a site has no config available, leave the second argument off — it safely defaults to the legacy set.)

- [ ] **Step 6: Run full suite for regressions + analyse**

Run: `./vendor/bin/pest tests/Unit/Config/`
Run: `./vendor/bin/phpstan analyse src/Config/ToolkitLoadingRegistry.php`
Expected: PASS / `[OK] No errors`.

- [ ] **Step 7: Commit**

```bash
git add src/Config/ToolkitLoadingRegistry.php src/Config/BootManager.php tests/Unit/Config/ToolkitLoadingRegistrySystemSetTest.php
git commit -m "feat(lean-default): resolve immutable toolkits from the active profile

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 3: Profile-aware toolkit deferral in `OrchestratorAgent`

The built-in toolkits are added eagerly and unconditionally at `src/Agent/OrchestratorAgent.php:374-511`. Route the non-core ones through a helper that defers them (as `StubToolkit`) under lean, recording deferred info so their tool-prompt slugs auto-exclude (existing path at 624-630).

**Files:**
- Modify: `src/Agent/OrchestratorAgent.php` — add `ToolProfileResolver` field + `addSystemToolkit()` helper; convert the 9 non-core toolkit adds.
- Test: `tests/Unit/Agent/LeanDefaultToolkitDeferralTest.php`

**Interfaces:**
- Consumes: `ToolProfileResolver::coreToolkits()`, existing `StubToolkit`, `deferredToolkitInfo[]` (shape `array{name:string,description:string,package:string}`), `recordToolkitLoadingDecision(...)`, `appliedLoadingModes`.
- Produces: private `addSystemToolkit(string $basename, string $description, ToolkitInterface $toolkit): void` on `OrchestratorAgent`.

- [ ] **Step 1: Write the failing test**

Deferral is observed through the real public accessors `getDeferredToolkitInfo(): list<array{name,description,package}>` and `getAppliedLoadingModes(): array<string,ToolkitLoadingMode>` — **not** via toolkit objects (`AbstractAgent::$toolkits` is private with no accessor). Create `tests/Unit/Agent/LeanDefaultToolkitDeferralTest.php`:

```php
<?php

declare(strict_types=1);

it('defers non-core built-in toolkits under the lean profile', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']); // harness helper (see below)

    $deferred = array_column($agent->getDeferredToolkitInfo(), 'name');

    // FileSystem + Shell stay eager (never deferred).
    expect($deferred)->not->toContain('FileSystemToolkit');
    expect($deferred)->not->toContain('ShellToolkit');
    // Memory + Loop + Schedule are deferred.
    expect($deferred)->toContain('MemoryToolkit');
    expect($deferred)->toContain('LoopToolkit');
    expect($deferred)->toContain('ScheduleToolkit');
});

it('keeps every built-in toolkit eager under the full profile', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);

    $deferred = array_column($agent->getDeferredToolkitInfo(), 'name');
    foreach (['MemoryToolkit', 'LoopToolkit', 'ScheduleToolkit', 'WebToolkit'] as $builtin) {
        expect($deferred)->not->toContain($builtin);
    }
});

it('excludes deferred toolkit prompt slugs from the system prompt under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $prompt = $agent->getSystemPromptText();

    // The ~930-token memory guidance and loops guidance are gone.
    expect($prompt)->not->toContain('# MEMORY');
    expect($prompt)->not->toContain('# LOOPS');
});

it('retains passive memory recall even though memory tools are deferred', function () {
    // Seed one memory, then assert it still appears in the rendered prompt under
    // lean. Mirror the memory-store seeding used by the existing memory-injection
    // test (grep tests/ for MemoryStore usage) so recall is exercised for real.
    $agent = makeOrchestrator(
        ['agents.defaults.toolProfile' => 'lean'],
        seedMemories: ['The user prefers tabs over spaces.'],
    );
    $prompt = $agent->getSystemPromptText();

    // Recall block is injected independently of the (deferred) memory toolkit.
    expect($prompt)->toContain('tabs over spaces');
    // But the memory management guidance/tools are not eagerly loaded.
    expect($prompt)->not->toContain('# MEMORY');
});
```

> **Harness:** `makeOrchestrator(array $configOverrides, array $pinEager = [], array $seedMemories = [])` is a shared helper this plan introduces in `tests/Unit/Agent/LeanHarness.php`. Build it by extracting the agent-construction already in `createExternalToolkitSurfaceAgent()` (`tests/Unit/Agent/ExternalToolkitSurfaceTest.php:41+`): construct `OpenClawConfig::fromArray(...)` merged with `$configOverrides`, a temp workspace, `ToolkitDiscovery`, a `ToolkitLoadingRegistry` **constructed with `(new ToolProfileResolver($config))->coreToolkits()` as its second argument** (otherwise non-core toolkits are still "system" and `setMode(..., Eager)` throws), writing `$pinEager` entries via `setMode(..., ToolkitLoadingMode::Eager)` before boot, a `MemoryStore` seeded with `$seedMemories`, and an `OrchestratorAgent` (with the same registry passed via `OrchestratorDependencies->loadingRegistry`) on an offline provider (the matrix already uses `ollama/qwen3:latest` with a localhost base URL and never makes a call in these prompt-only assertions). Reuse this helper across Tasks 3–8.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultToolkitDeferralTest.php`
Expected: FAIL — deferred set is empty (all built-ins currently eager).

- [ ] **Step 3a: Add the resolver field + helper**

In `OrchestratorAgent`, add a field near the other config-derived fields (around line 141) and initialize it early in the constructor (right after `$config` is available, before line 374):

```php
    private readonly ToolProfileResolver $toolProfileResolver;
```

In the constructor, before the toolkit-registration block (before line 374):

```php
        $this->toolProfileResolver = new ToolProfileResolver($config);
```

Add `use CoquiBot\Coqui\Config\ToolProfileResolver;` to the imports.

Add the helper method (place it near `addToolkit()`, after line 799):

```php
    /**
     * Register a built-in ("system") toolkit, deferring it under the active
     * tool profile unless it is in the core set or the user pinned it Eager.
     *
     * Deferred toolkits are wrapped as StubToolkit (zero prompt footprint) and
     * recorded so their tool-prompt slugs are excluded and the capability index
     * can advertise them. Eager registration is unchanged from a direct
     * addToolkit() call.
     */
    private function addSystemToolkit(string $basename, string $description, ToolkitInterface $toolkit): void
    {
        $core = in_array($basename, $this->toolProfileResolver->coreToolkits(), true);
        $pinnedEager = $this->loadingRegistry?->getMode($basename) === ToolkitLoadingMode::Eager;

        if ($core || $pinnedEager) {
            $this->addToolkit($toolkit);
            return;
        }

        $this->addToolkit(new StubToolkit($toolkit));
        $this->deferredToolkitInfo[] = [
            'name' => $basename,
            'description' => $description,
            'package' => '',
        ];
        $this->appliedLoadingModes[$basename] = ToolkitLoadingMode::Deferred;
    }
```

- [ ] **Step 3b: Convert the non-core built-in toolkit adds**

For each built-in toolkit currently added between lines 418-511, replace the direct `$this->addToolkit(new X(...))` with `$this->addSystemToolkit('X', '<description>', new X(...))`, keeping the exact constructor arguments already present. Leave `FileSystemToolkit` (374) and `ShellToolkit` (391/404) as direct `addToolkit` — they are always core. Conversions (basename → short description):

| Line | Basename | Description |
|---|---|---|
| 418 | `WebToolkit` | `Web search and fetch` |
| 427 | `MemoryToolkit` | `Persistent memory management (store, search, edit)` |
| 444 | `ArtifactToolkit` | `Create and manage artifacts` |
| 455 | `ProjectToolkit` | `Project working-scope management` |
| 467 | `CoquiSourceToolkit` | `Read Coqui's own source` |
| 471 | `ComposerToolkit` | `Composer package operations` |
| 475 | `PackagistToolkit` | `Search Packagist` |
| 482 | `ScheduleToolkit` | `Cron-style scheduled tasks` |
| 506 | `LoopToolkit` | `Autonomous loop management` |

Example (line 427):

```php
        // before:
        // $this->addToolkit(new MemoryToolkit( ...args... ));
        // after:
        $this->addSystemToolkit('MemoryToolkit', 'Persistent memory management (store, search, edit)', new MemoryToolkit( ...same args... ));
```

Preserve any surrounding `if` guards (e.g. Memory/Artifact/Project are already conditional on their stores) — wrap only the `addToolkit` call inside each existing guard.

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultToolkitDeferralTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Analyse**

Run: `./vendor/bin/phpstan analyse src/Agent/OrchestratorAgent.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Agent/OrchestratorAgent.php tests/Unit/Agent/LeanDefaultToolkitDeferralTest.php tests/Unit/Agent/LeanHarness.php
git commit -m "feat(lean-default): defer non-core built-in toolkits under lean profile

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 4: Profile-aware standalone-tool deferral

Standalone tools are surfaced to the LLM in `tools()` (`src/Agent/OrchestratorAgent.php:1319-1375`) but always registered in `toolRegistry` (754-783). Deferring one = omit it from `tools()` while it stays in the registry for `tool_search`.

**Files:**
- Modify: `src/Agent/OrchestratorAgent.php` — compute deferred standalone set in constructor; filter in `tools()`.
- Test: `tests/Unit/Agent/LeanDefaultStandaloneToolDeferralTest.php`

**Interfaces:**
- Consumes: `ToolProfileResolver::coreTools()`, `CoquiDefaults::ALL_STANDALONE_TOOLS`, existing `visibilityRegistry` / role filters in `tools()`.
- Produces: private `array<string,true> $deferredStandaloneTools` (name-set); `deferredStandaloneToolInfo` list (`array{name:string,description:string}`) for Task 5.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Agent/LeanDefaultStandaloneToolDeferralTest.php`:

```php
<?php

declare(strict_types=1);

it('omits non-core standalone tools from the LLM tool list under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);

    $names = array_map(fn($t) => $t->name(), $agent->tools());

    // core stays visible
    expect($names)->toContain('tool_search')->toContain('config')->toContain('php_execute');
    // deferred standalone tools are gone from the visible list
    expect($names)->not->toContain('spawn_agent');
    expect($names)->not->toContain('vision_analyze');
    expect($names)->not->toContain('extract_memories');
});

it('still finds a deferred standalone tool via tool_search', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);

    // Locate the tool_search tool in the visible list and invoke it directly —
    // real-behavior proof the deferred tool is in the BM25 registry.
    $search = null;
    foreach ($agent->tools() as $t) {
        if ($t->name() === 'tool_search') { $search = $t; break; }
    }
    expect($search)->not->toBeNull();

    $result = $search->execute(['query' => 'spawn child agent']); // ToolResult
    expect($result->content)->toContain('spawn_agent');
});

it('keeps all standalone tools visible under the full profile', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $names = array_map(fn($t) => $t->name(), $agent->tools());

    expect($names)->toContain('spawn_agent')->toContain('vision_analyze');
});
```

> `toolRegistrySnapshotNames()` — if no such accessor exists, assert indirectly by invoking the `tool_search` tool with query `"spawn agent"` and asserting the result mentions `spawn_agent`, or expose a tiny test-only accessor on the harness. Prefer the `tool_search` invocation to test real behavior.

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultStandaloneToolDeferralTest.php`
Expected: FAIL — `spawn_agent` still present in `tools()`.

- [ ] **Step 3a: Compute the deferred standalone set (constructor)**

After the standalone tools are created and registered (after line 783), add:

```php
        // Determine which standalone tools defer under the active profile.
        // They remain in $this->toolRegistry (registered above) for tool_search;
        // tools() simply omits them from the LLM-visible list.
        $coreTools = $this->toolProfileResolver->coreTools();
        $this->deferredStandaloneTools = [];
        $this->deferredStandaloneToolInfo = [];
        $standaloneDescriptions = [
            'spawn_agent' => 'Spawn isolated child agents',
            'package_info' => 'Inspect installed SDK packages',
            'vision_analyze' => 'Analyze images',
            'summarize_conversation' => 'Compress conversation history',
            'extract_memories' => 'Extract long-term memories',
            'restart_coqui' => 'Restart the agent process',
        ];
        foreach (CoquiDefaults::ALL_STANDALONE_TOOLS as $name) {
            if (!in_array($name, $coreTools, true)) {
                $this->deferredStandaloneTools[$name] = true;
                if (isset($standaloneDescriptions[$name])) {
                    $this->deferredStandaloneToolInfo[] = ['name' => $name, 'description' => $standaloneDescriptions[$name]];
                }
            }
        }
```

Add the fields near the other private state (around line 174):

```php
    /** @var array<string,true> Standalone tool names deferred under the active profile. */
    private array $deferredStandaloneTools = [];

    /** @var list<array{name:string,description:string}> Deferred standalone tools for the capability index. */
    private array $deferredStandaloneToolInfo = [];
```

- [ ] **Step 3b: Filter `tools()`**

In `tools()` (1358-1372), skip deferred standalone tools inside the `foreach ($visibilityManaged ...)` loop. Add the guard as the first check in the loop body:

```php
        foreach ($visibilityManaged as $name => $tool) {
            // Profile deferral: non-core standalone tools stay in the registry
            // (for tool_search) but are omitted from the LLM-visible list.
            if (isset($this->deferredStandaloneTools[$name])) {
                continue;
            }

            // Role-based filtering for standalone tools
            if (!$this->roleToolkitResolver->isToolAllowed($name)) {
                continue;
            }
            // ... unchanged ...
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultStandaloneToolDeferralTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Analyse**

Run: `./vendor/bin/phpstan analyse src/Agent/OrchestratorAgent.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: Commit**

```bash
git add src/Agent/OrchestratorAgent.php tests/Unit/Agent/LeanDefaultStandaloneToolDeferralTest.php
git commit -m "feat(lean-default): defer non-core standalone tools under lean profile

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 5: Concise capability index

Rewrite the deferred hint (`injectDeferredToolkitHint`, `src/Agent/OrchestratorAgent.php:1276-1306`) into a single compact capability index covering both deferred toolkits and deferred standalone tools, so a small model has a one-line map without full schemas.

**Files:**
- Modify: `src/Agent/OrchestratorAgent.php:1276-1306`
- Test: `tests/Unit/Agent/LeanDefaultCapabilityIndexTest.php`

**Interfaces:**
- Consumes: `deferredToolkitInfo`, `deferredStandaloneToolInfo`.
- Produces: same method name/signature (`injectDeferredToolkitHint(string $rendered): string`) — behavior changed.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Agent/LeanDefaultCapabilityIndexTest.php`:

```php
<?php

declare(strict_types=1);

it('renders a concise capability index listing deferred categories under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $prompt = $agent->getSystemPromptText();

    expect($prompt)->toContain('tool_search');
    // toolkit categories
    expect($prompt)->toContain('memory');
    expect($prompt)->toContain('loops');
    // deferred standalone capabilities
    expect($prompt)->toContain('spawn_agent');
    // stays compact: single index section, not per-toolkit guidance blocks
    expect(substr_count($prompt, '## DEFERRED'))->toBe(1);
});

it('omits the capability index entirely under full (nothing deferred)', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $prompt = $agent->getSystemPromptText();

    expect($prompt)->not->toContain('## DEFERRED');
});

it('drops a toolkit from the index once it is pinned eager', function () {
    $agent = makeOrchestrator([
        'agents.defaults.toolProfile' => 'lean',
    ], pinEager: ['MemoryToolkit']); // harness sets toolkit-loading.json override

    $prompt = $agent->getSystemPromptText();
    // Memory is now eager: its guidance returns and it is not in the deferred index.
    expect($prompt)->toContain('# MEMORY');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultCapabilityIndexTest.php`
Expected: FAIL — the standalone capabilities are absent from the current toolkit-only hint.

- [ ] **Step 3: Implement the concise index**

Replace the body of `injectDeferredToolkitHint` (from line 1286 `if (empty($this->deferredToolkitInfo))` to the `return` at 1305) with a version that merges both deferred sources and renders one compact block:

```php
        $toolkitLabels = array_map(
            static fn(array $info): string => $info['name'],
            $this->deferredToolkitInfo,
        );
        $standaloneLabels = array_map(
            static fn(array $info): string => $info['name'],
            $this->deferredStandaloneToolInfo,
        );
        $all = array_values(array_unique([...$toolkitLabels, ...$standaloneLabels]));

        if ($all === []) {
            return $rendered;
        }

        $lines = [
            '## DEFERRED CAPABILITIES',
            '',
            'These are available but not loaded. Load with `tool_search("<keyword>")` before use:',
            '',
            implode(', ', $all) . '.',
            '',
            'Use `coqui_toolkits` for a full inventory.',
        ];

        return $rendered . "\n\n" . implode("\n", $lines);
```

Keep the guard clauses above (lines 1278-1284, the profile section-enabled / stubbed checks) unchanged, but update their early `empty($this->deferredToolkitInfo)` check (line 1286) to also account for standalone deferrals — the merged `$all === []` check above already handles the empty case, so **delete the old lines 1286-1288** (`if (empty($this->deferredToolkitInfo)) { return $rendered; }`).

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultCapabilityIndexTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Grep for the old section title in other surfaces**

Run: `grep -rn "DEFERRED TOOLKITS" src/ tests/`
For any prompt-preview/inspection surface still emitting `## DEFERRED TOOLKITS` with the same data (e.g. around lines 1093, 1896), update the string to `## DEFERRED CAPABILITIES` for consistency, or leave preview-only copy if it is a different audience — note which in the commit. Do not change tests that assert the old wording without updating them here.

- [ ] **Step 6: Analyse + commit**

Run: `./vendor/bin/phpstan analyse src/Agent/OrchestratorAgent.php`
```bash
git add src/Agent/OrchestratorAgent.php tests/Unit/Agent/LeanDefaultCapabilityIndexTest.php
git commit -m "feat(lean-default): render concise deferred-capability index

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 6: Config precedence integration test

Prove the resolution order end-to-end: per-toolkit override > `coreToolkits` list > `toolProfile` preset > shipped default.

**Files:**
- Test: `tests/Unit/Agent/LeanDefaultProfilePrecedenceTest.php`

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

function deferredBasenames($agent): array
{
    return array_column($agent->getDeferredToolkitInfo(), 'name');
}

it('default (no config) is lean', function () {
    $agent = makeOrchestrator([]);
    expect(deferredBasenames($agent))->toContain('MemoryToolkit');
});

it('coreToolkits list overrides the profile preset', function () {
    $agent = makeOrchestrator([
        'agents.defaults.toolProfile' => 'lean',
        'agents.defaults.coreToolkits' => ['FileSystemToolkit', 'ShellToolkit', 'MemoryToolkit'],
    ]);
    // Memory now core => not deferred.
    expect(deferredBasenames($agent))->not->toContain('MemoryToolkit');
});

it('a per-toolkit eager override wins even under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean'], pinEager: ['LoopToolkit']);
    expect(deferredBasenames($agent))->not->toContain('LoopToolkit');
});

it('full profile defers nothing built-in', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $builtins = ['MemoryToolkit', 'LoopToolkit', 'WebToolkit', 'ScheduleToolkit'];
    foreach ($builtins as $b) {
        expect(deferredBasenames($agent))->not->toContain($b);
    }
});
```

- [ ] **Step 2: Run + verify pass**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultProfilePrecedenceTest.php`
Expected: PASS (4 tests). If the `pinEager` path fails, confirm Task 3's `addSystemToolkit` reads `loadingRegistry->getMode()` and that the harness writes `toolkit-loading.json` before constructing the agent.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/Agent/LeanDefaultProfilePrecedenceTest.php
git commit -m "test(lean-default): cover profile/config precedence

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 7: Synthetic discover-then-call + optional real-endpoint gate

Prove aggressive deferral is usable: a scripted provider issues a `tool_search` call, then calls a deferred tool — end to end, no live model.

**Files:**
- Test: `tests/Unit/Agent/LeanDefaultDiscoverThenCallTest.php` (synthetic, always runs)
- Test: `tests/Integration/OllamaDiscoverThenCallTest.php` (env-gated, skipped by default)

**Interfaces:**
- Consumes: the mock/fake provider pattern already used in core agent tests (inspect `tests/` for the existing fake provider — reuse it; do not invent a new one).

- [ ] **Step 1: Write the synthetic test**

```php
<?php

declare(strict_types=1);

it('discovers a deferred tool via tool_search then invokes it (no live model)', function () {
    // Provider scripted to: (1) call tool_search("spawn agent"),
    // (2) after seeing results, call the now-known deferred tool, (3) stop.
    $agent = makeOrchestratorWithScriptedProvider(
        config: ['agents.defaults.toolProfile' => 'lean'],
        script: [
            ['tool' => 'tool_search', 'args' => ['query' => 'spawn child agent']],
            ['tool' => 'spawn_agent', 'args' => [/* minimal valid args */]],
            ['stop' => true],
        ],
    );

    $transcript = $agent->runToCompletion('use a sub-agent'); // harness accessor

    expect($transcript->toolCalls())->toContain('tool_search');
    expect($transcript->toolCalls())->toContain('spawn_agent');
    // spawn_agent was NEVER in the initial schema:
    expect($transcript->initialToolNames())->not->toContain('spawn_agent');
});
```

> Build `makeOrchestratorWithScriptedProvider` on top of the existing fake-provider harness. If scripting `spawn_agent` end-to-end is heavy (it boots a child agent), substitute a lighter deferred tool such as `package_info` or `summarize_conversation` — the assertion is about the discover-then-call *path*, not the specific tool. Document the substitution in a code comment.

- [ ] **Step 2: Run + verify pass**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultDiscoverThenCallTest.php`
Expected: PASS.

- [ ] **Step 3: Write the optional real-endpoint integration test**

```php
<?php

declare(strict_types=1);

it('a real small Ollama model discovers then calls a deferred tool', function () {
    if (getenv('COQUI_OLLAMA_IT') !== '1') {
        test()->markTestSkipped('Set COQUI_OLLAMA_IT=1 with a running Ollama endpoint to run.');
    }

    $model = getenv('COQUI_OLLAMA_MODEL') ?: 'ollama/qwen3.5:9b';
    $agent = makeLiveOrchestrator(['agents.defaults.toolProfile' => 'lean', 'agents.defaults.model' => $model]);

    $transcript = $agent->runToCompletion(
        'Search your available tools for a way to run PHP code, then use it to echo 2+2.',
    );

    expect($transcript->toolCalls())->toContain('tool_search');
})->group('integration', 'ollama');
```

- [ ] **Step 4: Confirm it skips cleanly with no endpoint**

Run: `./vendor/bin/pest tests/Integration/OllamaDiscoverThenCallTest.php`
Expected: SKIPPED (1 skipped, 0 failed).

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/Agent/LeanDefaultDiscoverThenCallTest.php tests/Integration/OllamaDiscoverThenCallTest.php
git commit -m "test(lean-default): synthetic discover-then-call + optional Ollama gate

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Task 8: Size/count guards + docs + source map

Lock the win with regression guards and update user-facing docs.

**Files:**
- Test: `tests/Unit/Agent/LeanDefaultSizeGuardTest.php`
- Modify: `docs/CONFIGURATION.md`
- Modify: `config/source.json`

- [ ] **Step 1: Write the guard test**

```php
<?php

declare(strict_types=1);

use CarmeloSantana\PHPAgents\Context\HeuristicCounter;

it('keeps the lean system prompt small', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $tokens = (new HeuristicCounter())->count($agent->getSystemPromptText());

    // Design target ~1.5-2k; guard generously to catch regressions, not noise.
    expect($tokens)->toBeLessThan(2500);
});

it('exposes only the core tool set under lean', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'lean']);
    $names = array_map(fn($t) => $t->name(), $agent->tools());

    expect($names)->not->toContain('spawn_agent');
    expect($names)->not->toContain('vision_analyze');
    expect($names)->toContain('tool_search');
    expect($names)->toContain('php_execute');
});

it('full profile restores the pre-change eager surface', function () {
    $agent = makeOrchestrator(['agents.defaults.toolProfile' => 'full']);
    $names = array_map(fn($t) => $t->name(), $agent->tools());

    // Sanity: the previously-eager standalone tools are all present again.
    foreach (['spawn_agent', 'vision_analyze', 'summarize_conversation', 'extract_memories'] as $n) {
        expect($names)->toContain($n);
    }
});
```

- [ ] **Step 2: Run + verify pass**

Run: `./vendor/bin/pest tests/Unit/Agent/LeanDefaultSizeGuardTest.php`
Expected: PASS. If the lean prompt exceeds 2500, check that Task 3's slug exclusion actually fired (deferred toolkits recorded before line 624) and that core prompt sections aren't unexpectedly large.

- [ ] **Step 3: Document the config keys**

In `docs/CONFIGURATION.md`, add a `Tool profile` subsection under the agents/defaults area:

```markdown
### Tool profile (`agents.defaults.toolProfile`)

Controls how many tools load into a fresh session's context.

- `lean` (default) — only a bootstrap core loads eagerly (filesystem, shell,
  `tool_search`, `config`, `credentials`, `coqui_toolkits`, `coqui_skills`,
  `php_execute`). Everything else — memory tools, loops, schedules, artifacts,
  projects, web, vision, sub-agents, and more — is deferred and discovered on
  demand via `tool_search`. This keeps the prompt small enough for local Ollama
  models. Passive memory recall and active-project context still apply.
- `full` — restores the legacy behavior where every built-in toolkit and
  standalone tool loads eagerly.

Advanced: `agents.defaults.coreToolkits` accepts an explicit list of toolkit
class basenames to keep eager, overriding the profile preset. Per-toolkit
overrides via `/toolkits` (or `.workspace/toolkit-loading.json`) still apply on
top — e.g. pin `MemoryToolkit` eager without switching to `full`.
```

- [ ] **Step 4: Update the source map**

Add `src/Config/ToolProfileResolver.php` to `config/source.json` following the existing entry format for `src/Config/` classes (copy a neighboring entry's shape, e.g. `ToolkitLoadingRegistry`, and adapt the path/responsibility text: "Resolves the always-eager core tool set for the active tool profile").

- [ ] **Step 5: Full suite + analyse**

Run: `composer test`
Run: `./vendor/bin/phpstan analyse`
Expected: green; `[OK] No errors`. Investigate any pre-existing flaky tests (e.g. `ProcessSpawnerTest` under parallel load) by re-running in isolation before attributing failures to this change.

- [ ] **Step 6: Commit**

```bash
git add tests/Unit/Agent/LeanDefaultSizeGuardTest.php docs/CONFIGURATION.md config/source.json
git commit -m "test(lean-default): size/count guards; docs + source map for toolProfile

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Final verification (whole-branch)

- [ ] `composer test` green with no Ollama endpoint.
- [ ] `./vendor/bin/phpstan analyse` clean at level 8.
- [ ] Fresh `lean` session: ~30 eager tools, prompt < 2.5k tokens, no `# MEMORY`/`# LOOPS` guidance, capability index present.
- [ ] `toolProfile: full` reproduces today's eager surface (guard test passes).
- [ ] Deferred tools reachable via `tool_search` (synthetic discover-then-call passes).
- [ ] `git status` shows the user's `.gitignore` / `.vscode/settings.json` edits still unstaged and untouched.
- [ ] Optional: with an endpoint, `COQUI_OLLAMA_IT=1 ./vendor/bin/pest --group=ollama` passes (pre-merge manual gate).
