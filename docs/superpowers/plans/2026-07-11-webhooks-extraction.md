# Webhooks Extraction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the entire Webhooks feature out of Coqui core into a new optional mod `coqui-toolkit-webhooks`, building two reusable extension points: an API-feature provider (HTTP routes) and storage in the toolkit-discovery context (so storage-backed mod toolkits work).

**Architecture:** Mirrors the backstory-formats extraction (`docs/superpowers/plans/2026-07-10-backstory-formats-extraction.md`). **Part A** adds the hooks in core + removes all webhook code (testable in core alone with a fake provider). **Part B** is the new sibling mod repo (moves the webhook code verbatim, re-namespaced). **Part C** installs the local mod into core, proves end-to-end, then reverts so core ships mod-optional.

**Tech Stack:** PHP 8.4, Pest (`composer test`), PHPStan level 8 (`composer analyse`), Composer.

**Spec:** `docs/superpowers/specs/2026-07-11-webhooks-extraction-design.md`.

## Global Constraints

- PHP 8.4, `declare(strict_types=1);`, `final` by default, one class per file, 4-space indent.
- Branch off `origin/feat/webhooks-extraction` (= `main` + this spec/plan): `git fetch origin && git checkout -b feat/webhooks-extraction-impl origin/feat/webhooks-extraction`. Base carries no code changes over main — only these docs.
- **Never `git add -A` / `git add .`** — the coqui working tree has two intentional unstaged edits (`.gitignore` modified, `.vscode/settings.json` deleted) that MUST stay unstaged. Stage only exact paths.
- Every commit message ends with: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.
- Both `composer test` and `composer analyse` must be green before every commit.
- **Never weaken the safety model** (catastrophic blacklist, audit logging, sandboxing, approvals).
- **Repos:** core work in `/home/carmelo/Projects/CoquiBot/Core/coqui`; the mod is a NEW sibling repo `/home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-webhooks` (created in Part B), mirroring `coqui-toolkit-backstory-formats`.
- **Core new namespaces:** `CoquiBot\Coqui\Contract\ApiFeatureInterface`, `CoquiBot\Coqui\Api\CoreServices`, `CoquiBot\Coqui\Config\ApiFeatureDiscovery`.
- **Mod namespace:** `CoquiBot\Toolkits\Webhooks\` (mirrors `CoquiBot\Toolkits\BackstoryFormats\`).

---

## Part A — Core: the two extension points + webhook removal (coqui repo)

### Task A1: `ApiFeatureInterface` + `CoreServices`

**Files:**
- Create: `src/Contract/ApiFeatureInterface.php`
- Create: `src/Api/CoreServices.php`
- Test: `tests/Unit/Api/CoreServicesTest.php`

**Interfaces produced:** `ApiFeatureInterface::register(Router $router, CoreServices $services): void`; `CoreServices` with `sessionStorage(): SessionStorage`, `pdo(): \PDO`, `profileDiscovery(): ProfileDiscovery`, `config(): OpenClawConfig`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;

test('CoreServices exposes core collaborators', function (): void {
    $dbPath = sys_get_temp_dir() . '/coqui-coreservices-' . bin2hex(random_bytes(8)) . '.db';
    $storage = new SessionStorage($dbPath);
    $config = new OpenClawConfig([]);
    $profileDiscovery = new ProfileDiscovery($config);

    try {
        $services = new CoreServices($storage, $profileDiscovery, $config);

        expect($services->sessionStorage())->toBe($storage);
        expect($services->pdo())->toBe($storage->getPdo());
        expect($services->profileDiscovery())->toBe($profileDiscovery);
        expect($services->config())->toBe($config);
    } finally {
        cleanupSqliteTestDb($dbPath);
    }
});
```

> Before running, confirm the `OpenClawConfig` and `ProfileDiscovery` constructors match (`grep -nA3 "public function __construct" src/Config/OpenClawConfig.php src/Config/ProfileDiscovery.php`). If either needs different args, adjust the test's construction only — not the `CoreServices` API.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/CoreServicesTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `ApiFeatureInterface`**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Contract;

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Api\Router;

