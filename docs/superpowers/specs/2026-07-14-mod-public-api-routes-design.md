# Mod-Declarable Public API Routes — Design

**Date:** 2026-07-14
**Status:** Approved (design) — pending spec review
**Branch:** `feat/mod-public-api-routes`
**Brief:** #7 — API-extensible mods (public-route completion + hardening + docs)

## Problem

The original #7 premise — *"toolkits can't extend the HTTP API the way they extend the REPL"* — is **stale**. The extension point already exists on `main`, shipped with the webhooks extraction:

- `ApiFeatureInterface::register(Router $router, CoreServices $services): void` (`src/Contract/ApiFeatureInterface.php`)
- `ApiFeatureDiscovery` scans `extra.php-agents.apiFeatures` in `installed.json`, instantiates no-arg providers, isolates registration failures (`src/Config/ApiFeatureDiscovery.php`)
- Wired in `ApiCommand` after core routes register (`src/Command/ApiCommand.php`)
- A real consumer: the webhooks mod registers full CRUD via `WebhooksFeature`
- Covered by `tests/Unit/Config/ApiFeatureDiscoveryTest.php`

Because middleware wraps *all* routes and is added *after* every route is registered, mod routes already inherit auth, CORS, rate-limiting, and size/content-type checks by construction.

**The real gap:** a mod cannot declare a route as **public** (auth-exempt). `AuthMiddleware` hardcodes its only exemption as `/api/v1/health` (`src/Api/Middleware/AuthMiddleware.php:41`). So the canonical external-facing case — webhooks' `POST /api/v1/webhooks/incoming/{name}`, which authenticates by *signature*, not the API key — is `401`'d the moment an `api.key` is configured, before it ever reaches its verifier. Public endpoints (webhook receivers, OAuth/payment callbacks, a mod's public status page) are exactly what external-facing mods need, and the extension point has no way to express them.

Two secondary gaps: the auth-inheritance guarantee (mod routes are authenticated by default) is only *structural* — no test locks it in; and there is **no mod-author documentation** for the API extension point.

## Goals

- Let any mod declare a specific route it registers as **public** (auth-exempt), honored by `AuthMiddleware`, via one explicit and auditable mechanism.
- Replace the hardcoded `/api/v1/health` exemption with that same mechanism (dogfood — one code path for core and mods).
- Lock in the auth-inheritance guarantee with an explicit test.
- Document the API extension point for mod authors.
- Prove it end-to-end by adopting it in the webhooks mod (Part 2).

## Non-goals

- **No `toolkit → mod` rename.** "Mod" is the umbrella category (skills + toolkits + future extension kinds); "toolkit" stays one kind of mod. Vocabulary is clarified in docs, not renamed in code.
- **No `CoreServices` widening.** It stays deliberately minimal; widen only when a real feature-mod requires more (the webhooks incoming route needs nothing new).
- **No rate-limit exemption for mods.** Public routes remain rate-limited (they are the DoS surface). The mechanism lifts *only* the API-key requirement.
- **No per-mod middleware injection**, no new REST program, no change to the auth scheme (still `Authorization: Bearer <key>`).

## Design

**Guiding principle:** public is opt-in, explicit, per-route, and greppable — every unauthenticated route in the codebase is findable with one search (`addPublicRoute`). Default stays authenticated.

### Component 1 — `Router` (`src/Api/Router.php`)

- `addRoute()` gains a trailing `bool $requiresAuth = true`. **Every existing core call is unchanged** (default `true`). The route entry records `requiresAuth`.
- **New `addPublicRoute(string $method, string $path, callable $handler): void`** — the single explicit entry point for an auth-exempt route. Internally calls `addRoute(..., requiresAuth: false)` and records the route's compiled `{param}`→regex in a public-patterns list.
- **New `isPublicPath(string $path): bool`** — matches a request path against the registered public patterns. This is the *only* path-matching implementation used for exemptions (the middleware does not re-implement regex matching).

The verb helpers (`get`/`post`/`put`/`delete`/`patch`) stay authenticated-only (unchanged signatures) — keeping core's ~22 registrations untouched and making `addPublicRoute` stand out in review.

### Component 2 — `AuthMiddleware` (`src/Api/Middleware/AuthMiddleware.php`)

- Constructor gains `?callable $isPublic = null` — a `fn(string $path): bool`, wired to `$router->isPublicPath(...)`.
- The hardcoded `/api/v1/health` string is **removed**. Health re-registers via `addPublicRoute` and is exempted through the same mechanism.
- `__invoke`: if `$isPublic` matches the request path → `$next($request)` (skip the Bearer check). The existing OPTIONS-preflight skip and the "no key configured → allow all" branch are unchanged.
- When `$isPublic` is `null` (e.g. a unit test constructing the middleware alone), no routes are exempt — safe default.

### Component 3 — `ApiCommand` wiring (`src/Command/ApiCommand.php`)

No ordering change: core + mod routes already register *before* the middleware stack is built, so the public set is complete when `AuthMiddleware` is constructed.

- Construct `AuthMiddleware` with `$router->isPublicPath(...)`.
- Move the `/api/v1/health` registration to `addPublicRoute('GET', '/api/v1/health', ...)`.
- **Boot-time audit:** after all features register, log the full list of public routes (method + path) at API-server start, so an operator sees exactly what is exposed without a key.

### Security guardrails

- A mod can only expose routes **it** registers (it calls `addPublicRoute` on the `Router` it is handed; it cannot alter core route entries).
- Rate limiting, CORS, size, and content-type middleware **still apply** to public routes.
- **Securing a public route is the mod's responsibility** — core lifts only the API-key gate; the mod verifies signatures/HMAC/etc. itself. Stated prominently in the docs.

## Data / component changes

| Change | Location |
|--------|----------|
| `addRoute()` + `bool $requiresAuth = true`; record flag | `src/Api/Router.php` |
| New `addPublicRoute()` + public-patterns list | `src/Api/Router.php` |
| New `isPublicPath(string $path): bool` | `src/Api/Router.php` |
| `?callable $isPublic` ctor param; drop hardcoded health exemption | `src/Api/Middleware/AuthMiddleware.php` |
| Wire `isPublicPath`; health via `addPublicRoute`; boot audit log | `src/Command/ApiCommand.php` |
| API-features + public-routes author guide | `docs/TOOLKIT-EXTENSIBILITY.md`, cross-ref `docs/API.md` |
| Responsibility updates | `config/source.json` |

## Testing (TDD)

- **Auth-inheritance (the missing guarantee):** a normal mod-registered route → `401` without a key when `api.key` is configured; `200` with the correct Bearer key.
- **Public route:** a mod route registered via `addPublicRoute` → reachable **without** a key even when `api.key` is set.
- **Public route still rate-limited:** exceeding the limit on a public route → `429`.
- **Matching precision:** `addPublicRoute('POST', '/api/v1/webhooks/incoming/{name}', ...)` → `isPublicPath` matches `/api/v1/webhooks/incoming/gh`, and does **not** match a sibling like `/api/v1/webhooks` or `/api/v1/webhooks/incoming/gh/extra`.
- **Health via mechanism:** `/api/v1/health` still exempt, now through `addPublicRoute` rather than the removed hardcoded string.
- **`Router` units:** `addPublicRoute` registers a dispatchable route; `requiresAuth` default is `true`; `isPublicPath` returns `false` for unregistered paths.
- **Back-compat:** existing core routes and existing mod features are unaffected; no `api.key` still means local-open mode.

## Part 2 — webhooks adoption (integration proof, separate repo)

In `coqui-toolkit-webhooks`:

- `WebhookHandler::register` switches the incoming receiver to `$router->addPublicRoute('POST', '/api/v1/webhooks/incoming/{name}', ...)`. `WebhookManagementHandler` CRUD routes stay authenticated (unchanged).
- Integration test against the coqui commit that adds `addPublicRoute`: with `api.key` set, `incoming/{name}` is reachable without a key while `/api/v1/webhooks` returns `401` without a key. (Same version-pairing pattern as backstory ↔ #157.)

This is the first-party consumer that proves the mechanism; bundling it prevents shipping an extensibility feature with no live consumer — the exact circumstance that left the auth-inheritance guarantee untested.

**Sequencing:** the core work (coqui repo) is self-contained and independently mergeable — it lands first (the health-route dogfood and the full test suite validate the mechanism inside core, with no dependency on the webhooks repo). The webhooks adoption is a fast-follow against the merged/released coqui commit, exactly like the backstory toolkit followed #157.

## Definition of Done

- `Router` public-route mechanism + `AuthMiddleware` rewire implemented via TDD; hardcoded health exemption removed.
- All tests above pass; `composer test` and `composer analyse` green.
- `docs/TOOLKIT-EXTENSIBILITY.md` (API-features + public routes) and `config/source.json` updated; `docs/API.md` cross-reference added.
- Boot-time public-route audit log present.
- Core mechanism is independently green and mergeable on its own; webhooks adoption (Part 2) is a fast-follow in its repo with the integration test green against the coqui commit that ships `addPublicRoute`.
