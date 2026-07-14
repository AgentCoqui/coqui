# Toolkit REPL Extensibility

Toolkits can register their own slash commands in the Coqui REPL without modifying core code. This guide explains the contract, lifecycle, and implementation pattern.

## Overview

The extensibility system uses interface-based discovery:

1. A toolkit implements `ReplCommandProvider` alongside `ToolkitInterface`
2. At REPL startup, `ToolkitDiscovery` finds enabled toolkits that implement the interface
3. Their `commandHandlers()` are collected and wired into the `SlashCommandRouter`
4. Tab completion, help output, and command dispatch work automatically

Core commands always take precedence over toolkit-provided commands. When two toolkits register the same command name, the first discovered handler wins and Coqui logs a warning during REPL startup.

## Contracts

All contracts live in `CoquiBot\Coqui\Contract\`:

### ReplCommandProvider

```php
interface ReplCommandProvider
{
    /** @return list<ToolkitCommandHandler> */
    public function commandHandlers(): array;
}
```

Implement this on your toolkit class alongside `ToolkitInterface`.

### ToolkitCommandHandler

```php
interface ToolkitCommandHandler
{
    public function commandName(): string;       // e.g. 'image'
    public function subcommands(): array;        // e.g. ['generate', 'list']
    public function usage(): string;             // e.g. '/image [action]'
    public function description(): string;       // concise one-line text for global /help
    public function handle(ToolkitReplContext $context, string $arg): void;
}
```

One toolkit can return multiple handlers from `commandHandlers()`, so registering `/foo` and `/bar` from the same package is supported.

### ToolkitCommandHelpProvider (optional)

Use this when you want richer structured help for `/command` and `/command help` while still using the shared core formatter.

```php
interface ToolkitCommandHelpProvider
{
    public function help(): ToolkitCommandHelp;
}

final readonly class ToolkitCommandHelp
{
    public function __construct(
        ?string $title = null,
        ?string $summary = null,
        array $subcommands = [],   // list<ToolkitCommandHelpEntry>
        array $examples = [],      // list<ToolkitCommandExample>
        array $notes = [],         // list<string>
    ) {}
}
```

If a handler does not implement `ToolkitCommandHelpProvider`, Coqui auto-generates a command homepage from `usage()`, `description()`, and `subcommands()`.

Use `title` when you want the help page heading to be a human-readable display title like `Image Generation & Management` instead of the raw slash command.

### ToolkitTabCompletionProvider (optional)

```php
interface ToolkitTabCompletionProvider
{
    /** @return list<string> */
    public function completeArguments(string $commandName, array $parts): array;
}
```

Implement this on your command handler for dynamic tab completion beyond static subcommands.

### ToolkitReplContext

A readonly services object passed to `handle()`:

| Property / Method | Description |
|---|---|
| `$context->io` | `SymfonyStyle` for formatted output |
| `$context->prompt` | `InterruptiblePrompt` with ESC cancellation |
| `$context->workspacePath` | Absolute workspace directory |
| `$context->activeProfile` | Current personality profile (nullable) |
| `$context->sessionId` | Current session ID |
| `$context->createSpinner(string $label)` | Returns an `AnimatedTickCallback` for progress |
| `$context->openDatabase(string $name)` | Returns a WAL-mode SQLite PDO |

## Minimal Example

```php
// src/MyCommandHandler.php
final class MyCommandHandler implements ToolkitCommandHandler
{
    public function commandName(): string { return 'mykit'; }
    public function subcommands(): array { return ['status', 'run']; }
    public function usage(): string { return '/mykit [action]'; }
    public function description(): string { return 'My toolkit commands.'; }

    public function handle(ToolkitReplContext $context, string $arg): void
    {
        $context->io->success('Hello from /mykit ' . $arg);
    }
}

// src/MyToolkit.php
final class MyToolkit implements ToolkitInterface, ReplCommandProvider
{
    public function tools(): array { return []; }
    public function guidelines(): string { return ''; }