/**
 * A feature contributed by an installed mod that registers HTTP API routes.
 *
 * Mods declare their provider class(es) under extra.php-agents.apiFeatures in
 * composer.json; ApiFeatureDiscovery finds them and ApiCommand calls register()
 * with the live Router and a CoreServices handle. Implementations must be
 * no-arg constructable.
 */
interface ApiFeatureInterface
{
    public function register(Router $router, CoreServices $services): void;
}
```

- [ ] **Step 4: Implement `CoreServices`**

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Api;

use CoquiBot\Coqui\Config\OpenClawConfig;
use CoquiBot\Coqui\Config\ProfileDiscovery;
use CoquiBot\Coqui\Storage\SessionStorage;

/**
 * Read-only handle to the core collaborators a mod-provided API feature needs.
 * Deliberately minimal — widen only when a future feature-mod requires more.
 */
final readonly class CoreServices
{
    public function __construct(
        private SessionStorage $sessionStorage,
        private ProfileDiscovery $profileDiscovery,
        private OpenClawConfig $config,
    ) {}

    public function sessionStorage(): SessionStorage
    {
        return $this->sessionStorage;
    }

    public function pdo(): \PDO
    {
        return $this->sessionStorage->getPdo();
    }

    public function profileDiscovery(): ProfileDiscovery
    {
        return $this->profileDiscovery;
    }

    public function config(): OpenClawConfig
    {
        return $this->config;
    }
}
```

- [ ] **Step 5: Run tests + analyse; commit**

Run: `./vendor/bin/pest tests/Unit/Api/CoreServicesTest.php && composer analyse`
Expected: PASS; `[OK] No errors`.

```bash
git add src/Contract/ApiFeatureInterface.php src/Api/CoreServices.php tests/Unit/Api/CoreServicesTest.php
git commit -m "$(cat <<'EOF'
feat(api): add ApiFeatureInterface + CoreServices extension point

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

### Task A2: `ApiFeatureDiscovery` (composer-extra discovery)

**Files:**
- Create: `src/Config/ApiFeatureDiscovery.php`
- Test: `tests/Unit/Config/ApiFeatureDiscoveryTest.php`

**Interfaces:** `ApiFeatureDiscovery::__construct(?string $projectRoot = null)`, `discover(): list<ApiFeatureInterface>`. Mirrors `BackstoryExtractorDiscovery` (`src/Backstory/Extractor/BackstoryExtractorDiscovery.php`) — read that file first as the reference implementation.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Config\ApiFeatureDiscovery;
use CoquiBot\Coqui\Contract\ApiFeatureInterface;

// Global-namespace fake so class_exists() resolves it by name.
if (!class_exists('FakePingFeature')) {
    class FakePingFeature implements ApiFeatureInterface
    {
        public function register(Router $router, CoreServices $services): void
        {
            $router->get('/api/v1/__ping', static fn () => new \React\Http\Message\Response(200, [], 'pong'));
        }
    }
}

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/coqui-apifeature-' . bin2hex(random_bytes(4));
    mkdir($this->tempDir, 0755, true);
});

afterEach(function () {
    cleanupTestTree($this->tempDir);
});

test('discover returns empty when installed.json is missing', function () {
    expect((new ApiFeatureDiscovery($this->tempDir))->discover())->toBe([]);
});

test('discover instantiates a declared api feature', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/webhooks',
            'extra' => ['php-agents' => ['apiFeatures' => ['FakePingFeature']]],
        ]],
    ]));

    $result = (new ApiFeatureDiscovery($this->tempDir))->discover();

    expect($result)->toHaveCount(1);
    expect($result[0])->toBeInstanceOf(FakePingFeature::class);
});

test('a discovered feature registers routes on the Router', function () {
    $router = new Router();
    (new FakePingFeature())->register($router, /* not used by the fake */ Mockery::mock(CoreServices::class));
    $response = $router->dispatch(new \React\Http\Message\ServerRequest('GET', '/api/v1/__ping'));
    expect($response->getStatusCode())->toBe(200);
});

test('discover skips classes that do not implement ApiFeatureInterface', function () {
    $composerDir = $this->tempDir . '/vendor/composer';
    mkdir($composerDir, 0755, true);
    file_put_contents($composerDir . '/installed.json', json_encode([
        'packages' => [[
            'name' => 'acme/bad',
            'extra' => ['php-agents' => ['apiFeatures' => ['stdClass', 'Nonexistent\\Class']]],
        ]],
    ]));

    expect((new ApiFeatureDiscovery($this->tempDir))->discover())->toBe([]);
});
```

