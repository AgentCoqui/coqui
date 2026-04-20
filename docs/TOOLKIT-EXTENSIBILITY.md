# Toolkit REPL Extensibility

Toolkits can register their own slash commands in the Coqui REPL without modifying core code. This guide explains the contract, lifecycle, and implementation pattern.

## Overview

The extensibility system uses interface-based discovery:

1. A toolkit implements `ReplCommandProvider` alongside `ToolkitInterface`
2. At REPL startup, `ToolkitDiscovery` finds enabled toolkits that implement the interface
3. Their `commandHandlers()` are collected and wired into the `SlashCommandRouter`
4. Tab completion, help output, and command dispatch work automatically

Core commands always take precedence over toolkit-provided commands.

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
    public function description(): string;       // one-line help text
    public function handle(ToolkitReplContext $context, string $arg): void;
}
```

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

## Lifecycle

1. `BootManager::commandHandlers()` calls `ToolkitDiscovery::commandHandlers()`
2. Only toolkits with `Enabled` visibility are checked
3. `CredentialGuardToolkit` wrappers are unwrapped via `innerToolkit()`
4. If the inner toolkit implements `ReplCommandProvider`, its handlers are collected
5. `ReplCommandCatalog::registerToolkitHandlers()` registers specs for help output
6. `TabCompletion::setToolkitCommandHandlers()` enables argument completion
7. `SlashCommandRouter` dispatches unrecognized commands to matching toolkit handlers

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
- `ImageCommandHandler` implements both `ToolkitCommandHandler` and `ToolkitTabCompletionProvider`
- The handler receives the built tools from the parent toolkit — no core coupling

See `Toolkits/coqui-toolkit-images/src/Command/ImageCommandHandler.php` for the full implementation.
