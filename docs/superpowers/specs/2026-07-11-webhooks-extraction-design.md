# Webhooks Extraction Design (Phase 2)

**Status:** Approved (brainstormed 2026-07-11).
**Roadmap:** platform-thinning Phase 2. Webhooks → optional mod; **Artifacts stays in core** (hard dependency of loops — see `artifact-redesign`).

## Goal

Move the entire Webhooks feature out of Coqui core into a new optional mod, `coqui-toolkit-webhooks`, and build the reusable **mod extension points** that make it possible. Webhooks is a clean leaf: it consumes core (creates tasks, triggers agents) but nothing in core depends on it. It is dep-free, so this is **scope-thinning** (trim what doesn't serve loops/profiles/budgeting), not dependency reduction. Precedent: the backstory-formats extraction (`docs/superpowers/plans/2026-07-10-backstory-formats-extraction.md`) and its mod repo.

## Why two extension points

Webhooks presents on two surfaces, each registered by a different subsystem:

1. **HTTP API** (`WebhookHandler` incoming receiver + `WebhookManagementHandler` CRUD, plus verifiers + dispatch + store). Today `ApiCommand` hardcodes ~24 handlers and calls `->register($router)` on each. **No discovery for API handlers exists.** → New hook needed.
2. **Agent toolkit** (`WebhookToolkit`). Today it is hand-registered inline in `OrchestratorAgent` because it needs a `WebhookStore` (→ PDO), and the existing toolkit-discovery context (`config`, `activeProfile`, `sessionId`, `mcp_runtime`, `workspacePath`) has **no storage**. The discovery machinery already supports a `static fromCoquiContext(array $context)` factory (`ToolkitDiscovery::tryInstantiate` Strategy 0) for dependency-needing toolkits — it just can't build a store without a PDO in the context. → Small additive fix: put storage in the context.

Both hooks are additive and reusable by any future feature-mod. This is the "package API/storage extension point" the roadmap gated Phase 2 on.

## Hook 1 — API Feature provider (new)

```php
// src/Contract/ApiFeatureInterface.php
interface ApiFeatureInterface {
    public function register(Router $router, CoreServices $services): void;
}
```

`CoreServices` (`src/Api/CoreServices.php`) is a tiny read-only container exposing exactly what a feature-mod needs from core — grounded in what the webhook services take:

- `WebhookStore(pdo)`, `WebhookDispatchService(store, SessionStorage)`, `WebhookHandler(store, SessionStorage, verifiers, dispatcher)`, `WebhookManagementHandler(store, ProfileDiscovery, SessionStorage, dispatcher)`.

So: `sessionStorage(): SessionStorage`, `pdo(): \PDO` (= `sessionStorage()->getPdo()`), `profileDiscovery(): ProfileDiscovery`, `config(): OpenClawConfig`. We widen it only when a future mod needs more.

`ApiFeatureDiscovery` (`src/Config/ApiFeatureDiscovery.php`) mirrors `BackstoryExtractorDiscovery`: it reads `extra.php-agents.apiFeatures` (a list of provider FQCNs) from each package in `vendor/composer/installed.json`, instantiates the no-arg-constructable providers that implement `ApiFeatureInterface`, and returns them. `ApiCommand`, after registering its built-in handlers, loops: `foreach ($discovery->discover() as $feature) { $feature->register($router, $coreServices); }`.

## Hook 2 — storage in the toolkit-discovery context (additive)

`OrchestratorAgent` passes `storage` into the toolkit-discovery context:

```php
$discovery->instantiateRegisteredGrouped(context: [
    'config' => $this->config,
    'activeProfile' => $this->activeProfile,
    'sessionId' => $this->sessionId,
    'mcp_runtime' => $this->mcpRuntime,
    'storage' => $this->storage,   // NEW — enables storage-backed mod toolkits
]);
```

The mod's `WebhookToolkit` gains a `public static function fromCoquiContext(array $context): self` that builds its `WebhookStore` from `$context['storage']->getPdo()` and reads `$context['activeProfile']`. This is the existing Strategy-0 convention — no change to `ToolkitDiscovery::tryInstantiate` itself, only one added context entry. Existing no-arg mod toolkits are unaffected (extra context keys are ignored).

## The mod — `coqui-toolkit-webhooks`

New sibling repo at `/home/carmelo/Projects/CoquiBot/Core/coqui-toolkit-webhooks`, namespace `CoquiBot\Toolkits\Webhooks\`, mirroring `coqui-toolkit-backstory-formats`: `composer.json` (with a `../coqui` path-repo + `require-dev coquibot/coqui:@dev` so core types resolve at dev time), `phpstan.neon`, `README.md`, `.gitignore`, `src/`, `tests/`. It declares **both** hooks in `extra.php-agents`:

```json
"extra": { "php-agents": {
    "apiFeatures": ["CoquiBot\\Toolkits\\Webhooks\\WebhooksFeature"],
    "toolkits":    ["CoquiBot\\Toolkits\\Webhooks\\WebhookToolkit"]
}}
```

`WebhooksFeature` owns all HTTP wiring behind one entry point:

```php
final class WebhooksFeature implements ApiFeatureInterface {
    public function register(Router $router, CoreServices $s): void {
        $store = new WebhookStore($s->pdo());
        $dispatcher = new WebhookDispatchService($store, $s->sessionStorage());
        (new WebhookHandler($store, $s->sessionStorage(), new WebhookVerifierRegistry(), $dispatcher))->register($router);
        (new WebhookManagementHandler($store, $s->profileDiscovery(), $s->sessionStorage(), $dispatcher))->register($router);
    }
}
```

Moved verbatim (re-namespaced `CoquiBot\Coqui\Api\Webhook`/`Storage`/`Toolkit`/`Contract` → `CoquiBot\Toolkits\Webhooks`): `WebhookDispatchService`, `GenericWebhookVerifier`, `GithubWebhookVerifier`, `SlackWebhookVerifier`, `WebhookVerifierRegistry`, `WebhookVerifierInterface`, `WebhookHandler`, `WebhookManagementHandler`, `WebhookStore`, `WebhookToolkit` (+ `fromCoquiContext`). Storage stays on the shared core SQLite via `CoreServices::pdo()` — `WebhookStore` still self-creates `webhook_deliveries` idempotently; no data move.

## What's removed / decoupled in core

- **Delete:** `src/Api/Handler/WebhookHandler.php`, `WebhookManagementHandler.php`, `src/Api/Webhook/*` (dispatch + 3 verifiers + registry), `src/Contract/WebhookVerifierInterface.php`, `src/Storage/WebhookStore.php`, `src/Toolkit/WebhookToolkit.php`, `src/Repl/Handler/WebhookHandler.php`, and their tests.
- **Decouple:** `ApiCommand` (drop webhook instantiation + the two `register` calls + `registerRoutes` params); `OrchestratorAgent` (drop the inline `WebhookToolkit` block + the `'WebhookToolkit' => 'webhooks'` gate-map entry); `HealthHandler` (drop the `?WebhookStore` param + `$data['webhooks']` block — webhook stats become the mod's concern); REPL (`ReplCommandCatalog` `/webhooks` spec, `SlashCommandRouter` import/param/case/`handleWebhooks`, `TabCompletion` import/case/`completeWebhooks`); `DoctorCommand`/`RunCommand` refs.
- **REPL `/webhooks` is dropped** (API-first; webhook management is API-driven).

## Behavior when the mod is not installed

Webhook routes 404, the toolkit is absent, health omits its webhook block. `/mods install coquibot/coqui-toolkit-webhooks` restores the full feature via the two hooks — no core change.

## Testing

- **Core:** `ApiFeatureDiscovery` unit test (finds/instantiates a fake provider from a synthetic `installed.json`; skips non-implementers). An `ApiCommand`-level test that a fake provider's route is reachable through the discovery loop. The full suite stays green with **all** webhook code gone (webhook tests deleted; `HealthHandler`/REPL tests updated).
- **Mod:** self-contained tests for the verifiers, dispatch, store, handlers, toolkit `fromCoquiContext`, and the `WebhooksFeature` provider (registers its routes onto a real `Router`).
- **Integration (verify, then revert):** install the local mod into core, hit `POST /webhooks/...` + confirm the toolkit is discovered, then revert so core ships mod-optional.

## Out of scope

Artifacts (stays in core); migrating other built-in handlers onto `ApiFeatureInterface`; generalizing `CoreServices` beyond what webhooks needs; publishing the mod to Packagist; converting the mod to fully self-contained stubs (a later refinement, as with backstory-formats — path-repo is fine for now).