> If `Mockery` is not the project's mocking tool, replace that one assertion's `CoreServices` mock with a real `CoreServices` built from a temp `SessionStorage` (as in Task A1). Confirm with `grep -rn "Mockery\|mock(" tests/ | head`.

- [ ] **Step 2: Run to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Config/ApiFeatureDiscoveryTest.php`
Expected: FAIL — `ApiFeatureDiscovery` not found.

- [ ] **Step 3: Implement `ApiFeatureDiscovery`** (adapt `BackstoryExtractorDiscovery` verbatim: change the interface to `ApiFeatureInterface`, the extra key to `apiFeatures`, the return type to `list<ApiFeatureInterface>`, and the project-root fallback to `dirname(__DIR__, 2)` since this file is in `src/Config/`)

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Coqui\Config;

use CoquiBot\Coqui\Contract\ApiFeatureInterface;

/**
 * Discovers API-feature providers contributed by installed Composer packages.
 *
 * Packages declare provider classes under extra.php-agents.apiFeatures in
 * composer.json. Each must implement ApiFeatureInterface and be no-arg
 * constructable. Mirrors ToolkitDiscovery / BackstoryExtractorDiscovery so
 * that whole HTTP features live in optional mods instead of core.
 */
final class ApiFeatureDiscovery
{
    private readonly string $installedJsonPath;

    public function __construct(?string $projectRoot = null)
    {
        $root = $projectRoot ?? self::locateProjectRoot();
        $this->installedJsonPath = rtrim($root, '/') . '/vendor/composer/installed.json';
    }

    /**
     * @return list<ApiFeatureInterface>
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

        $packages = $data['packages'] ?? $data;
        if (!is_array($packages)) {
            return [];
        }

        $features = [];

        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $declared = $package['extra']['php-agents']['apiFeatures'] ?? null;
            if (!is_array($declared)) {
                continue;
            }

            foreach ($declared as $className) {
                if (!is_string($className)) {
                    continue;
                }

                $feature = self::tryInstantiate($className);
                if ($feature !== null) {
                    $features[] = $feature;
                }
            }
        }

        return $features;
    }

    private static function tryInstantiate(string $className): ?ApiFeatureInterface
    {
        try {
            if (!class_exists($className)) {
                return null;
            }

            /** @var class-string $className */
            $reflection = new \ReflectionClass($className);

            if (!$reflection->implementsInterface(ApiFeatureInterface::class) || $reflection->isAbstract()) {
                return null;
            }

            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                return null;
            }

            $instance = $reflection->newInstance();

            return $instance instanceof ApiFeatureInterface ? $instance : null;
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

        // Fallback: src/Config -> project root is two levels up.
        return dirname(__DIR__, 2);
    }
}
```

- [ ] **Step 4: Run tests + analyse; commit**

Run: `./vendor/bin/pest tests/Unit/Config/ApiFeatureDiscoveryTest.php && composer analyse`
Expected: PASS; `[OK] No errors`.

