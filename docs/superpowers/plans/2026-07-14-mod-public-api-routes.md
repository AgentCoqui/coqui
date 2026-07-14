# Mod-Declarable Public API Routes — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any mod declare a specific API route it registers as **public** (auth-exempt), honored by `AuthMiddleware` through one explicit, greppable mechanism (`addPublicRoute`), and dogfood it by moving the core `/api/v1/health` exemption onto the same path.

**Architecture:** `Router` gains a `requiresAuth` flag on `addRoute`, an explicit `addPublicRoute()` that records the route's compiled `{param}`→regex in a separate public-patterns list, and an `isPublicPath()` matcher (the single exemption implementation). `AuthMiddleware` takes a `?callable $isPublic` closure wired to `$router->isPublicPath(...)`, replacing its hardcoded `/api/v1/health` string. `ApiCommand` wires the closure, re-registers health via `addPublicRoute`, and logs the public-route set at boot. Auth is the *only* thing lifted for public routes — rate-limit, CORS, size, and content-type middleware still apply.

**Tech Stack:** PHP 8.4 (strict types, `final`), ReactPHP HTTP request/response objects, Pest for tests, PHPStan for static analysis. No new dependencies.

## Global Constraints

- `declare(strict_types=1);` in every PHP file; `final` classes; 4-space indent; constructor injection.
- **No `toolkit → mod` rename in code.** "Mod" is the umbrella category (skills + toolkits + future kinds); "toolkit" stays one kind of mod. Vocabulary clarified in docs only.
- **No `CoreServices` widening** — it stays minimal.
- **No rate-limit exemption for mods.** Public routes remain rate-limited. The mechanism lifts *only* the API-key requirement. Do **not** touch `RateLimitMiddleware::EXEMPT_PATHS` (its separate `/api/v1/health` entry stays).
- **No change to the auth scheme** — still `Authorization: Bearer <api.key>`.
- Do **not** commit `config/documentation.json` (generated + untracked).
- Validation gates: `composer test` fully green; `composer analyse` (PHPStan, `--memory-limit=512M`) zero errors.

---

## File Structure

- `src/Api/Router.php` — add `requiresAuth` flag, `addPublicRoute()`, `isPublicPath()`, `publicRoutes()`, and a private `compilePattern()` helper shared by `addRoute`/`addPublicRoute` (one regex-compile implementation).
- `src/Api/Middleware/AuthMiddleware.php` — add `?callable $isPublic` ctor param; remove hardcoded `/api/v1/health` string; skip Bearer check when the closure matches.
- `src/Command/ApiCommand.php` — construct `AuthMiddleware` with `$router->isPublicPath(...)`; register health via `addPublicRoute`; log the public-route set at boot.
- `tests/Unit/Api/RouterTest.php` — **new** — Router unit coverage (flag default, `addPublicRoute` dispatch, `isPublicPath` matching precision, `publicRoutes()`).
- `tests/Unit/Api/AuthMiddlewareTest.php` — **new** — middleware unit coverage (null-closure default, public skip, enforced-by-default, health no longer auto-exempt).
- `tests/Unit/Api/PublicRouteMiddlewareStackTest.php` — **new** — integration over Router + RateLimit + Auth (auth-inheritance 401/200, public reachable without key, public still 429).
- `docs/TOOLKIT-EXTENSIBILITY.md` — **new "API features" section** (declaring `extra.php-agents.apiFeatures`, implementing `ApiFeatureInterface`, `CoreServices` surface, authenticated vs `addPublicRoute`, secure-your-own-route warning, mod-umbrella note).
- `docs/API.md` — cross-reference to the new section.
- `config/source.json` — update `Router` and `AuthMiddleware` responsibility entries.

---

## Task 1: Router — `requiresAuth` flag + shared `compilePattern()` helper

**Files:**
- Modify: `src/Api/Router.php:20-46` (routes array shape, `addRoute`)
- Test: `tests/Unit/Api/RouterTest.php` (create)

**Interfaces:**
- Consumes: nothing (first task).
- Produces:
  - `Router::addRoute(string $method, string $path, callable $handler, bool $requiresAuth = true): void`
  - private `Router::compilePattern(string $path): array{regex: string, params: string[]}`
  - route entries now carry `'requiresAuth' => bool`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Api/RouterTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Router;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