    public function commandHandlers(): array
    {
        return [new MyCommandHandler()];
    }
}
```

## Dependency

Add `coquibot/coqui` to your toolkit's `composer.json` `require` section for the contract interfaces:

```json
{
    "require": {
        "coquibot/coqui": "^0.12"
    }
}
```

A lighter `coquibot/coqui-contracts` package is a reasonable future extraction if third-party toolkit development grows, but Coqui currently keeps these REPL contracts in the main package because the toolkits are still co-developed together.

## Lifecycle

1. `BootManager::commandHandlers()` calls `ToolkitDiscovery::commandHandlers()`
2. Only toolkits with `Enabled` visibility are checked
3. `CredentialGuardToolkit` wrappers are unwrapped via `innerToolkit()`
4. If the inner toolkit implements `ReplCommandProvider`, its handlers are collected
5. `ReplCommandCatalog::registerToolkitHandlers()` applies collision policy and registers specs for help output
6. `TabCompletion::setToolkitCommandHandlers()` enables argument completion
7. `SlashCommandRouter` renders the standardized toolkit help page for `/command` and `/command help`
8. `SlashCommandRouter` dispatches non-help invocations to the matching toolkit handler

## Help and UX Rules

- Keep `description()` short. It is shown in the global `/help` table and should not duplicate the subcommand list.
- Prefer `ToolkitCommandHelpProvider` for command-specific help pages instead of printing bespoke help text from `handle()`.
- `help` is a reserved first argument for toolkit commands. Coqui adds it automatically for tab completion and standardized help routing.
- If a toolkit command reports usage errors, prefer pointing the user back to `/command help` instead of dumping a fully custom help page.

## Services Available to Handlers

### Spinner

```php
$spinner = $context->createSpinner('processing');
$spinner->start('processing');
// ... long operation ...
$spinner->stop();
```

### Database

```php
$pdo = $context->openDatabase('my-toolkit-data');
$pdo->exec('CREATE TABLE IF NOT EXISTS items (id TEXT PRIMARY KEY, name TEXT)');
```

Databases are stored at `{workspacePath}/{name}.db` with WAL mode enabled.

### Interactive Prompts

```php
$answer = $context->prompt->ask('Enter a value');
$confirmed = $context->prompt->confirm('Proceed?', false);
$choice = $context->prompt->choice('Pick one', ['a', 'b', 'c']);
```

All prompts support ESC cancellation.

## Real-World Example: Image Toolkit

The `coqui-toolkit-images` package registers `/image` via this pattern:

- `ImagesToolkit` implements both `ToolkitInterface` and `ReplCommandProvider`
- `commandHandlers()` returns `[new ImageCommandHandler($this->tools())]`
- `ImageCommandHandler` implements `ToolkitCommandHandler`, `ToolkitCommandHelpProvider`, and `ToolkitTabCompletionProvider`
- The handler receives the built tools from the parent toolkit — no core coupling

See `Toolkits/coqui-toolkit-images/src/Command/ImageCommandHandler.php` for the full implementation.

## API Features

Beyond REPL commands, a mod can contribute HTTP routes to the API server. This uses the same interface-based discovery model as REPL commands, so whole HTTP features can live in optional mods instead of core.

> **"Mod" vs "toolkit":** "mod" is the umbrella category for everything an installed package can contribute (skills, toolkits, and future kinds); a "toolkit" is one kind of mod. This is a vocabulary clarification, not a rename — the discovery mechanism is the same.

### Declaring an API feature

A mod lists its provider class(es) under `extra.php-agents.apiFeatures` in `composer.json`:

```json
{
    "extra": {
        "php-agents": {
            "apiFeatures": [
                "Vendor\\MyMod\\Api\\ExampleFeature"
            ]
        }
    }
}
```

At API-server boot, `ApiFeatureDiscovery` scans `vendor/composer/installed.json`, instantiates each declared provider (each must implement `ApiFeatureInterface` and be **no-arg constructable**), and registers it. Registration failures are isolated: each provider's `register()` runs in its own try/catch, so one faulty mod cannot abort API-server boot — the failure is reported and the remaining providers still register.

### Implementing `ApiFeatureInterface`

The contract lives in `CoquiBot\Coqui\Contract\`:

```php
interface ApiFeatureInterface
{
    public function register(Router $router, CoreServices $services): void;
}
```

A minimal provider that registers one authenticated route and one public route:

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

### The `CoreServices` surface

`CoreServices` is a read-only handle to the core collaborators a feature needs. It is deliberately minimal — widen it only when a future feature-mod genuinely requires more:

| Method | Description |
|---|---|
| `$services->sessionStorage()` | The shared session store (`SessionStorage`) |
| `$services->pdo()` | The core SQLite `PDO` handle |
| `$services->profileDiscovery()` | Profile resolution (`ProfileDiscovery`) |
| `$services->config()` | Runtime configuration (`OpenClawConfig`) |

### Authenticated vs public routes

- `$router->addRoute(...)` and the verb helpers (`get`, `post`, `put`, `patch`, `delete`) are **authenticated by default**: they inherit the Bearer `api.key` gate enforced by `AuthMiddleware`.
- `$router->addPublicRoute($method, $path, $handler)` registers an **auth-exempt** route. It is the single greppable marker for unauthenticated routes — searching the codebase for `addPublicRoute` enumerates every route that bypasses the API key.

### Securing a public route

> **Security warning.** Registering a route as public lifts **only** the API-key gate. The rate-limit, CORS, size, and content-type middleware still apply, but the API key is no longer checked — so **you must secure your own public route**. Verify a signature, HMAC, or shared secret yourself over the raw request body before trusting the request. This is exactly how a webhook receiver authenticates: by signature, not by the API key. Note the exemption is **path-scoped, not method-scoped**: it lifts the API key for *all* HTTP methods on the matching path pattern, so if you register a public `POST /…/{name}` and also serve a `GET` on the same path pattern, the `GET` is unauthenticated too — scope your public paths accordingly.

### Dependency

Add `coquibot/coqui` to your mod's `composer.json` `require` section for the `ApiFeatureInterface`, `Router`, and `CoreServices` types, the same way REPL command providers depend on it.