```bash
git add src/Config/ApiFeatureDiscovery.php tests/Unit/Config/ApiFeatureDiscoveryTest.php
git commit -m "$(cat <<'EOF'
feat(api): add ApiFeatureDiscovery for mod-contributed HTTP features

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

### Task A3: Wire discovered features into `ApiCommand`

**Files:**
- Modify: `src/Command/ApiCommand.php`

- [ ] **Step 1: Add the discovery loop**

After the `$this->registerRoutes($router, ...)` call (~line 368), before the server starts, add:

```php
        $coreServices = new \CoquiBot\Coqui\Api\CoreServices($storage, $boot->profileDiscovery(), $config);
        foreach ((new \CoquiBot\Coqui\Config\ApiFeatureDiscovery())->discover() as $apiFeature) {
            $apiFeature->register($router, $coreServices);
        }
```

Confirm the local variable names in scope at that point: `grep -nE '\$storage =|\$config =|\$boot|\$router =' src/Command/ApiCommand.php | head`. Use the actual names for `$storage` (the `SessionStorage`), `$config` (the `OpenClawConfig`), and the `$boot` boot manager. If `profileDiscovery()` is accessed differently, match the existing `$webhookMgmtHandler` construction which already calls `$boot->profileDiscovery()`.

- [ ] **Step 2: Verify green (no regression yet — webhooks still hardcoded)**

Run: `composer test && composer analyse`
Expected: green. (End-to-end discovery is proven in Part C.)

- [ ] **Step 3: Commit**

```bash
git add src/Command/ApiCommand.php
git commit -m "$(cat <<'EOF'
feat(api): register discovered API features in the server

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

### Task A4: Storage in the toolkit-discovery context

**Files:**
- Modify: `src/Agent/OrchestratorAgent.php` (the `instantiateRegisteredGrouped(context: [...])` call, ~line 547)

- [ ] **Step 1: Add `storage` to the context**

In the `context:` array passed to `$discovery->instantiateRegisteredGrouped(...)`, add:

```php
                'storage' => $this->storage,
```

This lets a discovered mod toolkit build a storage-backed instance via its `static fromCoquiContext(array $context)` factory (Strategy 0 in `ToolkitDiscovery::tryInstantiate`). Existing no-arg mod toolkits ignore the extra key.

- [ ] **Step 2: Verify green; commit**

Run: `composer test && composer analyse`
Expected: green.