test('addRoute registers an authenticated route that dispatches', function () {
    $router = new Router();
    $router->addRoute('GET', '/api/v1/thing', static fn (): Response => new Response(200, [], 'ok'));

    $response = $router->dispatch(new ServerRequest('GET', '/api/v1/thing'));

    expect($response->getStatusCode())->toBe(200);
});

test('a route registered via addRoute is not public by default', function () {
    $router = new Router();
    $router->addRoute('GET', '/api/v1/thing', static fn (): Response => new Response(200));

    expect($router->isPublicPath('/api/v1/thing'))->toBeFalse();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Api/RouterTest.php`
Expected: FAIL — `Call to undefined method ...Router::isPublicPath()` (the second test). The first passes already; it locks existing behavior.

- [ ] **Step 3: Write minimal implementation**

In `src/Api/Router.php`, update the routes-array docblock, extract `compilePattern`, and thread `requiresAuth`:

```php
    /** @var array<string, array{pattern: string, handler: callable, regex: string, params: string[], requiresAuth: bool}> */
    private array $routes = [];

    /** @var list<string> Compiled regexes for routes registered as public (auth-exempt). */
    private array $publicPatterns = [];

    /** @var list<array{method: string, path: string}> Public routes, for the boot-time audit log. */
    private array $publicRoutes = [];
```

Replace `addRoute` with:

```php
    /**
     * Register a route handler.
     *
     * @param callable(ServerRequestInterface, array<string, string>): Response $handler
     * @param bool $requiresAuth When false, the route is exempt from AuthMiddleware. Prefer addPublicRoute() to set this.
     */
    public function addRoute(string $method, string $path, callable $handler, bool $requiresAuth = true): void
    {
        $compiled = $this->compilePattern($path);

        $key = strtoupper($method) . ':' . $path;
        $this->routes[$key] = [
            'pattern' => $path,
            'handler' => $handler,
            'regex' => $compiled['regex'],
            'params' => $compiled['params'],
            'requiresAuth' => $requiresAuth,
        ];
    }

    /**
     * Compile a {param} path pattern into an anchored, single-segment regex.
     *
     * @return array{regex: string, params: string[]}
     */
    private function compilePattern(string $path): array
    {
        $params = [];
        $regex = preg_replace_callback('/\{(\w+)\}/', static function (array $matches) use (&$params): string {
            $params[] = $matches[1];
            return '([^/]+)';
        }, $path);

        return ['regex' => '#^' . $regex . '$#', 'params' => $params];
    }
```

(Add `isPublicPath()` as a stub returning `false` so the second test can resolve the method — it is fully implemented in Task 2. Place it below `compilePattern`:)

```php
    public function isPublicPath(string $path): bool
    {
        foreach ($this->publicPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./vendor/bin/pest tests/Unit/Api/RouterTest.php`
Expected: PASS (both tests).

- [ ] **Step 5: Run static analysis on the file**

Run: `./vendor/bin/phpstan analyse src/Api/Router.php --memory-limit=512M`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Api/Router.php tests/Unit/Api/RouterTest.php
git commit -m "refactor(api): thread requiresAuth flag through Router.addRoute"
```

---

## Task 2: Router — `addPublicRoute()`, `isPublicPath()`, `publicRoutes()`

**Files:**
- Modify: `src/Api/Router.php` (add public-route methods, populate `publicPatterns`/`publicRoutes`)
- Test: `tests/Unit/Api/RouterTest.php` (extend)

**Interfaces:**
- Consumes: `addRoute(..., bool $requiresAuth)`, `compilePattern()`, `isPublicPath()` stub from Task 1.
- Produces:
  - `Router::addPublicRoute(string $method, string $path, callable $handler): void`
  - `Router::isPublicPath(string $path): bool` (now backed by real patterns)
  - `Router::publicRoutes(): list<array{method: string, path: string}>`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Api/RouterTest.php`:

```php
test('addPublicRoute registers a dispatchable route', function () {
    $router = new Router();
    $router->addPublicRoute('GET', '/api/v1/status', static fn (): Response => new Response(200, [], 'up'));

    $response = $router->dispatch(new ServerRequest('GET', '/api/v1/status'));

    expect($response->getStatusCode())->toBe(200);
});

test('isPublicPath matches a registered public route', function () {
    $router = new Router();
    $router->addPublicRoute('GET', '/api/v1/status', static fn (): Response => new Response(200));

    expect($router->isPublicPath('/api/v1/status'))->toBeTrue();
});

test('isPublicPath returns false for an unregistered path', function () {
    $router = new Router();

    expect($router->isPublicPath('/api/v1/status'))->toBeFalse();
});

test('isPublicPath matches a {param} public route on a single segment only', function () {
    $router = new Router();
    $router->addPublicRoute('POST', '/api/v1/webhooks/incoming/{name}', static fn (): Response => new Response(200));

    expect($router->isPublicPath('/api/v1/webhooks/incoming/gh'))->toBeTrue();
    // A sibling route without the trailing segment is NOT public.
    expect($router->isPublicPath('/api/v1/webhooks'))->toBeFalse();
    // An extra path segment must NOT match ([^/]+ is single-segment, regex is anchored).
    expect($router->isPublicPath('/api/v1/webhooks/incoming/gh/extra'))->toBeFalse();
});

test('publicRoutes lists registered public routes for the audit log', function () {
    $router = new Router();
    $router->addPublicRoute('GET', '/api/v1/health', static fn (): Response => new Response(200));
    $router->addPublicRoute('POST', '/api/v1/webhooks/incoming/{name}', static fn (): Response => new Response(200));

    expect($router->publicRoutes())->toBe([
        ['method' => 'GET', 'path' => '/api/v1/health'],
        ['method' => 'POST', 'path' => '/api/v1/webhooks/incoming/{name}'],
    ]);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Api/RouterTest.php`
Expected: FAIL — `Call to undefined method ...Router::addPublicRoute()` (and `publicRoutes()`).

- [ ] **Step 3: Write minimal implementation**

In `src/Api/Router.php`, add after `addRoute()` (and remove the Task 1 `isPublicPath` stub, replacing with the final versions below):

```php
    /**
     * Register a PUBLIC (auth-exempt) route.
     *
     * This is the single, greppable entry point for an unauthenticated route:
     * search the codebase for `addPublicRoute` to enumerate every route that
     * bypasses the API key. The route still passes through rate-limit, CORS,
     * size, and content-type middleware — only the API-key check is lifted, and
     * securing it (signature/HMAC/etc.) is the registrant's responsibility.
     *
     * @param callable(ServerRequestInterface, array<string, string>): Response $handler
     */
    public function addPublicRoute(string $method, string $path, callable $handler): void
    {
        $this->addRoute($method, $path, $handler, requiresAuth: false);

        $this->publicPatterns[] = $this->compilePattern($path)['regex'];
        $this->publicRoutes[] = ['method' => strtoupper($method), 'path' => $path];
    }

    /**
     * Whether a request path matches a route registered via addPublicRoute().
     *
     * The only exemption matcher — AuthMiddleware defers to this rather than
     * re-implementing pattern matching.
     */
    public function isPublicPath(string $path): bool
    {
        foreach ($this->publicPatterns as $pattern) {
            if (preg_match($pattern, $path) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registered public routes (method + path pattern), for the boot audit log.
     *
     * @return list<array{method: string, path: string}>
     */
    public function publicRoutes(): array
    {
        return $this->publicRoutes;
    }
```

Delete the temporary `isPublicPath` stub from Task 1 (the version above replaces it).

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Api/RouterTest.php`
Expected: PASS (all Router tests).

- [ ] **Step 5: Run static analysis**

Run: `./vendor/bin/phpstan analyse src/Api/Router.php --memory-limit=512M`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Api/Router.php tests/Unit/Api/RouterTest.php
git commit -m "feat(api): add Router.addPublicRoute/isPublicPath/publicRoutes"
```

---

## Task 3: AuthMiddleware — `?callable $isPublic` + remove hardcoded health exemption

**Files:**
- Modify: `src/Api/Middleware/AuthMiddleware.php:19-62`
- Test: `tests/Unit/Api/AuthMiddlewareTest.php` (create)

**Interfaces:**
- Consumes: nothing from prior tasks at runtime (the closure is supplied by the caller); conceptually pairs with `Router::isPublicPath`.
- Produces: `new AuthMiddleware(?string $apiKey = null, ?callable $isPublic = null)` where `$isPublic` is `fn(string $path): bool`. Null ⇒ no route is exempt.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Api/AuthMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

$ok = static fn (): Response => new Response(200, [], 'ok');

test('no configured key allows all requests', function () use ($ok) {
    $mw = new AuthMiddleware(null, null);

    $response = $mw(new ServerRequest('GET', '/api/v1/anything'), $ok);

    expect($response->getStatusCode())->toBe(200);
});

test('configured key rejects a request with no Authorization header', function () use ($ok) {
    $mw = new AuthMiddleware('secret', null);

    $response = $mw(new ServerRequest('GET', '/api/v1/sessions'), $ok);

    expect($response->getStatusCode())->toBe(401);
});

test('configured key accepts a correct Bearer token', function () use ($ok) {
    $mw = new AuthMiddleware('secret', null);
    $request = (new ServerRequest('GET', '/api/v1/sessions'))->withHeader('Authorization', 'Bearer secret');

    $response = $mw($request, $ok);

    expect($response->getStatusCode())->toBe(200);
});

test('health is no longer auto-exempt when no isPublic closure is given', function () use ($ok) {
    // Locks in removal of the hardcoded /api/v1/health string: with a null
    // closure, health must be treated like any other route → 401 without a key.
    $mw = new AuthMiddleware('secret', null);

    $response = $mw(new ServerRequest('GET', '/api/v1/health'), $ok);

    expect($response->getStatusCode())->toBe(401);
});

test('isPublic closure exempts a matching path from the Bearer check', function () use ($ok) {
    $isPublic = static fn (string $path): bool => $path === '/api/v1/health';
    $mw = new AuthMiddleware('secret', $isPublic);

    $response = $mw(new ServerRequest('GET', '/api/v1/health'), $ok);

    expect($response->getStatusCode())->toBe(200);
});

test('isPublic closure does not exempt a non-matching path', function () use ($ok) {
    $isPublic = static fn (string $path): bool => $path === '/api/v1/health';
    $mw = new AuthMiddleware('secret', $isPublic);

    $response = $mw(new ServerRequest('GET', '/api/v1/sessions'), $ok);

    expect($response->getStatusCode())->toBe(401);
});

test('OPTIONS preflight is skipped regardless of key', function () use ($ok) {
    $mw = new AuthMiddleware('secret', null);

    $response = $mw(new ServerRequest('OPTIONS', '/api/v1/sessions'), $ok);

    expect($response->getStatusCode())->toBe(200);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Unit/Api/AuthMiddlewareTest.php`
Expected: FAIL — the ctor takes one arg (`Too few / unknown argument`), and `health is no longer auto-exempt` fails because the hardcoded string still returns 200.

- [ ] **Step 3: Write minimal implementation**

Replace the constructor and the health-skip block in `src/Api/Middleware/AuthMiddleware.php`:

```php
    /**
     * @param ?callable(string): bool $isPublic Returns true for auth-exempt request paths (wired to Router::isPublicPath). Null ⇒ no exemptions.
     */
    public function __construct(
        private readonly ?string $apiKey = null,
        private $isPublic = null,
    ) {}
```

In `__invoke`, delete the hardcoded health block (lines 40-43) and insert, after the OPTIONS skip:

```php
        // Skip auth for routes a mod (or core) registered as public via addPublicRoute
        if ($this->isPublic !== null && ($this->isPublic)($request->getUri()->getPath())) {
            return $next($request);
        }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/pest tests/Unit/Api/AuthMiddlewareTest.php`
Expected: PASS (all).

- [ ] **Step 5: Run static analysis**

Run: `./vendor/bin/phpstan analyse src/Api/Middleware/AuthMiddleware.php --memory-limit=512M`
Expected: no errors. (If PHPStan flags the untyped `$isPublic` property, add `/** @var ?callable(string): bool */` above it — do not add a native `callable` property type, which PHP forbids.)

- [ ] **Step 6: Commit**

```bash
git add src/Api/Middleware/AuthMiddleware.php tests/Unit/Api/AuthMiddlewareTest.php
git commit -m "feat(api): AuthMiddleware honors an isPublic closure; drop hardcoded health exemption"
```

---

## Task 4: Integration — middleware stack (auth-inheritance, public reachable, still rate-limited)

**Files:**
- Test: `tests/Unit/Api/PublicRouteMiddlewareStackTest.php` (create)

**Interfaces:**
- Consumes: `Router::addRoute`, `Router::addPublicRoute`, `Router::isPublicPath` (Tasks 1–2); `AuthMiddleware(?string, ?callable)` (Task 3); existing `RateLimitMiddleware(int $maxRequests, int $windowSeconds)`.
- Produces: no new production surface — this task proves the real closure-over-router wiring end-to-end and that rate-limiting still applies to public routes.

Note: middleware is applied via `Router::applyMiddleware`, which reverses the added order; adding `[rateLimit, auth]` makes rate-limit the *outer* wrapper (so a rate-limited request 429s before auth runs) — matching `ApiCommand`'s CORS→rate→…→auth ordering. The 429 test must use a **non-health** public path, because `RateLimitMiddleware::EXEMPT_PATHS` still exempts `/api/v1/health` from rate limiting (that separate exemption is intentionally out of scope).

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Api/PublicRouteMiddlewareStackTest.php`:

```php
<?php

declare(strict_types=1);

use CoquiBot\Coqui\Api\Middleware\AuthMiddleware;
use CoquiBot\Coqui\Api\Middleware\RateLimitMiddleware;
use CoquiBot\Coqui\Api\Router;
use React\Http\Message\Response;
use React\Http\Message\ServerRequest;

/**
 * Build a router with one authenticated and one public route, wrapped in the
 * rate-limit + auth stack exactly as ApiCommand wires it.
 */
function buildStack(string $apiKey, int $rateMax = 30): Router
{
    $router = new Router();
    $router->addRoute('GET', '/api/v1/private', static fn (): Response => new Response(200, [], 'private'));
    $router->addPublicRoute('POST', '/api/v1/public/{name}', static fn (): Response => new Response(200, [], 'public'));

    $router->addMiddleware(new RateLimitMiddleware($rateMax, 60));
    $router->addMiddleware(new AuthMiddleware($apiKey, static fn (string $path): bool => $router->isPublicPath($path)));

    return $router;
}

test('a normal mod route is 401 without a key when api.key is configured', function () {
    $router = buildStack('secret');

    $response = $router->dispatch(new ServerRequest('GET', '/api/v1/private'));

    expect($response->getStatusCode())->toBe(401);
});

test('a normal mod route is 200 with the correct Bearer key', function () {
    $router = buildStack('secret');
    $request = (new ServerRequest('GET', '/api/v1/private'))->withHeader('Authorization', 'Bearer secret');

    $response = $router->dispatch($request);

    expect($response->getStatusCode())->toBe(200);
});

test('a public route is reachable without a key even when api.key is set', function () {
    $router = buildStack('secret');

    $response = $router->dispatch(new ServerRequest('POST', '/api/v1/public/gh'));

    expect($response->getStatusCode())->toBe(200);
});

test('a public route is still rate-limited', function () {
    // Capacity of 1: first request consumes the only token, second is 429 —
    // proving auth exemption does not imply rate-limit exemption.
    $router = buildStack('secret', rateMax: 1);

    $first = $router->dispatch(new ServerRequest('POST', '/api/v1/public/gh'));
    $second = $router->dispatch(new ServerRequest('POST', '/api/v1/public/gh'));

    expect($first->getStatusCode())->toBe(200);
    expect($second->getStatusCode())->toBe(429);
});
```

- [ ] **Step 2: Run tests to verify they fail or pass correctly**

Run: `./vendor/bin/pest tests/Unit/Api/PublicRouteMiddlewareStackTest.php`
Expected: PASS — all four. (Tasks 1–3 already provide the production surface; this task adds only tests. If any fail, fix the production code from the earlier task rather than the test.)

- [ ] **Step 3: Run static analysis**

Run: `./vendor/bin/phpstan analyse tests/Unit/Api/PublicRouteMiddlewareStackTest.php --memory-limit=512M`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add tests/Unit/Api/PublicRouteMiddlewareStackTest.php
git commit -m "test(api): lock auth-inheritance, public reachability, and public rate-limiting"
```

---

## Task 5: ApiCommand — wire closure, register health publicly, boot audit log

**Files:**
- Modify: `src/Command/ApiCommand.php:391-393` (AuthMiddleware construction), `:566` (health route), and add the audit-log loop after feature registration (near `:373`).

**Interfaces:**
- Consumes: `Router::addPublicRoute`, `Router::isPublicPath`, `Router::publicRoutes` (Tasks 1–2); `AuthMiddleware(?string, ?callable)` (Task 3).
- Produces: no new public API — production wiring only. Verified by the full suite staying green plus the existing `ApiHealthCheckTest`.

- [ ] **Step 1: Register health via `addPublicRoute`**

In `registerRoutes()`, change line 566:

```php
        // Health (public — no API key required so liveness probes work unauthenticated)
        $router->addPublicRoute('GET', $v1 . '/health', $health);
```

- [ ] **Step 2: Wire the `isPublic` closure into `AuthMiddleware`**

Change the construction block at lines 391-393:

```php
        if ($apiKey !== null) {
            $middlewareStack[] = new AuthMiddleware(
                $apiKey,
                static fn (string $path): bool => $router->isPublicPath($path),
            );
        }
```

- [ ] **Step 3: Add the boot-time public-route audit log**

Immediately after the `$apiFeatureDiscovery->registerAll(...)` call (after line 373, before the middleware-stack comment at line 375), add:

```php
        // Boot-time audit: surface exactly which routes are exposed without an
        // API key, so an operator can see the public surface (core + mods) at a glance.
        foreach ($router->publicRoutes() as $publicRoute) {
            $output->writeln(sprintf(
                '<info>Public API route (no auth):</info> %s %s',
                $publicRoute['method'],
                $publicRoute['path'],
            ));
        }
```

- [ ] **Step 4: Run the full suite to verify nothing regressed**

Run: `composer test`
Expected: PASS — full green, including the existing `tests/Unit/Api/ApiHealthCheckTest.php`.

- [ ] **Step 5: Run static analysis on the file**

Run: `./vendor/bin/phpstan analyse src/Command/ApiCommand.php --memory-limit=512M`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add src/Command/ApiCommand.php
git commit -m "feat(api): register health via addPublicRoute; wire isPublic closure; log public routes at boot"
```

---

## Task 6: Documentation + source map

**Files:**
- Modify: `docs/TOOLKIT-EXTENSIBILITY.md` (add "API features" section)
- Modify: `docs/API.md` (cross-reference)
- Modify: `config/source.json` (Router + AuthMiddleware entries)

**Interfaces:**
- Consumes: the final signatures from Tasks 1–5.
- Produces: no code surface.

- [ ] **Step 1: Add the "API features" section to `docs/TOOLKIT-EXTENSIBILITY.md`**

Append a new top-level section. It MUST cover, in this order:

1. **Declaring an API feature** — a mod lists provider class(es) under `extra.php-agents.apiFeatures` in its `composer.json`; `ApiFeatureDiscovery` scans installed mods and instantiates each no-arg provider; registration failures are isolated so one faulty mod cannot abort API-server boot.
2. **Implementing `ApiFeatureInterface`** — `register(Router $router, CoreServices $services): void`. Show a minimal provider that registers one authenticated route.
3. **The `CoreServices` surface** — enumerate exactly what it exposes (`sessionStorage()`, `pdo()`, `profileDiscovery()`, `config()`) and note it is deliberately minimal.
4. **Authenticated vs public routes** — `$router->addRoute(...)` / the verb helpers are authenticated by default (inherit the Bearer `api.key` gate). `$router->addPublicRoute($method, $path, $handler)` registers an auth-exempt route; it is the single greppable marker for unauthenticated routes.
5. **Security warning (prominent, e.g. a blockquote/callout):** core lifts **only** the API-key gate for a public route. Rate-limit, CORS, size, and content-type middleware still apply, but **you must secure your own public route** — verify a signature/HMAC/shared-secret yourself (this is exactly how a webhook receiver authenticates by signature rather than the API key).
6. **Mod-umbrella note (one line):** "mod" is the umbrella category (skills + toolkits + future kinds); "toolkit" is one kind of mod — this is a vocabulary clarification, **not** a rename.

Use this provider example for step 2/4:

````markdown
```php
use CoquiBot\Coqui\Api\CoreServices;
use CoquiBot\Coqui\Api\Router;
use CoquiBot\Coqui\Contract\ApiFeatureInterface;
use React\Http\Message\Response;

final class ExampleFeature implements ApiFeatureInterface
{
    public function register(Router $router, CoreServices $services): void
    {
        // Authenticated: inherits the Bearer api.key gate.
        $router->get('/api/v1/example/items', static fn () => new Response(200, [], '[]'));

        // Public: auth-exempt. YOU must verify the caller (e.g. an HMAC signature).
        $router->addPublicRoute('POST', '/api/v1/example/incoming/{name}', static function ($request, string $name) {
            // verify signature over the raw body here before trusting the request
            return new Response(202);
        });
    }
}
```
````

- [ ] **Step 2: Add the cross-reference in `docs/API.md`**

Add a short line/subsection pointing readers who want to *extend* the API to the new "API features" section of `docs/TOOLKIT-EXTENSIBILITY.md` (contrast: `API.md` documents consuming the API; extensibility docs cover adding routes from a mod).

- [ ] **Step 3: Update `config/source.json`**

Update the `src/Api/Router.php` entry — description and `methods` — to include the public-route surface:

```json
        {
            "path": "src/Api/Router.php",
            "fqcn": "CoquiBot\\Coqui\\Api\\Router",
            "layer": "config",
            "description": "Pattern-matching HTTP router for ReactPHP. Supports {param} path parameters via anchored single-segment regex. Routes are authenticated by default; addPublicRoute() registers auth-exempt routes and isPublicPath() is the sole exemption matcher AuthMiddleware defers to. Provides convenience verb methods, middleware chain, jsonResponse(), and errorResponse().",
            "methods": [
                "addRoute(string $method, string $path, callable $handler, bool $requiresAuth = true): void",
                "addPublicRoute(string $method, string $path, callable $handler): void — register an auth-exempt route (the single greppable marker for public routes)",
                "isPublicPath(string $path): bool — the only exemption matcher",
                "publicRoutes(): list<array{method,path}> — public routes for the boot audit log",
                "get/post/put/patch/delete(string $path, callable $handler): void — authenticated convenience methods",
                "errorResponse(ApiErrorCode $code, string $message, mixed $details): Response",
                "dispatch(ServerRequestInterface $request): Response"
            ]
        },
```

Update the `src/Api/Middleware/AuthMiddleware.php` entry:

```json
        {
            "path": "src/Api/Middleware/AuthMiddleware.php",
            "fqcn": "CoquiBot\\Coqui\\Api\\Middleware\\AuthMiddleware",
            "layer": "config",
            "description": "Bearer token API key authentication middleware. Skips auth for OPTIONS and for any path matched by the injected isPublic closure (Router::isPublicPath) — there is no hardcoded exemption. Uses hash_equals for timing-safe comparison.",
            "methods": [
                "__construct(?string $apiKey = null, ?callable $isPublic = null)",
                "__invoke(ServerRequestInterface $request, callable $next): Response"
            ]
        },
```

- [ ] **Step 4: Verify docs consistency (no build step)**

Run: `grep -n "addPublicRoute\|isPublicPath" docs/TOOLKIT-EXTENSIBILITY.md config/source.json`
Expected: matches in both files. Confirm `config/documentation.json` is **not** staged (it is generated + untracked).

- [ ] **Step 5: Commit**

```bash
git add docs/TOOLKIT-EXTENSIBILITY.md docs/API.md config/source.json
git commit -m "docs(api): document mod API features + public routes; update source map"
```

---

## Final Validation (whole branch)

- [ ] **Full test suite**

Run: `composer test`
Expected: fully green (report actual pass/fail counts).

- [ ] **Static analysis**

Run: `composer analyse`
Expected: zero errors.

- [ ] **Grep the greppability guarantee**

Run: `grep -rn "addPublicRoute" src/`
Expected: the `Router` definition plus exactly the health registration in `ApiCommand` — every public route in core is findable in one search.

- [ ] **Confirm the hardcoded exemption is gone**

Run: `grep -n "/api/v1/health" src/Api/Middleware/AuthMiddleware.php`
Expected: no matches.

- [ ] **Push the branch**

```bash
git push origin feat/mod-public-api-routes
```

Do **not** open or merge the PR — leave integration to the user.

---

## Self-Review Notes (author checklist, already applied)

- **Spec coverage:** Router `requiresAuth`/`addPublicRoute`/`isPublicPath` (T1–T2); AuthMiddleware closure + health-hardcode removal (T3); ApiCommand wiring + health dogfood + boot audit log (T5); every §7/spec-Testing case mapped — auth-inheritance 401/200 (T4), public-without-key (T4), public-still-429 (T4), matching precision (T2), health-via-mechanism (T3 removal test + T5 registration + existing `ApiHealthCheckTest`), Router units (T1–T2); docs + source.json (T6).
- **Out of scope, honored:** no `toolkit→mod` rename; no `CoreServices` widening; no rate-limit exemption (T4 proves the opposite); `RateLimitMiddleware::EXEMPT_PATHS` untouched; webhooks repo untouched; `config/documentation.json` not committed.
- **Type consistency:** `addPublicRoute(string,string,callable): void`, `isPublicPath(string): bool`, `publicRoutes(): list<array{method,path}>`, `AuthMiddleware(?string,?callable)` used identically across T1–T6.