```bash
git add src/Agent/OrchestratorAgent.php
git commit -m "$(cat <<'EOF'
feat(toolkits): expose storage to the toolkit-discovery context

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

### Task A5: Remove all webhook code from core

**Files — delete (source):**
- `src/Api/Handler/WebhookHandler.php`, `src/Api/Handler/WebhookManagementHandler.php`
- `src/Api/Webhook/WebhookDispatchService.php`, `GenericWebhookVerifier.php`, `GithubWebhookVerifier.php`, `SlackWebhookVerifier.php`, `WebhookVerifierRegistry.php`
- `src/Contract/WebhookVerifierInterface.php`
- `src/Storage/WebhookStore.php`
- `src/Toolkit/WebhookToolkit.php`
- `src/Repl/Handler/WebhookHandler.php`

**Files — delete (tests):** every webhook test. Enumerate first: `grep -rln "Webhook" tests/ --include=*.php`.

**Files — modify (decouple):**
- `src/Command/ApiCommand.php` — remove the `WebhookHandler`/`WebhookManagementHandler`/`WebhookVerifierRegistry`/`WebhookStore` imports, the `$webhookStore`/`$verifierRegistry`/`$webhookDispatcher`/`$webhookHandler`/`$webhookMgmtHandler` instantiations, the two `$webhook->register($router); $webhookMgmt->register($router);` calls (~line 693–694), and the `$webhook`/`$webhookMgmt` params from `registerRoutes()` (signature + call site ~line 368, 558–561).
- `src/Agent/OrchestratorAgent.php` — remove the inline `WebhookToolkit` block (~line 587–599) and the `'WebhookToolkit' => 'webhooks'` gate-map entry (~line 170), and the `use CoquiBot\Coqui\Toolkit\ArtifactToolkit;`-style webhook imports if present.
- `src/Api/Handler/HealthHandler.php` — remove the `use CoquiBot\Coqui\Storage\WebhookStore;`, the `?WebhookStore $webhookStore = null` constructor param, and the `if ($this->webhookStore !== null) { $data['webhooks'] = ... }` block (~line 66–68). Update the `ApiCommand` call site that passes a `WebhookStore` into `HealthHandler`.
- `src/Repl/ReplCommandCatalog.php` — remove the `/webhooks` `ReplCommandSpec` (~line 49).
- `src/Repl/SlashCommandRouter.php` — remove the `WebhookHandler` import (~line 26), the `$webhook` constructor param (~line 64), the `'/webhooks' => $this->handleWebhooks(...)` case (~line 126), and the `handleWebhooks()` method (~line 424). Update the `RunCommand` wiring that constructs `SlashCommandRouter` with a webhook handler.
- `src/Repl/TabCompletion.php` — remove the `WebhookStore` import (~line 14), the `'/webhooks' => $this->completeWebhooks(...)` case (~line 94), and `completeWebhooks()` (~line 210).
- `src/Command/RunCommand.php`, `src/Command/DoctorCommand.php` — remove webhook references (`grep -nE "webhook|Webhook" src/Command/RunCommand.php src/Command/DoctorCommand.php`).

- [ ] **Step 1: Enumerate the full reference set (work-list)**

```bash
grep -rln "Webhook" src/ tests/ --include=*.php
grep -rniE "webhook" src/ config/ --include=*.php --include=*.json | grep -viE "^Binary"
```

- [ ] **Step 2: Delete the source + test files** (`git rm` each path from the delete lists above, plus every test file found in Step 1).

- [ ] **Step 3: Excise every reference in the "modify" list.** Update the `HealthHandler` test and any REPL/ApiCommand test whose fixture passed a webhook collaborator.

- [ ] **Step 4: Run PHPStan and fix every dangling reference**

Run: `composer analyse`
Expected: `[OK] No errors`. Any undefined `Webhook*` symbol is a missed excision — fix and repeat.

- [ ] **Step 5: Update the session-shape / health tests**

`HealthHandler` no longer returns a `webhooks` key. In its test, remove any `expect($data)->toHaveKey('webhooks')` and assert absence: `expect($data)->not->toHaveKey('webhooks')`.

- [ ] **Step 6: Full suite; final orphan grep**

```bash
composer test
grep -rniE "webhookstore|webhookhandler|webhooktoolkit|webhookmanagement|webhookverifier|webhookdispatch|handleWebhooks|completeWebhooks" src/ tests/ --include=*.php
```
Expected: suite green; grep empty.

- [ ] **Step 7: Commit**

```bash
git add -u src/ tests/
git status --short   # verify .gitignore / .vscode/settings.json NOT staged
git commit -m "$(cat <<'EOF'
refactor(webhooks): remove the Webhooks feature from core

Webhooks moves to the optional coqui-toolkit-webhooks mod via the API-feature
and toolkit-discovery-context extension points. When the mod is not installed,
webhook routes 404, the toolkit is absent, and health omits its webhook block.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Part B — The mod package (new sibling repo `coqui-toolkit-webhooks`)

Created at `/home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-webhooks`. Uses a `../coqui` path repo so core types resolve at dev time (the self-contained-stubs refinement is a later follow-up, as with backstory-formats).

### Task B1: Scaffold the package

- [ ] **Step 1: Initialize**

```bash
mkdir -p /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-webhooks/src
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-webhooks
git init
```

- [ ] **Step 2: `composer.json`** (align the `pestphp/pest` / `phpstan/phpstan` constraints with `../coqui/composer.json`'s `require-dev` if `composer install` complains)

```json
{
    "name": "coquibot/coqui-toolkit-webhooks",
    "description": "Incoming webhooks for Coqui: HTTP receiver + management CRUD, HMAC verifiers (GitHub/Slack/generic), and the webhook toolkit.",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": "^8.4"
    },
    "require-dev": {
        "coquibot/coqui": "@dev",
        "pestphp/pest": "^3.8",
        "phpstan/phpstan": "^2.1"
    },
    "autoload": {
        "psr-4": { "CoquiBot\\Toolkits\\Webhooks\\": "src/" }
    },
    "autoload-dev": {
        "psr-4": { "CoquiBot\\Toolkits\\Webhooks\\Tests\\": "tests/" }
    },
    "repositories": [
        { "type": "path", "url": "../coqui", "options": { "symlink": true } }
    ],
    "extra": {
        "php-agents": {
            "apiFeatures": ["CoquiBot\\Toolkits\\Webhooks\\WebhooksFeature"],
            "toolkits": ["CoquiBot\\Toolkits\\Webhooks\\WebhookToolkit"],
            "description": "Incoming webhooks: receiver + management API and the webhook toolkit."
        }
    }
}
```

- [ ] **Step 3: `phpstan.neon`, `.gitignore`, `README.md`** (mirror backstory-formats)

`phpstan.neon`:
```neon
parameters:
    level: 8
    paths:
        - src
```
`.gitignore`:
```gitignore
/vendor/
composer.lock
.phpunit.cache/
```
`README.md`: a short description + `/mods install coquibot/coqui-toolkit-webhooks`, noting it self-registers its HTTP routes (`extra.php-agents.apiFeatures`) and toolkit (`extra.php-agents.toolkits`).

- [ ] **Step 4: `composer install`, then commit**

```bash
composer install
git add composer.json phpstan.neon .gitignore README.md
git commit -m "$(cat <<'EOF'
chore: scaffold coqui-toolkit-webhooks mod

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

### Task B2: Move the API pieces + add the `WebhooksFeature` provider

Copy each file **from the coqui repo** (they are the source of truth; if Part A5 already deleted them, recover with `git -C ../coqui show HEAD~1:<path>` from the pre-A5 commit) into the mod's `src/`, changing only the namespace and imports.

**Move (verbatim, re-namespaced `CoquiBot\Coqui\{Api\Webhook,Storage,Contract}` → `CoquiBot\Toolkits\Webhooks`):**

| From (coqui) | To (mod) |
|---|---|
| `src/Contract/WebhookVerifierInterface.php` | `src/WebhookVerifierInterface.php` |
| `src/Api/Webhook/GenericWebhookVerifier.php` | `src/GenericWebhookVerifier.php` |
| `src/Api/Webhook/GithubWebhookVerifier.php` | `src/GithubWebhookVerifier.php` |
| `src/Api/Webhook/SlackWebhookVerifier.php` | `src/SlackWebhookVerifier.php` |
| `src/Api/Webhook/WebhookVerifierRegistry.php` | `src/WebhookVerifierRegistry.php` |
| `src/Storage/WebhookStore.php` | `src/WebhookStore.php` |
| `src/Api/Webhook/WebhookDispatchService.php` | `src/WebhookDispatchService.php` |
| `src/Api/Handler/WebhookHandler.php` | `src/WebhookHandler.php` |
| `src/Api/Handler/WebhookManagementHandler.php` | `src/WebhookManagementHandler.php` |

For each moved file: set `namespace CoquiBot\Toolkits\Webhooks;`; replace `use CoquiBot\Coqui\{Api\Webhook,Storage\WebhookStore,Contract\WebhookVerifierInterface}\...` (the moved siblings) with same-namespace references (drop the `use` or point it at `CoquiBot\Toolkits\Webhooks\...`); KEEP imports of core types that stayed in core (`CoquiBot\Coqui\Api\Router`, `CoquiBot\Coqui\Storage\SessionStorage`, `CoquiBot\Coqui\Config\ProfileDiscovery`, `CoquiBot\Coqui\Api\Router`, `Psr\Http\Message\ServerRequestInterface`, `React\Http\Message\Response`, etc.) — the `../coqui` path repo resolves them.

- [ ] **Step 1: Move the nine files** and re-namespace as above.

- [ ] **Step 2: Create `src/WebhooksFeature.php`** (the provider)

```php
<?php

declare(strict_types=1);

namespace CoquiBot\Toolkits\Webhooks;

use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Contract\ApiFeatureInterface;

/**
 * Registers the incoming-webhook receiver and the webhook management CRUD API.
 */
final class WebhooksFeature implements ApiFeatureInterface
{
    public function register(Router $router, CoreServices $services): void
    {
        $store = new WebhookStore($services->pdo());
        $dispatcher = new WebhookDispatchService($store, $services->sessionStorage());

        (new WebhookHandler($store, $services->sessionStorage(), new WebhookVerifierRegistry(), $dispatcher))
            ->register($router);
        (new WebhookManagementHandler($store, $services->profileDiscovery(), $services->sessionStorage(), $dispatcher))
            ->register($router);
    }
}
```

- [ ] **Step 3: `composer analyse` in the mod** (`./vendor/bin/phpstan analyse --memory-limit=512M`) — resolve any missed re-namespacing. Commit when clean.

```bash
git add src/
git commit -m "$(cat <<'EOF'
feat: webhook HTTP API + WebhooksFeature provider

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

### Task B3: Move `WebhookToolkit` + add `fromCoquiContext`

- [ ] **Step 1: Move `src/Toolkit/WebhookToolkit.php`** → mod `src/WebhookToolkit.php`, namespace `CoquiBot\Toolkits\Webhooks;`, point its `WebhookStore` reference at the local class.

- [ ] **Step 2: Add the discovery factory** to `WebhookToolkit` (so `ToolkitDiscovery` can build it with a store from the context):

```php
    /**
     * @param array<string, mixed> $context
     */
    public static function fromCoquiContext(array $context): self
    {
        $storage = $context['storage'] ?? null;
        if (!$storage instanceof \CoquiBot\Coqui\Storage\SessionStorage) {
            throw new \RuntimeException('WebhookToolkit requires a SessionStorage in the discovery context.');
        }

        $activeProfile = $context['activeProfile'] ?? null;

        return new self(
            new WebhookStore($storage->getPdo()),
            '',
            is_string($activeProfile) ? $activeProfile : null,
        );
    }
```

Confirm the `WebhookToolkit` constructor signature is `(WebhookStore $webhookStore, string $apiBaseUrl = '', ?string $activeProfileId = null)` and adjust the factory if it differs.

- [ ] **Step 3: `composer analyse`; commit**

```bash
git add src/WebhookToolkit.php
git commit -m "$(cat <<'EOF'
feat: webhook toolkit with fromCoquiContext discovery factory

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

### Task B4: Move / write the mod's tests

- [ ] **Step 1: `tests/Pest.php`** — a minimal bootstrap (mirror backstory-formats' `tests/Pest.php`: any file helpers the moved tests need).

- [ ] **Step 2: Move the webhook tests** deleted from core in A5 into `tests/`, re-namespaced (`use CoquiBot\Toolkits\Webhooks\...`). Add:
  - a `WebhooksFeature` test: build `CoreServices` from a temp `SessionStorage`, call `register()` on a real `Router`, and assert an incoming-webhook route dispatches (e.g. a 404/401/200 as appropriate for an unknown subscription — match the handler's real behavior).
  - a `WebhookToolkit::fromCoquiContext` test: pass `['storage' => $storage, 'activeProfile' => 'default']`, assert a `WebhookToolkit` is returned and a subsequent tool call works.

- [ ] **Step 3: Run the mod suite + PHPStan**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-webhooks
./vendor/bin/pest
./vendor/bin/phpstan analyse --memory-limit=512M
```
Expected: PASS; `[OK] No errors`.

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "$(cat <<'EOF'
test: webhook mod — verifiers, handlers, feature, toolkit

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```

---

## Part C — Integration verification (coqui repo, not committed)

Proves installing the mod restores webhooks end-to-end via both hooks, then reverts so core ships mod-optional.

- [ ] **Step 1: Temporarily install the local mod into coqui**

```bash
cd /home/carmelo/Projects/CoquiBot/Core/coqui
composer config repositories.webhooks path ../coqui-toolkit-webhooks
composer require coquibot/coqui-toolkit-webhooks:@dev
```

- [ ] **Step 2: Verify API-feature discovery**

```bash
php -r 'require "vendor/autoload.php"; $d = new CoquiBot\Coqui\Config\ApiFeatureDiscovery(); $f = $d->discover(); echo count($f) . " feature(s): "; foreach ($f as $x) { echo get_class($x) . " "; } echo PHP_EOL;'
```
Expected: prints `1 feature(s): CoquiBot\Toolkits\Webhooks\WebhooksFeature`.

- [ ] **Step 3: Verify the toolkit is discoverable with storage** — construct `ToolkitDiscovery` as the app does and confirm `WebhookToolkit` instantiates via `fromCoquiContext` when `storage` is in the context (a short `php -r` using a temp `SessionStorage`, or run any existing toolkit-discovery integration test with the mod installed).

- [ ] **Step 4: Full suite + PHPStan with the mod installed**

Run: `composer test && composer analyse`
Expected: green.

- [ ] **Step 5: Revert the local wiring**

```bash
composer remove coquibot/coqui-toolkit-webhooks
composer config --unset repositories.webhooks
git checkout composer.json composer.lock
composer install
git status --short
```
Expected: `composer.json`/`composer.lock` back to their A5 state, `vendor/` no longer contains the mod, `git status` shows only the two intentional unstaged edits. No commit.

---

## Final Verification (coqui repo)

- [ ] `composer test` green; `composer analyse` `[OK] No errors`.
- [ ] `grep -rln "Webhook" src/` returns nothing (all webhook code gone from core).
- [ ] `git status --short` shows only `.gitignore` (M) and `.vscode/settings.json` (D).
- [ ] Update `config/source.json`: remove all webhook file entries; add `src/Contract/ApiFeatureInterface.php`, `src/Api/CoreServices.php`, `src/Config/ApiFeatureDiscovery.php`. Update the `OrchestratorAgent`/`HealthHandler`/`ApiCommand` entries if their descriptions mention webhooks. Commit:

```bash
git add config/source.json docs/API.md docs/FEATURES.md README.md
git commit -m "$(cat <<'EOF'
docs(webhooks): source map + docs for the webhooks mod extraction

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>
EOF
)"
```
- [ ] Docs: in `docs/API.md` note the webhook endpoints now require `coqui-toolkit-webhooks`; in `docs/FEATURES.md`/`README.md` mark webhooks as an optional mod; if a `docs/WEBHOOKS.md` exists, add an install note (or move its content to the mod's README).

## Self-Review

- **Spec coverage:** Hook 1 (ApiFeatureInterface/CoreServices/ApiFeatureDiscovery/ApiCommand loop) ✓ A1–A3; Hook 2 (storage in context + fromCoquiContext) ✓ A4 + B3; removal/decoupling ✓ A5; mod (both `extra` hooks, provider, moved code, toolkit factory, tests) ✓ B1–B4; integration verify+revert ✓ Part C; source.json/docs ✓ Final.
- **Placeholder scan:** new code is complete; moved files use precise copy+renamespace instructions with a namespace map (a verbatim move, not a placeholder).
- **Type consistency:** `CoreServices` accessors match their uses in `WebhooksFeature`; `fromCoquiContext` matches the `WebhookToolkit` constructor; `ApiFeatureDiscovery.discover(): list<ApiFeatureInterface>` matches the `ApiCommand` loop.

**Handoff:** developed on `feat/webhooks-extraction-impl` (core) + a new `coqui-toolkit-webhooks` sibling repo; the reviewer verifies and opens the PR. Do not push or open a PR without confirmation.
